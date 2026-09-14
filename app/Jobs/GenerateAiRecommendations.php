<?php

namespace App\Jobs;

use App\Enums\AiGenerationSource;
use App\Enums\AiRecommendationsStatus;
use App\Exceptions\AiGenerationFailed;
use App\Models\AI\AiRecommendation;
use App\Models\Exams\ExamAttempt;
use App\Services\AI\AiRecommendationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Análisis de IA de un intento, fuera del ciclo de petición (decisión D1).
 *
 * Lo encola `ExamAttemptController::recommendations()` la primera vez que el
 * alumno abre los resultados de un intento. Al terminar, las recomendaciones de
 * plantilla que escribió la entrega quedan **sustituidas** por las que redactó
 * el modelo leyendo las respuestas falladas.
 *
 * Por qué sustituir y no acumular: el alumno tiene un solo sitio donde mirar. Si
 * se guardaran los dos lotes vería ocho tarjetas, la mitad genéricas, sin saber
 * cuáles son las buenas. El `attempt_id` que añadió la migración del 13/09 es lo
 * que permite hacerlo sin adivinar por fechas.
 *
 * **El tenant se rebinda a mano.** Fuera de una petición HTTP no hay
 * `SetTenantFromAuth`, así que `TenantScoped` ni filtra las consultas ni rellena
 * `institution_id` al crear. Sin este `instance()` el job fallaría al insertar
 * (columna NOT NULL) y, peor, `recursoSugerido()` podría proponerle al alumno un
 * material de otra institución.
 */
class GenerateAiRecommendations implements ShouldQueue
{
    use Queueable;

    /** Tres intentos: los fallos de OpenAI que se ven son timeouts y 429. */
    public int $tries = 3;

    /** Espera creciente entre reintentos, en segundos. */
    public array $backoff = [10, 30];

    /**
     * Margen sobre `OPENAI_REQUEST_TIMEOUT` (15 s en producción). Si el job
     * muriera antes que la llamada, el intento quedaría «preparing» para siempre.
     */
    public int $timeout = 60;

    public function __construct(public readonly string $attemptId)
    {
    }

    public function handle(AiRecommendationService $service): void
    {
        $attempt = ExamAttempt::with('exam')->find($this->attemptId);

        if (!$attempt) {
            return; // el intento se borró mientras esperaba en la cola
        }

        app()->instance('tenant_id', $attempt->institution_id);

        try {
            DB::transaction(function () use ($service, $attempt) {
                // `false`: si el modelo no responde, que lance. Las plantillas de
                // este intento ya existen desde la entrega; volver a escribirlas
                // duplicaría el lote en vez de arreglar nada.
                $service->regenerateForAttempt($attempt, '', false);

                AiRecommendation::query()
                    ->where('attempt_id', $attempt->id)
                    ->where('generated_by', AiGenerationSource::Heuristic->value)
                    ->delete();

                $attempt->forceFill([
                    'ai_recommendations_status' => AiRecommendationsStatus::Ready,
                ])->save();
            });
        } finally {
            // El worker es de vida larga: dejar el tenant colgado contaminaría el
            // siguiente job de la cola.
            app()->forgetInstance('tenant_id');
        }
    }

    /**
     * Solo se llama cuando se agotaron los reintentos. Marcar «failed» antes
     * sería mentirle al frontend, que dejaría de esperar un análisis que todavía
     * puede llegar.
     */
    public function failed(?Throwable $e): void
    {
        $attempt = ExamAttempt::find($this->attemptId);

        if (!$attempt) {
            return;
        }

        $attempt->forceFill([
            'ai_recommendations_status' => AiRecommendationsStatus::Failed,
        ])->save();

        Log::warning('Análisis de IA agotado para el intento ' . $this->attemptId, [
            'error' => $e?->getMessage(),
        ]);
    }
}
