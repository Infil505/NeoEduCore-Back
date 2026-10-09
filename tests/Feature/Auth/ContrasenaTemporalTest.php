<?php

namespace Tests\Feature\Auth;

use App\Domain\Auth\ContrasenaTemporal;
use App\Domain\Auth\PasswordPolicy;
use App\Jobs\EnviarEnlaceDeAlta;
use App\Mail\ContrasenaTemporalMail;
use App\Models\Academic\Group;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Contraseña temporal de las cuentas nuevas (decisión del 09/10/2026).
 *
 * Toda cuenta nueva nace activa con una contraseña temporal aleatoria que le
 * llega por correo, y está obligada a cambiarla en su primer acceso: hasta que
 * lo hace, la API solo le deja ver su sesión, cerrarla y cambiar la contraseña.
 */
class ContrasenaTemporalTest extends TestCase
{
    use ApiAuth;

    private function conTemporal(array $extra = []): User
    {
        return User::factory()->create(array_merge([
            'password_hash'        => Hash::make('Temporal2x'),
            'status'               => 'active',
            'must_change_password' => true,
        ], $extra));
    }

    /* ---------- generador ---------- */

    public function test_la_temporal_generada_cumple_la_politica_y_no_se_repite(): void
    {
        $politica = new PasswordPolicy();
        $vistas = [];

        for ($i = 0; $i < 200; $i++) {
            $clave = ContrasenaTemporal::generar();
            $this->assertTrue($politica->isValid($clave), "«{$clave}» no cumple la política.");
            $this->assertSame(1, preg_match('/^[A-HJ-NP-Za-km-z2-9]{10}$/', $clave), "«{$clave}» tiene caracteres ambiguos.");
            $vistas[$clave] = true;
        }

        $this->assertGreaterThan(195, count($vistas), 'Las contraseñas temporales se repiten demasiado.');
    }

    /* ---------- el correo (sin plantilla Blade) ---------- */

    public function test_el_correo_se_arma_sin_vistas_y_escapa_los_datos(): void
    {
        $user = User::factory()->create(['full_name' => '<b>Ana</b> & Co', 'email' => 'ana@ejemplo.com']);
        $correo = new ContrasenaTemporalMail($user, 'Abcd2345xy');

        $html = $correo->render();

        $this->assertStringContainsString('Abcd2345xy', $html);
        // Lleva el enlace para iniciar sesión (primer origen de FRONTEND_URL).
        config(['cors.allowed_origins' => ['https://app.ejemplo.edu/']]);
        $conEnlace = (new ContrasenaTemporalMail($user, 'Abcd2345xy'))->render();
        $this->assertStringContainsString('href="https://app.ejemplo.edu/login"', $conEnlace);
        $this->assertStringContainsString('Iniciar sesión', $conEnlace);
        $this->assertStringContainsString('&lt;b&gt;Ana&lt;/b&gt; &amp; Co', $html);
        $this->assertStringNotContainsString('<b>Ana</b>', $html);
        $this->assertFileDoesNotExist(resource_path('views/emails/contrasena-temporal.blade.php'));
    }

    /* ---------- el job ---------- */

    public function test_el_job_pone_una_temporal_activa_la_cuenta_y_la_manda_por_correo(): void
    {
        Mail::fake();
        $user = User::factory()->create(['status' => 'inactive', 'must_change_password' => false]);

        (new EnviarEnlaceDeAlta($user->id))->handle(app(\App\Services\Auth\PasswordSetupService::class));

        $user->refresh();
        $this->assertSame('active', $user->status->value);
        $this->assertTrue($user->must_change_password);

        Mail::assertSent(ContrasenaTemporalMail::class, function ($m) use ($user) {
            return $m->hasTo($user->email) && Hash::check($m->temporal, $user->password_hash);
        });
    }

    public function test_el_job_no_toca_una_cuenta_cuyo_dueno_ya_eligio_su_clave(): void
    {
        Mail::fake();
        $user = User::factory()->create(['status' => 'active', 'must_change_password' => false, 'password_hash' => Hash::make('MiPropia1x')]);

        (new EnviarEnlaceDeAlta($user->id))->handle(app(\App\Services\Auth\PasswordSetupService::class));

        Mail::assertNothingSent();
        $this->assertTrue(Hash::check('MiPropia1x', $user->fresh()->password_hash));
    }

    public function test_el_job_no_manda_acceso_a_una_cuenta_suspendida(): void
    {
        Mail::fake();
        $user = User::factory()->create(['status' => 'suspended', 'must_change_password' => true]);

        (new EnviarEnlaceDeAlta($user->id))->handle(app(\App\Services\Auth\PasswordSetupService::class));

        Mail::assertNothingSent();
        $this->assertSame('suspended', $user->fresh()->status->value);
    }

