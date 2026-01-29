<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Student;
use App\Models\Question;
use App\Services\AiRecommendationService;
use App\Services\ExamAttemptRulesService;
use App\Services\ExamGradingService;
use App\Services\StudentProgressService;
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

        // RN: examen startable (activo + ventana)
        try {
            $rules->assertExamIsStartable($exam);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        // Intentos usados (enviados)
        $usedAttempts = ExamAttempt::where('exam_id', $exam->id)
            ->where('student_user_id', $user->id)
            ->whereNotNull('submitted_at')
            ->count();

        // RN: intentos disponibles
        try {
            $rules->assertAttemptsAvailable($exam, $usedAttempts);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        $attempt = ExamAttempt::create([
            'exam_id' => $exam->id,
            'student_user_id' => $user->id,
            'attempt_number' => $usedAttempts + 1,
            'started_at' => now(),
            'submitted_at' => null,
            'score' => 0,
            'max_score' => (float) $exam->questions()->sum('points'),
            'grade_status' => 'pending',
        ]);

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

        // RN: intentos submittable
        try {
            $rules->assertAttemptIsSubmittable($exam, $attempt);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        $data = $request->validate([
            'answers' => ['required', 'array', 'min:1'],
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
            $attempt = $grading->gradeAttempt($exam, $attempt, $data['answers']);

            // 2) Recalcular progreso (promedio por materia) si el examen tiene subject_id
            $progress = null;
            if (!empty($exam->subject_id)) {
                $progress = $progressService->recalcFromAttempts(
                    $attempt->student_user_id,
                    $exam->subject_id
                );
            }

            // 3) Generar recomendaciones
            $recommendations = $aiService->generateFromAttempt($attempt);

            return [
                'attempt' => $attempt,
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
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $attempt->load([
            'answers.question.options',
            'answers.selectedOptions',
        ]);

        return response()->json([
            'data' => $attempt,
        ]);
    }
}