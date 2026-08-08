<?php

namespace App\Mail;

use App\Models\Admin\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Correo de **alta de cuenta**: el usuario no la pidió, se la crearon (carga
 * masiva de estudiantes o alta desde administración) y tiene que establecer su
 * primera contraseña.
 *
 * Usa el mismo mecanismo de token que la recuperación (`password_reset_tokens`),
 * pero el mensaje es distinto y por eso es un Mailable aparte: aquí procede
 * explicar que la cuenta es nueva; en `PasswordResetMail` eso sería falso.
 */
class PasswordSetupMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public string $setupUrl;

    public function __construct(public string $token, public User $user)
    {
        $this->setupUrl = url('/password/reset/' . $token) . '?email=' . urlencode($user->email);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Activá tu cuenta de ' . config('app.name'),
        );
    }

    public function content(): Content
    {
        $datos = [
            'setupUrl' => $this->setupUrl,
            'user'     => $this->user,
            'appName'  => config('app.name'),
            // Se anuncia el plazo REAL configurado, no una cifra fija:
            // si alguien cambia AUTH_PASSWORD_RESET_EXPIRE_MINUTES, el
            // correo no se queda diciendo otra cosa.
            'horas'    => (int) round(\App\Http\Controllers\Auth\ForgotPasswordController::minutosDeVigencia() / 60),
        ];

        return new Content(
            view: 'emails.password-setup',
            text: 'emails.password-setup-text',
            with: $datos,
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
