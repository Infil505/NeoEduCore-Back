<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Los temas del material de estudio de una materia, según los títulos de los recursos que el docente
 * cargó para el aula del estudiante («El ciclo del agua», «Decimales paso a paso»…).
 *
 * Sirven de TEMA cuando el estudiante no escribió uno al pedir «Explícamelo de otra forma» o
 * «Practicar»: en vez de dejar al modelo adivinar «lo que más le cuesta», trabaja sobre lo que el
 * docente ya enseña, que además coincide con los recursos que el estudiante tiene a mano.
 *
 * Misma regla de visibilidad que `StudyResource::scopeVisibleTo` para el estudiante: solo los
 * recursos enviados a un aula donde está matriculado ahora. Los títulos los teclea un docente: entran
 * saneados y el prompt los marca como datos.
 */
class TemasDelMaterial
{
    /** Títulos que se ofrecen como máximo (el prompt del tutor viaja en cada turno). */
    private const MAXIMO = 5;

    public function __construct(private readonly AiInputSanitizer $sanitizer)
    {
    }

    /** @return string[] títulos saneados, los recursos más recientes primero */
    public function de(string $studentUserId, string $institutionId, string $subjectId): array
    {
        return Cache::remember(
            "ai:tutor:material:{$studentUserId}:{$subjectId}",
            (int) config('openai.tutor.context_ttl', 300),
            function () use ($studentUserId, $institutionId, $subjectId) {
                return DB::table('study_resources as r')
                    ->where('r.institution_id', $institutionId)
                    ->where('r.subject_id', $subjectId)
                    ->whereIn('r.id', DB::table('study_resource_groups')
                        ->select('study_resource_id')
                        ->where('institution_id', $institutionId)
                        ->whereIn('group_id', DB::table('group_students')
                            ->select('group_id')
                            ->where('institution_id', $institutionId)
                            ->where('student_user_id', $studentUserId)
                            ->whereNull('left_at')))
                    ->orderByDesc('r.created_at')
                    ->limit(self::MAXIMO * 2)
                    ->pluck('r.title')
                    ->map(fn ($titulo) => $this->sanitizer->paraPrompt($titulo, 80))
                    ->filter()
                    ->unique()
                    ->take(self::MAXIMO)
                    ->values()
                    ->all();
            }
        );
    }
}
