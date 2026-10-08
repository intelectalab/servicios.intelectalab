<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use Illuminate\Support\Facades\Route;
use App\Modules\Subscription\Http\Controllers\SaasPlanController;
use App\Modules\Subscription\Http\Controllers\SuperAdmin\SuperAdminDashboardController;
use App\Modules\Subscription\Http\Controllers\TenantRegisterController;
use App\Modules\Core\Http\Controllers\CustomerController;
use App\Modules\Core\Http\Controllers\SupplierController;
use App\Modules\Core\Http\Controllers\ContactController;
use App\Modules\Core\Http\Controllers\CustomFieldController;
use App\Modules\Services\Http\Controllers\ServiceController;
use App\Modules\Services\Http\Controllers\ServiceCategoryController;
use App\Modules\Services\Http\Controllers\QuotationController;
use App\Modules\Services\Http\Controllers\ContractController;
use App\Modules\Services\Http\Controllers\OrderController;
use App\Modules\Operations\Http\Controllers\WorkOrderController;
use App\Modules\Operations\Http\Controllers\TaskController;
use App\Modules\Operations\Http\Controllers\ChecklistController;
use App\Modules\Operations\Http\Controllers\TimeEntryController;
use App\Modules\Operations\Http\Controllers\ResourceController;
use App\Modules\Documents\Http\Controllers\DocumentController;
use App\Modules\Finance\Http\Controllers\ExchangeRateController;
use App\Modules\Finance\Http\Controllers\InvoiceController;
use App\Modules\Finance\Http\Controllers\PaymentController;
use App\Modules\Support\Http\Controllers\SupportTicketController;
use App\Modules\Credentials\Http\Controllers\CredentialController;
use App\Modules\Crm\Http\Controllers\CrmLeadController;
use App\Modules\Core\Http\Controllers\PartyController;
use App\Modules\Portal\Http\Controllers\PortalController;
use App\Modules\Portal\Http\Controllers\PortalRequestController;
use App\Modules\Core\Http\Controllers\EmployeeController;
use App\Modules\Core\Http\Controllers\SearchController;
use App\Modules\Core\Http\Controllers\RoleController;
use App\Modules\Core\Http\Controllers\UserController;
use App\Modules\Core\Http\Controllers\TenantSettingsController;

