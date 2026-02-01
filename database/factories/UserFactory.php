<?php

namespace Database\Factories;

use App\Models\Institution;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

class UserFactory extends Factory
{
    protected $model = \App\Models\User::class;

    public function definition(): array
    {
        return [
            'institution_id' => Institution::factory(), // ✅ crea y asigna una institución real (uuid)
            'email' => fake()->unique()->safeEmail(),
            'password_hash' => Hash::make('Abcdefg1'),
            'full_name' => fake()->name(),
            'user_type' => 'teacher',
            'status' => 'active',
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['user_type' => 'admin']);
    }

    public function teacher(): static
    {
        return $this->state(fn () => ['user_type' => 'teacher']);
    }

    public function student(): static
    {
        return $this->state(fn () => ['user_type' => 'student']);
    }
}