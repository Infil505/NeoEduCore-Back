<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Una cuenta con contraseña temporal (`must_change_password`) solo puede ver su
 * sesión, cerrarla y cambiar la contraseña. Todo lo demás responde 403 con el
 * código `password_change_required`, que el frontend usa para llevarla a la
 * pantalla de cambio obligatorio.
 *
 * Va tras `auth:sanctum` y `activa`. Se decide por NOMBRE de ruta, no por URL:
 * así un alias o un prefijo nuevo no abre ni cierra nada sin querer.
 */
class ExigeCambioDeClave
{
    /** Rutas que una cuenta con clave temporal SÍ puede usar. */
    private const PERMITIDAS = ['me', 'logout', 'password.change'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password && ! in_array($request->route()?->getName(), self::PERMITIDAS, true)) {
            return response()->json([
                'message' => 'Debes cambiar tu contraseña temporal antes de continuar.',
                'code'    => 'password_change_required',
            ], 403);
        }

        return $next($request);
    }
}
