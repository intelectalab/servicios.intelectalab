<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant.context' => \App\Http\Middleware\SetTenantContext::class,
            'permission' => \App\Http\Middleware\CheckPermission::class,
            'module' => \App\Http\Middleware\CheckModuleEnabled::class,
            'portal.user' => \App\Http\Middleware\EnsurePortalUser::class,
            'super.admin' => \App\Http\Middleware\EnsureIsSuperAdmin::class,
            'idempotency' => \App\Http\Middleware\Idempotency::class,
            'record.version' => \App\Http\Middleware\CheckRecordVersion::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Un identificador que no tiene forma de UUID no existe: se responde "no encontrado"
        // en vez de dejar que el error de la base llegue como fallo interno.
        $exceptions->render(function (\Illuminate\Database\QueryException $e, \Illuminate\Http\Request $request) {
            if ($e->getCode() === '22P02' && $request->is('api/*')) {
                return response()->json(['message' => 'No encontrado.', 'code' => 'NOT_FOUND'], 404);
            }
        });

        // Dos peticiones a la vez sobre lo mismo (p. ej. convertir dos veces una cotización):
        // la base deja pasar solo una; la otra recibe un aviso claro, no un fallo interno.
        $exceptions->render(function (\Illuminate\Database\UniqueConstraintViolationException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Esta operación ya se había registrado. Actualiza la página.',
                    'code' => 'DUPLICATE_RESOURCE',
                ], 409);
            }
        });
    })->create();
