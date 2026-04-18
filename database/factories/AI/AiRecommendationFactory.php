<?php

namespace Database\Factories\AI;

use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Academic\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

class AiRecommendationFactory extends Factory
{
    protected $model = \App\Models\AI\AiRecommendation::class;

    public function definition(): array
    {
        return [
            'institution_id'      => Institution::factory(),
            'student_user_id'     => User::factory()->student(),
            'subject_id'          => Subject::factory(),
            'exam_id'             => null,
            'recommendation_type' => fake()->randomElement(['strength', 'weakness', 'action', 'resource']),
            'recommendation_text' => fake()->sentence(10),
            'resource'            => null,
            'generated_at'        => now(),
        ];
    }
}
