<?php

namespace Tests\Feature\Security;

use Illuminate\Mail\Transport\ResendTransport;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Todo el correo sale por Resend; SMTP no existe.
 *
 * DigitalOcean bloquea los puertos SMTP (25/465/587). Un SMTP bloqueado no falla: se cuelga hasta
 * que el worker mata el job, y las claves temporales nunca llegan. Por eso el mailer `smtp` y sus
 * variantes no están definidos, y esta prueba impide que alguien los vuelva a añadir.
 */
class MailSoloResendTest extends TestCase
{
    public function test_los_unicos_mailers_son_resend_log_y_array(): void
    {
        $this->assertEqualsCanonicalizing(['resend', 'log', 'array'], array_keys(config('mail.mailers')));

        foreach (config('mail.mailers') as $nombre => $mailer) {
            $this->assertNotContains($mailer['transport'], ['smtp', 'sendmail', 'failover', 'roundrobin', 'ses', 'postmark'], "El mailer «{$nombre}» no puede ser SMTP.");
        }
    }

    public function test_pedir_smtp_se_rechaza_en_vez_de_intentar_enviar(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Mail::mailer('smtp');
    }

    public function test_resend_es_el_transporte_de_produccion(): void
    {
        config(['services.resend.key' => 're_prueba']);

        $this->assertInstanceOf(ResendTransport::class, Mail::mailer('resend')->getSymfonyTransport());
    }

    public function test_la_configuracion_no_menciona_variables_smtp(): void
    {
        $codigo = file_get_contents(config_path('mail.php'));

        // Se ignoran los comentarios: se documenta por qué no hay SMTP, y eso nombra la palabra.
        $sinComentarios = preg_replace(['!/\*.*?\*/!s', '!//[^\n]*!'], '', $codigo);

        foreach (['MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_SCHEME', "'smtp'"] as $rastro) {
            $this->assertStringNotContainsString($rastro, $sinComentarios, "config/mail.php no debe usar {$rastro}.");
        }
    }
}
