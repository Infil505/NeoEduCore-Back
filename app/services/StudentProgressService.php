<?php

namespace App\Services;

use App\Models\ExamAttempt;
use App\Models\StudentProgress;

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

        return $this->upsertProgress($studentUserId, $subjectId, (float) $avg);
    }
}