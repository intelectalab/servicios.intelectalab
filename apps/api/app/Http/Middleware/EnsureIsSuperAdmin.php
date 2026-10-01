<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIsSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->is_super_admin) {
            return response()->json([
                'message' => 'No autorizado. Se requiere acceso de Super Administrador.'
            ], 403);
        }

        return $next($request);
    }
}