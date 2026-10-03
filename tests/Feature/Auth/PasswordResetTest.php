<?php

namespace Tests\Feature\Auth;

use App\Jobs\EnviarEnlaceRecuperacion;
use App\Models\Admin\User;
use App\Models\Admin\Institution;
use Tests\TestCase;
use Tests\Traits\ApiAuth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class PasswordResetTest extends TestCase
{
    use ApiAuth;

    public function test_request_password_reset(): void
    {
        Mail::fake();

        $institution = Institution::factory()->create();
        $user = User::factory()->create([
            'institution_id' => $institution->id,
            'email' => 'user@mail.com',
        ]);

        $res = $this->postJson('/api/password/forgot', [
            'email' => 'user@mail.com',
        ]);

        $res->assertOk();
    }

    public function test_verify_reset_token(): void
    {
        $institution = Institution::factory()->create();
        $user = User::factory()->create([
            'institution_id' => $institution->id,
        ]);

        // Crear token en la BD
        $token = \Illuminate\Support\Str::random(64);
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make($token),
            'created_at' => now(),
        ]);

        $res = $this->postJson('/api/password/verify', [
            'email' => $user->email,
            'token' => $token,
        ]);

        $res->assertOk();
    }

    public function test_reset_password(): void
    {
        $institution = Institution::factory()->create();
        $user = User::factory()->create([
            'institution_id' => $institution->id,
        ]);

        // Crear token en la BD
        $token = \Illuminate\Support\Str::random(64);
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make($token),
            'created_at' => now(),
        ]);

        $res = $this->postJson('/api/password/reset', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $res->assertOk();
    }

    public function test_change_password_authenticated(): void
    {
        $institution = Institution::factory()->create();
        $user = $this->signInTeacher(['institution_id' => $institution->id]);

        $res = $this->postJson('/api/password/change', [
            'current_password' => 'Abcdefg1',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $res->assertOk();
    }

    /* =====================================================================
     | Caducidad del enlace
     |
     | Regresión de un fallo real: la comprobación era
     | `now()->diffInHours($createdAt) > 24`, y en Carbon 3 (Laravel 12)
     | `diffInHours` devuelve un float CON SIGNO, negativo para fechas
     | pasadas. La condición nunca se cumplía y **los enlaces no caducaban
     | jamás**: se verificó cambiando una contraseña con un token de 30 días.
     |
     | Ninguno de los tests anteriores lo detectaba porque todos usaban
     | tokens recién creados.
     ===================================================================== */

    public function test_expired_token_is_rejected_on_verify(): void
    {
        [$user, $token] = $this->tokenDeHace(now()->subDays(30));

        $this->postJson('/api/password/verify', [
            'email' => $user->email,
            'token' => $token,
        ])->assertStatus(400)->assertJson(['message' => 'El token de reset ha expirado']);
    }

    public function test_expired_token_cannot_change_the_password(): void
    {
        [$user, $token] = $this->tokenDeHace(now()->subDays(30));

        $this->postJson('/api/password/reset', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'NuevaClave123',
            'password_confirmation' => 'NuevaClave123',
        ])->assertStatus(400);

        $user->refresh();
        $this->assertFalse(
            Hash::check('NuevaClave123', $user->password_hash),
            'La contraseña se cambió con un token caducado'
        );

        // El token caducado se limpia al detectarlo
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_token_within_the_window_still_works(): void
    {
        // Justo dentro del plazo: comprueba que el arreglo no caducó de más
        $minutos = \App\Http\Controllers\Auth\ForgotPasswordController::minutosDeVigencia();
        [$user, $token] = $this->tokenDeHace(now()->subMinutes($minutos - 5));

        $this->postJson('/api/password/verify', [
            'email' => $user->email,
            'token' => $token,
        ])->assertOk();
    }

    public function test_expiry_window_comes_from_configuration(): void
    {
        // Con la ventana en 1 hora, un token de 2 horas ya no sirve.
        config(['auth.passwords.users.expire' => 60]);

        [$user, $token] = $this->tokenDeHace(now()->subHours(2));

        $this->postJson('/api/password/verify', [
            'email' => $user->email,
            'token' => $token,
        ])->assertStatus(400);
    }

    public function test_token_is_single_use(): void
    {
        [$user, $token] = $this->tokenDeHace(now());

        $datos = [
            'email' => $user->email,
            'token' => $token,
            'password' => 'NuevaClave123',
            'password_confirmation' => 'NuevaClave123',
        ];

        $this->postJson('/api/password/reset', $datos)->assertOk();
        $this->postJson('/api/password/reset', $datos)->assertStatus(400);
    }

    /* =====================================================================
     | El formulario web al que apunta el correo
     |
     | El enlace del correo lleva a GET /password/reset/{token}, que devolvía
     | `view('auth.reset-password')` — una vista que NO existía. El flujo
     | completo terminaba en un error 500 al pulsar el botón del correo.
     ===================================================================== */

    public function test_email_link_renders_the_reset_form(): void
    {
        [$user, $token] = $this->tokenDeHace(now());

        $this->get("/password/reset/{$token}?email=" . urlencode($user->email))
            ->assertOk()
            ->assertSee('Restablecer contraseña')
            ->assertSee($user->email);
    }

    public function test_email_link_with_expired_token_explains_itself(): void
    {
        [$user, $token] = $this->tokenDeHace(now()->subDays(30));

        // 200 y no 500 ni 403: la página explica qué pasó y cómo pedir otro enlace
        $res = $this->get("/password/reset/{$token}?email=" . urlencode($user->email));

        $res->assertOk()->assertSee('Este enlace ya no es válido');
        $res->assertDontSee('<form', false);
    }

    /* =====================================================================
     | Cada flujo manda su propio correo
     ===================================================================== */

    public function test_forgot_password_sends_the_recovery_email(): void
    {
        Mail::fake();

        $institution = Institution::factory()->create();
        $user = User::factory()->create([
            'institution_id' => $institution->id,
            'email' => 'olvidadizo@mail.com',
            'status' => 'active',
        ]);

        $this->postJson('/api/password/forgot', ['email' => $user->email])->assertOk();

        // El de recuperación, NO el de alta de cuenta: antes se mandaba el
        // mismo Mailable en los dos casos y el texto decía «se creó tu cuenta».
        Mail::assertQueued(\App\Mail\PasswordResetMail::class);
        Mail::assertNotQueued(\App\Mail\PasswordSetupMail::class);
    }

    /**
     * Crea un usuario con un token de reset emitido en la fecha indicada.
     *
     * @return array{0:User,1:string}
     */
    private function tokenDeHace(\Illuminate\Support\Carbon $creadoEn, string $status = 'active'): array
    {
        $institution = Institution::factory()->create();
        $user = User::factory()->create([
            'institution_id' => $institution->id,
            'password_hash'  => Hash::make('Original123'),
            'status'         => $status,
        ]);

        $token = \Illuminate\Support\Str::random(64);

        DB::table('password_reset_tokens')->insert([
            'email'      => $user->email,
            'token'      => Hash::make($token),
            'created_at' => $creadoEn,
        ]);

        return [$user, $token];
    }

    /* =====================================================================
     | Enumeración por temporización en /password/forgot
     |
     | La respuesta siempre fue genérica, pero el TIEMPO delataba: se salía por
     | un `return` temprano cuando el correo no existía y se saltaba el
     | `Hash::make` del token, que es bcrypt y domina el coste. Medido con
     | BCRYPT_ROUNDS=10: 91 ms para un correo registrado frente a 3 ms para uno
     | inexistente. Cronometrando se podía extraer la lista de altas.
     |
     | Igualar el bcrypt dejó un residual de ~13 ms (el INSERT del token y el
     | encolado del correo), así que desde el 03/10/2026 (O6) la petición no
     | hace NADA que dependa de la cuenta: encola `EnviarEnlaceRecuperacion` y
     | responde. Todo lo demás corre en el worker.
     |
     | El test no cronometra (sería inestable según la carga de la máquina):
     | comprueba el MECANISMO, que los dos caminos ejecuten exactamente las
     | mismas consultas y encolen lo mismo.
     ===================================================================== */

    public function test_forgot_password_does_the_same_work_for_known_and_unknown_emails(): void
    {
        Queue::fake();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $institution = Institution::factory()->create();
        $registrado = User::factory()->create([
            'institution_id' => $institution->id,
            'email'          => 'registrado@mail.com',
            'status'         => 'active',
        ]);

        // Calentamiento: la primera petición puede cargar cosas que las demás no.
        $this->postJson('/api/password/forgot', ['email' => 'calentamiento@mail.com']);

        $consultasDe = function (string $email): array {
            $sql = [];
            DB::listen(function ($q) use (&$sql) {
                $sql[] = $q->sql;
            });
            $this->postJson('/api/password/forgot', ['email' => $email])->assertOk();

            return $sql;
        };

        $conCuenta = $consultasDe($registrado->email);
        $sinCuenta = $consultasDe('fantasma@mail.com');

        $this->assertSame(
            $conCuenta,
            $sinCuenta,
            'La petición hace distinto trabajo según exista la cuenta: el tiempo vuelve a delatarla.'
        );

        foreach ($conCuenta as $sql) {
            $this->assertStringNotContainsString('password_reset_tokens', $sql);
            $this->assertDoesNotMatchRegularExpression('/\bfrom "?users"?/i', $sql);
        }

        Queue::assertPushed(EnviarEnlaceRecuperacion::class, fn ($job) => $job->email === $registrado->email);
        Queue::assertPushed(EnviarEnlaceRecuperacion::class, fn ($job) => $job->email === 'fantasma@mail.com');
    }

    public function test_recovery_job_ignores_unknown_emails(): void
    {
        Mail::fake();

        (new EnviarEnlaceRecuperacion('fantasma@mail.com'))->handle();

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'fantasma@mail.com']);
        Mail::assertNothingQueued();
    }

    public function test_forgot_password_answers_the_same_for_unknown_and_suspended_accounts(): void
    {
        Mail::fake();

        $institution = Institution::factory()->create();
        $suspendido = User::factory()->create([
            'institution_id' => $institution->id,
            'email' => 'suspendido@mail.com',
            'status' => 'suspended',
        ]);

        $aDesconocido = $this->postJson('/api/password/forgot', ['email' => 'nadie@mail.com']);
        $aSuspendido  = $this->postJson('/api/password/forgot', ['email' => $suspendido->email]);

        // Mismo código y mismo cuerpo: nada distingue una cuenta de otra.
        $aDesconocido->assertOk();
        $aSuspendido->assertOk();
        $this->assertSame($aDesconocido->json(), $aSuspendido->json());

        // A una cuenta suspendida no se le manda enlace
        Mail::assertNothingQueued();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $suspendido->email]);
    }

    /* =====================================================================
     | Activación de la cuenta
     |
     | Desde el 07/08/2026 una cuenta creada por carga masiva nace INACTIVA y
     | la activa su dueño al definir la contraseña desde el correo. Antes nacía
     | activa: en el panel no había forma de distinguir a quien nunca entró de
     | quien llevaba meses usando la plataforma — y aun así no podía entrar,
     | porque su contraseña era aleatoria.
     ===================================================================== */

    public function test_setting_the_password_activates_the_account(): void
    {
        [$user, $token] = $this->tokenDeHace(now(), 'inactive');

        // Sin activar no puede entrar, aunque acierte la contraseña
        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'Original123',
        ])->assertStatus(403);

        $this->postJson('/api/password/reset', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'MiClaveNueva1',
            'password_confirmation' => 'MiClaveNueva1',
        ])->assertOk();

        $user->refresh();
        $this->assertSame(
            \App\Enums\UserStatus::Active,
            $user->status,
            'Definir la contraseña debe activar la cuenta'
        );

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'MiClaveNueva1',
        ])->assertOk();
    }

    public function test_resetting_does_not_reactivate_a_suspended_account(): void
    {
        [$user, $token] = $this->tokenDeHace(now(), 'suspended');

        $this->postJson('/api/password/reset', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'MiClaveNueva1',
            'password_confirmation' => 'MiClaveNueva1',
        ])->assertOk();

        $user->refresh();

        // La contraseña cambia, pero el bloqueo del administrador se mantiene
        $this->assertTrue(Hash::check('MiClaveNueva1', $user->password_hash));
        $this->assertSame(\App\Enums\UserStatus::Suspended, $user->status);
    }

    public function test_an_unactivated_user_can_request_a_new_link_and_gets_the_setup_email(): void
    {
        Mail::fake();

        $institution = Institution::factory()->create();
        $user = User::factory()->create([
            'institution_id' => $institution->id,
            'email' => 'sinactivar@mail.com',
            'status' => 'inactive',
        ]);

        $this->postJson('/api/password/forgot', ['email' => $user->email])->assertOk();

        // Sin esto, quien perdiera el correo de alta quedaba bloqueado para siempre
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);

        // Recibe «activá tu cuenta», no «recuperar contraseña»: nunca tuvo una
        Mail::assertQueued(\App\Mail\PasswordSetupMail::class);
        Mail::assertNotQueued(\App\Mail\PasswordResetMail::class);
    }

    public function test_a_suspended_user_gets_no_link_at_all(): void
    {
        Mail::fake();

        $institution = Institution::factory()->create();
        $user = User::factory()->create([
            'institution_id' => $institution->id,
            'email' => 'bloqueado@mail.com',
            'status' => 'suspended',
        ]);

        $this->postJson('/api/password/forgot', ['email' => $user->email])->assertOk();

        Mail::assertNothingQueued();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }
}
