<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Event;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configurarProxiesDeConfianza();
        $this->registrarLimitadores();
        $this->avisarSiLaCacheAnulaLosLimites();
        $this->limitarDuracionDeConsultas();
    }

    /**
     * Hace que `$request->ip()` devuelva la IP real del cliente y no la del
     * proxy que tiene delante.
     *
     * **De esto dependen todos los límites por IP.** En producción la aplicación
     * está detrás de Traefik (lo pone Coolify): sin declarar los proxies de
     * confianza, `$request->ip()` devuelve siempre la IP interna de Traefik y
     * todo el tráfico cae en el mismo cubo de rate limiting. El límite de 60
     * accesos por minuto y por IP, pensado para que un aula entera tras el mismo
     * NAT pueda entrar, pasaría a aplicarse a **todos los usuarios a la vez**:
     * el sistema se bloquearía solo, sin que nadie lo atacara, y a la vez los
     * límites dejarían de distinguir a un atacante del tráfico legítimo.
     *
     * Va aquí y no en `bootstrap/app.php` porque allí las variables de entorno
     * todavía no están cargadas: `env()` devolvería null sin avisar. Los
     * providers arrancan antes de que corra el pipeline de middleware, así que
     * `TrustProxies` ya encuentra el valor puesto.
     *
     * La lista está en `config/trusted_proxies.php`, con el porqué del valor por
     * defecto y qué añadir si se pone Cloudflare delante.
     */
    private function configurarProxiesDeConfianza(): void
    {
        $proxies = config('trusted_proxies.proxies', []);

        if ($proxies !== []) {
            TrustProxies::at($proxies);
        }
    }

    /**
     * Corta en la base cualquier consulta que se pase del tiempo previsto.
     *
     * Sin esto, una consulta pesada retiene un worker de Octane indefinidamente,
     * y como el presupuesto de conexiones a Supabase deja el techo en ~40
     * workers, es la vía más barata de tumbar el servicio: bastan unas pocas
     * peticiones caras simultáneas. Es la red de seguridad que faltaba por
     * debajo de los rate limiters, que acotan el NÚMERO de peticiones pero no
     * lo que cuesta cada una.
     *
     * Detalles que condicionan la implementación:
     *
     * - El DSN de Laravel para PostgreSQL **no admite opciones libpq
     *   arbitrarias** (ver `PostgresConnector::getDsn`), así que no se puede
     *   declarar en `config/database.php` junto al resto de la conexión; hay que
     *   fijarlo al establecerse cada conexión.
     * - `SET` es de sesión, así que solo persiste con el pooler de Supabase en
     *   modo **session** (puerto 5432), que es el que usa el proyecto. En modo
     *   transaction no se mantendría.
     * - **No se aplica en consola**: migraciones, seeders y trabajos en cola
     *   pueden tardar legítimamente mucho más que una petición HTTP, y cortarlas
     *   a media migración sería peor que el problema que se quiere evitar.
     */
    private function limitarDuracionDeConsultas(): void
    {
        $milisegundos = (int) config('database.statement_timeout_ms');

        if ($milisegundos <= 0 || $this->app->runningInConsole()) {
            return;
        }

        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $evento) use ($milisegundos) {
            if ($evento->connection->getDriverName() !== 'pgsql') {
                return;
            }

            $evento->connection->unprepared("SET statement_timeout = {$milisegundos}");
        });
    }

    /**
     * Los rate limiters guardan sus contadores en la caché. Con
     * `CACHE_STORE=array` cada worker de Octane lleva el suyo y **ningún límite
     * es global**: con ~40 workers, un `throttle:5,1` se convierte de hecho en
     * 200/min. Ya pasó una vez (estaba en `array` en el `.env`), y el fallo es
     * invisible: nada se rompe, solo deja de proteger.
     *
     * Fuera de tests, se deja constancia en el log al arrancar.
     */
    private function avisarSiLaCacheAnulaLosLimites(): void
    {
        if ($this->app->runningUnitTests() || config('cache.default') !== 'array') {
            return;
        }

        Log::warning(
            'CACHE_STORE=array: los rate limiters NO son globales entre workers de Octane, '
            . 'así que los límites de peticiones quedan multiplicados por el número de workers. '
            . 'Usar database, file o redis.'
        );
    }

    /**
     * Limitadores de tasa con nombre.
     *
     * Van aquí y NO en `bootstrap/app.php`: dentro de `withMiddleware()` el
     * facade root todavía no está fijado y `RateLimiter::for` revienta con
     * "A facade root has not been set" al servir por HTTP. Los tests no lo
     * detectan porque arrancan la aplicación por otra vía.
     */
    private function registrarLimitadores(): void
    {
        /*
        | Red de seguridad para TODA la API (grupo `api` en bootstrap/app.php).
        |
        | No está para frenar un ataque —de eso se encargan los límites de abajo—
        | sino para que un cliente descontrolado o un bucle en el frontend no
        | agote los ~40 workers de Octane. Por eso es holgado.
        |
        | Se cuenta por usuario cuando hay token y por IP cuando no. Es
        | importante que sea por usuario: un aula entera comparte la IP de salida
        | del centro, y contarlos juntos los dejaría fuera a todos.
        */
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(config('rate_limits.api'))
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn () => response()->json([
                    'message' => 'Demasiadas peticiones. Espera un momento antes de volver a intentarlo.',
                ], 429));
        });

        /*
        | Intentos de acceso.
        |
        | `POST /auth/login` no tenía NINGÚN límite, siendo el endpoint más
        | atacado de cualquier sistema. Con BCRYPT_ROUNDS=10 cada intento cuesta
        | ~100 ms de CPU, así que además de la fuerza bruta era una vía directa
        | de agotamiento: sin tope, un atacante ocupa todos los workers
        | obligándolos a calcular bcrypt.
        |
        | Dos límites a la vez (Laravel aplica ambos):
        |   1. correo + IP, estrecho — frena la fuerza bruta contra una cuenta
        |      sin permitir que un tercero deje fuera a un alumno agotándole el
        |      cupo, porque el atacante gasta el suyo propio.
        |   2. solo IP, holgado — acota a quien pruebe muchas cuentas desde un
        |      mismo sitio, pero deja pasar a un aula entera tras el mismo NAT,
        |      que es justo el pico de este sistema.
        */
        RateLimiter::for('login', function (Request $request) {
            $correo = strtolower(trim((string) $request->input('email')));

            $respuesta = fn () => response()->json([
                'message' => 'Demasiados intentos de acceso. Espera un minuto antes de volver a probar.',
            ], 429);

            return [
                Limit::perMinute(config('rate_limits.login'))
                    ->by('login:' . $correo . '|' . $request->ip())
                    ->response($respuesta),

                Limit::perMinute(config('rate_limits.login_ip'))
                    ->by('login-ip:' . $request->ip())
                    ->response($respuesta),
            ];
        });

        /*
        | Presupuesto GLOBAL de IA — protege el flujo de examen.
        |
        | Los throttle de IA existentes son por usuario, así que no acotan el
        | total: cada llamada a OpenAI bloquea un worker de Octane 1-15 s y con
        | ~40 workers basta una decena de chats simultáneos sostenidos para que
        | no quede ninguno libre para entregar exámenes.
        |
        | Este limitador es de institución y reserva capacidad: 120/min ≈ 2 req/s
        | ≈ ~4 workers ocupados de media con llamadas de 2 s.
        |
        | OJO: los rate limiters usan el CACHE. Con CACHE_STORE=array cada worker
        | de Octane lleva su propio contador y el límite deja de ser global —
        | usar `file` (un contenedor) o `redis` (varios). Ver .env.example.
        */
        RateLimiter::for('ai-global', function (Request $request) {
            $tenant = $request->user()?->institution_id ?? 'sin-tenant';

            return Limit::perMinute(config('rate_limits.ai_global'))
                ->by("ai-global:{$tenant}")
                ->response(fn () => response()->json([
                    'message' => 'El tutor IA está saturado en este momento. Intenta de nuevo en un minuto.',
                ], 429));
        });

        /*
        | Los que antes iban literales en `routes/api.php` como `throttle:5,1`.
        |
        | Se registran como limitadores con nombre —y no se deja el número en la
        | ruta— porque `throttle:N,1` no admite leer de config: el valor se
        | resuelve al construir la ruta y quedaría fijo igualmente.
        |
        | Todos cuentan por usuario autenticado y caen a la IP si no lo hay, que
        | es lo correcto para los públicos (recuperación de contraseña).
        */
        $porUsuarioOIp = fn (Request $request) => $request->user()?->id ?: $request->ip();

        $simples = [
            'password'       => 'Demasiadas peticiones de contraseña. Espera un minuto.',
            'password-verify' => 'Demasiadas verificaciones. Espera un minuto.',
            'bulk-upload'    => 'Demasiadas cargas masivas seguidas. Espera un minuto.',
            'bulk-ops'       => 'Demasiadas operaciones masivas seguidas. Espera un minuto.',
            'ai-chat'        => 'Vas muy rápido con el tutor. Espera un momento.',
            'ai-diagnosis'   => 'Demasiados diagnósticos seguidos. Espera un minuto.',
            'ai-regenerate'  => 'Demasiadas regeneraciones seguidas. Espera un minuto.',
            'ai-generate'    => 'Demasiadas generaciones seguidas. Espera un minuto.',
        ];

        foreach ($simples as $nombre => $mensaje) {
            // El nombre del limitador usa guiones (así se escribe en la ruta);
            // la clave de config usa guiones bajos.
            $clave = str_replace('-', '_', $nombre);

            RateLimiter::for($nombre, function (Request $request) use ($nombre, $clave, $mensaje, $porUsuarioOIp) {
                return Limit::perMinute(config("rate_limits.{$clave}"))
                    ->by($nombre . ':' . $porUsuarioOIp($request))
                    ->response(fn () => response()->json(['message' => $mensaje], 429));
            });
        }
    }
}
