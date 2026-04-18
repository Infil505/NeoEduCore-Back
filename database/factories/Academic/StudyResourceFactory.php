<?php

namespace Database\Factories\Academic;

use App\Models\Admin\Institution;
use Illuminate\Database\Eloquent\Factories\Factory;

class StudyResourceFactory extends Factory
{
    protected $model = \App\Models\Academic\StudyResource::class;

    public function definition(): array
    {
        return [
            'institution_id'     => Institution::factory(),
            'title'              => fake()->sentence(5),
            'description'        => fake()->optional()->sentence(),
            'resource_type'      => fake()->randomElement(['video', 'article', 'pdf', 'link']),
            'url'                => fake()->url(),
            'estimated_duration' => fake()->optional()->numberBetween(5, 60),
            'difficulty'         => fake()->randomElement(['basic', 'intermediate', 'advanced']),
            'grade_min'          => fake()->numberBetween(7, 10),
            'grade_max'          => fake()->numberBetween(10, 12),
            'language'           => 'es',
            'created_by'         => null,
        ];
    }
}
