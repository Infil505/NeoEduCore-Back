<?php

namespace App\Services\Exams;

use App\Models\Exams\Exam;
use App\Services\Admin\ReportMetricsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Análisis de TODAS las respuestas de un examen entre el alumnado al que se
 * asignó: qué preguntas y qué temas salieron peor, qué error se repite, cómo
 * le fue a cada aula y a quién conviene atender.
 *
 * Es lo que el docente necesita para decidir QUÉ volver a explicar a la clase;
 * `examSummary` solo da la nota (histograma y niveles).
 *
 * **Quién entra:** los estudiantes con matrícula vigente en un aula destino del
 * examen (`exam_targets`), igual que `Exam::destinatarios()` pero SIN exigir la
 * cuenta activa: una cuenta que nunca entró tampoco presentó el examen, y eso es
 * justo lo que el docente quiere ver. Un alumno que ya no está en el aula no
 * cuenta.
 *
 * **Qué intento cuenta:** el último entregado de cada alumno. Con varios
 * intentos, sumar todos contaría dos veces al mismo estudiante y mezclaría lo
 * que sabía al principio con lo que aprendió después.
 *
 * Todo sale de un número fijo de consultas agregadas, no de un bucle por alumno,
 * y filtra por institución a mano porque usa `DB::table()`.
 */
class ExamGroupAnalysisService
{
    /** Preguntas «más falladas» que se destacan. */
    private const PREGUNTAS_DIFICILES = 5;

    /** Con menos respuestas que esto, el % de acierto de una pregunta no dice nada. */
    private const MINIMO_RESPUESTAS = 3;

    /** Respuestas abiertas equivocadas que se repiten (≥ 2 alumnos) por pregunta. */
    private const ERRORES_COMUNES = 3;

    public function __construct(private readonly ReportMetricsService $metrics)
    {
    }

    /** @return array<string,mixed> */
    public function analizar(Exam $exam): array
    {
        $centro  = $exam->institution_id;
        $passing = $this->metrics->passingPercentage($centro);

        $asignados = $this->asignados($exam);
        $intentos  = $this->ultimosIntentos($exam, $asignados->pluck('student_id')->all());
        $idsIntento = $intentos->pluck('id')->all();

        $preguntas = DB::table('questions')
            ->where('institution_id', $centro)
            ->where('exam_id', $exam->id)
            ->orderBy('order_index')->orderBy('id')
            ->get(['id', 'question_text', 'question_type', 'points', 'topic', 'topic_normalized', 'indicator', 'difficulty']);

        $porPregunta = $this->respuestasPorPregunta($centro, $idsIntento);
        $opciones    = $this->opciones($centro, $preguntas->pluck('id')->all(), $idsIntento);
        $comunes     = $this->erroresComunes($centro, $idsIntento, $preguntas, $opciones);

        $detalle = $preguntas->values()->map(function ($p, int $i) use ($porPregunta, $opciones, $comunes) {
            $r = $porPregunta->get($p->id);
            $respondidas = (int) ($r->answered ?? 0);
            $correctas   = (int) ($r->correct ?? 0);

            return [
                'id'           => $p->id,
                'number'       => $i + 1,
                'text'         => $p->question_text,
                'type'         => $p->question_type,
                'points'       => (int) $p->points,
                'topic'        => $p->topic,
                'indicator'    => $p->indicator,
                'difficulty'   => $p->difficulty,
                'answered'     => $respondidas,
                'correct'      => $correctas,
                'correct_rate' => $respondidas > 0 ? round($correctas / $respondidas * 100, 1) : null,
                'needs_review' => (int) ($r->pending ?? 0),
                // Solo preguntas con opciones: cuántos eligieron cada una.
                'options'      => $opciones->get($p->id)?->all() ?? [],
                // Solo abiertas: la respuesta equivocada que más se repite.
                'common_wrong_answers' => $comunes->get($p->id, []),
            ];
        })->all();

        $porcentajes = $intentos->map(fn ($a) => $this->porcentaje($a))->values();
        $presentaron = $intentos->count();
        $aprobaron   = $porcentajes->filter(fn (float $p) => $p >= $passing)->count();

        $temas = $this->temas($detalle);

        return [
            'exam' => [
                'id'      => $exam->id,
                'title'   => $exam->title,
                'subject' => $exam->subject?->name,
                'grade'   => $exam->grade,
            ],
            'passing_percentage' => $passing,
            'coverage' => [
                'assigned'         => $asignados->count(),
                'presented'        => $presentaron,
                'not_presented'    => max(0, $asignados->count() - $presentaron),
                'average_pct'      => $presentaron > 0 ? round($porcentajes->avg(), 1) : null,
                'passing_rate_pct' => $presentaron > 0 ? round($aprobaron / $presentaron * 100, 1) : null,
                // Respuestas abiertas sin calificar: las notas son provisionales.
                'needs_review'     => (int) $porPregunta->sum('pending'),
            ],
            'questions' => $detalle,
            'hardest_questions' => $this->masDificiles($detalle),
            'topics' => $temas,
            'groups' => $this->porAula($asignados, $intentos),
            'students_to_attend' => $this->alumnadoAAtender($asignados, $intentos, $passing, $centro, $idsIntento),
            'not_presented' => $asignados
                ->reject(fn ($a) => $intentos->contains('student_user_id', $a->student_id))
                ->map(fn ($a) => ['student_user_id' => $a->student_id, 'name' => $a->full_name, 'group' => $a->group_name])
                ->values()->all(),
        ];
    }

