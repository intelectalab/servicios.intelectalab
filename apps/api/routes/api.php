<?php

use App\Http\Controllers\Auth\AuthController;
use Illuminate\Support\Facades\Route;
use App\Modules\Core\Http\Controllers\CustomerController;

Route::prefix('v1')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware(['auth:sanctum', 'tenant.context'])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);

        Route::middleware('permission:core.view')->group(function () {
            Route::get('/customers', [CustomerController::class, 'index']);
            Route::get('/customers/{customer}', [CustomerController::class, 'show']);
        });

        Route::middleware('permission:core.manage')->post('/customers', [CustomerController::class, 'store']);

        Route::middleware('permission:users.view')->get('/test/users-permission', function () {
            return response()->json(['message' => 'Tienes permiso para ver usuarios.']);
        });

        // Ruta de prueba: requiere un permiso que tu usuario NO tiene
        Route::middleware('permission:system.admin')->get('/test/no-permission', function () {
            return response()->json(['message' => 'No deberías poder ver esto.']);
        });
    });
});