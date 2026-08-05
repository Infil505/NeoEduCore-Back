<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
        $this->registrarLimitadores();
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

            return Limit::perMinute(120)
                ->by("ai-global:{$tenant}")
                ->response(fn () => response()->json([
                    'message' => 'El tutor IA está saturado en este momento. Intenta de nuevo en un minuto.',
                ], 429));
        });
    }
}
