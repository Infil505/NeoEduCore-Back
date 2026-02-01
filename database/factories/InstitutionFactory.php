<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class InstitutionFactory extends Factory
{
    // Si ya tenés el modelo, cambia la clase:
    protected $model = \App\Models\Institution::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('INST-####')),
            'name' => fake()->company(),
            'address' => fake()->address(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->unique()->companyEmail(),
            'is_active' => true,
        ];
    }
}