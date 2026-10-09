<?php

namespace App\Jobs;

use App\Enums\ExamStatus;
use App\Models\Exams\Exam;
use App\Notifications\ExamenDisponible;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;

/**
 * Avisa al alumnado de que un examen pasó a `active` (O1).
 *
 * Lo encola `ExamController::setStatus()`. Va a la cola porque el destinatario
 * son grupos enteros: una fila de `notifications` por alumno no tiene por qué
 * alargar la respuesta al docente.
 *
 * Quién recibe el aviso lo decide `Exam::destinatarios()`, con el tenant
 * explícito (en el worker no hay `SetTenantFromAuth`).
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

        // Los mismos que verán el examen en `available-exams`: ver `Exam::destinatarios()`.
        $destinatarios = $exam->destinatarios()->get();

        if ($destinatarios->isNotEmpty()) {
            Notification::sendNow($destinatarios, new ExamenDisponible($exam));
        }
    }
}
