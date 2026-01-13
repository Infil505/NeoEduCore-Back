<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\ExamAttempt;

class ExamAttemptRulesService
{
    public function assertExamIsStartable(Exam $exam): void
    {
        if ($exam->status->value !== 'active') {
            throw new \RuntimeException('El examen no está activo');
        }

        if ($exam->available_from && now()->lt($exam->available_from)) {
            throw new \RuntimeException('El examen aún no está disponible');
        }

        if ($exam->available_until && now()->gt($exam->available_until)) {
            throw new \RuntimeException('El examen ya no está disponible');
        }
    }

    public function assertAttemptsAvailable(Exam $exam, int $usedAttempts): void
    {
        $maxAttempts = (int) ($exam->max_attempts ?? 1);
        if ($usedAttempts >= $maxAttempts) {
            throw new \RuntimeException('Has alcanzado el máximo de intentos permitidos');
        }
    }

    public function assertAttemptIsSubmittable(Exam $exam, ExamAttempt $attempt): void
    {
        if ($attempt->exam_id !== $exam->id) {
            throw new \RuntimeException('Intento no corresponde a este examen');
        }

        if ($attempt->submitted_at) {
            throw new \RuntimeException('Este intento ya fue enviado');
        }
    }
}