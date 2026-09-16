<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deja constancia de que lo que corre es una petición HTTP.
 *
 * Existe por `TenantScoped`, que ante un modelo consultado **sin tenant** tiene
 * que decidir entre lanzar (en HTTP es un bug: significa devolver filas de todas
 * las instituciones) y dejar pasar (en consola es lo normal: migraciones,
 * seeders, tests, comandos).
 *
 * Esa decisión la tomaba `app()->runningInConsole()`, que mira `PHP_SAPI`. Y ahí
 * estaba la trampa: **Octane arranca desde la línea de comandos**. Con
 * FrankenPHP —lo que fija hoy el `Dockerfile`— el SAPI no es `cli` y todo
 * funciona; con `--server=swoole` o `--server=roadrunner` **sí lo es**, y
 * entonces cada petición HTTP pasaría por la rama de consola: el trait dejaría
 * de lanzar y empezaría a devolver, en silencio, datos de otras instituciones.
 * Una palabra en el `CMD` separaba fallar cerrado de fallar abierto, y el `CMD`
 * se toca justo al desplegar.
 *
 * La marca no se deduce del entorno: la pone quien sabe la respuesta. Va en el
 * stack **global** —no en el grupo `api`— para que valga también en las rutas
 * `web`, donde hoy no se consulta ningún modelo acotado pero mañana puede.
 *
 * Si esta marca se filtrara entre peticiones de Octane, el efecto sería exigir
 * tenant de más, nunca de menos: falla hacia el lado seguro.
 */
class MarcaContextoHttp
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->instance('contexto_http', true);

        return $next($request);
    }
}
