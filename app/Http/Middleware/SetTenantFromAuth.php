<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetTenantFromAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        // Resolve via sanctum guard so this middleware can run before SubstituteBindings
        // (Sanctum reads the Bearer token directly without needing auth:sanctum to have run)
        $user = auth()->guard('sanctum')->user() ?? $request->user();

        if ($user && $user->institution_id) {
            app()->instance('tenant_id', $user->institution_id);
        }

        return $next($request);
    }
}
