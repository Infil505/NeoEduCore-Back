<?php

namespace App\Jobs;

use App\Enums\UserStatus;
use App\Models\Academic\CalendarEvent;
use App\Models\Admin\User;
use App\Notifications\AvisoDelCentro;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Manda a la campana de notificaciones los avisos recién publicados.
 *
 * Se despacha con `dispatchAfterResponse()`: corre en el mismo proceso, justo
 * después de responder, así que quien publica no espera y no depende de que
 * haya un worker de cola en marcha.
 *
 * Destinatarios (solo cuentas activas de la institución):
 *  - Aviso del centro (`audience`): estudiantes, docentes o ambos.
 *  - Aviso de sección (`group_id`): los estudiantes matriculados ahora en ella.
 */
class NotificarAviso
{
    use Dispatchable;

    /** @param array<int,string> $eventIds */
    public function __construct(public readonly array $eventIds)
    {
    }

    /**
     * `dispatchAfterResponse` cuelga la misma instancia de los callbacks de fin
     * de petición. En producción hay una petición por proceso y corre una vez;
     * donde la aplicación se reutiliza entre peticiones (los tests, Octane) se
     * volvería a ejecutar en cada una y duplicaría las notificaciones.
     */
    private bool $enviado = false;

    public function handle(): void
    {
        if ($this->enviado) {
            return;
        }
        $this->enviado = true;

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

            $usuarios = $destinatarios->get();

            if ($usuarios->isNotEmpty()) {
                Notification::sendNow($usuarios, new AvisoDelCentro($evento, (string) $evento->creator?->full_name));
            }
        }
    }
}
