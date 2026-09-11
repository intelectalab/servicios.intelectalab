<?php

use App\Http\Controllers\Auth\AuthController;
use Illuminate\Support\Facades\Route;
use App\Modules\Core\Http\Controllers\CustomerController;
use App\Modules\Services\Http\Controllers\ServiceController;
use App\Modules\Services\Http\Controllers\QuotationController;
use App\Modules\Services\Http\Controllers\ContractController;

Route::prefix('v1')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword']);
    Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);

    Route::middleware(['auth:sanctum', 'tenant.context'])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::get('/me/permissions', [AuthController::class, 'permissions']);
        Route::post('/logout', [AuthController::class, 'logout']);

        Route::middleware('permission:core.view')->group(function () {
            Route::get('/customers', [CustomerController::class, 'index']);
            Route::get('/customers/{customer}', [CustomerController::class, 'show']);
        });

        Route::middleware('permission:core.manage')->post('/customers', [CustomerController::class, 'store']);

        // Servicios
        Route::middleware('permission:services.view')->group(function () {
            Route::get('/services', [ServiceController::class, 'index']);
            Route::get('/services/{service}', [ServiceController::class, 'show']);
        });
        Route::middleware('permission:services.manage')->group(function () {
            Route::post('/services', [ServiceController::class, 'store']);
            Route::post('/services/{service}/activate', [ServiceController::class, 'activate']);
        });

        // Cotizaciones
        Route::middleware('permission:quotations.view')->group(function () {
            Route::get('/quotations', [QuotationController::class, 'index']);
            Route::get('/quotations/{quotation}', [QuotationController::class, 'show']);
        });
        Route::middleware('permission:quotations.manage')->group(function () {
            Route::post('/quotations', [QuotationController::class, 'store']);
            Route::post('/quotations/{quotation}/submit', [QuotationController::class, 'submit']);
            Route::post('/quotations/{quotation}/approve', [QuotationController::class, 'approve']);
            Route::post('/quotations/{quotation}/reject', [QuotationController::class, 'reject']);
        });

        // Contratos
        Route::middleware('permission:contracts.view')->group(function () {
            Route::get('/contracts', [ContractController::class, 'index']);
            Route::get('/contracts/{contract}', [ContractController::class, 'show']);
        });
        Route::middleware('permission:contracts.manage')->group(function () {
            Route::post('/quotations/{quotation}/contract', [ContractController::class, 'generateFromQuotation']);
            Route::post('/contracts/{contract}/activate', [ContractController::class, 'activate']);
        });

        Route::middleware('permission:users.view')->get('/test/users-permission', function () {
            return response()->json(['message' => 'Tienes permiso para ver usuarios.']);
        });

        // Ruta de prueba: requiere un permiso que tu usuario NO tiene
        Route::middleware('permission:system.admin')->get('/test/no-permission', function () {
            return response()->json(['message' => 'No deberías poder ver esto.']);
        });
    });
});