<?php

namespace App\Jobs;

use App\Enums\ExamStatus;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Notifications\ExamenDisponible;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Avisa al alumnado de que un examen pasó a `active` (O1).
 *
 * Lo encola `ExamController::setStatus()`. Va a la cola porque el destinatario
 * son grupos enteros: una fila de `notifications` por alumno no tiene por qué
 * alargar la respuesta al docente.
 *
 * **El tenant va explícito en cada consulta.** En el worker no hay
 * `SetTenantFromAuth`, así que `TenantScoped` no filtra: si no se acotara a
 * mano por `institution_id`, la subconsulta de grupos no tendría nada que la
 * atara al centro del examen.
 */
class NotificarExamenDisponible implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $examId)
    {
    }

    public function handle(): void
    {
        $exam = Exam::with('subject')->find($this->examId);

        // Entre el encolado y la ejecución pudo cerrarse o borrarse.
        if (!$exam || $exam->status !== ExamStatus::Active) {
            return;
        }

        // Los mismos que verán el examen en `available-exams`: miembros
        // vigentes (sin `left_at`) de algún grupo destino. Solo cuentas
        // activas: una inactiva todavía no ha entrado nunca y una suspendida
        // no debe recibir nada.
        $destinatarios = User::query()
            ->where('institution_id', $exam->institution_id)
            ->where('user_type', UserType::Student->value)
            ->where('status', UserStatus::Active->value)
            ->whereIn('id', DB::table('group_students')
                ->select('student_user_id')
                ->where('institution_id', $exam->institution_id)
                ->whereNull('left_at')
                ->whereIn('group_id', DB::table('exam_targets')
                    ->select('group_id')
                    ->where('institution_id', $exam->institution_id)
                    ->where('exam_id', $exam->id)))
            ->get();

        if ($destinatarios->isNotEmpty()) {
            Notification::sendNow($destinatarios, new ExamenDisponible($exam));
        }
    }
}
