<?php

namespace App\Notifications;

use App\Models\Academic\CalendarEvent;
use Illuminate\Notifications\Notification;

/**
 * Aviso del calendario que llega a la campana de notificaciones de la app.
 * Lo genera un aviso del administrador (a estudiantes, docentes o todos) o un
 * aviso de sección.
 */
class AvisoDelCentro extends Notification
{
    public function __construct(private readonly CalendarEvent $event, private readonly string $autor)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return 'calendar_event';
    }

    public function toArray(object $notifiable): array
    {
        return [
            'event_id'    => $this->event->id,
            'title'       => $this->event->title,
            'description' => $this->event->description,
            'event_type'  => $this->event->event_type?->value ?? $this->event->event_type,
            'start_at'    => $this->event->start_at?->toISOString(),
            'end_at'      => $this->event->end_at?->toISOString(),
            'author'      => $this->autor,
        ];
    }
}
