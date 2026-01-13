<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Student;
use App\Services\AiRecommendationService;
use App\Services\ExamAttemptRulesService;
use App\Services\ExamGradingService;
use App\Services\StudentProgressService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
     * - Califica (ExamGradingService)
     * - Recalcula progreso por materia (StudentProgressService)
     * - Genera recomendaciones (AiRecommendationService)
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

            // 3) Generar recomendaciones (fallback sin OpenAI)
            //    (si luego querés OpenAI, aquí llamás al AiController/servicio de OpenAI)
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