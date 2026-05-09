<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Academic\Subject;
use App\Models\Exams\ExamAttempt;
use App\Models\Students\Student;
use App\Models\Students\StudentProgress;
use App\Models\Exams\Exam;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    /**
     * Estadísticas generales de la institución.
     * GET /api/analytics/institution
     */
    public function institution(Request $request)
    {
        $totalStudents  = Student::count();
        $activeStudents = Student::where('status', 'active')->count();
        $examsCompleted = ExamAttempt::whereNotNull('submitted_at')->count();

        $avgPct = ExamAttempt::whereNotNull('submitted_at')
            ->where('max_score', '>', 0)
            ->selectRaw('AVG(score / max_score * 100) as avg_pct')
            ->value('avg_pct');

        return response()->json([
            'data' => [
                'total_students'           => $totalStudents,
                'active_students'          => $activeStudents,
                'exams_completed'          => $examsCompleted,
                'average_score_percentage' => $avgPct ? round((float) $avgPct, 2) : 0,
            ],
        ]);
    }

    /**
     * Rendimiento por materia.
     * GET /api/analytics/subjects
     */
    public function subjects(Request $request)
    {
        $subjects   = Subject::query()->select('id', 'name')->get();
        $subjectIds = $subjects->pluck('id');

        $progressStats = StudentProgress::whereIn('subject_id', $subjectIds)
            ->selectRaw('subject_id, COUNT(*) as student_count, AVG(mastery_percentage) as avg_mastery')
            ->groupBy('subject_id')
            ->get()
            ->keyBy('subject_id');

        $examCounts = Exam::whereIn('subject_id', $subjectIds)
            ->selectRaw('subject_id, COUNT(*) as exams_count')
            ->groupBy('subject_id')
            ->get()
            ->keyBy('subject_id');

        $data = $subjects->map(function ($subject) use ($progressStats, $examCounts) {
            $ps = $progressStats->get($subject->id);
            $ec = $examCounts->get($subject->id);

            return [
                'id'                => $subject->id,
                'name'              => $subject->name,
                'exams_count'       => $ec ? (int) $ec->exams_count : 0,
                'enrolled_students' => $ps ? (int) $ps->student_count : 0,
                'average_mastery'   => $ps ? round((float) $ps->avg_mastery, 2) : 0,
            ];
        });

        return response()->json(['data' => $data]);
    }

    /**
     * Detalle analítico de un estudiante.
     * GET /api/analytics/students/{student_user_id}
     */
    public function student(Request $request, string $student_user_id)
    {
        $student = Student::with(['user', 'progress.subject'])
            ->where('user_id', $student_user_id)
            ->firstOrFail();

        // Total y promedio calculados en BD para no cargar todos los intentos en memoria
        $stats = ExamAttempt::where('student_user_id', $student_user_id)
            ->whereNotNull('submitted_at')
            ->where('max_score', '>', 0)
            ->selectRaw('COUNT(*) as total_attempts, AVG(score / max_score * 100) as avg_pct')
            ->first();

        // Solo los 10 más recientes para el listado
        $recentAttempts = ExamAttempt::where('student_user_id', $student_user_id)
            ->whereNotNull('submitted_at')
            ->with('exam.subject')
            ->orderBy('submitted_at', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'data' => [
                'student'                  => $student,
                'total_attempts'           => (int) ($stats->total_attempts ?? 0),
                'average_score_percentage' => $stats->avg_pct ? round((float) $stats->avg_pct, 2) : 0,
                'progress_by_subject'      => $student->progress,
                'recent_attempts'          => $recentAttempts,
            ],
        ]);
    }
}
