<?php

namespace App\Services\Admin;

use App\Http\Controllers\Concerns\AcotaAlDocente;
use App\Models\AI\AiRecommendation;
use App\Models\Admin\User;
use App\Models\Students\Student;

/**
 * Reporte de estrategias del tutor virtual (requisito [740] del informe).
 *
 * Agrupa las `ai_recommendations` de un estudiante en las cuatro categorías del
 * enum para que el frontend las componga como plan de estudio descargable.
 *
 * **Aquí nunca entra el historial de chat.** El informe [175] es explícito: el
 * tutor conversa *directamente con el estudiantado* y «el personal docente no
 * verá mensajes individuales». Las `ai_chat_sessions` son del alumno y de nadie
 * más; lo que sí es material de seguimiento pedagógico [741] son estas
 * recomendaciones estructuradas, que es lo que este servicio expone.
 */
class ReportStrategyService
{
    use AcotaAlDocente;

    /** Recomendaciones por categoría. Suficiente para un plan; el resto es ruido. */
    public const DEFAULT_LIMIT = 20;

    public const MAX_LIMIT = 100;

    /**
     * Orden narrativo del documento: primero lo que el estudiante ya domina
     * (motiva), después el diagnóstico, luego qué hacer y con qué material.
     *
     * Pública para que un test pueda comprobar que cubre todo
     * `AiRecommendationType`: si alguien añade una categoría al enum y se olvida
     * de esta lista, las recomendaciones de ese tipo desaparecerían del reporte
     * en silencio.
     *
     * @var array<string,string>
     */
    public const SECTIONS = [
        'strength' => 'Fortalezas',
        'weakness' => 'Aspectos por reforzar',
        'action'   => 'Acciones sugeridas',
        'resource' => 'Recursos de apoyo',
    ];

    /**
     * @param  User  $viewer  Quien descarga: decide el alcance (ver `scopeFor`)
     * @param  array{subject_id?:string,limit?:int}  $filters
     * @return array<string,mixed>
     */
    public function studentStrategies(Student $student, User $viewer, array $filters = []): array
    {
        $student->loadMissing('user:id,full_name');

        $limit = max(1, min((int) ($filters['limit'] ?? self::DEFAULT_LIMIT), self::MAX_LIMIT));

        $sections = [];
        $totals   = [];

        // Una consulta por categoría en vez de una sola con todas: así el tope
        // de `limit` se aplica *por sección* y una categoría muy poblada no
        // desplaza a las demás fuera del documento.
        foreach (self::SECTIONS as $key => $label) {
            $items = $this->baseQuery($student, $viewer, $filters)
                ->where('recommendation_type', $key)
                ->limit($limit)
                ->get();

            $sections[] = [
                'key'   => $key,
                'label' => $label,
                'count' => $items->count(),
                'items' => $items->map(fn (AiRecommendation $r) => [
                    'id'           => $r->id,
                    'text'         => $r->recommendation_text,
                    'subject'      => $r->subject?->name,
                    'exam_id'      => $r->exam_id,
                    'exam_title'   => $r->exam?->title,
                    // Recurso de la lista blanca; el frontend debe abrirlo en
                    // pestaña nueva y con aviso de salida, como pide [175].
                    'resource'     => $r->resource,
                    'generated_at' => $r->generated_at,
                ])->all(),
            ];

            $totals[$key] = $items->count();
        }

        return [
            'student' => [
                'user_id'      => $student->user_id,
                'full_name'    => $student->user?->full_name,
                'student_code' => $student->student_code,
                'grade'        => $student->grade,
                'section'      => $student->section,
            ],
            'totals'     => $totals + ['total' => array_sum($totals)],
            'truncated'  => collect($totals)->contains(fn (int $n) => $n >= $limit),
            'limit'      => $limit,
            'strategies' => $sections,
        ];
    }

    /**
     * Alcance según quién mira.
     *
     * - **Estudiante**: solo lo suyo. Lo garantiza el controlador, que resuelve
     *   el estudiante a partir del usuario autenticado.
     * - **Docente**: solo las materias que imparte, según `teacher_assignments`.
     *   Que el alumno sea suyo lo decide antes el controlador
     *   (`ReportController::findStudent()`, por pertenencia al grupo); aquí se
     *   estrecha por materia, que es la segunda mitad de la asignación: dar
     *   Matemáticas en 7-A no da acceso a las recomendaciones de Lengua del
     *   mismo alumno.
     * - **Admin**: todo lo de su institución; el scope de tenant ya lo acota.
     *
     * La regla anterior —recomendaciones de exámenes que él creó— tenía dos
     * defectos: se ampliaba sola creando un examen dirigido a cualquier grupo, y
     * dejaba fuera las recomendaciones sin `exam_id` (las de `POST /ai/generate`),
     * que ahora sí entran si son de una materia suya.
     */
    private function scopeFor($query, User $viewer)
    {
        if ($viewer->user_type->value === 'teacher') {
            $query->whereIn('subject_id', $this->materiasDelDocente($viewer->id));
        }

        return $query;
    }

    /** @param  array{subject_id?:string}  $filters */
    private function baseQuery(Student $student, User $viewer, array $filters)
    {
        $query = AiRecommendation::query()
            ->where('student_user_id', $student->user_id)
            ->with(['subject:id,name', 'exam:id,title'])
            ->orderByDesc('generated_at');

        if (!empty($filters['subject_id'])) {
            $query->where('subject_id', $filters['subject_id']);
        }

        return $this->scopeFor($query, $viewer);
    }
}
