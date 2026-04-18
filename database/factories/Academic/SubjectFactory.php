<?php

namespace Database\Factories\Academic;

use App\Models\Admin\Institution;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubjectFactory extends Factory
{
    protected $model = \App\Models\Academic\Subject::class;

    public function definition(): array
    {
        return [
            'institution_id' => Institution::factory(),
            'name' => fake()->randomElement(['Matemáticas', 'Español', 'Ciencias', 'Inglés', 'Historia']),
        ];
    }
}
