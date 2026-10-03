<?php

namespace App\Services\AI;

use App\Enums\AiIncidentStage;
use App\Enums\AiIncidentType;
use App\Models\AI\AiTutorIncident;
use App\Models\Admin\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Deja constancia de una incidencia del tutor IA (decisión D5).
 *
 * Sigue escribiendo en el log, que es donde mira quien está depurando en
 * caliente, y además inserta la fila que hace medible el criterio de [173].
 *
 * **Registrar una incidencia no puede provocar otra.** Si la inserción falla
 * —la tabla no existe todavía en un entorno a medio migrar, la base está
 * saturada—, se traga el error y se anota en el log. El alumno ya tiene un
 * problema (su respuesta fue bloqueada); convertirlo en un 500 sería castigarlo
 * dos veces por un fallo de instrumentación.
 */
class AiIncidentLogger
{
    public function registrar(
        AiIncidentType $tipo,
        AiIncidentStage $etapa,
        string $studentUserId,
        ?string $sessionId = null,
    ): void {
        Log::warning('Tutor IA: incidencia registrada', [
            'tipo'    => $tipo->value,
            'etapa'   => $etapa->value,
            'sesion'  => $sessionId,
            // El id del alumno va al log, no su nombre ni el texto bloqueado.
            'alumno'  => $studentUserId,
        ]);

        try {
            $institutionId = $this->institucionDe($studentUserId);

            if ($institutionId === null) {
                return; // sin centro al que imputarla no hay métrica que salga
            }

            AiTutorIncident::create([
                'institution_id'  => $institutionId,
                'student_user_id' => $studentUserId,
                'session_id'      => $sessionId,
                'type'            => $tipo->value,
                'stage'           => $etapa->value,
                'occurred_at'     => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('Tutor IA: no se pudo registrar la incidencia', ['error' => $e->getMessage()]);
        }
    }

    /**
     * La institución se lee del alumno y no de `app('tenant_id')`: así la fila
     * sale bien tanto desde una petición HTTP como desde la cola o una consola,
     * donde no hay tenant enlazado.
     */
    private function institucionDe(string $studentUserId): ?string
    {
        return User::query()->whereKey($studentUserId)->value('institution_id');
    }
}
