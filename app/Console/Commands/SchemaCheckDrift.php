<?php

namespace App\Console\Commands;

use App\Support\SchemaDrift;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Comprueba que `database/sql/01_schema.sql` es lo que dejan las migraciones.
 *
 * El drift de `tokenable_id` (G3) vivió meses sin verse porque los tests
 * cargan el artefacto, no las migraciones: si alguien edita una migración sin
 * regenerar el SQL —o al revés—, la suite sigue en verde. Este comando
 * automatiza el chequeo que antes se hacía a mano:
 *
 *   1. CREATE DATABASE temporal en el mismo servidor.
 *   2. `migrate` contra ella, desde cero.
 *   3. `schema:dump-sql` de esa base a un fichero temporal.
 *   4. Comparación con el artefacto, ignorando solo las líneas de versión
 *      de `pg_dump` (ver `SchemaDrift`).
 *   5. DROP DATABASE, pase lo que pase.
 *
 * Los pasos 2 y 3 corren en un proceso hijo con `DB_DATABASE` apuntando a la
 * base temporal: es el mismo procedimiento que el manual, y deja intacta la
 * conexión de este proceso. Por eso exige que la configuración no esté
 * cacheada — con caché, el hijo ignoraría `DB_DATABASE` y migraría la base
 * configurada.
 *
 * Por defecto se niega con un host que no sea local: no tiene sentido crear
 * bases en Supabase para esto. `--allow-remote` existe para un CI cuyo
 * Postgres de servicio no se llame localhost.
 *
 * Código de salida: 0 sin drift, 1 con drift o si algo falla.
 */
class SchemaCheckDrift extends Command
{
    protected $signature = 'schema:check-drift
        {--against=database/sql/01_schema.sql : Artefacto con el que comparar}
        {--allow-remote : Permite un host de BD que no sea local}
        {--keep : No borrar la base temporal (para inspeccionarla)}';

    protected $description = 'Verifica que las migraciones producen exactamente database/sql/01_schema.sql';

    private const HOSTS_LOCALES = ['127.0.0.1', 'localhost', '::1'];

    public function handle(): int
    {
        $conexion = config('database.default');
        $cfg      = config("database.connections.{$conexion}");

        if (($cfg['driver'] ?? null) !== 'pgsql') {
            $this->error("La conexión por defecto ({$conexion}) no es PostgreSQL.");
            return self::FAILURE;
        }

        if ($this->laravel->configurationIsCached()) {
            $this->error('La configuración está cacheada: el proceso hijo no vería la base temporal. Corre `php artisan config:clear`.');
            return self::FAILURE;
        }

        if (! in_array($cfg['host'], self::HOSTS_LOCALES, true) && ! $this->option('allow-remote')) {
            $this->error("El host de BD ({$cfg['host']}) no es local. Usa --allow-remote si es el Postgres de servicio de un CI.");
            return self::FAILURE;
        }

        $artefacto = base_path($this->option('against'));
        if (! is_file($artefacto)) {
            $this->error("No existe el artefacto {$this->option('against')}.");
            return self::FAILURE;
        }

        $temporal = substr($cfg['database'], 0, 40) . '_drift_' . bin2hex(random_bytes(4));
        $volcado  = 'storage/framework/' . $temporal . '.sql';

        $this->info("Base temporal: {$temporal} en {$cfg['host']}:{$cfg['port']}");
        DB::connection($conexion)->statement('CREATE DATABASE ' . $this->identificador($temporal));

        try {
            if (! $this->hijo(['migrate', '--force', '--no-interaction'], $temporal, 'migrate')) {
                return self::FAILURE;
            }

            if (! $this->hijo(['schema:dump-sql', "--output={$volcado}"], $temporal, 'schema:dump-sql')) {
                return self::FAILURE;
            }

            $diff = SchemaDrift::diff(file_get_contents($artefacto), file_get_contents(base_path($volcado)));
        } finally {
            @unlink(base_path($volcado));

            if ($this->option('keep')) {
                $this->warn("Se conserva la base {$temporal} (--keep). Bórrala a mano al terminar.");
            } else {
                DB::connection($conexion)->statement(
                    'DROP DATABASE IF EXISTS ' . $this->identificador($temporal) . ' WITH (FORCE)'
                );
            }
        }

        if ($diff === null) {
            $this->info('Sin drift: las migraciones producen exactamente el artefacto.');
            return self::SUCCESS;
        }

        $this->error('Hay drift entre las migraciones y el artefacto:');
        $this->line($diff);
        $this->line('Si el cambio es intencionado: `php artisan migrate` y `php artisan schema:dump-sql`, y commitea ambos.');

        return self::FAILURE;
    }

    /** Corre un comando artisan en un proceso hijo apuntando a la base temporal. */
    private function hijo(array $argumentos, string $base, string $etiqueta): bool
    {
        $proceso = new Process(
            [PHP_BINARY, 'artisan', '--env=' . $this->laravel->environment(), ...$argumentos],
            base_path(),
            ['DB_DATABASE' => $base],
            null,
            600
        );

        $proceso->run();

        if (! $proceso->isSuccessful()) {
            $this->error("Falló `{$etiqueta}` sobre la base temporal:");
            $this->line($proceso->getOutput() . $proceso->getErrorOutput());
            return false;
        }

        return true;
    }

    /** El nombre lo genera este comando, pero se cita igual: va en DDL sin bindings. */
    private function identificador(string $nombre): string
    {
        return '"' . str_replace('"', '""', $nombre) . '"';
    }
}
