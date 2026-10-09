<?php

namespace App\Mail;

use App\Models\Admin\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Symfony\Component\Mime\Email;

/**
 * Correo de **alta de cuenta** con la contraseña temporal.
 *
 * Lleva la contraseña, bien visible, y el enlace para iniciar sesión. Al entrar
 * con ella, el frontend abre la pantalla para elegir una propia.
 *
 * Sin plantilla Blade: el backend es una API y no lleva vistas (la única es el
 * formulario de restablecer contraseña). El cuerpo se arma aquí, en texto plano
 * y en un HTML mínimo, con los datos escapados.
 *
 * Deliberadamente NO es `ShouldQueue`: el que lo manda ya es un job
 * (`EnviarEnlaceDeAlta`), y encolar el Mailable dejaría la contraseña en claro
 * en la tabla `jobs` hasta que alguien lo enviara. Se envía directamente desde
 * el worker.
 */
class ContrasenaTemporalMail extends Mailable
{
    public function __construct(public User $user, public string $temporal)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Tu contraseña temporal de ' . config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->cuerpoHtml());
    }

    /** Parte de texto plano: la que leen los clientes de correo sin HTML. */
    public function build(): static
    {
        return $this->withSymfonyMessage(function (Email $mensaje) {
            $mensaje->text($this->cuerpoTexto());
        });
    }

    public function attachments(): array
    {
        return [];
    }

    private function nombre(): string
    {
        return $this->user->full_name ?: 'Usuario';
    }

    /** Enlace a la pantalla de inicio de sesión: el primer origen de FRONTEND_URL. */
    private function loginUrl(): ?string
    {
        $origen = config('cors.allowed_origins')[0] ?? null;

        return $origen ? rtrim($origen, '/') . '/login' : null;
    }

    private function cuerpoTexto(): string
    {
        $app = config('app.name');
        $login = $this->loginUrl();

        $lineas = [
            "Hola {$this->nombre()},",
            '',
            "Esta es tu contraseña temporal de {$app}:",
            '',
            "    {$this->temporal}",
            '',
        ];

        if ($login) {
            $lineas[] = "Inicia sesión aquí: {$login}";
            $lineas[] = '';
        }

        array_push(
            $lineas,
            'Al iniciar sesión te pediremos que elijas una propia.',
            'No compartas esta contraseña con nadie.',
            '',
            '--',
            $app,
        );

        return implode("\n", $lineas);
    }

    private function cuerpoHtml(): string
    {
        $app      = e(config('app.name'));
        $nombre   = e($this->nombre());
        $temporal = e($this->temporal);
        $boton    = $this->loginUrl()
            ? '<div style="text-align:center;margin:0 0 24px"><a href="' . e($this->loginUrl()) . '" '
                . 'style="display:inline-block;background:#2563eb;color:#ffffff;padding:13px 32px;border-radius:10px;'
                . 'font-size:15px;font-weight:600;text-decoration:none">Iniciar sesión</a></div>'
            : '';

        return <<<HTML
        <div style="background:#f4f7fb;padding:32px 16px;font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif">
            <div style="max-width:440px;margin:0 auto;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 10px 30px rgba(15,23,42,0.10)">
                <div style="background:#0f172a;padding:22px 32px;color:#ffffff;font-size:18px;font-weight:700;letter-spacing:.3px">
                    {$app}
                </div>
                <div style="padding:32px;color:#1f2937">
                    <p style="margin:0 0 6px;font-size:16px">Hola <strong>{$nombre}</strong>,</p>
                    <p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#4b5563">
                        Esta es tu contraseña temporal:
                    </p>

                    <div style="text-align:center;margin:0 0 24px">
                        <span style="display:inline-block;background:#eff6ff;border:2px dashed #93c5fd;border-radius:12px;padding:16px 28px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:28px;font-weight:700;letter-spacing:4px;color:#1d4ed8">{$temporal}</span>
                    </div>

                    {$boton}

                    <p style="margin:0 0 8px;font-size:14px;line-height:1.6;color:#4b5563">
                        Al iniciar sesión te pediremos que elijas una contraseña propia.
                    </p>
                    <p style="margin:0;font-size:13px;line-height:1.6;color:#9ca3af">
                        No compartas esta contraseña con nadie.
                    </p>
                </div>
            </div>
        </div>
        HTML;
    }
}
