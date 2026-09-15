<?php

namespace Tests\Feature\Auth;

use App\Mail\PasswordSetupMail;
use App\Models\Admin\User;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * `php artisan superadmin:create` — la única vía para dar de alta un operador
 * de plataforma. No hay ruta de API que lo haga, así que este comando es la
 * superficie más privilegiada del sistema y conviene fijarla con pruebas.
 */
class SuperAdminCreateCommandTest extends TestCase
{
    public function test_creates_an_active_superadmin_with_a_password(): void
    {
        $this->artisan('superadmin:create', [
            '--email'    => 'ops@ejemplo.com',
            '--name'     => 'Operaciones',
            '--password' => 'Abcdefg1',
        ])->assertSuccessful();

        $this->assertDatabaseHas('users', [
            'email'          => 'ops@ejemplo.com',
            'user_type'      => 'superadmin',
            'status'         => 'active',
            'institution_id' => null,
        ]);
    }

    /**
     * Con `--send-setup-link` no existe contraseña en ningún momento: la cuenta
     * nace inactiva y la activa su dueño desde el enlace.
     */
    public function test_send_setup_link_creates_the_account_inactive(): void
    {
        Mail::fake();

        $this->artisan('superadmin:create', [
            '--email'           => 'porenlace@ejemplo.com',
            '--name'            => 'Por Enlace',
            '--send-setup-link' => true,
        ])->assertSuccessful();

        $this->assertDatabaseHas('users', [
            'email'          => 'porenlace@ejemplo.com',
            'user_type'      => 'superadmin',
            'status'         => 'inactive',
            'institution_id' => null,
        ]);
    }

    /**
     * La guarda que evita el correo inservible: con `APP_URL` local, el enlace
     * se armaría con `url()` apuntando a localhost. La cuenta se crea igual
     * —eso no depende de la URL— pero no se manda nada.
     *
     * En la suite `APP_URL` no está definida, así que es exactamente el caso.
     */
    public function test_no_email_is_sent_when_app_url_is_local(): void
    {
        Mail::fake();
        config(['app.url' => 'http://localhost']);

        $this->artisan('superadmin:create', [
            '--email'           => 'sinenlace@ejemplo.com',
            '--name'            => 'Sin Enlace',
            '--send-setup-link' => true,
        ])->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'sinenlace@ejemplo.com']);

        Mail::assertNothingQueued();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'sinenlace@ejemplo.com']);
    }

    /** Con una APP_URL real sí se encola el enlace. */
    public function test_the_link_is_queued_when_app_url_is_real(): void
    {
        Mail::fake();
        config(['app.url' => 'https://neoeducore.ejemplo.com']);

        $this->artisan('superadmin:create', [
            '--email'           => 'conenlace@ejemplo.com',
            '--name'            => 'Con Enlace',
            '--send-setup-link' => true,
        ])->assertSuccessful();

        Mail::assertQueued(PasswordSetupMail::class, 1);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'conenlace@ejemplo.com']);
    }

    public function test_rejects_a_duplicate_email(): void
    {
        User::factory()->admin()->create(['email' => 'ocupado@ejemplo.com']);

        $this->artisan('superadmin:create', [
            '--email'    => 'ocupado@ejemplo.com',
            '--name'     => 'Duplicado',
            '--password' => 'Abcdefg1',
        ])->assertFailed();

        $this->assertSame(0, User::where('email', 'ocupado@ejemplo.com')
            ->where('user_type', 'superadmin')
            ->count());
    }

    public function test_rejects_a_weak_password(): void
    {
        $this->artisan('superadmin:create', [
            '--email'    => 'debil@ejemplo.com',
            '--name'     => 'Contraseña Débil',
            '--password' => 'abc',
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'debil@ejemplo.com']);
    }
}
