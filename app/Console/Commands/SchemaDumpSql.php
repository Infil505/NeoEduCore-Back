<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Regenera database/sql/01_schema.sql desde el ESQUEMA REAL de la BD
 * (que es el resultado de las migraciones). Así el SQL que cargan los tests
 * es un artefacto GENERADO y no puede divergir de las migraciones.
 *
 * Flujo recomendado:
 *   1. Cambiás el esquema con una migración.
 *   2. php artisan migrate
 *   3. php artisan schema:dump-sql   (regenera el SQL de tests)
 *   4. Commit de la migración + del 01_schema.sql.
 *
 * Requiere pg_dump (PostgreSQL client). Ruta configurable con PG_DUMP_PATH.
 */
class SchemaDumpSql extends Command
{
    protected $signature = 'schema:dump-sql {--output=database/sql/01_schema.sql}';
    protected $description = 'Genera el SQL de esquema (para tests) desde la BD real vía pg_dump';

    public function handle(): int
    {
        $cfg = config('database.connections.pgsql');
        $pgDump = env('PG_DUMP_PATH', 'pg_dump');

        $process = new Process([
            $pgDump,
            '-h', $cfg['host'],
            '-p', (string) $cfg['port'],
            '-U', $cfg['username'],
            '-d', $cfg['database'],
            '--schema-only',
            '--no-owner',
            '--no-privileges',
            '--no-comments',
            '--schema=public',
        ], base_path(), ['PGPASSWORD' => $cfg['password']], null, 120);

        $this->info("Ejecutando pg_dump contra {$cfg['host']}:{$cfg['port']}/{$cfg['database']} ...");
        $process->run();

        if (!$process->isSuccessful()) {
            $this->error('pg_dump falló: ' . $process->getErrorOutput());
            $this->line('Sugerencia: definí PG_DUMP_PATH en .env apuntando a pg_dump.exe');
            return self::FAILURE;
        }

        $clean = $this->clean($process->getOutput());

        $out = $this->option('output');
        file_put_contents(base_path($out), $clean);

        $this->info("Esquema regenerado en {$out} (" . substr_count($clean, "\n") . " líneas).");
        return self::SUCCESS;
    }

    /**
     * Quita lo que el cargador de tests (DB::unprepared) no traga:
     * meta-comandos psql (\restrict/\unrestrict), CREATE SCHEMA public
     * (el trait ya lo crea) y el set_config de search_path.
     */
    private function clean(string $sql): string
    {
        $out = [];
        foreach (explode("\n", $sql) as $line) {
            $t = ltrim($line);
            if ($t !== '' && $t[0] === '\\') continue;                 // \restrict, \unrestrict, \connect
            if (str_starts_with($t, 'CREATE SCHEMA public;')) continue;
            if (str_contains($line, "set_config('search_path'")) continue;
            $out[] = $line;
        }
        return implode("\n", $out);
    }
}
