<?php

namespace Database\Factories\Exams;

use App\Models\Admin\Institution;
use App\Models\Exams\Exam;
use App\Models\Admin\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExamAttemptFactory extends Factory
{
    protected $model = \App\Models\Exams\ExamAttempt::class;

    public function definition(): array
    {
        return [
            'institution_id'   => Institution::factory(),
            'exam_id'          => Exam::factory(),
            'student_user_id'  => User::factory()->student(),
            'attempt_number'   => 1,
            'started_at'       => now()->subMinutes(30),
            'submitted_at'     => null,
            'score'            => 0,
            'max_score'        => 10,
            'grade_status'     => 'pending',
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn () => [
            'submitted_at' => now(),
            'grade_status' => 'completed',
        ]);
    }
}
