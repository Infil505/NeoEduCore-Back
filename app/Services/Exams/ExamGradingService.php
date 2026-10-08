<?php

namespace App\Services\Exams;

use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Exams\Question;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExamGradingService
{
    /**
     * Filas por INSERT. El límite de PostgreSQL son 65535 parámetros por
     * sentencia; con 12 columnas, 500 filas son 6000 parámetros. Un examen
     * normal cabe de sobra en un solo lote.
     */

    /**
     * Corrige un intento y persiste las respuestas.
     *
     * El coste en queries es CONSTANTE respecto al número de preguntas: se
     * acumulan las filas en memoria y se insertan en lote al final. Antes el
     * bucle hacía por pregunta un `create()` + un `syncWithPivotValues()`
     * (INSERT + SELECT + INSERT), es decir `29 + 3·N` queries por entrega.
     *
     * Importa porque las entregas llegan en ráfaga al cerrarse la ventana del
     * examen y cada query es un round-trip de red contra la BD gestionada.
     * Ver docs/ANALISIS_CONCURRENCIA.md y tests/Feature/Perf/QueryBudgetTest.php.
     */
    public function gradeAttempt(
        Exam $exam,
        ExamAttempt $attempt,
        array $answersPayload,
        ?Collection $questions = null
    ): ExamAttempt {

        // El submit ya las cargó (con opciones) para validar los tipos: se
        // reutilizan en vez de pedirlas otra vez a la BD (O3, 2 queries menos).
        $questions ??= Question::where('exam_id', $exam->id)
            ->with('options')
            ->get()
            ->keyBy('id');

        $totalScore = 0;
        $maxScore = $questions->sum('points');
        $pendientes = 0;

        $byQuestion = collect($answersPayload)->keyBy('question_id');

        $ahora = now();
        $filasRespuestas = [];
        $filasOpciones = [];

        foreach ($questions as $question) {
            $payload = $byQuestion->get($question->id);

            $answerText = $payload['answer_text'] ?? null;
            $selectedIds = $payload['selected_option_ids'] ?? [];

            $isCorrect = false;
            $points = 0;
            $correctSnapshot = null;
            $reviewStatus = 'auto_graded';

            if ($question->question_type->value === 'short_answer') {
                // Coincide con la esperada (sin importar mayúsculas, tildes ni
                // signos) => se califica sola. Si no, la revisa el profesor.
                $isCorrect = self::normalizarTexto($answerText) !== ''
                    && self::normalizarTexto($answerText) === self::normalizarTexto($question->correct_answer_text);
                $points = $isCorrect ? $question->points : 0;
                // En blanco no hay nada que revisar: 0 puntos directamente.
                $reviewStatus = $isCorrect || self::normalizarTexto($answerText) === '' ? 'auto_graded' : 'needs_review';
                $correctSnapshot = ['correct_answer_text' => $question->correct_answer_text];
            } elseif ($question->question_type->value === 'essay') {
                // Essay siempre requiere revisión manual
                $points = 0;
                $reviewStatus = 'needs_review';
            } else {
                $correctOption = $question->options->firstWhere('is_correct', true);
                if ($correctOption) {
                    $picked = (string) ($selectedIds[0] ?? '');
                    $isCorrect = $picked !== '' && $picked === (string) $correctOption->id;
                    $points = $isCorrect ? $question->points : 0;
                    $correctSnapshot = ['option_text' => $correctOption->option_text];
                }
            }

            $totalScore += $points;
            if ($reviewStatus === 'needs_review') {
                $pendientes++;
            }

            // El id se genera aquí porque el INSERT masivo no pasa por HasUuids
            // (y `student_answers.id` no tiene DEFAULT en la BD). orderedUuid()
            // es exactamente lo que usa HasUuids::newUniqueId().
            $answerId = (string) Str::orderedUuid();

            $filasRespuestas[] = [
                'id'              => $answerId,
                // Sin pasar por Eloquent no corre el hook de TenantScoped que
                // autoasigna institution_id, y la columna es NOT NULL.
                'institution_id'  => $attempt->institution_id,
                'attempt_id'      => $attempt->id,
                'question_id'     => $question->id,
                'answer_text'     => $answerText,
                'is_correct'      => $isCorrect,
                'points_awarded'  => $points,
                // El cast 'array' tampoco se aplica: se serializa a mano.
                'correct_answer_snapshot' => $correctSnapshot !== null
                    ? json_encode($correctSnapshot, JSON_UNESCAPED_UNICODE)
                    : null,
                'answered_at'     => $ahora,
                'review_status'   => $reviewStatus,
                'created_at'      => $ahora,
                'updated_at'      => $ahora,
            ];

            if (!empty($selectedIds)) {
                // Las opciones ya están cargadas en $question->options (eager load).
                // Filtrar en memoria evita N queries de lectura en el bucle.
                $validIds = $question->options
                    ->whereIn('id', array_map('strval', $selectedIds))
                    ->pluck('id');

                foreach ($validIds as $optionId) {
                    $filasOpciones[] = [
                        'institution_id'    => $attempt->institution_id,
                        'student_answer_id' => $answerId,
                        'option_id'         => $optionId,
                    ];
                }
            }
        }

        // Las respuestas van primero: student_answer_options tiene FK contra ellas.
        foreach (array_chunk($filasRespuestas, config('bulk.insert_batch_size')) as $lote) {
            DB::table('student_answers')->insert($lote);
        }

        foreach (array_chunk($filasOpciones, config('bulk.insert_batch_size')) as $lote) {
            DB::table('student_answer_options')->insert($lote);
        }

        // Sin `fresh()` después: `update()` ya deja en memoria lo que acaba de
        // escribir, y releer la fila era una query más en la operación de pico
        // (O3). El segundo se trunca aquí porque la columna es timestamp(0): así
        // la respuesta muestra lo mismo que quedó guardado.
        $attempt->update([
            'score' => round($totalScore, 2),
            'max_score' => round($maxScore, 2),
            'submitted_at' => now()->startOfSecond(),
            // Con respuestas por revisar la nota aún puede subir: queda pendiente.
            'grade_status' => $pendientes > 0 ? 'pending' : 'completed',
        ]);

        return $attempt;
    }

    /**
     * Texto comparable de una respuesta corta: minúsculas, sin tildes y sin
     * signos, con los espacios colapsados. «Pérdida de tierras.» y
     * «perdida de tierras» cuentan como la misma respuesta.
     */
    public static function normalizarTexto(?string $texto): string
    {
        $texto = mb_strtolower(trim((string) $texto));
        $sinTildes = \Normalizer::normalize($texto, \Normalizer::FORM_D);
        $texto = preg_replace('/\p{Mn}+/u', '', $sinTildes === false ? $texto : $sinTildes);
        $texto = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $texto);

        return trim($texto);
    }
}
