<?php

namespace App\Services\Admin;

use App\Http\Controllers\Concerns\AcotaAlDocente;
use App\Models\AI\AiRecommendation;
use App\Models\Admin\User;
use App\Models\Students\Student;
use App\Support\CatalogoMaterias;

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
     * Los mismos cuatro apartados cuando son consejo PARA EL DOCENTE: se rotulan para quien los lee (no
     * «Acciones sugeridas», que al estudiante le habla de lo que debe hacer él).
     *
     * @var array<string,string>
     */
    public const SECTIONS_DOCENTE = [
        'strength' => 'Fortalezas del estudiante',
        'weakness' => 'Aspectos por reforzar',
        'action'   => 'Qué puedes hacer tú',
        'resource' => 'Recursos de apoyo',
    ];

    /**
     * @param  User  $viewer  Quien descarga: decide el alcance (ver `scopeFor`)
     * @param  array{subject_id?:string,exam_id?:string,audience?:string,limit?:int}  $filters
     * @return array<string,mixed>
     */
    public function studentStrategies(Student $student, User $viewer, array $filters = []): array
    {
        $student->loadMissing('user:id,full_name');

        $limit = max(1, min((int) ($filters['limit'] ?? self::DEFAULT_LIMIT), self::MAX_LIMIT));

        // A quién va dirigido lo que se pide. Para `teacher` se trae también lo del estudiante, en la MISMA consulta:
        // si el docente aún no generó su consejo, el plan muestra lo que recibió el estudiante (y lo dice).
        $pedida = ($filters['audience'] ?? AiRecommendation::PARA_ESTUDIANTE) === AiRecommendation::PARA_DOCENTE
            ? AiRecommendation::PARA_DOCENTE
            : AiRecommendation::PARA_ESTUDIANTE;

        $sections = [];
        $totals   = [];

        // UNA consulta para las cuatro categorías (antes una por categoría, con la
        // base remota ~0,5 s cada una). El tope de `limit` sigue siendo *por
        // sección*: `ROW_NUMBER() ... PARTITION BY` numera cada categoría por
        // separado y se queda con las `limit` más recientes de cada una, así que
        // una categoría muy poblada no desplaza a las demás fuera del documento.
        $numeradas = $this->baseQuery($student, $viewer, $filters)
            ->reorder()
            ->select('ai_recommendations.*')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY audience, recommendation_type ORDER BY generated_at DESC) AS rn_seccion')
            // El título del examen en la misma consulta (subselect), no una relación aparte:
            // cada viaje a la base remota cuesta ~0,4 s.
            ->selectRaw('(SELECT e.title FROM exams e WHERE e.id = ai_recommendations.exam_id AND e.institution_id = ai_recommendations.institution_id) AS exam_title_j');

        // La materia sale del catálogo en caché del centro (no de otra consulta).
        $materias = CatalogoMaterias::delCentro($student->institution_id);

        // La subconsulta ya lleva el alcance de institución; el exterior solo
        // recorta y ordena, y no lo repite (la columna vive dentro de la subconsulta).
        $todas = AiRecommendation::query()
            ->withoutGlobalScopes()
            ->fromSub($numeradas, 'ai_recommendations')
            ->where('rn_seccion', '<=', $limit)
            ->orderByDesc('generated_at')
            ->get();

        // Consejo del docente si ya hay alguno; si no, lo que recibió el estudiante.
        $efectiva = $pedida === AiRecommendation::PARA_DOCENTE && $todas->contains(fn (AiRecommendation $r) => $r->audience === AiRecommendation::PARA_DOCENTE)
            ? AiRecommendation::PARA_DOCENTE
            : AiRecommendation::PARA_ESTUDIANTE;

        $todas = $todas
            ->filter(fn (AiRecommendation $r) => $r->audience === $efectiva)
            ->groupBy(fn (AiRecommendation $r) => $r->recommendation_type instanceof \BackedEnum
                ? $r->recommendation_type->value
                : (string) $r->recommendation_type);

        foreach (($efectiva === AiRecommendation::PARA_DOCENTE ? self::SECTIONS_DOCENTE : self::SECTIONS) as $key => $label) {
            $items = $todas->get($key, collect());

            $sections[] = [
                'key'   => $key,
                'label' => $label,
                'count' => $items->count(),
                'items' => $items->map(fn (AiRecommendation $r) => [
                    'id'           => $r->id,
                    'text'         => $r->recommendation_text,
                    'subject'      => $r->subject_id ? $materias->get($r->subject_id)?->name : null,
                    'exam_id'      => $r->exam_id,
                    'exam_title'   => $r->getAttribute('exam_title_j'),
                    // Recurso de la lista blanca; el frontend debe abrirlo en
                    // pestaña nueva y con aviso de salida, como pide [175].
                    'resource'     => $r->resource,
                    'generated_at' => $r->generated_at,
                ])->values()->all(),
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
            // `audience` es lo que se está mostrando; `requested_audience`, lo que se pidió. Si difieren, el docente
            // pidió su consejo y todavía no existe: lo que se ve es lo que recibió el estudiante.
            'audience'           => $efectiva,
            'requested_audience' => $pedida,
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

    /** @param  array{subject_id?:string,exam_id?:string}  $filters */
    private function baseQuery(Student $student, User $viewer, array $filters)
    {
        $query = AiRecommendation::query()
            ->where('student_user_id', $student->user_id)
            ->with(['subject:id,name', 'exam:id,title'])
            ->orderByDesc('generated_at');

        if (!empty($filters['subject_id'])) {
            $query->where('subject_id', $filters['subject_id']);
        }

        // Lo dirigido al estudiante; con `teacher` también el consejo del docente (se elige luego, ver arriba).
        if (($filters['audience'] ?? AiRecommendation::PARA_ESTUDIANTE) !== AiRecommendation::PARA_DOCENTE) {
            $query->where('audience', AiRecommendation::PARA_ESTUDIANTE);
        }

        // El plan de UN examen: lo que el docente ve en analíticas al elegir el examen.
        if (!empty($filters['exam_id'])) {
            $query->where('exam_id', $filters['exam_id']);
        }

        return $this->scopeFor($query, $viewer);
    }
}
