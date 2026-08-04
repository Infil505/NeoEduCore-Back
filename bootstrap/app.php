<?php

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
        | Limitador GLOBAL de IA — protege el flujo de examen.
        |
        | Los throttle existentes de IA son por usuario, así que no acotan el
        | total: cada llamada a OpenAI bloquea un worker de Octane 1-15 s, y con
        | ~40 workers basta una decena de chats simultáneos sostenidos para que
        | no quede ninguno libre para entregar exámenes.
        |
        | Este limitador es de institución (no de usuario) y reserva capacidad:
        | 120/min ≈ 2 req/s ≈ ~4 workers ocupados de media con llamadas de 2 s,
        | dejando el resto para el resto del sistema.
        |
        | OJO: los rate limiters usan el CACHE. Con CACHE_STORE=array cada worker
        | de Octane tiene su propio contador y el límite deja de ser global —
        | usar `file` (un contenedor) o `redis` (varios). Ver .env.example.
        */
        RateLimiter::for('ai-global', function (Request $request) {
            $tenant = $request->user()?->institution_id ?? 'sin-tenant';

            return Limit::perMinute(120)
                ->by("ai-global:{$tenant}")
                ->response(fn () => response()->json([
                    'message' => 'El tutor IA está saturado en este momento. Intenta de nuevo en un minuto.',
                ], 429));
        });

        $middleware->alias([
            'tenant' => \App\Http\Middleware\SetTenantFromAuth::class,
            'role'   => \App\Http\Middleware\RequireRole::class,
        ]);

        // SetTenantFromAuth must run before SubstituteBindings so TenantScoped
        // global scopes are active during route model binding.
        $middleware->prependToGroup('api', \App\Http\Middleware\SetTenantFromAuth::class);
        $middleware->appendToGroup('api', \App\Http\Middleware\SecurityHeaders::class);
        $middleware->appendToGroup('web', \App\Http\Middleware\SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
