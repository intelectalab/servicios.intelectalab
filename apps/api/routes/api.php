<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use Illuminate\Support\Facades\Route;
use App\Modules\Core\Http\Controllers\CustomerController;
use App\Modules\Core\Http\Controllers\SupplierController;
use App\Modules\Core\Http\Controllers\ContactController;
use App\Modules\Core\Http\Controllers\CustomFieldController;
use App\Modules\Services\Http\Controllers\ServiceController;
use App\Modules\Services\Http\Controllers\QuotationController;
use App\Modules\Services\Http\Controllers\ContractController;
use App\Modules\Operations\Http\Controllers\WorkOrderController;
use App\Modules\Operations\Http\Controllers\TaskController;
use App\Modules\Operations\Http\Controllers\ChecklistController;
use App\Modules\Operations\Http\Controllers\TimeEntryController;
use App\Modules\Operations\Http\Controllers\ResourceController;
use App\Modules\Documents\Http\Controllers\DocumentController;
use App\Modules\Finance\Http\Controllers\InvoiceController;
use App\Modules\Finance\Http\Controllers\PaymentController;
use App\Modules\Support\Http\Controllers\SupportTicketController;
use App\Modules\Credentials\Http\Controllers\CredentialController;
use App\Modules\Crm\Http\Controllers\CrmLeadController;
use App\Modules\Portal\Http\Controllers\PortalController;

