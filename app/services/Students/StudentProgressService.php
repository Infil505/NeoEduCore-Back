<?php

namespace App\Services\Students;

use App\Models\Exams\ExamAttempt;
use App\Models\Students\Student;
use App\Models\Students\StudentProgress;
use Illuminate\Support\Facades\DB;

class StudentProgressService
{
    public function upsertProgress(string $studentUserId, string $subjectId, float $percentage): StudentProgress
    {
        return StudentProgress::updateOrCreate(
            [
                'student_user_id' => $studentUserId,
                'subject_id' => $subjectId,
            ],
            [
                'mastery_percentage' => round($percentage, 2),
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Recalcular desde intentos enviados (promedio de porcentajes)
     */
    public function recalcFromAttempts(string $studentUserId, string $subjectId): StudentProgress
    {
        // JOIN en lugar de whereHas + get() evita cargar registros en RAM para calcular AVG.
        $result = ExamAttempt::query()
            ->join('exams', 'exams.id', '=', 'exam_attempts.exam_id')
            ->where('exam_attempts.student_user_id', $studentUserId)
            ->whereNotNull('exam_attempts.submitted_at')
            ->where('exams.subject_id', $subjectId)
            ->where('exam_attempts.max_score', '>', 0)
            ->selectRaw('COUNT(*) as total, AVG((exam_attempts.score / exam_attempts.max_score) * 100) as avg_pct')
            ->first();

        if (!$result || (int) $result->total === 0) {
            return $this->upsertProgress($studentUserId, $subjectId, 0);
        }

        $progress = $this->upsertProgress($studentUserId, $subjectId, (float) $result->avg_pct);

        $this->syncStudentStats($studentUserId);

        return $progress;
    }

    public function syncStudentStats(string $studentUserId): void
    {
        // AVG y COUNT directamente en SQL; evita cargar todas las filas en RAM.
        $overallAverage = round(
            (float) (StudentProgress::where('student_user_id', $studentUserId)
                ->selectRaw('AVG(mastery_percentage) as avg')
                ->value('avg') ?? 0.0),
            2
        );

        $examsCompleted = ExamAttempt::where('student_user_id', $studentUserId)
            ->whereNotNull('submitted_at')
            ->count();

        Student::where('user_id', $studentUserId)->update([
            'overall_average'       => $overallAverage,
            'exams_completed_count' => $examsCompleted,
            'last_activity_at'      => now(),
        ]);
    }
}