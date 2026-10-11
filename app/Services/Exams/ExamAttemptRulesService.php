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

    /**
     * Segundos de pausa que se le acreditan al intento: lo ya acumulado más la
     * pausa en curso, **sin pasar de `academic.exam.max_pause_seconds`**.
     *
     * Es la única definición: la usan `resume()` (para guardar) y el plazo de
     * entrega (para calcular), de modo que nunca pueden discrepar.
     */
    public function pausaAcreditada(ExamAttempt $attempt): int
    {
        $tope = max(0, (int) config('academic.exam.max_pause_seconds'));
        $acumulado = min((int) ($attempt->total_paused_seconds ?? 0), $tope);

        if ($attempt->paused_at) {
            // abs(): en Carbon 3 `diffInSeconds` es con signo y `paused_at` está en el pasado.
            $enCurso = (int) abs(now()->diffInSeconds($attempt->paused_at));
            $acumulado = min($acumulado + $enCurso, $tope);
        }

        return $acumulado;
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

        $deadline = $this->plazoDeEntrega($exam, $attempt, $student);
        if ($deadline && now()->gt($deadline)) {
            throw new \RuntimeException('El tiempo del examen ha expirado');
        }
    }

    /**
     * Hasta cuándo se puede entregar un intento: inicio + duración (ajustada por
     * adecuación) + pausas acreditadas + gracia por latencia. Null si el examen no
     * tiene duración. Lo usan el envío y el monitor del docente.
     */
    public function plazoDeEntrega(Exam $exam, ExamAttempt $attempt, ?Student $student = null): ?\Illuminate\Support\Carbon
    {
        if (!$exam->duration_minutes || !$attempt->started_at) {
            return null;
        }

        $adjustedMin = (int) ceil($exam->duration_minutes * $this->timeMultiplierFor($student));

        // La pausa en curso también cuenta, con el mismo tope que al reanudar.
        return $attempt->started_at->copy()
            ->addMinutes($adjustedMin)
            ->addSeconds($this->pausaAcreditada($attempt))
            ->addSeconds((int) config('academic.exam.grace_seconds'));
    }
}