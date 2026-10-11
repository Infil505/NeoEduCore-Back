<?php

namespace App\Http\Controllers\Students;

use App\Http\Controllers\Controller;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Exams\Question;
use App\Support\RelacionesEnLinea;
use App\Models\Students\StudentAnswer;
use App\Services\AI\AiRecommendationService;
use App\Services\Students\StudentProgressService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentAnswerController extends Controller
{
    public function index(Request $request, ExamAttempt $attempt)
    {
        $user = $request->user();

        if ($user->user_type->value === 'teacher') {
            $attempt->loadMissing('exam');
            if ($attempt->exam->created_by_teacher_id !== $user->id) {
                return response()->json(['message' => 'No autorizado'], 403);
            }
        }

        // La pregunta unida a cada respuesta en la MISMA consulta (`RelacionesEnLinea`), no una
        // más: con la base remota cada viaje cuesta ~0,4 s.
        $respuestas = RelacionesEnLinea::unir($attempt->answers()->getQuery(), [
            'question' => ['questions', 'question_id', Question::COLUMNAS],
        ])->get();
        RelacionesEnLinea::hidratar($respuestas, ['question' => Question::class]);

        return response()->json([
            'data' => $respuestas,
        ]);
    }

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
        string $studentAnswer,
        StudentProgressService $progressService,
        AiRecommendationService $aiService
    ) {
        $user = $request->user();

        // Estudiante NO puede revisar
        if ($user->user_type->value === 'student') {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        // La respuesta con su pregunta, y el intento con su examen: DOS consultas (antes el binding y
        // cuatro relaciones más). Con la base remota cada viaje cuesta ~0,4 s.
        $studentAnswer = RelacionesEnLinea::unir(StudentAnswer::query(), [
            'question' => ['questions', 'question_id', Question::COLUMNAS],
        ])->where('student_answers.id', $studentAnswer)->first() ?? abort(404);
        RelacionesEnLinea::hidratar([$studentAnswer], ['question' => Question::class]);

        $intento = RelacionesEnLinea::unir(ExamAttempt::query(), [
            'exam' => ['exams', 'exam_id', Exam::COLUMNAS],
        ])->where('exam_attempts.id', $studentAnswer->attempt_id)->first() ?? abort(404);
        RelacionesEnLinea::hidratar([$intento], ['exam' => Exam::class]);

        if ($user->user_type->value === 'teacher') {
            if ($intento->exam->created_by_teacher_id !== $user->id) {
                return response()->json(['message' => 'No autorizado'], 403);
            }
        }

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
            $intento,
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

            // 2) Recalcular attempt (score y max_score): los totales salen de UNA consulta agregada
            // (antes se cargaban todas las respuestas con su pregunta).
            $attempt = $intento;

            $suma = DB::selectOne(
                "SELECT COALESCE(SUM(sa.points_awarded), 0) AS total, COALESCE(SUM(q.points), 0) AS maximo,
                        COALESCE(BOOL_OR(sa.review_status = 'needs_review'), false) AS pendientes
                   FROM student_answers sa LEFT JOIN questions q ON q.id = sa.question_id
                  WHERE sa.attempt_id = ? AND sa.institution_id = ?",
                [$attempt->id, $attempt->institution_id]
            );

            $total = (float) $suma->total;
            $max   = (float) $suma->maximo;

            // Sigue pendiente mientras quede alguna respuesta sin revisar.
            $quedanPendientes = (bool) $suma->pendientes;

            $attempt->update([
                'score' => round($total, 2),
                'max_score' => round($max, 2),
                'grade_status' => $quedanPendientes ? 'pending' : 'completed',
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
                    null,
                    $attempt->id
                );
            }

            // Sin `fresh()`: `update()` ya deja en memoria lo escrito, y releer eran dos viajes más.
            // La pregunta y el examen solo sirvieron para decidir; no van en la respuesta.
            $studentAnswer->unsetRelation('question');
            $attempt->unsetRelation('exam');

            return [
                'studentAnswer' => $studentAnswer,
                'attempt' => $attempt,
                'progress' => $progress,
                'recommendation' => $createdRec,
            ];
        });

        return response()->json([
            'data' => $result,
        ]);
    }
}
