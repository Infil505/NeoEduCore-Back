<?php

namespace App\Services\Students;

use App\Models\Exams\ExamAttempt;
use App\Models\Students\Student;
use App\Models\Students\StudentProgress;

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
        $attempts = ExamAttempt::query()
            ->where('student_user_id', $studentUserId)
            ->whereNotNull('submitted_at')
            ->whereHas('exam', fn($q) => $q->where('subject_id', $subjectId))
            ->get();

        if ($attempts->count() === 0) {
            return $this->upsertProgress($studentUserId, $subjectId, 0);
        }

        $avg = $attempts->avg(fn($a) => (float) $a->percentage);

        $progress = $this->upsertProgress($studentUserId, $subjectId, (float) $avg);

        $this->syncStudentStats($studentUserId);

        return $progress;
    }

    public function syncStudentStats(string $studentUserId): void
    {
        $progresses = StudentProgress::where('student_user_id', $studentUserId)->get();

        $overallAverage = $progresses->isEmpty()
            ? 0.0
            : round($progresses->avg('mastery_percentage'), 2);

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