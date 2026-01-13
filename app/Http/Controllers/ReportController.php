<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Student;
use App\Services\ReportExportService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    /**
     * Reporte: resultados de un examen (JSON)
     */
    public function examResults(Exam $exam)
    {
        $attempts = ExamAttempt::query()
            ->where('exam_id', $exam->id)
            ->whereNotNull('submitted_at')
            ->with(['student.user'])
            ->orderByDesc('score')
            ->get();

        return response()->json([
            'data' => [
                'exam' => $exam,
                'attempts' => $attempts->map(function ($a) {
                    return [
                        'attempt_id' => $a->id,
                        'student_user_id' => $a->student_user_id,
                        'student_name' => $a->student?->user?->full_name,
                        'score' => (float) $a->score,
                        'max_score' => (float) $a->max_score,
                        'percentage' => $a->percentage,
                        'submitted_at' => $a->submitted_at,
                    ];
                }),
            ],
        ]);
    }

    /**
     * Export CSV: resultados de un examen
     */
    public function exportExamResultsCsv(Exam $exam, ReportExportService $export): StreamedResponse
    {
        $attempts = ExamAttempt::query()
            ->where('exam_id', $exam->id)
            ->whereNotNull('submitted_at')
            ->with(['student.user'])
            ->orderByDesc('score')
            ->get();

        $rows = $attempts->map(function ($a) {
            return [
                $a->student_user_id,
                $a->student?->user?->full_name,
                (float) $a->score,
                (float) $a->max_score,
                $a->percentage,
                $a->submitted_at,
            ];
        })->all();

        return $export->streamCsv(
            'exam_results_' . $exam->id . '.csv',
            ['student_user_id', 'student_name', 'score', 'max_score', 'percentage', 'submitted_at'],
            $rows
        );
    }

    /**
     * Reporte: historial de un estudiante (JSON)
     */
    public function studentHistory(string $student_user_id)
    {
        $student = Student::with('user')->where('user_id', $student_user_id)->firstOrFail();

        $attempts = ExamAttempt::query()
            ->where('student_user_id', $student_user_id)
            ->whereNotNull('submitted_at')
            ->with('exam.subject')
            ->orderByDesc('submitted_at')
            ->get();

        return response()->json([
            'data' => [
                'student' => $student,
                'attempts' => $attempts->map(function ($a) {
                    return [
                        'attempt_id' => $a->id,
                        'exam_id' => $a->exam_id,
                        'exam_title' => $a->exam?->title,
                        'subject' => $a->exam?->subject?->name,
                        'score' => (float) $a->score,
                        'max_score' => (float) $a->max_score,
                        'percentage' => $a->percentage,
                        'submitted_at' => $a->submitted_at,
                    ];
                }),
            ],
        ]);
    }
}