    /* ---------- alta individual ---------- */

    public function test_el_alta_individual_sin_clave_genera_la_temporal_y_la_manda(): void
    {
        Mail::fake();
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $res = $this->postJson('/api/register', [
            'full_name' => 'Docente Nuevo',
            'email'     => 'docente.nuevo@ejemplo.com',
            'user_type' => 'teacher',
        ])->assertCreated();

        $res->assertJson(['temporary_password_emailed' => true, 'user' => ['must_change_password' => true, 'status' => 'active']]);

        Mail::assertSent(ContrasenaTemporalMail::class, fn ($m) => $m->hasTo('docente.nuevo@ejemplo.com'));

        // La clave solo viaja por correo: la respuesta de la API no la contiene.
        $enviada = Mail::sent(ContrasenaTemporalMail::class)->first()->temporal;
        $this->assertNotEmpty($enviada);
        $this->assertStringNotContainsString($enviada, $res->getContent());
    }

    public function test_el_alta_individual_con_clave_la_toma_como_temporal_sin_mandar_correo(): void
    {
        Queue::fake();
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $this->postJson('/api/register', [
            'full_name' => 'Docente Dos', 'email' => 'docente.dos@ejemplo.com', 'user_type' => 'teacher',
            'password' => 'Entregada1x', 'password_confirmation' => 'Entregada1x',
        ])->assertCreated()->assertJson(['temporary_password_emailed' => false]);

        $creado = User::where('email', 'docente.dos@ejemplo.com')->first();
        $this->assertTrue($creado->must_change_password);
        $this->assertTrue(Hash::check('Entregada1x', $creado->password_hash));
        Queue::assertNotPushed(EnviarEnlaceDeAlta::class);
    }

    /* ---------- login y bloqueo ---------- */

    public function test_el_login_avisa_de_que_hay_que_cambiar_la_clave(): void
    {
        $user = $this->conTemporal();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Temporal2x'])
            ->assertOk()
            ->assertJsonPath('user.must_change_password', true);
    }

    public function test_con_clave_temporal_solo_se_puede_ver_la_sesion_cerrarla_y_cambiar_la_clave(): void
    {
        $user = $this->conTemporal(['user_type' => 'admin']);
        $token = $user->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.must_change_password', true);

        foreach (['/api/users', '/api/students', '/api/groups', '/api/exams', '/api/notifications', '/api/dashboard/staff-overview'] as $ruta) {
            $this->withToken($token)->getJson($ruta)
                ->assertStatus(403)
                ->assertJsonPath('code', 'password_change_required');
        }

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();
    }

    public function test_cambiar_la_clave_levanta_el_bloqueo(): void
    {
        $user = $this->conTemporal(['user_type' => 'admin']);
        $token = $user->createToken('t')->plainTextToken;

        $this->withToken($token)->postJson('/api/password/change', [
            'current_password'      => 'Temporal2x',
            'password'              => 'MiNueva2026x',
            'password_confirmation' => 'MiNueva2026x',
        ])->assertOk();

        $this->assertFalse($user->fresh()->must_change_password);
        $this->withToken($token)->getJson('/api/notifications')->assertOk();
    }

    public function test_no_se_puede_cambiar_la_temporal_por_la_misma(): void
    {
        $user = $this->conTemporal();
        $token = $user->createToken('t')->plainTextToken;

        $this->withToken($token)->postJson('/api/password/change', [
            'current_password'      => 'Temporal2x',
            'password'              => 'Temporal2x',
            'password_confirmation' => 'Temporal2x',
        ])->assertStatus(422);

        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_una_cuenta_normal_no_se_ve_afectada(): void
    {
        $user = User::factory()->create(['user_type' => 'admin', 'status' => 'active', 'must_change_password' => false]);

        $this->withToken($user->createToken('t')->plainTextToken)->getJson('/api/notifications')->assertOk();
    }

    /* ---------- restablecer ---------- */

    public function test_el_administrador_que_restablece_una_clave_entrega_una_temporal(): void
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);
        $docente = User::factory()->create([
            'institution_id' => $institution->id, 'user_type' => 'teacher', 'status' => 'active', 'must_change_password' => false,
        ]);

        $this->patchJson("/api/users/{$docente->id}/reset-password", [
            'password' => 'Entregada1x', 'password_confirmation' => 'Entregada1x',
        ])->assertOk();

        $this->assertTrue($docente->fresh()->must_change_password);
    }
}
