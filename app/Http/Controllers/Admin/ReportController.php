<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AI\AiChatSession;
use App\Models\AI\AiRecommendation;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Students\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    private const RESULTS_PER_PAGE = 50;

    /**
     * Reporte paginado: resultados de un examen (JSON)
     */
    private function assertCanAccessExam(Exam $exam, Request $request): bool
    {
        $user = $request->user();
        if ($user->user_type->value === 'teacher' && $exam->created_by_teacher_id !== $user->id) {
            return false;
        }
        return true;
    }

    public function examResults(Exam $exam, Request $request)
    {
        if (!$this->assertCanAccessExam($exam, $request)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $paginator = ExamAttempt::query()
            ->where('exam_id', $exam->id)
            ->whereNotNull('submitted_at')
            ->with(['student.user'])
            ->orderByDesc('score')
            ->paginate(self::RESULTS_PER_PAGE);

        $paginator->through(fn ($a) => [
            'attempt_id'      => $a->id,
            'student_user_id' => $a->student_user_id,
            'student_name'    => $a->student?->user?->full_name,
            'score'           => (float) $a->score,
            'max_score'       => (float) $a->max_score,
            'percentage'      => $a->percentage,
            'submitted_at'    => $a->submitted_at,
        ]);

        return response()->json([
            'data' => [
                'exam'    => $exam,
                'results' => $paginator,
            ],
        ]);
    }

    /**
     * Export CSV: resultados de un examen.
     * Usa cursor() para procesar fila a fila sin cargar todo en memoria.
     */
    public function exportExamResultsCsv(Exam $exam, Request $request): StreamedResponse
    {
        if (!$this->assertCanAccessExam($exam, $request)) {
            abort(403, 'No autorizado');
        }

        $filename = 'exam_results_' . $exam->id . '.csv';
        $headers  = ['student_user_id', 'student_name', 'score', 'max_score', 'percentage', 'submitted_at'];

        return response()->streamDownload(function () use ($exam, $headers) {
            $output = fopen('php://output', 'w');
            fputs($output, "\xEF\xBB\xBF");
            fputcsv($output, $headers);

            // cursor() procesa un registro a la vez — sin cargar la colección entera
            ExamAttempt::query()
                ->where('exam_id', $exam->id)
                ->whereNotNull('submitted_at')
                ->with(['student.user'])
                ->orderByDesc('score')
                ->cursor()
                ->each(function ($a) use ($output) {
                    fputcsv($output, [
                        $a->student_user_id,
                        $a->student?->user?->full_name,
                        (float) $a->score,
                        (float) $a->max_score,
                        $a->percentage,
                        $a->submitted_at,
                    ]);
                });

            fclose($output);
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Reporte: historial completo de un estudiante (JSON)
     */
    public function studentHistory(string $student_user_id, Request $request)
    {
        $student = Student::with('user')->where('user_id', $student_user_id)->firstOrFail();

        $paginator = ExamAttempt::query()
            ->where('student_user_id', $student_user_id)
            ->whereNotNull('submitted_at')
            ->with('exam.subject')
            ->orderByDesc('submitted_at')
            ->paginate(self::RESULTS_PER_PAGE);

        $paginator->through(fn ($a) => [
            'attempt_id'   => $a->id,
            'exam_id'      => $a->exam_id,
            'exam_title'   => $a->exam?->title,
            'subject'      => $a->exam?->subject?->name,
            'score'        => (float) $a->score,
            'max_score'    => (float) $a->max_score,
            'percentage'   => $a->percentage,
            'submitted_at' => $a->submitted_at,
        ]);

        return response()->json([
            'data' => [
                'student'  => $student,
                'attempts' => $paginator,
            ],
        ]);
    }

    /**
     * Métricas de uso del tutor IA:
     * - total de sesiones y mensajes
     * - sesiones activas vs cerradas
     * - top 5 tipos de recomendación más generados
     * - distribución de uso por estudiante (top 10)
     */
    public function tutorUsage(Request $request)
    {
        $sessions = AiChatSession::selectRaw(
            'COUNT(*) as total_sessions,
             COUNT(CASE WHEN ended_at IS NULL THEN 1 END) as active_sessions,
             COUNT(CASE WHEN ended_at IS NOT NULL THEN 1 END) as closed_sessions,
             COUNT(DISTINCT student_user_id) as unique_students,
             SUM(jsonb_array_length(messages)) as total_messages'
        )->first();

        $topRecommendationTypes = AiRecommendation::select('recommendation_type', DB::raw('COUNT(*) as total'))
            ->groupBy('recommendation_type')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'type'  => $r->recommendation_type instanceof \BackedEnum ? $r->recommendation_type->value : $r->recommendation_type,
                'total' => (int) $r->total,
            ]);

        $topStudentsByUsage = AiChatSession::select(
                'student_user_id',
                DB::raw('COUNT(*) as sessions'),
                DB::raw('SUM(jsonb_array_length(messages)) as messages')
            )
            ->groupBy('student_user_id')
            ->orderByDesc('messages')
            ->limit(10)
            ->with('student.user:id,full_name')
            ->get()
            ->map(fn ($s) => [
                'student_user_id' => $s->student_user_id,
                'full_name'       => $s->student?->user?->full_name ?? 'N/D',
                'sessions'        => (int) $s->sessions,
                'messages'        => (int) $s->messages,
            ]);

        return response()->json([
            'data' => [
                'sessions'                => $sessions,
                'top_recommendation_types' => $topRecommendationTypes,
                'top_students_by_usage'   => $topStudentsByUsage,
            ],
        ]);
    }
}
