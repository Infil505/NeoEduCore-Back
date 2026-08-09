<?php

namespace App\Services\Exams;

use App\Enums\AdecuacionType;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Students\Student;

class ExamAttemptRulesService
{
    /**
     * Multiplicador de tiempo según adecuación curricular del estudiante (Costa Rica).
     * acceso/evaluacion → tiempo extra; contenido → sin cambio en tiempo.
     */
    private function timeMultiplierFor(?Student $student): float
    {
        return match ($student?->adecuacion_type) {
            AdecuacionType::Acceso    => (float) config('academic.exam.adecuacion.acceso'),
            AdecuacionType::Evaluacion => (float) config('academic.exam.adecuacion.evaluacion'),
            default                   => 1.0,
        };
    }

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

    public function assertAttemptIsSubmittable(Exam $exam, ExamAttempt $attempt, ?Student $student = null): void
    {
        if ($attempt->exam_id !== $exam->id) {
            throw new \RuntimeException('Intento no corresponde a este examen');
        }

        if ($attempt->submitted_at) {
            throw new \RuntimeException('Este intento ya fue enviado');
        }

        if ($exam->duration_minutes && $attempt->started_at) {
            $multiplier  = $this->timeMultiplierFor($student);
            $adjustedMin = (int) ceil($exam->duration_minutes * $multiplier);

            // Descontar tiempo acumulado en pausas + 30 s de gracia para latencia
            $pausedSoFar = (int) ($attempt->total_paused_seconds ?? 0);
            if ($attempt->paused_at) {
                $pausedSoFar += now()->diffInSeconds($attempt->paused_at);
            }
            $deadline = $attempt->started_at->copy()
                ->addMinutes($adjustedMin)
                ->addSeconds($pausedSoFar)
                ->addSeconds((int) config('academic.exam.grace_seconds'));
            if (now()->gt($deadline)) {
                throw new \RuntimeException('El tiempo del examen ha expirado');
            }
        }
    }
}