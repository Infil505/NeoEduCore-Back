<?php

namespace Tests\Feature\Mail;

use App\Mail\PasswordResetMail;
use App\Mail\PasswordSetupMail;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use Tests\TestCase;

/**
 * El contenido de los dos correos de contraseña.
 *
 * Los demás tests usan `Mail::fake()`: comprueban que el correo se encola, pero
 * nunca lo renderizan, así que una plantilla rota o un enlace mal armado no los
 * haría fallar. Aquí se renderizan de verdad, en HTML y en texto plano.
 */
class CorreosDeContrasenaTest extends TestCase
{
    private function usuario(): User
    {
        return User::factory()->student()->create([
            'institution_id' => Institution::factory()->create()->id,
            // Único por test (la base acumula filas) y con «+» para comprobar
            // que el enlace codifica el correo.
            'email'          => 'alumno+' . strtolower(\Illuminate\Support\Str::random(8)) . '@ejemplo.com',
        ]);
    }

    public function test_the_recovery_email_carries_a_working_link_and_the_real_expiry(): void
    {
        config(['auth.passwords.users.expire' => 120]);

        $user = $this->usuario();
        $correo = new PasswordResetMail('TOKEN-DE-PRUEBA', $user);

        // El enlace lleva el token y el correo codificado (el «+» no se pierde).
        $enlace = url('/password/reset/TOKEN-DE-PRUEBA') . '?email=' . urlencode($user->email);
        $this->assertSame($enlace, $correo->resetUrl);

        $correo->assertHasSubject('Recuperar tu contraseña · ' . config('app.name'));
        $correo->assertSeeInHtml(e($enlace), false);
        $correo->assertSeeInText($enlace);

        // Anuncia el plazo configurado (120 min = 2 h), no una cifra fija.
        $correo->assertSeeInText('2');
        $this->assertSame(2, $correo->content()->with['horas']);
    }

    public function test_the_setup_email_is_a_different_message_from_the_recovery_one(): void
    {
        $user = $this->usuario();

        $alta = new PasswordSetupMail('TOKEN-ALTA', $user);
        $recuperacion = new PasswordResetMail('TOKEN-ALTA', $user);

        // Mismo mecanismo de token, mensaje distinto: a quien le crearon la
        // cuenta no se le habla de «recuperar» una contraseña que nunca tuvo.
        $this->assertNotSame($alta->envelope()->subject, $recuperacion->envelope()->subject);
        $this->assertNotSame($alta->render(), $recuperacion->render());

        $alta->assertSeeInHtml('TOKEN-ALTA', false);
    }
}
