<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class CheckModuleEnabled
{
    public function handle(Request $request, Closure $next, string $moduleCode): Response
    {
        $tenantId = $request->attributes->get('tenant_id');

        $moduleRow = DB::table('tenant_modules')
            ->where('tenant_id', $tenantId)
            ->whereRaw('UPPER(module_code) = ?', [strtoupper($moduleCode)])
            ->first();

        // Si no existe una fila explícita, se asume DESACTIVADO por defecto
        // (seguridad por defecto: nada se activa solo).
        $enabled = $moduleRow ? (bool) $moduleRow->enabled : false;

        if (! $enabled) {
            return response()->json([
                'message' => "El módulo '{$moduleCode}' está desactivado para este tenant.",
            ], 403);
        }

        return $next($request);
    }
}