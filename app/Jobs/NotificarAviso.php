<?php

namespace App\Jobs;

use App\Enums\UserStatus;
use App\Models\Academic\CalendarEvent;
use App\Models\Admin\User;
use App\Notifications\AvisoDelCentro;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Manda a la campana de notificaciones los avisos recién publicados.
 *
 * Va **a la cola** (`ShouldQueue`): quien publica recibe «guardado» al instante
 * y el reparto a todos los destinatarios ocurre en segundo plano, en el worker
 * (`queue:work`). Antes corría con `dispatchAfterResponse()` en el mismo proceso,
 * y con un servidor de un solo hilo (`artisan serve`) bloqueaba todas las demás
 * peticiones mientras duraba el reparto (24 s medidos con la Supabase remota).
 *
 * Las notificaciones se insertan en **una sola consulta por lote** en vez de una
 * por destinatario: con ~0,5 s por viaje a la base, el coste ya no crece con el
 * tamaño del aula.
 *
 * Destinatarios (solo cuentas activas de la institución):
 *  - Aviso del centro (`audience`): estudiantes, docentes o ambos.
 *  - Aviso de sección (`group_id`): los estudiantes matriculados ahora en ella.
 */
class NotificarAviso implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Filas por INSERT: acota el tamaño de la consulta en centros grandes. */
    private const LOTE = 500;

    /** @param array<int,string> $eventIds */
    public function __construct(public readonly array $eventIds)
    {
    }

    public function handle(): void
    {
        $eventos = CalendarEvent::withoutGlobalScopes()->with('creator:id,full_name')->whereIn('id', $this->eventIds)->get();

        foreach ($eventos as $evento) {
            $destinatarios = User::withoutGlobalScopes()
                ->where('institution_id', $evento->institution_id)
                ->where('status', UserStatus::Active->value)
                ->where('id', '!=', $evento->created_by);

            if ($evento->audience) {
                $tipos = match ($evento->audience) {
                    'students' => ['student'],
                    'teachers' => ['teacher'],
                    default    => ['student', 'teacher'],
                };
                $destinatarios->whereIn('user_type', $tipos);
            } elseif ($evento->group_id) {
                $destinatarios->whereIn('id', DB::table('group_students')
                    ->select('student_user_id')
                    ->where('institution_id', $evento->institution_id)
                    ->where('group_id', $evento->group_id)
                    ->whereNull('left_at'));
            } else {
                continue;
            }

            $ids = $destinatarios->pluck('id');
            if ($ids->isEmpty()) {
                continue;
            }

            // El contenido es el mismo para todos: se calcula una vez.
            $aviso = new AvisoDelCentro($evento, (string) $evento->creator?->full_name);
            $modelo = new User();
            $datos = json_encode($aviso->toArray($modelo), JSON_UNESCAPED_UNICODE);
            $tipo = $aviso->databaseType($modelo);
            $ahora = now();

            foreach ($ids->chunk(self::LOTE) as $lote) {
                DB::table('notifications')->insert($lote->map(fn ($id) => [
                    'id'              => (string) Str::uuid(),
                    'type'            => $tipo,
                    'notifiable_type' => $modelo->getMorphClass(),
                    'notifiable_id'   => $id,
                    'data'            => $datos,
                    'read_at'         => null,
                    'created_at'      => $ahora,
                    'updated_at'      => $ahora,
                ])->all());
            }
        }
    }
}
