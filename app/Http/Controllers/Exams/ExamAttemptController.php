<?php

namespace App\Http\Controllers\Exams;

use App\Http\Controllers\Controller;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Students\Student;
use App\Models\Exams\Question;
use App\Services\AI\AiRecommendationService;
use App\Services\Exams\ExamAttemptRulesService;
use App\Services\Exams\ExamGradingService;
use App\Services\Students\StudentProgressService;
use App\Models\AI\AiRecommendation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExamAttemptController extends Controller
{
    /**
     * Iniciar intento
     */
    public function start(
        Request $request,
        Exam $exam,
        ExamAttemptRulesService $rules
    ) {
        $user = $request->user();

        // Debe ser estudiante
        $student = Student::where('user_id', $user->id)->first();
        if (!$student) {
            return response()->json(['message' => 'Solo estudiantes pueden iniciar intentos'], 403);
        }

        // RN: examen startable (activo + ventana) — fuera de transacción, no modifica datos
        try {
            $rules->assertExamIsStartable($exam);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        // Transacción con lock para evitar race condition si el estudiante
        // lanza dos requests simultáneos (doble clic, re-submit del navegador)
        try {
            $attempt = DB::transaction(function () use ($exam, $user, $rules) {
                // Bloquear fila del estudiante → serializa starts concurrentes del mismo usuario
                Student::where('user_id', $user->id)->lockForUpdate()->firstOrFail();

                $usedAttempts = ExamAttempt::where('exam_id', $exam->id)
                    ->where('student_user_id', $user->id)
                    ->whereNotNull('submitted_at')
                    ->count();

                $rules->assertAttemptsAvailable($exam, $usedAttempts);

                return ExamAttempt::create([
                    'exam_id'         => $exam->id,
                    'student_user_id' => $user->id,
                    'attempt_number'  => $usedAttempts + 1,
                    'started_at'      => now(),
                    'submitted_at'    => null,
                    'score'           => 0,
                    'max_score'       => (float) $exam->questions()->sum('points'),
                    'grade_status'    => 'pending',
                ]);
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['data' => $attempt], 201);
    }

    /**
     * Enviar intento (submit)
     *
     * Reglas ajustadas a triggers:
     * - multiple_choice / true_false => selected_option_ids requerido y EXACTAMENTE 1 id
     * - short_answer => answer_text requerido (y NO opciones)
     */
    public function submit(
        Request $request,
        Exam $exam,
        ExamAttempt $attempt,
        ExamAttemptRulesService $rules,
        ExamGradingService $grading,
        StudentProgressService $progressService,
        AiRecommendationService $aiService
    ) {
        $user = $request->user();

        // Seguridad: intento del usuario y del examen
        if ($attempt->exam_id !== $exam->id || $attempt->student_user_id !== $user->id) {
            return response()->json(['message' => 'Intento no válido'], 404);
        }

        $student = Student::where('user_id', $user->id)->first();

        // RN: intentos submittable (pasa Student para aplicar adecuación curricular)
        try {
            $rules->assertAttemptIsSubmittable($exam, $attempt, $student);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        $data = $request->validate([
            'answers' => ['present', 'array'],
            'answers.*.question_id' => ['required', 'uuid'],
            'answers.*.answer_text' => ['nullable', 'string', 'max:4000'],
            'answers.*.selected_option_ids' => ['nullable', 'array'],
            'answers.*.selected_option_ids.*' => ['integer'],
        ]);

        // ✅ Validación lógica contra tipos reales del examen (para no depender del trigger)
        $questions = Question::query()
            ->where('exam_id', $exam->id)
            ->with('options')
            ->get()
            ->keyBy('id');

        if ($questions->isEmpty()) {
            return response()->json(['message' => 'El examen no tiene preguntas'], 409);
        }

        $byQuestion = collect($data['answers'])->keyBy('question_id');

        $errors = [];

        foreach ($questions as $qid => $q) {
            $payload = $byQuestion->get($qid);

            // Si no viene, lo dejamos pasar (se guardará en blanco y quedará incorrecto),
            // pero si querés obligar a responder TODO, lo convertimos en error aquí.
            if (!$payload) {
                continue;
            }

            $type = $q->question_type->value;
            $answerText = $payload['answer_text'] ?? null;
            $selected = $payload['selected_option_ids'] ?? null;

            if (in_array($type, ['multiple_choice', 'true_false'], true)) {
                // Debe traer EXACTAMENTE 1 selección
                if (!is_array($selected) || count($selected) !== 1) {
                    $errors["answers.$qid.selected_option_ids"] = [
                        "Para {$type} debes enviar selected_option_ids con EXACTAMENTE 1 opción.",
                    ];
                }

                // No debe traer answer_text
                if (!empty($answerText) && trim((string) $answerText) !== '') {
                    $errors["answers.$qid.answer_text"] = [
                        "Para {$type} no se permite answer_text. Usa selected_option_ids.",
                    ];
                }
            }

            if ($type === 'short_answer') {
                // Debe traer texto
                if ($answerText === null || trim((string) $answerText) === '') {
                    $errors["answers.$qid.answer_text"] = [
                        "Para short_answer debes enviar answer_text.",
                    ];
                }

                // No debe traer opciones
                if (is_array($selected) && count($selected) > 0) {
                    $errors["answers.$qid.selected_option_ids"] = [
                        "Para short_answer no se permite selected_option_ids.",
                    ];
                }
            }
        }

        if (!empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        // Ejecutar todo en transacción
        $result = DB::transaction(function () use (
            $exam,
            $attempt,
            $data,
            $grading,
            $progressService,
            $aiService
        ) {
            // 1) Calificar intento + guardar respuestas
            $gradedAttempt = $grading->gradeAttempt($exam, $attempt, $data['answers']);

            // 2) Recalcular progreso (promedio por materia) si el examen tiene subject_id
            $progress = null;
            if (!empty($exam->subject_id)) {
                $progress = $progressService->recalcFromAttempts(
                    $gradedAttempt->student_user_id,
                    $exam->subject_id
                );
            }

            // 3) Generar recomendaciones
            $recommendations = $aiService->generateFromAttempt($gradedAttempt);

            return [
                'attempt' => $gradedAttempt,
                'progress' => $progress,
                'recommendations' => $recommendations,
            ];
        });

        return response()->json([
            'data' => [
                'attempt' => $result['attempt'],
                'display_score' => $result['attempt']->display_score,
                'percentage' => $result['attempt']->percentage,
                'progress' => $result['progress'],
                'recommendations' => $result['recommendations'],
            ],
        ]);
    }

    /**
     * Ver un intento (con respuestas)
     */
    public function show(Exam $exam, ExamAttempt $attempt, Request $request)
    {
        $user = $request->user();

        if ($attempt->exam_id !== $exam->id || $attempt->student_user_id !== $user->id) {
            return response()->json(['message' => 'No encontrado'], 404);
        }

        $attempt->load([
            'answers.question.options',
            'answers.selectedOptions',
        ]);

        // `is_correct` y `correct_answer_text` van ocultos por defecto en los
        // modelos, así que aquí no hace falta filtrarlos: al estudiante nunca
        // se le revelan (este endpoint es solo para él, ver el guard de arriba).
        //
        // Lo que sí hay que gobernar es la corrección de SUS respuestas. Antes
        // se devolvía siempre, incluso con el intento en curso y aunque el
        // docente hubiera desactivado la revisión. Con `max_attempts > 1` eso
        // era filtrar las respuestas de cara al intento siguiente.
        $entregado = $attempt->submitted_at !== null;
        $puedeRevisar = $entregado && $exam->allow_review_after_submission;

        if (!$puedeRevisar) {
            $attempt->answers->each->makeHidden([
                'is_correct',
                'points_awarded',
                'correct_answer_snapshot',
                'explanation',
            ]);
        }

        return response()->json([
            'data' => $attempt,
            'meta' => [
                'submitted'    => $entregado,
                'review_shown' => $puedeRevisar,
            ],
        ]);
    }

    public function pause(Request $request, Exam $exam, ExamAttempt $attempt)
    {
        $user = $request->user();

        if ($attempt->exam_id !== $exam->id || $attempt->student_user_id !== $user->id) {
            return response()->json(['message' => 'Intento no válido'], 404);
        }

        if ($attempt->submitted_at) {
            return response()->json(['message' => 'El intento ya fue enviado'], 409);
        }

        if ($attempt->paused_at) {
            return response()->json(['message' => 'El intento ya está pausado'], 409);
        }

        $attempt->update(['paused_at' => now()]);

        return response()->json(['data' => $attempt->fresh()]);
    }

    public function resume(Request $request, Exam $exam, ExamAttempt $attempt)
    {
        $user = $request->user();

        if ($attempt->exam_id !== $exam->id || $attempt->student_user_id !== $user->id) {
            return response()->json(['message' => 'Intento no válido'], 404);
        }

        if ($attempt->submitted_at) {
            return response()->json(['message' => 'El intento ya fue enviado'], 409);
        }

        if (!$attempt->paused_at) {
            return response()->json(['message' => 'El intento no está pausado'], 409);
        }

        $pausedSeconds = (int) abs(now()->diffInSeconds($attempt->paused_at));

        $attempt->update([
            'paused_at'            => null,
            'total_paused_seconds' => $attempt->total_paused_seconds + $pausedSeconds,
        ]);

        return response()->json(['data' => $attempt->fresh()]);
    }

    public function regenerateRecommendations(
        Request $request,
        ExamAttempt $attempt,
        AiRecommendationService $aiService
    ) {
        $user = $request->user();

        // Solo el estudiante dueño del intento puede regenerar
        if ($attempt->student_user_id !== $user->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        // Debe estar enviado
        if (!$attempt->submitted_at) {
            return response()->json(['message' => 'El intento aún no ha sido enviado'], 409);
        }

        // Necesitamos subject_id para guardar recomendaciones
        $attempt->load(['exam']);
        $subjectId = $attempt->exam?->subject_id;

        if (!$subjectId) {
            return response()->json(['message' => 'El examen no tiene materia asociada'], 409);
        }

        // Límite: la generación automática del submit + 3 regeneraciones.
        //
        // `ai_recommendations` no tiene `attempt_id`, así que este intento se
        // acota por tiempo: solo cuentan las generadas desde su entrega. Cada
        // llamada —el submit y cada regeneración— escribe su lote con un mismo
        // `generated_at`, de modo que contar instantes distintos cuenta
        // generaciones y no filas.
        //
        // Antes era `ceil($total / 4)` sobre TODAS las del par (examen, materia),
        // con dos errores a la vez: `generateFromAttempt` no guarda 4 filas sino
        // 1 o 2 según el porcentaje, así que la división no contaba nada real; y
        // sin corte temporal un segundo intento del mismo examen nacía con el
        // cupo del primero ya gastado.
        //
        // `generated_at` tiene precisión de segundo: entregar y regenerar dentro
        // del mismo segundo cuenta como una sola generación. El error va del lado
        // permisivo y hace falta un cronometraje imposible a mano.
        $MAX_REGENS = 3;

        $generacionesPrevias = AiRecommendation::where('student_user_id', $user->id)
            ->where('exam_id', $attempt->exam_id)
            ->where('subject_id', $subjectId)
            ->where('generated_at', '>=', $attempt->submitted_at)
            ->distinct()
            ->count('generated_at');

        if ($generacionesPrevias >= 1 + $MAX_REGENS) {
            return response()->json([
                'message' => 'Límite de regeneraciones alcanzado para este intento',
            ], 429);
        }

        // Generar y guardar nuevas recomendaciones
        $created = $aiService->regenerateForAttempt($attempt, $user->id);

        return response()->json([
            'data' => $created,
        ], 201);
    }
}
