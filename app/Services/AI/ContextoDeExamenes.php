<?php

namespace App\Services\AI;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Lo que el tutor sabe de los EXÁMENES de un estudiante: cómo le fue en los
 * últimos, en qué temas falló y qué tiene pendiente.
 *
 * Antes el tutor solo conocía el % de dominio por materia («Matemáticas: 55 %»),
 * así que sus consejos eran tan genéricos como esa cifra. Con el examen delante
 * puede decir «en la prueba de fracciones fallaste comparación y equivalentes».
 *
 * **Qué NO entra, a propósito:**
 *  - la respuesta correcta de ninguna pregunta: el tutor explica el concepto, no
 *    resuelve el examen (el estudiante puede volver a intentarlo);
 *  - el nombre del estudiante ni ningún identificador (ver `SIN_DATOS_IDENTIFICATIVOS`);
 *  - preguntas de un examen que el estudiante aún no ha entregado.
 *
 * **Seguridad:** títulos, materias, temas y lo que escribió el estudiante son texto
 * de terceros. Entran saneados (`AiInputSanitizer`) y el system prompt los trata
 * como datos.
 *
 * **Presupuesto de tokens:** el bloque es corto a propósito (pocos exámenes, pocos
 * temas, líneas de una sola frase). Es una decisión de coste: el system prompt
 * viaja en CADA turno del chat.
 *
 * Todo filtra por institución a mano: el chat asíncrono corre en el worker, donde
 * `TenantScoped` no tiene un tenant con el que filtrar.
 */
class ContextoDeExamenes
{
    /** Exámenes recientes que se cuentan. */
    private const EXAMENES = 4;

    /** Temas flojos por examen. */
    private const TEMAS_POR_EXAMEN = 3;

    /** Pendientes que se nombran. */
    private const PENDIENTES = 3;

    /** Preguntas falladas que se nombran por cada examen reciente. */
    private const PREGUNTAS_POR_EXAMEN = 3;

    /** Temas flojos (de todos sus exámenes) que se nombran. */
    private const PUNTOS_A_MEJORAR = 4;

    /** Preguntas falladas que se detallan al hablar de un examen concreto. */
    private const PREGUNTAS_DETALLADAS = 6;

    public function __construct(private readonly AiInputSanitizer $sanitizer)
    {
    }

    /**
     * Resumen de los últimos exámenes y de los pendientes, en líneas para el
     * system prompt. Cadena vacía si no hay nada que decir.
     */
    public function resumen(string $studentUserId, string $institutionId): string
    {
        return Cache::remember(
            $this->clave($studentUserId, "resumen:{$institutionId}"),
            (int) config('openai.tutor.context_ttl', 300),
            fn () => $this->construirResumen($studentUserId, $institutionId)
        );
    }

    /**
     * Detalle de UN examen ya entregado: qué preguntas falló (tema, indicador,
     * enunciado y lo que contestó), sin la respuesta correcta. Vacío si el
     * estudiante no lo ha entregado o no falló nada.
     */
    public function detalleDeExamen(string $studentUserId, string $institutionId, string $examId): string
    {
        return Cache::remember(
            $this->clave($studentUserId, "examen:{$examId}"),
            (int) config('openai.tutor.context_ttl', 300),
            fn () => $this->construirDetalle($studentUserId, $institutionId, $examId)
        );
    }

    /**
     * Descarta lo cacheado de este estudiante (resumen y detalles de examen).
     * Se sube una versión en vez de borrar claves: los detalles varían por examen.
     */
    public static function olvidar(string $studentUserId): void
    {
        $clave = "ai:tutor:examctx:v:{$studentUserId}";
        Cache::add($clave, 1, null);
        Cache::increment($clave);
    }

    private function clave(string $studentUserId, string $sufijo): string
    {
        $version = (int) Cache::get("ai:tutor:examctx:v:{$studentUserId}", 1);

        return "ai:tutor:examctx:{$studentUserId}:{$version}:{$sufijo}";
    }

    /* =========================================================
     | Construcción
     ========================================================= */

