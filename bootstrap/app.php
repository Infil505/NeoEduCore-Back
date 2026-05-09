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
