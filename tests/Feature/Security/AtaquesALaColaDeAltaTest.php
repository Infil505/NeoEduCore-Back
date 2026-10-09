<?php

namespace Tests\Feature\Security;

use App\Jobs\EnviarEnlaceDeAlta;
use App\Mail\ContrasenaTemporalMail;
use App\Mail\PasswordSetupMail;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * **Cola del enlace de alta (O-J1).** Las cuentas de la carga masiva nacen
 * `inactive` y solo se activan con el enlace del correo. Si ese enlace no sale,
 * la cuenta queda muerta, así que un fallo del envío tiene que ser VISIBLE
 * (el job falla y se reintenta), no tragarse en silencio.
 *
 * Verde = el sistema se defendió; rojo = hallazgo.
 */
class AtaquesALaColaDeAltaTest extends TestCase
{
    use ApiAuth;

    private function subirDocentes(string $csv)
    {
        $file = UploadedFile::fake()->createWithContent('docentes.csv', $csv);

        return $this->withHeaders(['Accept' => 'application/json'])
            ->post('/api/users/bulk-upload', ['role' => 'teacher', 'file' => $file]);
    }

    private function docenteInactivo(): User
    {
        return User::factory()->create([
            'user_type'      => 'teacher',
            'status'         => 'inactive',
            'institution_id' => Institution::factory()->create()->id,
        ]);
    }

    /** J1: si el correo no se puede encolar, el job NO puede terminar «bien». */
    public function test_un_fallo_al_encolar_el_correo_hace_fallar_el_job(): void
    {
        $docente = $this->docenteInactivo();

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('cola caída'));

        $lanzo = false;
        try {
            (new EnviarEnlaceDeAlta($docente->id))->handle(app(\App\Services\Auth\PasswordSetupService::class));
        } catch (\Throwable $e) {
            $lanzo = true;
        }

        $this->assertTrue($lanzo, 'El job terminó sin error aunque el correo no se encoló: no se reintenta ni queda en failed_jobs.');
    }

    /** J1: si el job reintenta, solo vale la ÚLTIMA contraseña temporal enviada; la anterior queda inservible. */
    public function test_el_reintento_deja_una_unica_contrasena_vigente(): void
    {
        Mail::fake();
        $docente = $this->docenteInactivo();

        $job = new EnviarEnlaceDeAlta($docente->id);
        $job->handle(app(\App\Services\Auth\PasswordSetupService::class));
        $job->handle(app(\App\Services\Auth\PasswordSetupService::class));

        Mail::assertSent(ContrasenaTemporalMail::class, 2);

        $enviadas = Mail::sent(ContrasenaTemporalMail::class)->map(fn ($m) => $m->temporal)->values();
        $hash = $docente->fresh()->password_hash;

        $this->assertTrue(\Hash::check($enviadas[1], $hash), 'La última temporal enviada no es la vigente.');
        $this->assertFalse(\Hash::check($enviadas[0], $hash), 'La temporal anterior sigue valiendo tras el reintento.');
    }

    /** J1: un docente cuyo enlace nunca llegó puede pedir uno nuevo él mismo. */
    public function test_docente_inactivo_puede_pedir_otro_enlace(): void
    {
        Mail::fake();
        $docente = $this->docenteInactivo();

        $this->postJson('/api/password/forgot', ['email' => $docente->email])->assertOk();

        Mail::assertQueued(PasswordSetupMail::class, fn ($m) => $m->hasTo($docente->email));
    }

    /** J1: una carga con docentes no activa nada por sí sola. */
    public function test_los_docentes_de_la_carga_no_pueden_entrar_sin_enlace(): void
    {
        Mail::fake();
        $this->signInAdmin(['institution_id' => Institution::factory()->create()->id]);

        $this->subirDocentes("Nombre completo;Correo institucional\nLaura;laura.cola@ejemplo.com\n")->assertOk();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        $this->postJson('/api/auth/login', ['email' => 'laura.cola@ejemplo.com', 'password' => 'Cualquiera1x'])
            ->assertStatus(401);
    }
}
