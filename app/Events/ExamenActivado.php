<?php

namespace App\Events;

use App\Models\Exams\Exam;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * El docente activó un examen: quien puede presentarlo lo recibe al instante
 * por WebSocket, sin recargar.
 *
 * ## A quién se emite
 *
 * Un canal privado **por alumno** (`private-alumno.{id}`), no por aula, para
 * que el aviso siga EXACTAMENTE las reglas de visibilidad del examen:
 *
 * - Los destinatarios salen de `Exam::destinatarios()`, la misma regla que la
 *   notificación en la app: matrícula vigente en un grupo destino, mismo
 *   centro y cuenta activa. Un alumno que dejó el grupo o está suspendido no
 *   recibe nada aunque tuviera la página abierta.
 * - La ventana de disponibilidad NO filtra: el evento solo informa. Quien decide
 *   si el examen se puede abrir ya es `available_from`, que la API aplica con
 *   `Exam::scopeVisibleTo()`; el payload lleva las fechas para que el frontend
 *   muestre «disponible desde…» en vez de un botón activo.
 *
 * Con un canal por aula, quien estaba suscrito y luego salió del grupo o fue
 * suspendido seguiría recibiendo avisos hasta reconectar.
 *
 * ## Cómo
 *
 * Va **por la cola** (`ShouldBroadcast`, no `...Now`): si Reverb está caído, la
 * petición del docente no falla ni se retrasa; el aviso en vivo se pierde y el
 * alumno lo ve igualmente al recargar o en su notificación.
 *
 * Solo viaja lo imprescindible para pintar el aviso; el detalle se pide por la
 * API, que aplica `Exam::scopeVisibleTo()`.
 */
class ExamenActivado implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    /** @param array<int,string> $studentIds */
    public function __construct(
        public string $examId,
        public string $titulo,
        public ?string $disponibleDesde,
        public ?string $disponibleHasta,
        public array $studentIds,
    ) {}

    public static function de(Exam $exam): self
    {
        $destinatarios = $exam->destinatarios()->pluck('id')->map(fn ($id) => (string) $id)->all();

        return new self(
            $exam->id,
            $exam->title,
            $exam->available_from?->toISOString(),
            $exam->available_until?->toISOString(),
            $destinatarios,
        );
    }

    public function broadcastOn(): array
    {
        return array_map(fn (string $id) => new PrivateChannel("alumno.{$id}"), $this->studentIds);
    }

    public function broadcastAs(): string
    {
        return 'examen.activado';
    }

    public function broadcastWith(): array
    {
        return [
            'exam_id'         => $this->examId,
            'title'           => $this->titulo,
            'available_from'  => $this->disponibleDesde,
            'available_until' => $this->disponibleHasta,
        ];
    }
}
