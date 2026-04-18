<?php

namespace Database\Factories\Academic;

use App\Models\Admin\Institution;
use Illuminate\Database\Eloquent\Factories\Factory;

class GroupFactory extends Factory
{
    protected $model = \App\Models\Academic\Group::class;

    public function definition(): array
    {
        return [
            'institution_id' => Institution::factory(),
            'name'           => fake()->randomElement(['7-A', '8-B', '9-C', '10-A', '11-B']),
            'grade'          => fake()->numberBetween(7, 12),
            'section'        => fake()->randomElement(['A', 'B', 'C']),
            'year'           => now()->year,
            'group_code'     => strtoupper(fake()->bothify('GRP-###')),
            'student_count'  => 0,
        ];
    }
}
