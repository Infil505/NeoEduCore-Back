<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRole
{
    /**
     * Uso: middleware('role:admin,teacher')
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $userRole = $user->user_type->value;

        if (!in_array($userRole, $roles, true)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        return $next($request);
    }
}