    private function construirResumen(string $studentUserId, string $institutionId): string
    {
        $lineas = [];

        $recientes = $this->ultimosIntentos($studentUserId, $institutionId);

        if ($recientes->isNotEmpty()) {
            $ids = $recientes->pluck('attempt_id')->all();
            $fallos = $this->temasFalladosPorIntento($institutionId, $ids);
            $conteos = $this->conteoPorIntento($institutionId, $ids);
            $preguntas = $this->preguntasFalladasPorIntento($institutionId, $ids);

            $lineas[] = 'Exámenes recientes (nota, preguntas falladas y dónde):';
            foreach ($recientes as $e) {
                $temas = ($fallos->get($e->attempt_id) ?? collect())
                    ->sortByDesc('fallos')->take(self::TEMAS_POR_EXAMEN)
                    ->map(fn ($t) => $this->limpio($t->topic, 60))->filter()->implode(', ');

                $c = $conteos->get($e->attempt_id);

                $lineas[] = sprintf(
                    '- %s, «%s»: %s %%%s%s%s',
                    $this->limpio($e->subject, 40) ?: 'Materia',
                    $this->limpio($e->title, 70) ?: 'Examen',
                    $this->porcentaje($e),
                    $e->grade_status === 'graded' || $e->grade_status === 'completed' ? '' : ' (nota provisional)',
                    $c !== null && (int) $c->fallos > 0 ? ". Falló {$c->fallos} de {$c->total} preguntas" : '',
                    $temas !== '' ? ". Fallos en: {$temas}" : ''
                );

                // Qué preguntas concretas falló y qué contestó (nunca la correcta).
                foreach (($preguntas->get($e->attempt_id) ?? collect())->take(self::PREGUNTAS_POR_EXAMEN) as $f) {
                    $respondio = $f->answer_text !== null && trim($f->answer_text) !== ''
                        ? ' → respondió «' . $this->limpio($f->answer_text, 60) . '»'
                        : ' → sin respuesta';

                    $lineas[] = '    · Falló: «' . $this->limpio($f->question_text, 110) . '»'
                        . ($f->topic ? ' (tema ' . $this->limpio($f->topic, 40) . ')' : '')
                        . $respondio;
                }
            }
        }

        $pendientes = $this->examenesPendientes($studentUserId, $institutionId);

        if ($pendientes->isNotEmpty()) {
            $lineas[] = 'Exámenes por presentar: ' . $pendientes->map(function ($p) {
                $hasta = $p->available_until ? ' (hasta el ' . date('d/m', strtotime($p->available_until)) . ')' : '';

                return '«' . ($this->limpio($p->title, 70) ?: 'Examen') . '»' . ($p->subject ? ' de ' . $this->limpio($p->subject, 40) : '') . $hasta;
            })->implode('; ') . '.';
        }

        return implode("\n", $lineas);
    }

    private function construirDetalle(string $studentUserId, string $institutionId, string $examId): string
    {
        $intento = DB::table('exam_attempts as ea')
            ->join('exams as e', 'e.id', '=', 'ea.exam_id')
            ->leftJoin('subjects as s', 's.id', '=', 'e.subject_id')
            ->where('ea.institution_id', $institutionId)
            ->where('ea.student_user_id', $studentUserId)
            ->where('ea.exam_id', $examId)
            ->whereNotNull('ea.submitted_at')
            ->orderByDesc('ea.submitted_at')
            ->first(['ea.id', 'ea.score', 'ea.max_score', 'e.title', 's.name as subject']);

        if ($intento === null) {
            return '';
        }

        $falladas = DB::table('student_answers as sa')
            ->join('questions as q', 'q.id', '=', 'sa.question_id')
            ->where('sa.institution_id', $institutionId)
            ->where('sa.attempt_id', $intento->id)
            ->where('sa.is_correct', false)
            ->orderBy('q.order_index')
            ->limit(self::PREGUNTAS_DETALLADAS)
            ->get(['q.question_text', 'q.topic', 'q.indicator', 'q.difficulty', 'sa.answer_text']);

        if ($falladas->isEmpty()) {
            return '';
        }

        $items = $falladas->map(function ($f) {
            $partes = array_filter([
                $f->topic ? 'tema ' . $this->limpio($f->topic, 60) : null,
                $f->indicator ? 'indicador ' . $this->limpio($f->indicator, 80) : null,
                $f->difficulty ? 'dificultad ' . $f->difficulty : null,
            ]);
            $dato = '- ' . ($partes !== [] ? implode(', ', $partes) . '. ' : '')
                . 'Pregunta: «' . $this->limpio($f->question_text, 140) . '»';
            if ($f->answer_text !== null && trim($f->answer_text) !== '') {
                $dato .= '. El estudiante respondió: «' . $this->limpio($f->answer_text, 80) . '»';
            }

            return $dato;
        })->implode("\n");

        return 'Examen sobre el que se conversa: «' . ($this->limpio($intento->title, 70) ?: 'Examen') . '»'
            . ($intento->subject ? ' de ' . $this->limpio($intento->subject, 40) : '')
            . ", {$this->porcentaje($intento)} %. Preguntas que falló:\n{$items}";
    }

