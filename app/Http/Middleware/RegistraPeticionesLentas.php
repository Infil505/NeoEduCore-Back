<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deja en el log (`storage/logs`) las peticiones que tardan más de `SLOW_REQUEST_MS`
 * y dice DÓNDE se fue el tiempo: en la base de datos (suma de sus consultas, con la
 * más lenta) o en PHP (arranque, serialización…).
 *
 * Existe para explicar los picos aislados que no se reproducen a mano (una petición
 * de 2 s que de pronto tarda 20 s): sin saber si fue la base, la conexión o la
 * propia aplicación no hay por dónde empezar. Con `SLOW_REQUEST_MS=0` (lo normal) no
 * hace nada ni instala escuchas, así que no cuesta en producción.
 *
 * `inicio` sale de `REQUEST_TIME_FLOAT` (el momento en que el servidor recibió la
 * petición), no de cuando arrancó este middleware: así el arranque de Laravel cuenta
 * como PHP y no se pierde.
 */
class RegistraPeticionesLentas
{
    public function handle(Request $request, Closure $next): Response
    {
        $umbral = (int) config('app.slow_request_ms');

        if ($umbral <= 0) {
            return $next($request);
        }

        $consultas = [];
        DB::listen(function ($consulta) use (&$consultas) {
            $consultas[] = ['ms' => round($consulta->time, 1), 'sql' => mb_substr(preg_replace('/\s+/', ' ', $consulta->sql), 0, 110)];
        });

        $respuesta = $next($request);

        $inicio = $request->server('REQUEST_TIME_FLOAT') ?: (defined('LARAVEL_START') ? LARAVEL_START : microtime(true));
        $totalMs = (microtime(true) - (float) $inicio) * 1000;

        if ($totalMs >= $umbral) {
            $dbMs = array_sum(array_column($consultas, 'ms'));
            usort($consultas, fn ($a, $b) => $b['ms'] <=> $a['ms']);

            Log::warning('Petición lenta', [
                'ruta'        => $request->method() . ' /' . $request->path(),
                'estado'      => $respuesta->getStatusCode(),
                'total_ms'    => round($totalMs),
                'base_ms'     => round($dbMs),
                'php_ms'      => round($totalMs - $dbMs),
                'consultas'   => count($consultas),
                'mas_lenta'   => $consultas[0] ?? null,
            ]);
        }

        return $respuesta;
    }
}
