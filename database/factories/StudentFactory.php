<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class StudentFactory extends Factory
{
    protected $model = \App\Models\Student::class;

    public function definition(): array
    {
        return [
            'student_code' => fake()->unique()->bothify('STU-####'),
            'grade' => fake()->numberBetween(1, 12),
            'section' => fake()->randomElement(['A','B','C']),
            'status' => 'active',
            'enrolled_at' => now(),
            'last_activity_at' => now(),
            'exams_completed_count' => 0,
            'overall_average' => 0,
            'birth_date' => fake()->date(),
            'parent_name' => fake()->name(),
            'parent_email' => fake()->unique()->safeEmail(),
            'group_code' => fake()->bothify('GRP-###'),
        ];
    }
}