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
        ]);

        // SetTenantFromAuth must run before SubstituteBindings so TenantScoped
        // global scopes are active during route model binding.
        $middleware->prependToGroup('api', \App\Http\Middleware\SetTenantFromAuth::class);

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
        //
    })->create();
