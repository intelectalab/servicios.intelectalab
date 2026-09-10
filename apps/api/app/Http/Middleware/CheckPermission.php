<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permissionCode): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasPermission($permissionCode)) {
            return response()->json([
                'message' => "No tienes el permiso requerido: {$permissionCode}",
            ], 403);
        }

        return $next($request);
    }
}