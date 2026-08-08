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
 * Correo de **recuperación** de contraseña: lo pide el propio usuario desde
 * «olvidé mi contraseña».
 *
 * No confundir con `PasswordSetupMail`, que es el del alta de cuenta. Antes los
 * dos flujos compartían este Mailable y el texto solo servía para el alta: quien
 * pedía recuperar su contraseña recibía un correo diciéndole que se acababa de
 * crear su cuenta, contradiciendo además al propio asunto.
 */
class PasswordResetMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public string $resetUrl;

    public function __construct(public string $token, public User $user)
    {
        $this->resetUrl = url('/password/reset/' . $token) . '?email=' . urlencode($user->email);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Recuperar tu contraseña · ' . config('app.name'),
        );
    }

    public function content(): Content
    {
        $datos = [
            'resetUrl' => $this->resetUrl,
            'user'     => $this->user,
            'appName'  => config('app.name'),
            // Se anuncia el plazo REAL configurado, no una cifra fija:
            // si alguien cambia AUTH_PASSWORD_RESET_EXPIRE_MINUTES, el
            // correo no se queda diciendo otra cosa.
            'horas'    => (int) round(\App\Http\Controllers\Auth\ForgotPasswordController::minutosDeVigencia() / 60),
        ];

        // `text:` añade la parte en texto plano del multipart. Sin ella el correo
        // va solo en HTML, lo que penaliza la entregabilidad y deja sin contenido
        // a los clientes que bloquean HTML.
        return new Content(
            view: 'emails.password-reset',
            text: 'emails.password-reset-text',
            with: $datos,
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
