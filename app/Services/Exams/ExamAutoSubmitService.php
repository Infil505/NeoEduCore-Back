<?php

namespace App\Services\Exams;

use App\Models\Exams\ExamAttempt;
use App\Models\Exams\Question;
use App\Models\Students\Student;
use App\Services\AI\AiRecommendationService;
use App\Services\Students\StudentProgressService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entrega por el servidor los intentos cuyo tiempo se agotó y que el alumno no llegó a enviar
 * (pestaña cerrada, conexión perdida, equipo apagado).
 *
 * Entrega lo último que el frontend guardó en `draft_answers` (autoguardado). Pasa por el mismo
 * camino que la entrega del alumno —`ExamGradingService::calificar`, progreso y recomendaciones—,
 * así que la nota, las respuestas por revisar y el dominio por materia quedan como si lo hubiera
 * enviado él. Lo no guardado cuenta como en blanco.
 *
 * Solo actúa pasado el plazo del intento MÁS `exam.auto_submit_delay_seconds`: ese margen es para
 * que el frontend envíe por sí mismo al llegar el cronómetro a 0 y el servidor solo recoja lo que
 * quedó sin enviar.
 *
 * Corre en la consola: no hay `SetTenantFromAuth`, así que el tenant se fija a mano por intento.
 */
class ExamAutoSubmitService
{
    /** Tope por pasada, para que una acumulación no alargue el ciclo del scheduler. */
    private const POR_PASADA = 200;

    public function __construct(
        private readonly ExamAttemptRulesService $reglas,
        private readonly ExamGradingService $calificacion,
        private readonly StudentProgressService $progreso,
        private readonly AiRecommendationService $recomendaciones,
    ) {}

    /** @return array{entregados:int, fallidos:int} */
    public function entregarVencidos(): array
    {
        $espera = max(0, (int) config('academic.exam.auto_submit_delay_seconds'));
        $corte  = now()->subSeconds((int) config('academic.exam.grace_seconds') + $espera);

        // Filtro grueso en SQL: el plazo real depende de la adecuación del alumno y de sus pausas, y
        // nunca es menor que inicio + duración. Lo exacto se comprueba después, por intento.
        $candidatos = ExamAttempt::withoutGlobalScope('tenant')
            ->join('exams', 'exams.id', '=', 'exam_attempts.exam_id')
            ->whereNull('exam_attempts.submitted_at')
            ->whereNotNull('exam_attempts.started_at')
            ->whereNotNull('exams.duration_minutes')
            ->whereRaw("exam_attempts.started_at + (exams.duration_minutes * interval '1 minute') < ?", [$corte->toDateTimeString()])
            ->orderBy('exam_attempts.started_at')
            ->limit(self::POR_PASADA)
            ->get(['exam_attempts.id', 'exam_attempts.institution_id']);

        $entregados = 0;
        $fallidos = 0;

        foreach ($candidatos as $fila) {
            try {
                $this->entregarUno($fila->id, $fila->institution_id, $espera) ? $entregados++ : null;
            } catch (\Throwable $e) {
                // Uno que falle no bloquea a los demás; se reintenta en la pasada siguiente.
                $fallidos++;
                Log::warning('Cierre automático de intento fallido', ['attempt' => $fila->id, 'error' => $e->getMessage()]);
            }
        }

        return ['entregados' => $entregados, 'fallidos' => $fallidos];
    }

    private function entregarUno(string $attemptId, string $institutionId, int $espera): bool
    {
        app()->instance('tenant_id', $institutionId);

        try {
            return (bool) DB::transaction(function () use ($attemptId, $espera) {
                // Bloqueado y releído: si el alumno envía justo ahora, gana su entrega.
                $attempt = ExamAttempt::query()->whereKey($attemptId)->lockForUpdate()->first();

                if (!$attempt || $attempt->submitted_at) {
                    return false;
                }

                $exam = $attempt->exam;
                $student = Student::where('user_id', $attempt->student_user_id)->first();
                $plazo = $exam ? $this->reglas->plazoDeEntrega($exam, $attempt, $student) : null;

                if (!$exam || !$student || !$plazo || now()->lt($plazo->copy()->addSeconds($espera))) {
                    return false;
                }

                $questions = Question::where('exam_id', $exam->id)->with('options')->get()->keyBy('id');

                if ($questions->isEmpty()) {
                    return false;
                }

                ['attempt' => $graded, 'respuestas' => $respuestas] = $this->calificacion->calificar(
                    $exam,
                    $attempt,
                    $this->respuestasValidas((array) ($attempt->makeVisible('draft_answers')->draft_answers ?? []), $questions),
                    $questions
                );

                $exam->loadMissing('subject');
                $graded->setRelation('exam', $exam);
                $graded->setRelation('student', $student);

                if (!empty($exam->subject_id)) {
                    $this->progreso->recalcFromAttempts($graded->student_user_id, $exam->subject_id);
                }

                $this->recomendaciones->generateFromAttempt($graded, $respuestas, $questions);

                return true;
            });
        } finally {
            app()->forgetInstance('tenant_id');
        }
    }

    /**
     * Del borrador, solo lo que una entrega normal habría aceptado: preguntas del examen, una opción
     * (que sea de esa pregunta) en las de elegir, y texto en las abiertas. Lo demás se descarta en
     * silencio: un borrador raro no debe impedir entregar el resto.
     *
     * @param  Collection<string, Question>  $questions
     * @return array<int, array{question_id:string, answer_text:?string, selected_option_ids:array<int,int>}>
     */
    private function respuestasValidas(array $borrador, Collection $questions): array
    {
        $validas = [];

        foreach ($borrador as $r) {
            $pregunta = $questions->get($r['question_id'] ?? null);
            if (!$pregunta) {
                continue;
            }

            $tipo = $pregunta->question_type->value;

            if (in_array($tipo, ['multiple_choice', 'true_false'], true)) {
                $elegida = collect($r['selected_option_ids'] ?? [])
                    ->first(fn ($id) => $pregunta->options->contains('id', (int) $id));

                if ($elegida !== null) {
                    $validas[] = ['question_id' => $pregunta->id, 'answer_text' => null, 'selected_option_ids' => [(int) $elegida]];
                }
            } else {
                $texto = isset($r['answer_text']) ? mb_substr((string) $r['answer_text'], 0, 4000) : '';

                if (trim($texto) !== '') {
                    $validas[] = ['question_id' => $pregunta->id, 'answer_text' => $texto, 'selected_option_ids' => []];
                }
            }
        }

        return $validas;
    }
}