    /* =========================================================
     | Quién y qué intento
     ========================================================= */

    /** @return Collection<int,object> una fila por estudiante (el primer aula destino) */
    private function asignados(Exam $exam): Collection
    {
        return DB::table('group_students as gs')
            ->join('exam_targets as et', function ($join) use ($exam) {
                $join->on('et.group_id', '=', 'gs.group_id')->where('et.exam_id', '=', $exam->id);
            })
            ->join('users as u', 'u.id', '=', 'gs.student_user_id')
            ->join('groups as g', 'g.id', '=', 'gs.group_id')
            ->where('gs.institution_id', $exam->institution_id)
            ->whereNull('gs.left_at')
            ->where('u.user_type', 'student')
            ->orderBy('g.name')->orderBy('u.full_name')
            ->get(['u.id as student_id', 'u.full_name', 'g.id as group_id', 'g.name as group_name'])
            ->unique('student_id')
            ->values();
    }

    /** @return Collection<int,object> */
    private function ultimosIntentos(Exam $exam, array $estudiantes): Collection
    {
        if ($estudiantes === []) {
            return collect();
        }

        return DB::table('exam_attempts')
            ->where('institution_id', $exam->institution_id)
            ->where('exam_id', $exam->id)
            ->whereNotNull('submitted_at')
            ->whereIn('student_user_id', $estudiantes)
            ->orderBy('student_user_id')->orderByDesc('submitted_at')
            ->get(['id', 'student_user_id', 'score', 'max_score', 'grade_status', 'submitted_at'])
            ->unique('student_user_id')   // el primero de cada alumno = el más reciente
            ->values();
    }

    private function porcentaje(object $intento): float
    {
        return (float) $intento->max_score > 0
            ? round((float) $intento->score / (float) $intento->max_score * 100, 1)
            : 0.0;
    }

    /* =========================================================
     | Respuestas
     ========================================================= */

