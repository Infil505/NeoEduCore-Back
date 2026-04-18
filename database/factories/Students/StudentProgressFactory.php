<?php

namespace Database\Factories\Students;

use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Academic\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

class StudentProgressFactory extends Factory
{
    protected $model = \App\Models\Students\StudentProgress::class;

    public function definition(): array
    {
        return [
            'institution_id'     => Institution::factory(),
            'student_user_id'    => User::factory()->student(),
            'subject_id'         => Subject::factory(),
            'mastery_percentage' => fake()->randomFloat(2, 0, 100),
        ];
    }
}