Route::prefix('v1')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword']);
    Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);

    Route::middleware(['auth:sanctum', 'tenant.context'])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::get('/me/permissions', [AuthController::class, 'permissions']);
        Route::post('/logout', [AuthController::class, 'logout']);

        // Clientes
        Route::middleware('permission:core.view')->group(function () {
            Route::get('/customers', [CustomerController::class, 'index']);
            Route::get('/customers/template', [CustomerController::class, 'downloadTemplate']);
            Route::get('/customers/{customer}', [CustomerController::class, 'show']);
        });

        Route::middleware('permission:core.view')->get('/customers/{customer}/overview', [CustomerController::class, 'overview']);

        Route::middleware('permission:core.manage')->group(function () {
            // Acciones masivas primero
            Route::post('/customers/bulk-deactivate', [CustomerController::class, 'bulkDeactivate']);
            Route::post('/customers/bulk-activate', [CustomerController::class, 'bulkActivate']);
            Route::post('/customers/import', [CustomerController::class, 'import']);
            
            // Acciones individuales
            Route::post('/customers', [CustomerController::class, 'store']);
            Route::put('/customers/{customer}', [CustomerController::class, 'update']);
            Route::post('/customers/{customer}/deactivate', [CustomerController::class, 'deactivate']);
            Route::post('/customers/{customer}/activate', [CustomerController::class, 'activate']);
        });

        // Campos personalizados
        Route::middleware('permission:core.view')->group(function () {
            Route::get('/custom-fields/definitions', [CustomFieldController::class, 'definitions']);
            Route::get('/custom-fields/values', [CustomFieldController::class, 'values']);
        });
        Route::middleware('permission:core.manage')->group(function () {
            Route::post('/custom-fields/definitions', [CustomFieldController::class, 'storeDefinition']);
            Route::delete('/custom-fields/definitions/{id}', [CustomFieldController::class, 'destroyDefinition']); 
            Route::post('/custom-fields/values', [CustomFieldController::class, 'storeValue']);
        });
        
        // Proveedores
        Route::middleware('permission:core.view')->group(function () {
            Route::get('/suppliers', [SupplierController::class, 'index']);
            Route::get('/suppliers/{supplier}', [SupplierController::class, 'show']);
        });
        Route::middleware('permission:core.manage')->group(function () {
            Route::post('/suppliers', [SupplierController::class, 'store']);
            Route::post('/suppliers/{supplier}/deactivate', [SupplierController::class, 'deactivate']);
        });

        // Contactos
        Route::middleware('permission:core.view')->get('/contacts', [ContactController::class, 'index']);
        Route::middleware('permission:core.manage')->group(function () {
            Route::post('/contacts', [ContactController::class, 'store']);
            Route::delete('/contacts/{contact}', [ContactController::class, 'destroy']);
        });

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

        // Checklists (plantillas)
        Route::middleware('permission:operations.view')->group(function () {
            Route::get('/checklists', [ChecklistController::class, 'index']);
            Route::get('/checklists/{checklist}', [ChecklistController::class, 'show']);
        });
        Route::middleware('permission:operations.manage')->post('/checklists', [ChecklistController::class, 'store']);

        // Órdenes de trabajo
        Route::middleware('permission:operations.view')->group(function () {
            Route::get('/work-orders', [WorkOrderController::class, 'index']);
            Route::get('/work-orders/{workOrder}', [WorkOrderController::class, 'show']);
        });
        Route::middleware('permission:operations.manage')->group(function () {
            Route::post('/work-orders', [WorkOrderController::class, 'store']);
            Route::post('/work-orders/{workOrder}/assign', [WorkOrderController::class, 'assign']);
            Route::post('/work-orders/{workOrder}/start', [WorkOrderController::class, 'start']);
            Route::post('/work-orders/{workOrder}/complete', [WorkOrderController::class, 'complete']);
        });

        // Tareas
        Route::middleware('permission:operations.view')->group(function () {
            Route::get('/tasks', [TaskController::class, 'index']);
            Route::get('/tasks/{task}', [TaskController::class, 'show']);
        });
        Route::middleware('permission:operations.manage')->group(function () {
            Route::post('/tasks', [TaskController::class, 'store']);
            Route::patch('/tasks/{task}/checklist-items/{item}', [TaskController::class, 'completeChecklistItem']);
        });

        // Registro de horas
        Route::middleware('permission:operations.view')->get('/time-entries', [TimeEntryController::class, 'index']);
        Route::middleware('permission:operations.manage')->group(function () {
            Route::post('/time-entries', [TimeEntryController::class, 'store']);
            Route::post('/time-entries/{timeEntry}/approve', [TimeEntryController::class, 'approve']);
        });

        // Recursos
        Route::middleware('permission:operations.view')->get('/resources', [ResourceController::class, 'index']);
        Route::middleware('permission:operations.manage')->group(function () {
            Route::post('/resources', [ResourceController::class, 'store']);
            Route::post('/resources/{resource}/assign', [ResourceController::class, 'assign']);
        });

        // Documentos
        Route::middleware('permission:documents.view')->group(function () {
            Route::get('/documents', [DocumentController::class, 'index']);
            Route::get('/documents/{document}', [DocumentController::class, 'show']);
            Route::get('/documents/{document}/download-url', [DocumentController::class, 'downloadUrl']);
        });
        Route::middleware('permission:documents.manage')->post('/documents', [DocumentController::class, 'store']);

        // Facturacion (interna, sin SUNAT todavia)
        Route::middleware('permission:billing.view')->group(function () {
            Route::get('/invoices', [InvoiceController::class, 'index']);
            Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
        });
        Route::middleware('permission:billing.manage')->post('/invoices', [InvoiceController::class, 'store']);

        // Cuentas por cobrar y pagos
        Route::middleware('permission:billing.view')->get('/receivables', [PaymentController::class, 'receivables']);
        Route::middleware('permission:billing.view')->get('/payments', [PaymentController::class, 'index']);
        Route::middleware('permission:billing.manage')->post('/payments', [PaymentController::class, 'store']);

        // Soporte
        Route::middleware('permission:support.view')->group(function () {
            Route::get('/support-tickets', [SupportTicketController::class, 'index']);
            Route::get('/support-tickets/{ticket}', [SupportTicketController::class, 'show']);
        });
        Route::middleware('permission:support.manage')->group(function () {
            Route::post('/support-tickets', [SupportTicketController::class, 'store']);
            Route::post('/support-tickets/{ticket}/assign', [SupportTicketController::class, 'assign']);
            Route::post('/support-tickets/{ticket}/comments', [SupportTicketController::class, 'addComment']);
            Route::post('/support-tickets/{ticket}/resolve', [SupportTicketController::class, 'resolve']);
            Route::post('/support-tickets/{ticket}/close', [SupportTicketController::class, 'close']);
            Route::post('/support-tickets/{ticket}/reopen', [SupportTicketController::class, 'reopen']);
        });

        // Rutas de prueba para verificar permisos
        Route::middleware('permission:credentials.view')->group(function () {
            Route::get('/credentials', [CredentialController::class, 'index']);
            Route::get('/credentials/{credential}', [CredentialController::class, 'show']);
        });
        Route::middleware('permission:credentials.manage')->group(function () {
            Route::post('/credentials', [CredentialController::class, 'store']);
            Route::post('/credentials/{credential}/rotate', [CredentialController::class, 'rotate']);
        });
        Route::middleware('permission:credentials.reveal')->post('/credentials/{credential}/reveal', [CredentialController::class, 'reveal']);
        Route::middleware('permission:credentials.delete')->post('/credentials/{credential}/revoke', [CredentialController::class, 'revoke']);

        // CRM - protegido por permiso Y por el switch ON/OFF del tenant
        Route::middleware(['module:crm', 'permission:crm.view'])->group(function () {
            Route::get('/crm/leads', [CrmLeadController::class, 'index']);
            Route::get('/crm/leads/{lead}', [CrmLeadController::class, 'show']);
        });
        Route::middleware(['module:crm', 'permission:crm.manage'])->group(function () {
            Route::post('/crm/leads', [CrmLeadController::class, 'store']);
            Route::post('/crm/leads/{lead}/qualify', [CrmLeadController::class, 'qualify']);
            Route::post('/crm/leads/{lead}/convert', [CrmLeadController::class, 'convert']);
        });

        Route::middleware('permission:users.view')->get('/test/users-permission', function () {
            return response()->json(['message' => 'Tienes permiso para ver usuarios.']);
        });

        // Ruta de prueba: requiere un permiso que tu usuario NO tiene
        Route::middleware('permission:system.admin')->get('/test/no-permission', function () {
            return response()->json(['message' => 'No deberías poder ver esto.']);
        });
    });

    // Portal del cliente
    Route::prefix('portal')->middleware(['auth:sanctum', 'tenant.context', 'portal.user'])->group(function () {
        Route::get('/dashboard', [PortalController::class, 'dashboard']);
        Route::get('/requests', [PortalController::class, 'requests']);
        Route::post('/requests', [PortalController::class, 'storeRequest']);
    });
});