<?php

namespace App\Jobs;

use App\Services\AI\AiTutorService;
use App\Services\AI\CuotaDelTutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turno del chat del tutor en modo asíncrono (O7).
 *
 * Lo encola `AiTutorService::chatAsincrono()`. La llamada a OpenAI ocupa al
 * worker de la cola en vez de a uno HTTP; la respuesta se anexa a la sesión y
 * el frontend la recoge con `GET /ai/tutor/sessions/{id}`.
 *
 * **Un solo intento.** `callOpenAi()` ya convierte cualquier fallo del modelo
 * en el mensaje de reserva, así que lo que pudiera llegar aquí como excepción
 * es otra cosa (la base caída). Reintentar entonces podría anexar el turno dos
 * veces; `failed()` le da al alumno el mensaje de reserva y lo desbloquea.
 *
 * **El tenant se rebinda a mano**, como en `GenerateAiRecommendations`: en el
 * worker no hay `SetTenantFromAuth`. A diferencia de aquel, se restaura el valor
 * anterior en vez de olvidarlo: con la cola `sync` el job corre dentro de la
 * propia petición, y olvidarlo dejaría el resto de esa petición sin tenant.
 */
class ResponderTutor implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Margen sobre `OPENAI_REQUEST_TIMEOUT` (15 s en producción). */
    public int $timeout = 60;

    public function __construct(
        public readonly string $sessionId,
        public readonly string $studentUserId,
        public readonly string $institutionId,
        public readonly string $message,
        public readonly string $mode,
        public readonly ?string $topic
    ) {
    }

    public function handle(AiTutorService $tutor): void
    {
        $anterior = app()->bound('tenant_id') ? app('tenant_id') : null;
        app()->instance('tenant_id', $this->institutionId);

        try {
            $tutor->responderPendiente($this->sessionId, $this->studentUserId, $this->message, $this->mode, $this->topic);
        } finally {
            $anterior !== null
                ? app()->instance('tenant_id', $anterior)
                : app()->forgetInstance('tenant_id');
        }
    }

    public function failed(?Throwable $e): void
    {
        app(AiTutorService::class)->liberarPendiente($this->sessionId, $this->message, $this->mode);

        // El alumno recibió el mensaje de reserva: la consulta no le cuenta para el tope del día.
        app(CuotaDelTutor::class)->devolver($this->studentUserId);

        Log::warning('Turno asíncrono del tutor fallido en la sesión ' . $this->sessionId, [
            'error' => $e?->getMessage(),
        ]);
    }
}
