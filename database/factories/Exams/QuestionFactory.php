<?php

namespace Database\Factories\Exams;

use App\Models\Admin\Institution;
use App\Models\Exams\Exam;
use Illuminate\Database\Eloquent\Factories\Factory;

class QuestionFactory extends Factory
{
    protected $model = \App\Models\Exams\Question::class;

    public function definition(): array
    {
        return [
            'institution_id'      => Institution::factory(),
            'exam_id'             => Exam::factory(),
            'question_text'       => fake()->sentence() . '?',
            'question_type'       => 'multiple_choice',
            'points'              => fake()->randomElement([1, 2, 5]),
            'correct_answer_text' => null,
            'order_index'         => 1,
        ];
    }

    public function trueFalse(): static
    {
        return $this->state(fn () => ['question_type' => 'true_false']);
    }

    public function shortAnswer(): static
    {
        return $this->state(fn () => [
            'question_type'       => 'short_answer',
            'correct_answer_text' => fake()->word(),
        ]);
    }
}
