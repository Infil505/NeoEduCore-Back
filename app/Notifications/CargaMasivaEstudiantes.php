<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Estado de una carga masiva de estudiantes que corre en segundo plano.
 *
 * Hace dos papeles con una sola fila de `notifications`:
 *
 *  - es el **aviso** que ve en la campana quien subió el archivo («tu carga está
 *    lista»), igual que los demás avisos de la app;
 *  - es el **registro de estado** que consulta
 *    `GET /api/students/bulk-upload/{import_id}`: nace `queued` al recibir el
 *    archivo y el job la actualiza a `processing` y luego `done` o `failed`.
 *
 * Se reutiliza esta tabla y no una nueva porque ya está aislada por usuario
 * (cada quien solo ve la suya), y porque en producción la API y el worker son
 * contenedores distintos que no comparten disco: el archivo no se guarda, sus
 * filas viajan dentro del job.
 */
class CargaMasivaEstudiantes extends Notification
{
    public const TIPO = 'bulk_import_students';

    public const EN_COLA     = 'queued';
    public const PROCESANDO  = 'processing';
    public const LISTA       = 'done';
    public const FALLIDA     = 'failed';

    public function __construct(private readonly int $totalRows)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return self::TIPO;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'status'     => self::EN_COLA,
            'title'      => 'Carga masiva de estudiantes',
            'message'    => "Procesando {$this->totalRows} filas…",
            'total_rows' => $this->totalRows,
        ];
    }
}
