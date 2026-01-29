<?php

namespace App\Http\Controllers;

use App\Models\StudentAnswer;
use App\Services\AiRecommendationService;
use App\Services\StudentProgressService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentAnswerController extends Controller
{
    /**
     * Revisar/calificar manualmente una respuesta (short_answer)
     * - Solo teacher/admin
     * - Solo aplica a preguntas short_answer
     * - Actualiza StudentAnswer
     * - Recalcula score de ExamAttempt
     * - Recalcula progreso por materia
     * - (Opcional) agrega recomendación "action" si quedó bajo
     */
    public function review(
        Request $request,
        StudentAnswer $studentAnswer,
        StudentProgressService $progressService,
        AiRecommendationService $aiService
    ) {
        $user = $request->user();

        // Estudiante NO puede revisar
        if ($user->user_type->value === 'student') {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $studentAnswer->load(['question', 'attempt.exam']);

        // ✅ Solo short_answer es revisable manualmente
        $qType = $studentAnswer->question?->question_type?->value;
        if ($qType !== 'short_answer') {
            return response()->json([
                'message' => 'Solo se pueden revisar manualmente respuestas de tipo short_answer',
            ], 409);
        }

        $data = $request->validate([
            'is_correct'     => ['required', 'boolean'],
            'points_awarded' => ['required', 'numeric', 'min:0'],
            'explanation'    => ['nullable', 'string', 'max:2000'],
        ]);

        $maxPoints = (float) ($studentAnswer->question?->points ?? 0);

        if ((float) $data['points_awarded'] > $maxPoints) {
            return response()->json([
                'message' => 'Los puntos asignados exceden el valor de la pregunta',
            ], 422);
        }

        $result = DB::transaction(function () use (
            $studentAnswer,
            $data,
            $progressService,
            $aiService
        ) {
            // 1) Guardar revisión
            $studentAnswer->update([
                'is_correct'     => (bool) $data['is_correct'],
                'points_awarded' => round((float) $data['points_awarded'], 2),
                'explanation'    => $data['explanation'] ?? null,
                'review_status'  => 'reviewed',
            ]);

            // 2) Recalcular attempt (score y max_score)
            $attempt = $studentAnswer->attempt()->with(['answers.question', 'exam'])->first();

            $total = (float) $attempt->answers()->sum('points_awarded');
            $max   = (float) $attempt->answers->sum(fn ($a) => (float) ($a->question?->points ?? 0));

            $attempt->update([
                'score' => round($total, 2),
                'max_score' => round($max, 2),
                'grade_status' => 'completed',
            ]);

            // 3) Recalcular progreso por materia
            $progress = null;
            $subjectId = $attempt->exam?->subject_id;

            if ($subjectId) {
                $progress = $progressService->recalcFromAttempts(
                    $attempt->student_user_id,
                    $subjectId
                );
            }

            // 4) (Opcional) recomendación simple si quedó bajo
            //    OJO: percentage es accesor en el modelo ExamAttempt. Si no existe, calculamos manual.
            $percentage = method_exists($attempt, 'getPercentageAttribute')
                ? (float) $attempt->percentage
                : (($attempt->max_score > 0) ? round(((float)$attempt->score / (float)$attempt->max_score) * 100, 2) : 0.0);

            $createdRec = null;
            if ($percentage < 70 && $subjectId) {
                $createdRec = $aiService->create(
                    $attempt->student_user_id,
                    $subjectId,
                    $attempt->exam_id,
                    'action',
                    'Se recomienda repasar los temas donde hubo errores y practicar con ejercicios guiados antes del próximo intento.',
                    null
                );
            }

            return [
                'studentAnswer' => $studentAnswer->fresh(),
                'attempt' => $attempt->fresh(),
                'progress' => $progress,
                'recommendation' => $createdRec,
            ];
        });

        return response()->json([
            'data' => $result,
        ]);
    }
}