    /* =========================================================
     | Consultas
     ========================================================= */

    /** @return Collection<int,object> el último intento entregado de cada examen, los más recientes primero */
    private function ultimosIntentos(string $studentUserId, string $institutionId): Collection
    {
        return DB::table('exam_attempts as ea')
            ->join('exams as e', 'e.id', '=', 'ea.exam_id')
            ->leftJoin('subjects as s', 's.id', '=', 'e.subject_id')
            ->where('ea.institution_id', $institutionId)
            ->where('ea.student_user_id', $studentUserId)
            ->whereNotNull('ea.submitted_at')
            ->orderByDesc('ea.submitted_at')
            ->limit(self::EXAMENES * 3) // margen: los repetidos de un mismo examen se descartan abajo
            ->get(['ea.id as attempt_id', 'ea.exam_id', 'ea.score', 'ea.max_score', 'ea.grade_status', 'e.title', 's.name as subject'])
            ->unique('exam_id')
            ->take(self::EXAMENES)
            ->values();
    }

    /** @return Collection<string,Collection<int,object>> attempt_id => temas con fallos */
    private function temasFalladosPorIntento(string $institutionId, array $intentos): Collection
    {
        return DB::table('student_answers as sa')
            ->join('questions as q', 'q.id', '=', 'sa.question_id')
            ->where('sa.institution_id', $institutionId)
            ->whereIn('sa.attempt_id', $intentos)
            ->where('sa.is_correct', false)
            // Sin tema etiquetado, el indicador de logro dice igualmente dónde falló.
            ->whereRaw('COALESCE(q.topic_normalized, NULLIF(TRIM(q.indicator), \'\')) IS NOT NULL')
            ->selectRaw('sa.attempt_id, MIN(COALESCE(q.topic, q.indicator)) AS topic, COUNT(*) AS fallos')
            ->groupByRaw('sa.attempt_id, COALESCE(q.topic_normalized, LOWER(TRIM(q.indicator)))')
            ->get()
            ->groupBy('attempt_id');
    }

    /**
     * Las preguntas falladas de cada intento (enunciado y lo que contestó el
     * alumno). La respuesta correcta NO se selecciona.
     *
     * @return Collection<string,Collection<int,object>> attempt_id => preguntas
     */
    private function preguntasFalladasPorIntento(string $institutionId, array $intentos): Collection
    {
        $marcadores = implode(',', array_fill(0, count($intentos), '?'));

        // Las primeras N de CADA intento (ventana), no las N primeras de todos.
        $filas = DB::select(
            "SELECT attempt_id, question_text, topic, answer_text FROM (
                SELECT sa.attempt_id, q.question_text, q.topic, sa.answer_text,
                       ROW_NUMBER() OVER (PARTITION BY sa.attempt_id ORDER BY q.order_index) AS n
                  FROM student_answers sa
                  JOIN questions q ON q.id = sa.question_id
                 WHERE sa.institution_id = ? AND sa.is_correct = false AND sa.attempt_id IN ({$marcadores})
             ) t WHERE n <= ?",
            [$institutionId, ...$intentos, self::PREGUNTAS_POR_EXAMEN]
        );

