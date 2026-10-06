<?php

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use App\Models\Admin\Institution;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La sesión solo vale mientras la cuenta —y su institución— sigan activas.
 *
 * Hasta el 05/10/2026 el estado se miraba **solo al iniciar sesión**: una cuenta
 * suspendida conservaba el token que ya tenía y seguía operando —un alumno
 * expulsado podía empezar un examen—, y `institutions.is_active` no lo leía
 * nadie, así que dar de baja un centro no cerraba nada.
 *
 * Va tras `auth:sanctum` en las rutas protegidas. El superadmin no pertenece a
 * ninguna institución y por eso solo se le exige la cuenta activa.
 *
 * El estado de la institución se lee por `Institution::estaActiva()`, que lo
 * cachea y lo invalida al guardarla: no añade una consulta a cada petición.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Sin usuario no hay nada que comprobar: de eso se ocupa `auth:sanctum`.
        if (!$user) {
            return $next($request);
        }

        if ($user->status !== UserStatus::Active) {
            return response()->json(['message' => 'Cuenta inactiva o suspendida'], 403);
        }

        if ($user->institution_id && !Institution::estaActiva($user->institution_id)) {
            return response()->json(['message' => 'La institución está dada de baja'], 403);
        }

        return $next($request);
    }
}
