<?php

namespace App\Jobs;

use App\Notifications\CargaMasivaEstudiantes;
use App\Services\Students\StudentBulkImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Procesa en segundo plano una carga masiva de estudiantes.
 *
 * La petición HTTP solo recibe el archivo y responde; esto corre en el worker,
 * donde no hay timeout de navegador ni de proxy. El resultado se escribe en el
 * aviso de quien subió el archivo (`CargaMasivaEstudiantes`).
 *
 * Las filas viajan en el job y no se guarda el archivo: la API y el worker son
 * contenedores distintos y no comparten disco. 5.000 filas son ~1 MB de payload.
 *
 * Un solo intento: la importación es una transacción, así que si falla no deja
 * nada a medias, pero reintentarla sola repetiría un trabajo largo sin que
 * nadie lo pida. Quien subió el archivo ve el fallo y decide.
 *
 * El worker debe permitir el tiempo que declara `$timeout` (`queue:work
 * --timeout=900` o más), o lo matará a los 60 s por defecto.
 */
class ProcesarCargaMasivaEstudiantes implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 900;

    /** Tope de mensajes de error que se guardan; `skipped` lleva la cuenta completa. */
    private const MAX_ERRORES_GUARDADOS = 500;

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    public function __construct(
        public readonly string $userId,
        public readonly string $institutionId,
        public readonly string $avisoId,
        public readonly array $rows,
    ) {
    }

    public function handle(StudentBulkImporter $importer): void
    {
        $this->actualizar([
            'status'  => CargaMasivaEstudiantes::PROCESANDO,
            'message' => 'Procesando ' . count($this->rows) . ' filas…',
        ]);

        // `Group` y `Student` están acotados por institución: en un worker no hay
        // petición ni middleware que ponga el tenant. Se restaura el anterior al
        // salir (con la cola `sync` esto corre dentro de una petición con tenant).
        $previo = app()->bound('tenant_id') ? app('tenant_id') : null;
        app()->instance('tenant_id', $this->institutionId);

        try {
            $resultado = $importer->importar($this->rows, $this->institutionId);
        } finally {
            if ($previo !== null) {
                app()->instance('tenant_id', $previo);
            } else {
                app()->forgetInstance('tenant_id');
            }
        }

        $errores = $resultado['errors'] ?? [];
        $resultado['errors'] = array_slice($errores, 0, self::MAX_ERRORES_GUARDADOS);
        $resultado['errors_truncated'] = count($errores) > self::MAX_ERRORES_GUARDADOS;

        $this->actualizar($resultado + [
            'status'  => CargaMasivaEstudiantes::LISTA,
            'message' => "Carga lista: {$resultado['created']} creados, {$resultado['updated']} actualizados"
                . ($resultado['skipped'] > 0 ? ", {$resultado['skipped']} con errores." : '.'),
        ], sinLeer: true);
    }

    public function failed(Throwable $e): void
    {
        Log::error('Carga masiva de estudiantes fallida', [
            'aviso' => $this->avisoId,
            'error' => $e->getMessage(),
        ]);

        $this->actualizar([
            'status'  => CargaMasivaEstudiantes::FALLIDA,
            'message' => 'La carga no se pudo completar. Revisa el archivo e inténtalo de nuevo.',
        ], sinLeer: true);
    }

    /**
     * Mezcla `$datos` en el aviso; con `$sinLeer` lo vuelve a marcar sin leer
     * para que la campana lo muestre como novedad.
     */
    private function actualizar(array $datos, bool $sinLeer = false): void
    {
        // UNA sentencia (mezcla de jsonb en la base) en vez de leer y guardar:
        // son dos viajes menos por actualización, ~0,8 s con la base remota.
        // Si el aviso ya no existe (el usuario se borró entre medias) no toca nada.
        // El JSON viaja como parámetro (no incrustado): los mensajes llevan «?» y comillas.
        DB::update(
            'update notifications set data = data::jsonb || ?::jsonb' . ($sinLeer ? ', read_at = null' : '') . ' where id = ?',
            [json_encode($datos, JSON_UNESCAPED_UNICODE), $this->avisoId]
        );
    }
}
