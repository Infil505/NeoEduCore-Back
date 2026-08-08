<?php

namespace Tests\Feature\Auth;

use App\Models\Admin\Institution;
use App\Models\Admin\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Límite de intentos en `POST /api/auth/login`.
 *
 * Hasta el 07/08/2026 este endpoint **no tenía ningún tope**, siendo el más
 * atacado de cualquier sistema. Con `BCRYPT_ROUNDS=10` cada intento cuesta
 * ~100 ms de CPU, así que servía tanto para fuerza bruta contra las contraseñas
 * —elegidas por niños de primaria— como para agotar los ~40 workers de Octane
 * obligándolos a calcular hashes.
 *
 * Los límites viven en `config/rate_limits.php`.
 */
class LoginThrottleTest extends TestCase
{
    /** Correo único: la suite no resetea la BD entre tests. */
    private function correo(string $etiqueta): string
    {
        return $etiqueta . '-' . uniqid() . '@mail.test';
    }

    private function usuario(string $email): User
    {
        return User::factory()->create([
            'institution_id' => Institution::factory()->create()->id,
            'email'          => $email,
            'password_hash'  => Hash::make('Correcta123'),
            'status'         => 'active',
        ]);
    }

    /** @return array<int,int> códigos de respuesta de cada intento */
    private function intentar(string $email, int $veces, string $password = 'Incorrecta1'): array
    {
        $codigos = [];

        for ($i = 0; $i < $veces; $i++) {
            $codigos[] = $this->postJson('/api/auth/login', [
                'email'    => $email,
                'password' => $password,
            ])->status();
        }

        return $codigos;
    }

    public function test_brute_force_against_one_account_is_blocked(): void
    {
        config(['rate_limits.login' => 3]);

        $user = $this->usuario($this->correo('victima'));

        $codigos = $this->intentar($user->email, 5);

        // Los 3 primeros llegan al controlador (401 credenciales inválidas),
        // el resto los corta el limitador antes de calcular ningún bcrypt.
        $this->assertSame([401, 401, 401, 429, 429], $codigos);
    }

    public function test_the_block_is_per_account_so_one_user_cannot_lock_out_another(): void
    {
        config(['rate_limits.login' => 3]);

        $victima = $this->usuario($this->correo('victima'));
        $otro    = $this->usuario($this->correo('otro'));

        $this->intentar($victima->email, 4);   // agota el cupo de esa cuenta

        // La otra cuenta sigue entrando con normalidad: la clave del limitador
        // incluye el correo, así que nadie puede dejar fuera a un alumno
        // gastándole el cupo justo antes de un examen.
        $this->postJson('/api/auth/login', [
            'email'    => $otro->email,
            'password' => 'Correcta123',
        ])->assertOk();
    }

    public function test_a_correct_login_still_works_within_the_limit(): void
    {
        $user = $this->usuario($this->correo('normal'));

        $this->postJson('/api/auth/login', [
            'email'    => $user->email,
            'password' => 'Correcta123',
        ])->assertOk();
    }

    /**
     * El pico real de este sistema es un aula entera entrando a la vez desde la
     * IP del centro. El tope por IP tiene que dejarlas pasar.
     */
    public function test_a_whole_classroom_behind_one_ip_can_log_in(): void
    {
        config(['rate_limits.login' => 5, 'rate_limits.login_ip' => 60]);

        for ($i = 0; $i < 30; $i++) {
            $alumno = $this->usuario($this->correo("alumno{$i}"));

            $this->postJson('/api/auth/login', [
                'email'    => $alumno->email,
                'password' => 'Correcta123',
            ])->assertOk("El alumno {$i} del aula fue bloqueado por el límite de IP");
        }
    }
}