    /** @return Collection<string,object> question_id => {answered, correct, pending} */
    private function respuestasPorPregunta(string $centro, array $intentos): Collection
    {
        if ($intentos === []) {
            return collect();
        }

        return DB::table('student_answers')
            ->where('institution_id', $centro)
            ->whereIn('attempt_id', $intentos)
            ->selectRaw("question_id,
                         COUNT(*) AS answered,
                         COUNT(*) FILTER (WHERE is_correct) AS correct,
                         COUNT(*) FILTER (WHERE review_status = 'needs_review') AS pending")
            ->groupBy('question_id')
            ->get()
            ->keyBy('question_id');
    }

    /**
     * Opciones de cada pregunta con cuántas veces se eligió cada una.
     *
     * @return Collection<string,Collection<int,array<string,mixed>>> question_id => opciones
     */
    private function opciones(string $centro, array $preguntas, array $intentos): Collection
    {
        if ($preguntas === []) {
            return collect();
        }

        $elegidas = $intentos === [] ? collect() : DB::table('student_answer_options as sao')
            ->join('student_answers as sa', 'sa.id', '=', 'sao.student_answer_id')
            ->where('sa.institution_id', $centro)
            ->whereIn('sa.attempt_id', $intentos)
            ->selectRaw('sao.option_id, COUNT(*) AS n')
            ->groupBy('sao.option_id')
            ->pluck('n', 'option_id');

        $filas = DB::table('question_options')
            ->where('institution_id', $centro)
            ->whereIn('question_id', $preguntas)
            ->orderBy('option_index')
            ->get(['id', 'question_id', 'option_text', 'is_correct']);

        return $filas->groupBy('question_id')->map(function (Collection $opts) use ($elegidas) {
            $total = (int) $opts->sum(fn ($o) => (int) ($elegidas[$o->id] ?? 0));

            return $opts->map(fn ($o) => [
                'text'       => $o->option_text,
                'is_correct' => (bool) $o->is_correct,
                'chosen'     => (int) ($elegidas[$o->id] ?? 0),
                'share_pct'  => $total > 0 ? round(((int) ($elegidas[$o->id] ?? 0)) / $total * 100, 1) : 0.0,
            ])->values();
        });
    }

    /**
     * Respuestas abiertas equivocadas que se repiten: dos alumnos que escriben lo
     * mismo mal suelen compartir una confusión concreta.
     *
     * @return Collection<string,array<int,array{answer:string,count:int}>>
     */
    private function erroresComunes(string $centro, array $intentos, Collection $preguntas, Collection $opciones): Collection
    {
        $abiertas = $preguntas->filter(fn ($p) => ! $opciones->has($p->id))->pluck('id')->all();

        if ($intentos === [] || $abiertas === []) {
            return collect();
        }

        return DB::table('student_answers')
            ->where('institution_id', $centro)
            ->whereIn('attempt_id', $intentos)
            ->whereIn('question_id', $abiertas)
            ->where('is_correct', false)
            ->whereNotNull('answer_text')
            ->whereRaw("btrim(answer_text) <> ''")
            ->selectRaw('question_id, lower(btrim(answer_text)) AS respuesta, COUNT(*) AS n')
            ->groupBy('question_id', DB::raw('lower(btrim(answer_text))'))
            ->havingRaw('COUNT(*) >= 2')
            ->orderByDesc('n')
            ->limit(300)
            ->get()
            ->groupBy('question_id')
            ->map(fn (Collection $filas) => $filas->take(self::ERRORES_COMUNES)
                ->map(fn ($f) => ['answer' => mb_substr((string) $f->respuesta, 0, 200), 'count' => (int) $f->n])
                ->values()->all());
    }

    /* =========================================================
     | Agregados
     ========================================================= */

    /**
     * @param  array<int,array<string,mixed>>  $preguntas
     * @return array<int,array<string,mixed>> temas del más flojo al más sólido
     */
    private function temas(array $preguntas): array
    {
        return collect($preguntas)
            ->filter(fn (array $p) => $p['topic'] !== null && trim((string) $p['topic']) !== '' && $p['answered'] > 0)
            ->groupBy(fn (array $p) => mb_strtolower(trim((string) $p['topic'])))
            ->map(function (Collection $grupo) {
                $respondidas = (int) $grupo->sum('answered');
                $correctas   = (int) $grupo->sum('correct');

                return [
                    'topic'        => (string) $grupo->first()['topic'],
                    'questions'    => $grupo->count(),
                    'answered'     => $respondidas,
                    'correct'      => $correctas,
                    'correct_rate' => $respondidas > 0 ? round($correctas / $respondidas * 100, 1) : null,
                ];
            })
            ->sortBy('correct_rate')
            ->values()
            ->all();
    }

    /**
     * @param  array<int,array<string,mixed>>  $preguntas
     * @return array<int,array<string,mixed>>
     */
    private function masDificiles(array $preguntas): array
    {
        return collect($preguntas)
            ->filter(fn (array $p) => $p['answered'] >= self::MINIMO_RESPUESTAS && $p['correct_rate'] !== null)
            ->sortBy('correct_rate')
            ->take(self::PREGUNTAS_DIFICILES)
            ->map(fn (array $p) => [
                'question_id'  => $p['id'],
                'number'       => $p['number'],
                'text'         => $p['text'],
                'topic'        => $p['topic'],
                'correct_rate' => $p['correct_rate'],
                'answered'     => $p['answered'],
            ])
            ->values()
            ->all();
    }

    /** @return array<int,array<string,mixed>> */
    private function porAula(Collection $asignados, Collection $intentos): array
    {
        $porcentajePorAlumno = $intentos->mapWithKeys(fn ($a) => [$a->student_user_id => $this->porcentaje($a)]);

        return $asignados->groupBy('group_id')->map(function (Collection $miembros) use ($porcentajePorAlumno) {
            $notas = $miembros->map(fn ($m) => $porcentajePorAlumno->get($m->student_id))->filter(fn ($p) => $p !== null);

            return [
                'group_id'    => $miembros->first()->group_id,
                'name'        => $miembros->first()->group_name,
                'assigned'    => $miembros->count(),
                'presented'   => $notas->count(),
                'average_pct' => $notas->isNotEmpty() ? round($notas->avg(), 1) : null,
            ];
        })->values()->all();
    }

    /**
     * Quien presentó y quedó por debajo del mínimo, con los temas donde más
     * falló. Los nombres son para el DOCENTE; nunca viajan al modelo de IA.
     *
     * @return array<int,array<string,mixed>>
     */
    private function alumnadoAAtender(Collection $asignados, Collection $intentos, float $passing, string $centro, array $idsIntento): array
    {
        $flojos = $intentos->filter(fn ($a) => $this->porcentaje($a) < $passing);

        if ($flojos->isEmpty()) {
            return [];
        }

        // Temas con fallos de cada alumno, en una sola consulta.
        $fallos = DB::table('student_answers as sa')
            ->join('exam_attempts as ea', 'ea.id', '=', 'sa.attempt_id')
            ->join('questions as q', 'q.id', '=', 'sa.question_id')
            ->where('sa.institution_id', $centro)
            ->whereIn('sa.attempt_id', $flojos->pluck('id')->all())
            ->where('sa.is_correct', false)
            ->whereNotNull('q.topic_normalized')
            ->selectRaw('ea.student_user_id, MIN(q.topic) AS topic, COUNT(*) AS fallos')
            ->groupBy('ea.student_user_id', 'q.topic_normalized')
            ->get()
            ->groupBy('student_user_id');

        $datos = $asignados->keyBy('student_id');

        return $flojos
            ->sortBy(fn ($a) => $this->porcentaje($a))
            ->map(function ($a) use ($datos, $fallos) {
                $alumno = $datos->get($a->student_user_id);

                return [
                    'student_user_id' => $a->student_user_id,
                    'name'            => $alumno?->full_name,
                    'group'           => $alumno?->group_name,
                    'percentage'      => $this->porcentaje($a),
                    // Con respuestas abiertas sin calificar la nota todavía puede cambiar.
                    'provisional'     => ! in_array($a->grade_status, ['graded', 'completed'], true),
                    'weak_topics'     => ($fallos->get($a->student_user_id) ?? collect())
                        ->sortByDesc('fallos')->take(3)->pluck('topic')->values()->all(),
                ];
            })
            ->values()
            ->all();
    }
}
