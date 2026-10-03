<?php

namespace App\Notifications;

use App\Models\Exams\Exam;
use Illuminate\Notifications\Notification;

/**
 * «Tienes un examen disponible» (O1). Solo canal `database`: la ve el
 * estudiante en la app, no le llega por correo.
 *
 * Lleva lo que el frontend necesita para pintar el aviso sin otra petición
 * —título, materia y ventana—, y nada que identifique al alumno.
 */
class ExamenDisponible extends Notification
{
    public function __construct(private readonly Exam $exam)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** Lo que se guarda en `notifications.type`: estable, no el nombre de la clase. */
    public function databaseType(object $notifiable): string
    {
        return 'exam_available';
    }

    public function toArray(object $notifiable): array
    {
        return [
            'exam_id'          => $this->exam->id,
            'exam_title'       => $this->exam->title,
            'subject_id'       => $this->exam->subject_id,
            'subject_name'     => $this->exam->subject?->name,
            'available_from'   => $this->exam->available_from?->toISOString(),
            'available_until'  => $this->exam->available_until?->toISOString(),
            'duration_minutes' => $this->exam->duration_minutes,
        ];
    }
}
