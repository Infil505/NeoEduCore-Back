<?php

namespace Database\Factories\Students;

use App\Models\Admin\Institution;
use App\Models\Exams\ExamAttempt;
use App\Models\Exams\Question;
use Illuminate\Database\Eloquent\Factories\Factory;

class StudentAnswerFactory extends Factory
{
    protected $model = \App\Models\Students\StudentAnswer::class;

    public function definition(): array
    {
        return [
            'institution_id' => Institution::factory(),
            'attempt_id'     => ExamAttempt::factory(),
            'question_id'    => Question::factory(),
            'answer_text'    => fake()->optional()->word(),
            'is_correct'     => fake()->boolean(),
            'points_awarded' => 0,
            'answered_at'    => now(),
            'review_status'  => 'auto_graded',
        ];
    }
}