Route::prefix('v1')->group(function () {
    Route::get('/saas-plans', [SaasPlanController::class, 'index']);
    Route::post('/tenants/register', [TenantRegisterController::class, 'register']);

    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/login/mfa', [AuthController::class, 'verifyMfa'])->middleware('throttle:10,1');
    Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('/reset-password', [PasswordResetController::class, 'resetPassword'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);

    Route::middleware(['auth:sanctum', 'tenant.context'])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::get('/me/permissions', [AuthController::class, 'permissions']);
        Route::get('/me/access', [AuthController::class, 'access']);
        Route::post('/me/password', [AuthController::class, 'changePassword'])->middleware('throttle:5,1');

        // Roles y permisos de la empresa (Administración). Como en el prototipo, dependen del recurso "usuarios".
        Route::middleware('permission:users.view')->get('/roles', [RoleController::class, 'index']);
        Route::middleware('permission:users.manage')->group(function () {
            Route::post('/roles', [RoleController::class, 'store']);
            Route::put('/roles/{role}/permissions', [RoleController::class, 'updatePermissions']);
        });

        // Usuarios de la empresa (Administración)
        Route::middleware('permission:users.view')->get('/users', [UserController::class, 'index']);
        Route::middleware('permission:users.manage')->group(function () {
            Route::post('/users', [UserController::class, 'store']);
            Route::put('/users/{user}', [UserController::class, 'update']);
            Route::post('/users/{user}/status', [UserController::class, 'setStatus']);
        });

        // Ajustes de la empresa (segregación de funciones)
        Route::get('/tenant/settings', [TenantSettingsController::class, 'show']);
        Route::middleware('permission:tenant.manage')->put('/tenant/settings', [TenantSettingsController::class, 'update']);

        // Buscador global de la cabecera
        Route::middleware('permission:core.view')->get('/search', [SearchController::class, 'index']);

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
            Route::get('/suppliers/template', [SupplierController::class, 'downloadTemplate']);
            Route::get('/suppliers/{supplier}', [SupplierController::class, 'show']);
        });

        Route::middleware('permission:core.view')->get('/suppliers/{supplier}/overview', [SupplierController::class, 'overview']);

        Route::middleware('permission:core.manage')->group(function () {
            // Acciones masivas primero
            Route::post('/suppliers/bulk-deactivate', [SupplierController::class, 'bulkDeactivate']);
            Route::post('/suppliers/bulk-activate', [SupplierController::class, 'bulkActivate']);
            Route::post('/suppliers/import', [SupplierController::class, 'import']);

            // Acciones individuales
            Route::post('/suppliers', [SupplierController::class, 'store']);
            Route::put('/suppliers/{supplier}', [SupplierController::class, 'update']);
            Route::post('/suppliers/{supplier}/deactivate', [SupplierController::class, 'deactivate']);
            Route::post('/suppliers/{supplier}/activate', [SupplierController::class, 'activate']);
        });

        // Contactos
        Route::middleware('permission:core.view')->get('/contacts', [ContactController::class, 'index']);
        Route::middleware('permission:core.manage')->group(function () {
            Route::post('/contacts', [ContactController::class, 'store']);
            Route::put('/contacts/{contact}', [ContactController::class, 'update']);
            Route::delete('/contacts/{contact}', [ContactController::class, 'destroy']);
        });

        // Empleados
        Route::middleware('permission:core.view')->group(function () {
            Route::get('/employees', [EmployeeController::class, 'index']);
            Route::get('/employees/template', [EmployeeController::class, 'downloadTemplate']);
            Route::get('/employees/{employee}', [EmployeeController::class, 'show']);
        });

        Route::middleware('permission:core.view')->get('/employees/{employee}/overview', [EmployeeController::class, 'overview']);

        Route::middleware('permission:core.manage')->group(function () {
            // Acciones masivas primero
            Route::post('/employees/bulk-deactivate', [EmployeeController::class, 'bulkDeactivate']);
            Route::post('/employees/bulk-activate', [EmployeeController::class, 'bulkActivate']);
            Route::post('/employees/import', [EmployeeController::class, 'import']);

            // Acciones individuales
            Route::post('/employees', [EmployeeController::class, 'store']);
            Route::put('/employees/{employee}', [EmployeeController::class, 'update']);
            Route::post('/employees/{employee}/deactivate', [EmployeeController::class, 'deactivate']);
            Route::post('/employees/{employee}/activate', [EmployeeController::class, 'activate']);
        });

        // Servicios
        Route::middleware('permission:services.view')->group(function () {
            Route::get('/service-categories', [ServiceCategoryController::class, 'index']);
            Route::get('/services', [ServiceController::class, 'index']);
            Route::get('/services/{service}', [ServiceController::class, 'show']);
        });
        Route::middleware('permission:services.manage')->group(function () {
            Route::post('/services', [ServiceController::class, 'store']);
            Route::put('/services/{service}', [ServiceController::class, 'update']);
            Route::post('/services/{service}/activate', [ServiceController::class, 'activate']);
            Route::post('/services/{service}/deactivate', [ServiceController::class, 'deactivate']);
            Route::post('/services/{service}/archive', [ServiceController::class, 'archive']);
            Route::post('/services/{service}/prices', [ServiceController::class, 'storePrice']);
            Route::post('/service-categories', [ServiceCategoryController::class, 'store']);
        });

        // Cotizaciones
        Route::middleware('permission:quotations.view')->group(function () {
            Route::get('/quotations', [QuotationController::class, 'index']);
            Route::get('/quotations/summary', [QuotationController::class, 'summary']);
            Route::get('/quotations/{quotation}', [QuotationController::class, 'show']);
        });
        Route::middleware('permission:quotations.manage')->group(function () {
            Route::post('/quotations', [QuotationController::class, 'store']);
            Route::post('/quotations/{quotation}/submit', [QuotationController::class, 'submit']);
            Route::put('/quotations/{quotation}', [QuotationController::class, 'update']);
            Route::post('/quotations/{quotation}/cancel', [QuotationController::class, 'cancel']);
            Route::post('/quotations/{quotation}/versions', [QuotationController::class, 'newVersion']);
        });

        // Aprobar es un permiso aparte: quien cotiza no necesariamente aprueba.
        Route::middleware('permission:quotations.approve')->group(function () {
            Route::post('/quotations/{quotation}/approve', [QuotationController::class, 'approve']);
            Route::post('/quotations/{quotation}/reject', [QuotationController::class, 'reject']);
            // Marcar vencida también es una decisión sobre la cotización enviada.
            Route::post('/quotations/{quotation}/expire', [QuotationController::class, 'expire']);
        });

        // Contratos
        Route::middleware('permission:contracts.view')->group(function () {
            Route::get('/contracts', [ContractController::class, 'index']);
            Route::get('/contracts/{contract}', [ContractController::class, 'show']);
        });
        Route::middleware('permission:contracts.manage')->group(function () {
            Route::post('/quotations/{quotation}/contract', [ContractController::class, 'generateFromQuotation']);
            Route::put('/contracts/{contract}', [ContractController::class, 'update']);
            Route::post('/contracts/{contract}/suspend', [ContractController::class, 'suspend']);
            Route::post('/contracts/{contract}/reactivate', [ContractController::class, 'reactivate']);
            Route::post('/contracts/{contract}/terminate', [ContractController::class, 'terminate']);
            Route::post('/contracts/{contract}/expire', [ContractController::class, 'expire']);
        });
        // Activar es un permiso aparte, como aprobar una cotización.
        Route::middleware('permission:contracts.approve')->post('/contracts/{contract}/activate', [ContractController::class, 'activate']);
        Route::middleware('permission:orders.manage')->post('/contracts/{contract}/orders', [ContractController::class, 'createOrder']);

        // Orden directa desde una cotización aprobada
        Route::middleware('permission:orders.manage')->post('/quotations/{quotation}/order', [OrderController::class, 'generateFromQuotation']);

        // Órdenes
        Route::middleware('permission:orders.view')->group(function () {
            Route::get('/orders', [OrderController::class, 'index']);
            Route::get('/orders/{order}', [OrderController::class, 'show']);
        });
        Route::middleware('permission:orders.manage')->group(function () {
            Route::post('/orders/{order}/confirm', [OrderController::class, 'confirm']);
            Route::post('/orders/{order}/start', [OrderController::class, 'start']);
            Route::post('/orders/{order}/complete', [OrderController::class, 'complete']);
            Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);
        });

        // Clientes y actores: la identidad con sus roles de cliente, proveedor y empleado
        Route::middleware('permission:core.view')->group(function () {
            Route::get('/parties', [PartyController::class, 'index']);
            Route::get('/parties/{party}', [PartyController::class, 'show']);
        });
        Route::middleware('permission:core.manage')->group(function () {
            Route::post('/parties/bulk-deactivate', [PartyController::class, 'bulkDeactivate']);
            Route::post('/parties/bulk-activate', [PartyController::class, 'bulkActivate']);
            Route::post('/parties', [PartyController::class, 'store']);
            Route::put('/parties/{party}', [PartyController::class, 'update']);
            Route::post('/parties/{party}/roles', [PartyController::class, 'addRole']);
            Route::post('/parties/{party}/deactivate', [PartyController::class, 'deactivate']);
            Route::post('/parties/{party}/activate', [PartyController::class, 'activate']);
        });

        // Solicitudes del portal (bandeja de la empresa)
        Route::middleware('permission:portal.view')->get('/portal-requests', [PortalRequestController::class, 'index']);
        // Tomar la solicitud es el primer paso de cotizarla.
        Route::middleware('permission:quotations.manage')->post('/portal-requests/{portalRequest}/take', [PortalRequestController::class, 'take']);
        Route::middleware('permission:portal.manage')->post('/portal-requests/{portalRequest}/reject', [PortalRequestController::class, 'reject']);

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

        // Monedas y tipo de cambio. El vigente lo lee también quien cotiza en dólares.
        Route::get('/exchange-rates/current', [ExchangeRateController::class, 'current']);
        Route::middleware('permission:billing.view')->group(function () {
            Route::get('/exchange-rates', [ExchangeRateController::class, 'index']);
            Route::get('/exchange-rates/summary', [ExchangeRateController::class, 'summary']);
        });
        Route::middleware('permission:billing.manage')->post('/exchange-rates', [ExchangeRateController::class, 'store']);

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

    Route::middleware(['auth:sanctum', 'super.admin'])->prefix('superadmin')->group(function () {
        // Métricas del Dashboard global
        Route::get('/dashboard/metrics', [SuperAdminDashboardController::class, 'metrics']);

        // Gestión de Planes SaaS (CRUD completo)
        Route::get('/plans', [SaasPlanController::class, 'adminIndex']);
        Route::post('/plans', [SaasPlanController::class, 'store']);
        Route::put('/plans/{id}', [SaasPlanController::class, 'update']);
        Route::delete('/plans/{id}', [SaasPlanController::class, 'destroy']);

        // Listado y gestión global de Tenants / Empresas
        Route::get('/tenants', [SuperAdminDashboardController::class, 'tenants']);

        // Auditoría de plataforma (acciones del super admin)
        Route::get('/audit', [SuperAdminDashboardController::class, 'audit']);
    });

    // Portal del cliente
    Route::prefix('portal')->middleware(['auth:sanctum', 'tenant.context', 'portal.user'])->group(function () {
        Route::get('/dashboard', [PortalController::class, 'dashboard']);
        Route::get('/requests', [PortalController::class, 'requests']);
        Route::post('/requests', [PortalController::class, 'storeRequest']);
    });
});