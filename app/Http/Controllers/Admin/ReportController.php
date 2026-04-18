<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Students\Student;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    private const RESULTS_PER_PAGE = 50;

    /**
     * Reporte paginado: resultados de un examen (JSON)
     */
    public function examResults(Exam $exam, Request $request)
    {
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
                'exam'     => $exam,
                'attempts' => $paginator,
            ],
        ]);
    }

    /**
     * Export CSV: resultados de un examen.
     * Usa cursor() para procesar fila a fila sin cargar todo en memoria.
     */
    public function exportExamResultsCsv(Exam $exam): StreamedResponse
    {
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
}
