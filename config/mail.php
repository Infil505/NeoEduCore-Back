<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mailer por defecto
    |--------------------------------------------------------------------------
    |
    | TODO el correo de la plataforma sale por Resend (API HTTPS). No hay SMTP: DigitalOcean
    | bloquea los puertos 25, 465 y 587, y un SMTP bloqueado no falla, se cuelga hasta que el
    | worker mata el job. Por eso el mailer `smtp` (y `ses`, `postmark`, `sendmail`, `failover`,
    | `roundrobin`) NO están definidos aquí, y `AppServiceProvider::dejarSoloLosMailersPermitidos()`
    | quita los que el framework trae por defecto: si alguien pone `MAIL_MAILER=smtp`, Laravel lo
    | rechaza en vez de intentar enviar. `MailSoloResendTest` vigila que no vuelvan.
    |
    | Los otros dos existen solo para trabajar sin enviar nada:
    |   - `log`   → desarrollo local: el correo queda en el log.
    |   - `array` → tests.
    |
    */

    'default' => env('MAIL_MAILER', 'resend'),

    'mailers' => [

        // Producción. Necesita RESEND_KEY y un remitente (MAIL_FROM_ADDRESS) de un dominio verificado en Resend.
        'resend' => [
            'transport' => 'resend',
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Remitente global
    |--------------------------------------------------------------------------
    |
    | Tiene que pertenecer al dominio verificado en Resend (neoeducore.eduregistro.com);
    | con otro dominio (gmail.com, etc.) Resend rechaza el envío.
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', 'Example'),
    ],

];
