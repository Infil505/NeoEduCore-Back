<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public $token;
    public $user;
    public $resetUrl;

    /**
     * Create a new message instance.
     */
    public function __construct(string $token, User $user)
    {
        $this->token = $token;
        $this->user = $user;
        
        // Construir la URL de reset (ajustar según tu configuración)
        $frontendUrl = config('app.frontend_url', config('app.url'));
        $this->resetUrl = $frontendUrl . '/reset-password?token=' . $token . '&email=' . urlencode($user->email);
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Recuperar Contraseña - ' . config('app.name'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.password-reset',
            with: [
                'resetUrl' => $this->resetUrl,
                'user' => $this->user,
                'appName' => config('app.name')
            ]
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}