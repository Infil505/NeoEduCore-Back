<?php

namespace Database\Factories\Students;

use App\Models\Admin\Institution;
use App\Models\Admin\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class StudentFactory extends Factory
{
    protected $model = \App\Models\Students\Student::class;

    public function definition(): array
    {
        return [
            'institution_id'         => Institution::factory(),
            'user_id'                => User::factory()->student(),
            'student_code'           => fake()->unique()->bothify('STU-####'),
            'grade'                  => fake()->numberBetween(7, 12),
            'section'                => fake()->randomElement(['A', 'B', 'C']),
            'status'                 => 'active',
            'enrolled_at'            => now(),
            'last_activity_at'       => null,
            'exams_completed_count'  => 0,
            'overall_average'        => 0,
            'birth_date'             => fake()->optional()->date(),
            'parent_name'            => fake()->optional()->name(),
            'parent_email'           => fake()->optional()->safeEmail(),
            'group_code'             => null,
            'adecuacion_type'        => null,
        ];
    }
}
