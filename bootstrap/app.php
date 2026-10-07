<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Los rate limiters con nombre (p. ej. 'ai-global') se registran en
        // AppServiceProvider::boot(): aquí el facade root aún no está fijado.
        $middleware->alias([
            'tenant' => \App\Http\Middleware\SetTenantFromAuth::class,
            'role'   => \App\Http\Middleware\RequireRole::class,
            'activa' => \App\Http\Middleware\EnsureAccountIsActive::class,
        ]);

        // Global, y lo primero de todo: marca que esto es HTTP para que
        // `TenantScoped` no tenga que deducirlo de `PHP_SAPI` —que bajo Octane
        // depende del servidor elegido—. Ver `MarcaContextoHttp`.
        $middleware->prepend(\App\Http\Middleware\MarcaContextoHttp::class);

        // SetTenantFromAuth must run before SubstituteBindings so TenantScoped
        // global scopes are active during route model binding.
        $middleware->prependToGroup('api', \App\Http\Middleware\SetTenantFromAuth::class);

        // Fuera el carácter NUL de toda entrada: PostgreSQL no lo admite (500 en el
        // chat del tutor; truncado silencioso en el resto). Ver `RemoveNullBytes`.
        $middleware->appendToGroup('api', \App\Http\Middleware\RemoveNullBytes::class);

        // Red de seguridad para toda la API. Se prepone DESPUÉS del anterior a
        // propósito: cada `prependToGroup` se coloca delante del que ya estaba,
        // así que este acaba siendo el PRIMERO del grupo y rechaza el exceso
        // antes de resolver tenant, autenticación o modelos, que es donde está
        // el coste. Sin esto, cualquier token válido podía martillear un
        // endpoint sin tope y agotar los ~40 workers de Octane.
        // El límite se define en config/rate_limits.php.
        $middleware->prependToGroup('api', 'throttle:api');
        $middleware->appendToGroup('api', \App\Http\Middleware\SecurityHeaders::class);
        $middleware->appendToGroup('web', \App\Http\Middleware\SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         | Mensajes en español para los errores que genera el framework en la API.
         | Sin esto, el frontend mostraba «Unauthenticated.», «Not Found» o «This
         | action is unauthorized.» tal cual. Solo cambia el texto: el código HTTP
         | es el mismo de siempre.
         */
        $enApi = fn (\Illuminate\Http\Request $request) => $request->is('api/*') || $request->expectsJson();
        $json = fn (string $message, int $status) => response()->json(['message' => $message], $status);

        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) use ($enApi, $json) {
            return $enApi($request) ? $json('Tu sesión expiró o no has iniciado sesión.', 401) : null;
        });

        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e, $request) use ($enApi, $json) {
            // Si el controlador ya puso un mensaje propio, se respeta.
            $propio = $e->getMessage() !== '' && $e->getMessage() !== 'This action is unauthorized.';

            return $enApi($request) ? $json($propio ? $e->getMessage() : 'No tienes permiso para hacer esto.', 403) : null;
        });

        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, $request) use ($enApi, $json) {
            return $enApi($request) ? $json('No encontrado.', 404) : null;
        });

        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException $e, $request) use ($enApi, $json) {
            return $enApi($request) ? $json('Operación no permitida en esta ruta.', 405) : null;
        });

        $exceptions->render(function (\Illuminate\Http\Exceptions\ThrottleRequestsException $e, $request) use ($enApi) {
            // Conserva las cabeceras Retry-After / X-RateLimit-* del límite.
            return $enApi($request)
                ? response()->json(['message' => 'Demasiadas solicitudes seguidas. Espera un momento e inténtalo de nuevo.'], 429, $e->getHeaders())
                : null;
        });
    })->create();
