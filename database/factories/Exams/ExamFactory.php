<?php

namespace Database\Factories\Exams;

use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Academic\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExamFactory extends Factory
{
    protected $model = \App\Models\Exams\Exam::class;

    public function definition(): array
    {
        return [
            'institution_id'              => Institution::factory(),
            'created_by_teacher_id'       => User::factory()->teacher(),
            'title'                       => fake()->sentence(5),
            'subject_id'                  => Subject::factory(),
            'grade'                       => fake()->numberBetween(7, 12),
            'instructions'                => fake()->optional()->sentence(),
            'duration_minutes'            => fake()->randomElement([30, 45, 60, 90]),
            'status'                      => 'draft',
            'max_attempts'                => 3,
            'show_results_immediately'    => true,
            'allow_review_after_submission' => true,
            'randomize_questions'         => false,
            'available_from'              => null,
            'available_until'             => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => 'active']);
    }

    public function published(): static
    {
        return $this->state(fn () => ['status' => 'published']);
    }
}
