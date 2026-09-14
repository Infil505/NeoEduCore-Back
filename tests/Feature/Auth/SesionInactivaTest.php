<?php

namespace Tests\Feature\Auth;

use App\Models\Admin\Institution;
use App\Models\Admin\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Caducidad de sesión por inactividad (decisión D3).
 *
 * [758] exige que la sesión expire tras 60 minutos **de inactividad**. Eso no es
 * `sanctum.expiration`, que cuenta desde que se emitió el token: bajarla a 60
 * habría expulsado al alumno a mitad de un examen, que admite hasta 300 minutos.
 *
 * Los dos casos que importan son opuestos y hay que comprobar los dos:
 *
 *  - el token **usado hace un rato** deja de valer aunque no haya llegado al
 *    tope absoluto de 12 h;
 *  - el token de alguien que **sigue trabajando** no caduca por mucho que la
 *    sesión lleve abierta, que es el examen largo.
 *
 * Los tests montan el token con `createToken()` y falsean `last_used_at` en la
 * base: es lo único que decide, y evita depender del reloj del test.
 */
class SesionInactivaTest extends TestCase
{
    use ApiAuth;

    public function test_un_token_sin_usar_mas_de_una_hora_deja_de_valer(): void
    {
        $token = $this->tokenDe($this->usuario(), usadoHaceMinutos: 61);

        $this->withToken($token)
            ->getJson('/api/system/config')
            ->assertStatus(401);
    }

    public function test_un_token_usado_hace_un_momento_sigue_valiendo(): void
    {
        $token = $this->tokenDe($this->usuario(), usadoHaceMinutos: 59);

        $this->withToken($token)
            ->getJson('/api/system/config')
            ->assertOk();
    }

    /**
     * El caso que justifica toda la decisión: una sesión abierta hace cinco
     * horas —más que cualquier examen— en la que se ha estado trabajando.
     * Con la expiración absoluta bajada a 60 minutos esto daría 401.
     */
    public function test_una_sesion_larga_pero_activa_no_caduca(): void
    {
        $user  = $this->usuario();
        $token = $this->tokenDe($user, usadoHaceMinutos: 1);

        // El token se emitió hace cinco horas, pero se ha usado hace un minuto.
        PersonalAccessToken::query()
            ->where('tokenable_id', $user->id)
            ->update(['created_at' => now()->subHours(5)]);

        $this->withToken($token)
            ->getJson('/api/system/config')
            ->assertOk();
    }

    /**
     * Un token recién emitido no tiene `last_used_at`: la cuenta arranca en su
     * creación, no en «nunca», que lo caducaría de inmediato.
     */
    public function test_un_token_recien_emitido_vale_aunque_no_se_haya_usado(): void
    {
        $user  = $this->usuario();
        $token = $user->createToken('test')->plainTextToken;

        PersonalAccessToken::query()
            ->where('tokenable_id', $user->id)
            ->update(['last_used_at' => null]);

        $this->withToken($token)
            ->getJson('/api/system/config')
            ->assertOk();
    }

    public function test_el_tope_absoluto_sigue_mandando_sobre_la_actividad(): void
    {
        $user  = $this->usuario();
        $token = $this->tokenDe($user, usadoHaceMinutos: 1);

        // Más viejo que `sanctum.expiration` (12 h), aunque se acabe de usar.
        PersonalAccessToken::query()
            ->where('tokenable_id', $user->id)
            ->update(['created_at' => now()->subHours(13)]);

        $this->withToken($token)
            ->getJson('/api/system/config')
            ->assertStatus(401);
    }

    public function test_en_cero_la_comprobacion_queda_desactivada(): void
    {
        // El límite se lee en cada comprobación, así que basta con cambiarlo.
        config(['sanctum.inactivity_minutes' => 0]);

        $token = $this->tokenDe($this->usuario(), usadoHaceMinutos: 600);

        $this->withToken($token)
            ->getJson('/api/system/config')
            ->assertOk();
    }

    /* =========================
     | Apoyo
     ========================= */

    private function usuario(): User
    {
        $institution = Institution::factory()->create();

        return User::factory()->admin()->create([
            'institution_id' => $institution->id,
            'password_hash'  => Hash::make('Abcdefg1'),
            'status'         => 'active',
        ]);
    }

    private function tokenDe(User $user, int $usadoHaceMinutos): string
    {
        $token = $user->createToken('test')->plainTextToken;

        DB::table('personal_access_tokens')
            ->where('tokenable_id', $user->id)
            ->update(['last_used_at' => now()->subMinutes($usadoHaceMinutos)]);

        return $token;
    }
}
