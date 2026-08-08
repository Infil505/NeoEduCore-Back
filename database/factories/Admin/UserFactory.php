<?php

namespace Database\Factories\Admin;

use App\Models\Admin\Institution;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

class UserFactory extends Factory
{
    protected $model = \App\Models\Admin\User::class;

    /** Contador de proceso: ver comentario en `definition()`. */
    private static int $secuencia = 0;

    public function definition(): array
    {
        // El unique() de Faker se resetea en CADA test (el contenedor se
        // reconstruye), pero los datos persisten en toda la suite: el schema se
        // carga una sola vez. Con safeEmail() (pool acotado) eso daba colisiones
        // intermitentes de la unique de email. Un contador de proceso no colisiona.
        $n = ++self::$secuencia;

        return [
            'institution_id' => Institution::factory(), // ✅ crea y asigna una institución real (uuid)
            'email' => fake()->userName() . ".{$n}@example.test",
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

    /**
     * Operador de la plataforma. Sin `institution_id` a propósito: es lo que lo
     * deja fuera de los datos académicos de cualquier centro.
     */
    public function superAdmin(): static
    {
        return $this->state(fn () => [
            'user_type'      => 'superadmin',
            'institution_id' => null,
        ]);
    }
}