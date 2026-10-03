<?php

use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. Typically, these should include your local
    | and production domains which access your API via a frontend SPA.
    |
    */

    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort(),
        // Sanctum::currentRequestHost(),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | This array contains the authentication guards that will be checked when
    | Sanctum is trying to authenticate a request. If none of these guards
    | are able to authenticate the request, Sanctum will use the bearer
    | token that's present on an incoming request for authentication.
    |
    */

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | This value controls the number of minutes until an issued token will be
    | considered expired. This will override any values set in the token's
    | "expires_at" attribute, but first-party sessions are not affected.
    |
    */

    'expiration' => env('SANCTUM_TOKEN_EXPIRATION_MINUTES', 60 * 12),

    /*
    |--------------------------------------------------------------------------
    | Inactividad (decisión D3)
    |--------------------------------------------------------------------------
    |
    | Minutos sin usar un token antes de darlo por caducado. **No es lo mismo
    | que `expiration`**, y la diferencia es justo lo que hacía falta aquí:
    |
    |   - `expiration` cuenta desde que se emitió el token, pase lo que pase.
    |   - esto cuenta desde la última petición hecha con él.
    |
    | El informe [758] exige «expirar tras 60 minutos **de inactividad**». Bajar
    | `expiration` a 60 no es eso: un examen admite hasta 300 minutos
    | (`duration_minutes between:1,300`), así que habría echado al alumno a mitad
    | de la prueba y le habría costado el intento. Con la inactividad medida de
    | verdad, quien está trabajando nunca pierde la sesión y quien deja el equipo
    | del aula abierto la pierde en una hora, que es el riesgo que [758] quiere
    | cubrir: son cuentas de menores en máquinas compartidas.
    |
    | La expiración absoluta se queda en 12 h como tope duro de la jornada.
    |
    | En 0 se desactiva la comprobación.
    */
    'inactivity_minutes' => (int) env('SANCTUM_TOKEN_INACTIVITY_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Sanctum can prefix new tokens in order to take advantage of numerous
    | security scanning initiatives maintained by open source platforms
    | that notify developers if they commit tokens into repositories.
    |
    | See: https://docs.github.com/en/code-security/secret-scanning/about-secret-scanning
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | When authenticating your first-party SPA with Sanctum you may need to
    | customize some of the middleware Sanctum uses while processing the
    | request. You may change the middleware listed below as required.
    |
    */

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],

];
