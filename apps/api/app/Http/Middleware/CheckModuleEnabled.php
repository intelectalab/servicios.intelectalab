<?php

namespace App\Http\Middleware;

use App\Modules\Subscription\Support\TenantModules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckModuleEnabled
{
    public function handle(Request $request, Closure $next, string $moduleCode): Response
    {
        $tenantId = $request->attributes->get('tenant_id');

        // Un módulo está disponible si es obligatorio en el catálogo o si el
        // tenant lo tiene encendido; sin fila explícita queda desactivado.
        if (! $tenantId || ! TenantModules::isEnabled($tenantId, $moduleCode)) {
            return response()->json([
                'message' => "El módulo '{$moduleCode}' está desactivado para este tenant.",
            ], 403);
        }

        return $next($request);
    }
}