        return collect($filas)->groupBy('attempt_id');
    }

    /** @return Collection<string,object> attempt_id => {total, fallos} (respuestas ya corregidas) */
    private function conteoPorIntento(string $institutionId, array $intentos): Collection
    {
        return DB::table('student_answers as sa')
            ->where('sa.institution_id', $institutionId)
            ->whereIn('sa.attempt_id', $intentos)
            ->whereNotNull('sa.is_correct')
            ->selectRaw('sa.attempt_id, COUNT(*) AS total, COUNT(*) FILTER (WHERE NOT sa.is_correct) AS fallos')
            ->groupBy('sa.attempt_id')
            ->get()
            ->keyBy('attempt_id');
    }

    /**
     * Los temas más flojos del estudiante en TODOS sus exámenes (con evidencia
     * suficiente, ver `TopicMasteryService::MINIMO_RESPUESTAS`). Es la respuesta
     * a «¿en qué debo mejorar?» cuando no pregunta por un examen concreto.
     */
    public function puntosAMejorar(string $studentUserId): string
    {
        return Cache::remember(
            $this->clave($studentUserId, 'puntos'),
            (int) config('openai.tutor.context_ttl', 300),
            function () use ($studentUserId) {
                $flojos = app(\App\Services\Academic\TopicMasteryService::class)
                    ->porEstudiante($studentUserId, self::PUNTOS_A_MEJORAR)
                    ->filter(fn (array $t) => $t['percentage'] < \App\Services\Academic\TopicMasteryService::UMBRAL_REFUERZO);

                if ($flojos->isEmpty()) {
                    return '';
                }

                return "Puntos a mejorar (temas con más fallos en todos sus exámenes):\n"
                    . $flojos->map(fn (array $t) => sprintf(
                        '- %s: %s %% (%d de %d correctas)',
                        $this->limpio($t['topic'], 60) ?: 'Tema',
                        round($t['percentage']),
                        $t['correctas'],
                        $t['total']
                    ))->implode("\n");
            }
        );
    }

    /**
     * Detalle del examen más reciente que el estudiante entregó de una materia:
     * para cuando el chat se abre desde una materia y no desde un examen.
     */
    public function detalleDeMateria(string $studentUserId, string $institutionId, string $subjectId): string
    {
        return Cache::remember(
            $this->clave($studentUserId, "materia:{$subjectId}"),
            (int) config('openai.tutor.context_ttl', 300),
            function () use ($studentUserId, $institutionId, $subjectId) {
                $examId = DB::table('exam_attempts as ea')
                    ->join('exams as e', 'e.id', '=', 'ea.exam_id')
                    ->where('ea.institution_id', $institutionId)
                    ->where('ea.student_user_id', $studentUserId)
                    ->where('e.subject_id', $subjectId)
                    ->whereNotNull('ea.submitted_at')
                    ->orderByDesc('ea.submitted_at')
                    ->value('ea.exam_id');

                return $examId === null ? '' : $this->construirDetalle($studentUserId, $institutionId, $examId);
            }
        );
    }

    /** @return Collection<int,object> exámenes activos de sus aulas que aún puede presentar */
    private function examenesPendientes(string $studentUserId, string $institutionId): Collection
    {
        return DB::table('exams as e')
            ->leftJoin('subjects as s', 's.id', '=', 'e.subject_id')
            ->where('e.institution_id', $institutionId)
            ->where('e.status', 'active')
            ->where(fn ($q) => $q->whereNull('e.available_from')->orWhere('e.available_from', '<=', now()))
            ->where(fn ($q) => $q->whereNull('e.available_until')->orWhere('e.available_until', '>=', now()))
            ->whereIn('e.id', DB::table('exam_targets')
                ->select('exam_id')
                ->where('institution_id', $institutionId)
                ->whereIn('group_id', DB::table('group_students')
                    ->select('group_id')
                    ->where('institution_id', $institutionId)
                    ->where('student_user_id', $studentUserId)
                    ->whereNull('left_at')))
            ->whereRaw('(SELECT COUNT(*) FROM exam_attempts a
                          WHERE a.exam_id = e.id AND a.student_user_id = ? AND a.submitted_at IS NOT NULL) < e.max_attempts', [$studentUserId])
            ->orderByRaw('e.available_until ASC NULLS LAST')
            ->limit(self::PENDIENTES)
            ->get(['e.title', 'e.available_until', 's.name as subject']);
    }

    /* =========================================================
     | Texto
     ========================================================= */

    private function porcentaje(object $intento): string
    {
        $pct = (float) $intento->max_score > 0 ? (float) $intento->score / (float) $intento->max_score * 100 : 0.0;

        return (string) round($pct);
    }

    private function limpio(?string $texto, int $max): string
    {
        return $this->sanitizer->paraPrompt($texto, $max);
    }
}
