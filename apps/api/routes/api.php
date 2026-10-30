<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use Illuminate\Support\Facades\Route;
use App\Modules\Subscription\Http\Controllers\SaasPlanController;
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
use App\Modules\Operations\Http\Controllers\ContractedServiceController;
use App\Modules\Operations\Http\Controllers\CostEntryController;
use App\Modules\Operations\Http\Controllers\AgendaController;
use App\Modules\Operations\Http\Controllers\FieldController;
use App\Modules\Operations\Http\Controllers\ProjectController;
use App\Modules\Operations\Http\Controllers\WorkOrderController;
use App\Modules\Operations\Http\Controllers\ChecklistController;
use App\Modules\Operations\Http\Controllers\TimeEntryController;
use App\Modules\Operations\Http\Controllers\ResourceController;
use App\Modules\Documents\Http\Controllers\DocumentController;
use App\Modules\Finance\Http\Controllers\BillableEventController;
use App\Modules\Finance\Http\Controllers\ExchangeRateController;
use App\Modules\Finance\Http\Controllers\FiscalController;
use App\Modules\Finance\Http\Controllers\InvoiceController;
use App\Modules\Finance\Http\Controllers\PaymentController;
use App\Modules\Finance\Http\Controllers\ReceivableController;
use App\Modules\Finance\Http\Controllers\RecurringController;
use App\Modules\Finance\Http\Controllers\TreasuryController;
use App\Modules\Purchasing\Http\Controllers\CostCenterController;
use App\Modules\Purchasing\Http\Controllers\PayableController;
use App\Modules\Purchasing\Http\Controllers\PurchaseOrderController;
use App\Modules\Purchasing\Http\Controllers\SupplierInvoiceController;
use App\Modules\Support\Http\Controllers\SlaSettingsController;
use App\Modules\Support\Http\Controllers\SupportTicketController;
use App\Modules\Support\Http\Controllers\WarrantyClaimController;
use App\Modules\Credentials\Http\Controllers\CredentialController;
use App\Modules\Crm\Http\Controllers\CrmActivityController;
use App\Modules\Crm\Http\Controllers\CrmCampaignController;
use App\Modules\Crm\Http\Controllers\CrmLeadController;
use App\Modules\Crm\Http\Controllers\CrmScoringController;
use App\Modules\Crm\Http\Controllers\CrmOpportunityController;
use App\Modules\Core\Http\Controllers\PartyController;
use App\Modules\Portal\Http\Controllers\PortalController;
use App\Modules\Services\Http\Controllers\ContractSignatureController;
use App\Modules\Portal\Http\Controllers\PortalRequestController;
use App\Modules\Portal\Http\Controllers\PortalCommercialController;
use App\Modules\Portal\Http\Controllers\PortalFinanceController;
use App\Modules\Portal\Http\Controllers\PortalNoticeController;
use App\Modules\Portal\Http\Controllers\PortalSupportController;
use App\Modules\Portal\Http\Controllers\PortalTeamController;
use App\Modules\Portal\Http\Controllers\PortalUserController;
use App\Modules\Core\Http\Controllers\EmployeeController;
use App\Modules\Core\Http\Controllers\SearchController;
use App\Modules\Core\Http\Controllers\AuditController;
use App\Modules\Core\Http\Controllers\OrgUnitController;
use App\Modules\Core\Http\Controllers\RoleController;
use App\Modules\Core\Http\Controllers\UserController;
use App\Modules\Core\Http\Controllers\TenantSettingsController;
use App\Modules\Accounting\Http\Controllers\BudgetController;
use App\Modules\Accounting\Http\Controllers\ChartController;
use App\Modules\Accounting\Http\Controllers\FiscalYearController;
use App\Modules\Accounting\Http\Controllers\JournalController;
use App\Modules\Accounting\Http\Controllers\LedgerController;

Route::prefix('v1')->group(function () {
    Route::get('/saas-plans', [SaasPlanController::class, 'index']);
    // Registro público: pocas altas por dirección, para que no se pueda automatizar.
    Route::post('/tenants/register', [TenantRegisterController::class, 'register'])->middleware('throttle:5,10,register');

    // Además del bloqueo por cuenta (LoginThrottle), un tope por dirección frena probar muchos correos.
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1,login');
    Route::post('/login/mfa', [AuthController::class, 'verifyMfa'])->middleware('throttle:10,1,mfa');
    Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword'])->middleware('throttle:5,1,forgot');
    Route::post('/reset-password', [PasswordResetController::class, 'resetPassword'])->middleware('throttle:10,1,reset');

    Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);

    // Descarga de un adjunto guardado en el disco del servidor: sin sesión, solo con el enlace firmado que vence.
    Route::get('/files/{path}', \App\Modules\Documents\Http\Controllers\DocumentFileController::class)
        ->where('path', '.*')->middleware(['signed', 'throttle:60,1,files'])->name('documents.file');

    // idempotency: un alta enviada dos veces se registra una vez. record.version: nadie pisa la edición de otro.
    Route::middleware(['auth:sanctum', 'tenant.context', 'idempotency', 'record.version', 'list.export'])->group(function () {
        // Registro de una exportación: exige el permiso de exportar del listado y queda en auditoría.
        Route::post('/exports', [\App\Http\Middleware\ListExport::class, 'record']);

        Route::get('/me', [AuthController::class, 'me']);
        Route::get('/me/permissions', [AuthController::class, 'permissions']);
        Route::get('/me/access', [AuthController::class, 'access']);
        Route::post('/me/password', [AuthController::class, 'changePassword'])->middleware('throttle:5,1,password');
        // Mi perfil: cuenta, sesiones abiertas y preferencias de notificación (siempre las propias).
        Route::get('/me/profile', [AuthController::class, 'profile']);
        Route::put('/me/name', [AuthController::class, 'updateName'])->middleware('throttle:10,1,profile-name');
        Route::post('/me/sessions/revoke-others', [AuthController::class, 'revokeOtherSessions']);
        Route::put('/me/notification-preferences', [AuthController::class, 'updateNotificationPreferences']);
        // Notificaciones (campana y centro de notificaciones): siempre las propias.
        Route::get('/me/notifications', [\App\Http\Controllers\NotificationController::class, 'index']);
        Route::get('/me/notifications/summary', [\App\Http\Controllers\NotificationController::class, 'summary']);
        Route::post('/me/notifications/read-all', [\App\Http\Controllers\NotificationController::class, 'readAll']);
        Route::post('/me/notifications/{notification}/read', [\App\Http\Controllers\NotificationController::class, 'read']);
        // Panel de control: cada bloque según los permisos, el alcance y el plan de la persona.
        Route::get('/dashboard', [\App\Http\Controllers\DashboardController::class, 'show']);

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
            Route::post('/users/{user}/invitation', [UserController::class, 'resendInvitation']);
        });

        // Sedes y equipos: de ellos depende el alcance "Sede" y "Equipo" de los roles.
        Route::middleware('permission:users.view')->get('/org-units', [OrgUnitController::class, 'index']);
        Route::middleware('permission:users.manage')->group(function () {
            Route::post('/org-units/{kind}', [OrgUnitController::class, 'store']);
            Route::put('/org-units/{kind}/{unit}', [OrgUnitController::class, 'update']);
        });

        // Auditoría de la empresa (Control): solo lectura
        Route::middleware('permission:audit.view')->group(function () {
            Route::get('/audit', [AuditController::class, 'index']);
            Route::get('/audit/options', [AuditController::class, 'options']);
        });

        // Ajustes de la empresa (segregación de funciones)
        Route::get('/tenant/settings', [TenantSettingsController::class, 'show']);
        Route::middleware('permission:tenant.manage')->put('/tenant/settings', [TenantSettingsController::class, 'update']);

        // Marca de la empresa (Configuración): logo, colores y contacto de sus documentos. Leerla, cualquiera de la empresa.
        Route::get('/branding', [\App\Http\Controllers\BrandingController::class, 'show']);
        Route::middleware('permission:tenant.manage')->group(function () {
            Route::put('/branding', [\App\Http\Controllers\BrandingController::class, 'update']);
            Route::post('/branding/logo', [\App\Http\Controllers\BrandingController::class, 'uploadLogo'])->middleware('throttle:20,1,branding');
            Route::delete('/branding/logo', [\App\Http\Controllers\BrandingController::class, 'removeLogo']);
        });

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
            // Desconectado (2026-10-08): el alta y la importación van por /parties (Clientes y actores),
            // que no duplica identidades. Para reconectar este módulo, quitar el comentario.
            // Route::post('/customers/import', [CustomerController::class, 'import']);
            
            // Acciones individuales
            // Route::post('/customers', [CustomerController::class, 'store']);
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
            Route::post('/custom-fields/values', [CustomFieldController::class, 'storeValue']);
        });
        // Eliminar es un permiso aparte de gestionar: quitar una columna borra sus valores en todas las filas.
        Route::middleware('permission:core.delete')->delete('/custom-fields/definitions/{id}', [CustomFieldController::class, 'destroyDefinition']);
        
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
            // Desconectado (2026-10-08): el alta y la importación van por /parties (Clientes y actores),
            // que no duplica identidades. Para reconectar este módulo, quitar el comentario.
            // Route::post('/suppliers/import', [SupplierController::class, 'import']);

            // Acciones individuales
            // Route::post('/suppliers', [SupplierController::class, 'store']);
            Route::put('/suppliers/{supplier}', [SupplierController::class, 'update']);
            Route::post('/suppliers/{supplier}/deactivate', [SupplierController::class, 'deactivate']);
            Route::post('/suppliers/{supplier}/activate', [SupplierController::class, 'activate']);
        });

        // Contactos
        Route::middleware('permission:core.view')->get('/contacts', [ContactController::class, 'index']);
        Route::middleware('permission:core.manage')->group(function () {
            Route::post('/contacts', [ContactController::class, 'store']);
            Route::put('/contacts/{contact}', [ContactController::class, 'update']);
        });
        Route::middleware('permission:core.delete')->delete('/contacts/{contact}', [ContactController::class, 'destroy']);

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
            // Desconectado (2026-10-08): el alta y la importación van por /parties (Clientes y actores),
            // que no duplica identidades. Para reconectar este módulo, quitar el comentario.
            // Route::post('/employees/import', [EmployeeController::class, 'import']);

            // Acciones individuales
            // Route::post('/employees', [EmployeeController::class, 'store']);
            Route::put('/employees/{employee}', [EmployeeController::class, 'update']);
            Route::post('/employees/{employee}/deactivate', [EmployeeController::class, 'deactivate']);
            Route::post('/employees/{employee}/activate', [EmployeeController::class, 'activate']);
        });

        // Servicios
        Route::middleware('permission:services.view')->group(function () {
            Route::get('/service-categories', [ServiceCategoryController::class, 'index']);
            Route::get('/services', [ServiceController::class, 'index']);
            Route::get('/services/template', [ServiceController::class, 'downloadTemplate']);
            Route::get('/services/{service}', [ServiceController::class, 'show']);
        });
        Route::middleware('permission:services.manage')->group(function () {
            Route::post('/services/import', [ServiceController::class, 'import']);
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
        // Crear es un permiso aparte de gestionar: quien vende genera el contrato o la orden de su
        // cotización aprobada sin poder editarlos, suspenderlos ni terminarlos.
        Route::middleware('permission:contracts.create')->post('/quotations/{quotation}/contract', [ContractController::class, 'generateFromQuotation']);
        Route::middleware('permission:contracts.manage')->group(function () {
            Route::put('/contracts/{contract}', [ContractController::class, 'update']);
            Route::post('/contracts/{contract}/suspend', [ContractController::class, 'suspend']);
            Route::post('/contracts/{contract}/reactivate', [ContractController::class, 'reactivate']);
            Route::post('/contracts/{contract}/terminate', [ContractController::class, 'terminate']);
            Route::post('/contracts/{contract}/expire', [ContractController::class, 'expire']);
        });
        // Activar es un permiso aparte, como aprobar una cotización.
        Route::middleware('permission:contracts.approve')->post('/contracts/{contract}/activate', [ContractController::class, 'activate']);
        // Firma electrónica: ver, enviar y anular (gestionar); firmar por la empresa (aprobar).
        Route::middleware('permission:contracts.view')->get('/contracts/{contract}/signature-requests', [ContractSignatureController::class, 'index']);
        Route::middleware('permission:contracts.manage')->group(function () {
            Route::post('/contracts/{contract}/signature-requests', [ContractSignatureController::class, 'store']);
            Route::post('/contracts/{contract}/signature-requests/{signatureRequest}/cancel', [ContractSignatureController::class, 'cancel']);
        });
        Route::middleware('permission:contracts.approve')->group(function () {
            Route::post('/contracts/{contract}/signature-requests/{signatureRequest}/code', [ContractSignatureController::class, 'code'])->middleware('throttle:5,1,sign-code');
            Route::post('/contracts/{contract}/signature-requests/{signatureRequest}/company-sign', [ContractSignatureController::class, 'sign']);
        });
        Route::middleware('permission:orders.create')->post('/contracts/{contract}/orders', [ContractController::class, 'createOrder']);

        // Orden directa desde una cotización aprobada
        Route::middleware('permission:orders.create')->post('/quotations/{quotation}/order', [OrderController::class, 'generateFromQuotation']);

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
            Route::get('/parties/template', [PartyController::class, 'downloadTemplate']);
            Route::get('/parties/{party}', [PartyController::class, 'show']);
        });
        Route::middleware('permission:core.manage')->group(function () {
            Route::post('/parties/bulk-deactivate', [PartyController::class, 'bulkDeactivate']);
            Route::post('/parties/bulk-activate', [PartyController::class, 'bulkActivate']);
            Route::post('/parties/import', [PartyController::class, 'import']);
            Route::post('/parties', [PartyController::class, 'store']);
            Route::put('/parties/{party}', [PartyController::class, 'update']);
            Route::post('/parties/{party}/roles', [PartyController::class, 'addRole']);
            Route::put('/parties/{party}/supplier', [PartyController::class, 'updateSupplier']);
            Route::post('/parties/{party}/deactivate', [PartyController::class, 'deactivate']);
            Route::post('/parties/{party}/activate', [PartyController::class, 'activate']);
        });

        // Solicitudes del portal (bandeja de la empresa)
        Route::middleware('permission:portal.view')->get('/portal-requests', [PortalRequestController::class, 'index']);
        // Tomar la solicitud es el primer paso de cotizarla.
        Route::middleware('permission:quotations.manage')->post('/portal-requests/{portalRequest}/take', [PortalRequestController::class, 'take']);
        Route::middleware('permission:portal.manage')->post('/portal-requests/{portalRequest}/reject', [PortalRequestController::class, 'reject']);

        // Usuarios del portal y avisos (Administración). Cuelgan del módulo Portal de clientes.
        Route::middleware('permission:portal_users.view')->group(function () {
            Route::get('/portal-users', [PortalUserController::class, 'index']);
            Route::get('/portal-users/summary', [PortalUserController::class, 'summary']);
            Route::get('/portal-users/options', [PortalUserController::class, 'options']);
            Route::get('/portal-notices', [PortalNoticeController::class, 'index']);
        });
        Route::middleware('permission:portal_users.create')->post('/portal-users', [PortalUserController::class, 'store']);
        Route::middleware('permission:portal_users.manage')->group(function () {
            Route::patch('/portal-users/{portalUser}', [PortalUserController::class, 'update']);
            Route::post('/portal-users/{portalUser}/resend', [PortalUserController::class, 'resend']);
            Route::post('/portal-notices', [PortalNoticeController::class, 'store']);
            Route::post('/portal-notices/{notice}/withdraw', [PortalNoticeController::class, 'withdraw']);
        });

        // Plantillas de checklist (Operaciones)
        Route::middleware('permission:checklists.view')->group(function () {
            Route::get('/checklists', [ChecklistController::class, 'index']);
            Route::get('/checklists/summary', [ChecklistController::class, 'summary']);
            Route::get('/checklists/options', [ChecklistController::class, 'options']);
        });
        Route::middleware('permission:checklists.manage')->group(function () {
            Route::get('/checklists/template', [ChecklistController::class, 'importTemplate']);
            Route::post('/checklists/import', [ChecklistController::class, 'import']);
            Route::post('/checklists', [ChecklistController::class, 'store']);
            Route::put('/checklists/{checklist}', [ChecklistController::class, 'update']);
            Route::post('/checklists/{checklist}/duplicate', [ChecklistController::class, 'duplicate']);
            Route::post('/checklists/{checklist}/deactivate', [ChecklistController::class, 'deactivate']);
            Route::post('/checklists/{checklist}/activate', [ChecklistController::class, 'activate']);
        });
        Route::middleware('permission:checklists.view')->get('/checklists/{checklist}', [ChecklistController::class, 'show']);

        // Servicios contratados
        Route::middleware('permission:contracted_services.view')->group(function () {
            Route::get('/contracted-services', [ContractedServiceController::class, 'index']);
            Route::get('/contracted-services/{contractedService}', [ContractedServiceController::class, 'show']);
        });
        Route::middleware('permission:contracted_services.manage')->group(function () {
            Route::post('/contracted-services/{contractedService}/activate', [ContractedServiceController::class, 'activate']);
            Route::post('/contracted-services/{contractedService}/suspend', [ContractedServiceController::class, 'suspend']);
            Route::post('/contracted-services/{contractedService}/reactivate', [ContractedServiceController::class, 'reactivate']);
            Route::post('/contracted-services/{contractedService}/complete', [ContractedServiceController::class, 'complete']);
            Route::post('/contracted-services/{contractedService}/cancel', [ContractedServiceController::class, 'cancel']);
        });

        // Órdenes de trabajo
        Route::middleware('permission:work_orders.view')->group(function () {
            Route::get('/work-orders', [WorkOrderController::class, 'index']);
            Route::get('/work-orders/options', [WorkOrderController::class, 'options']);
            Route::get('/work-orders/{workOrder}', [WorkOrderController::class, 'show']);
            Route::get('/work-orders/{workOrder}/checklist-items/{item}/evidence-url', [WorkOrderController::class, 'evidenceUrl']);
            // App del técnico: las visitas pendientes de una persona, en el orden sugerido.
            Route::get('/field/route', [FieldController::class, 'route']);
        });
        Route::middleware('permission:work_orders.create')->post('/work-orders', [WorkOrderController::class, 'store']);
        Route::middleware('permission:work_orders.manage')->group(function () {
            Route::put('/work-orders/{workOrder}', [WorkOrderController::class, 'update']);
            Route::post('/work-orders/{workOrder}/start', [WorkOrderController::class, 'start']);
            Route::post('/work-orders/{workOrder}/block', [WorkOrderController::class, 'block']);
            Route::post('/work-orders/{workOrder}/unblock', [WorkOrderController::class, 'unblock']);
            Route::post('/work-orders/{workOrder}/complete', [WorkOrderController::class, 'complete']);
            Route::post('/work-orders/{workOrder}/cancel', [WorkOrderController::class, 'cancel']);
            Route::patch('/work-orders/{workOrder}/checklist-items/{item}', [WorkOrderController::class, 'checkItem']);
            Route::post('/work-orders/{workOrder}/checklist-items/{item}/evidence', [WorkOrderController::class, 'attachEvidence']);
            Route::delete('/work-orders/{workOrder}/checklist-items/{item}/evidence', [WorkOrderController::class, 'removeEvidence']);
            // App del técnico: deja en el historial los cambios sin conexión que no se pudieron aplicar.
            Route::post('/field/sync-log', [FieldController::class, 'syncLog']);
        });
        // Asignar el técnico y revisar el checklist son de quien supervisa, no de quien ejecuta.
        Route::middleware('permission:work_orders.approve')->group(function () {
            Route::post('/work-orders/{workOrder}/assign', [WorkOrderController::class, 'assign']);
            Route::post('/work-orders/{workOrder}/checklist/approve', [WorkOrderController::class, 'approveChecklist']);
            Route::post('/work-orders/{workOrder}/checklist/return', [WorkOrderController::class, 'returnChecklist']);
        });

        // Tareas sueltas (TaskController): desconectadas el 2026-10-14. Las tareas se trabajan dentro
        // de su proyecto (rutas /projects/{project}/tasks); el controlador sigue en el repo.

        // Proyectos, con sus tareas y entregables
        Route::middleware('permission:projects.view')->group(function () {
            Route::get('/projects', [ProjectController::class, 'index']);
            Route::get('/projects/options', [ProjectController::class, 'options']);
            Route::get('/projects/{project}', [ProjectController::class, 'show']);
        });
        Route::middleware('permission:projects.create')->post('/projects', [ProjectController::class, 'store']);
        Route::middleware('permission:projects.manage')->group(function () {
            Route::put('/projects/{project}', [ProjectController::class, 'update']);
            Route::post('/projects/{project}/plan', [ProjectController::class, 'plan']);
            Route::post('/projects/{project}/start', [ProjectController::class, 'start']);
            Route::post('/projects/{project}/hold', [ProjectController::class, 'hold']);
            Route::post('/projects/{project}/resume', [ProjectController::class, 'resume']);
            Route::post('/projects/{project}/cancel', [ProjectController::class, 'cancel']);
        });
        // Trabajar las tareas es de quien ejecuta: no da para pausar el proyecto ni tocar su presupuesto.
        Route::middleware('permission:tasks.manage')->group(function () {
            Route::post('/projects/{project}/tasks', [ProjectController::class, 'storeTask']);
            Route::put('/projects/{project}/tasks/{task}', [ProjectController::class, 'updateTask']);
            Route::post('/projects/{project}/tasks/{task}/move', [ProjectController::class, 'moveTask']);
            Route::patch('/projects/{project}/tasks/{task}/check', [ProjectController::class, 'checkTask']);
        });
        // Cerrar el proyecto es de quien supervisa.
        Route::middleware('permission:projects.approve')->post('/projects/{project}/complete', [ProjectController::class, 'complete']);
        Route::middleware('permission:deliverables.manage')->group(function () {
            Route::post('/projects/{project}/deliverables', [ProjectController::class, 'storeDeliverable']);
            Route::put('/projects/{project}/deliverables/{deliverable}', [ProjectController::class, 'updateDeliverable']);
            Route::post('/projects/{project}/deliverables/{deliverable}/document', [ProjectController::class, 'attachDeliverableDocument']);
            Route::post('/projects/{project}/deliverables/{deliverable}/submit', [ProjectController::class, 'submitDeliverable']);
            Route::post('/projects/{project}/deliverables/{deliverable}/deliver', [ProjectController::class, 'deliverDeliverable']);
        });
        // Descargar el archivo de un entregable: quien puede verlos, dentro de los proyectos que alcanza.
        Route::middleware('permission:deliverables.view')
            ->get('/projects/{project}/deliverables/{deliverable}/document-url', [ProjectController::class, 'deliverableDocumentUrl']);
        Route::middleware('permission:deliverables.approve')->group(function () {
            Route::post('/projects/{project}/deliverables/{deliverable}/approve', [ProjectController::class, 'approveDeliverable']);
            Route::post('/projects/{project}/deliverables/{deliverable}/return', [ProjectController::class, 'returnDeliverable']);
        });

        // Agenda
        Route::middleware('permission:agenda.view')->group(function () {
            Route::get('/agenda', [AgendaController::class, 'index']);
            Route::get('/agenda/options', [AgendaController::class, 'options']);
            Route::get('/agenda/{agendaEvent}', [AgendaController::class, 'show']);
        });
        Route::middleware('permission:agenda.create')->post('/agenda', [AgendaController::class, 'store']);
        Route::middleware('permission:agenda.manage')->group(function () {
            Route::put('/agenda/{agendaEvent}', [AgendaController::class, 'update']);
            Route::post('/agenda/{agendaEvent}/done', [AgendaController::class, 'done']);
            Route::post('/agenda/{agendaEvent}/cancel', [AgendaController::class, 'cancel']);
        });

        // Registro de horas
        Route::middleware('permission:time_entries.view')->group(function () {
            Route::get('/time-entries', [TimeEntryController::class, 'index']);
            Route::get('/time-entries/summary', [TimeEntryController::class, 'summary']);
            Route::get('/time-entries/options', [TimeEntryController::class, 'options']);
        });
        Route::middleware('permission:time_entries.create')->post('/time-entries', [TimeEntryController::class, 'store']);
        Route::middleware('permission:time_entries.approve')->group(function () {
            Route::post('/time-entries/{timeEntry}/approve', [TimeEntryController::class, 'approve']);
            Route::post('/time-entries/{timeEntry}/reject', [TimeEntryController::class, 'reject']);
        });

        // Costos directos
        Route::middleware('permission:cost_entries.view')->get('/cost-entries', [CostEntryController::class, 'index']);
        Route::middleware('permission:cost_entries.create')->post('/cost-entries', [CostEntryController::class, 'store']);
        Route::middleware('permission:cost_entries.approve')->group(function () {
            Route::post('/cost-entries/{costEntry}/approve', [CostEntryController::class, 'approve']);
            Route::post('/cost-entries/{costEntry}/reject', [CostEntryController::class, 'reject']);
        });

        // Recursos
        Route::middleware('permission:operations.view')->get('/resources', [ResourceController::class, 'index']);
        Route::middleware('permission:operations.manage')->group(function () {
            Route::post('/resources', [ResourceController::class, 'store']);
            Route::post('/resources/{resource}/assign', [ResourceController::class, 'assign']);
        });

        // Documentos (Control → Documentos y la tarjeta de documentos de cada ficha)
        Route::middleware('permission:documents.view')->group(function () {
            Route::get('/documents', [DocumentController::class, 'index']);
            Route::get('/documents/options', [DocumentController::class, 'options']);
            Route::get('/documents/{document}', [DocumentController::class, 'show']);
            Route::get('/documents/{document}/download-url', [DocumentController::class, 'downloadUrl']);
        });
        Route::middleware('permission:documents.create')->group(function () {
            Route::post('/documents', [DocumentController::class, 'store']);
            Route::post('/documents/{document}/versions', [DocumentController::class, 'storeVersion']);
        });
        Route::middleware('permission:documents.manage')->group(function () {
            Route::put('/documents/{document}', [DocumentController::class, 'update']);
            Route::post('/documents/{document}/archive', [DocumentController::class, 'archive']);
        });

        // Finanzas: eventos facturables y comprobantes (sin envío a SUNAT todavía)
        Route::middleware('permission:billing.view')->group(function () {
            Route::get('/billable-events', [BillableEventController::class, 'index']);
            Route::get('/billable-events/options', [BillableEventController::class, 'options']);
            Route::get('/invoices', [InvoiceController::class, 'index']);
            Route::get('/invoices/summary', [InvoiceController::class, 'summary']);
            Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
        });
        Route::middleware('permission:billing.create')->group(function () {
            Route::post('/billable-events', [BillableEventController::class, 'store']);
            Route::post('/invoices/from-events', [InvoiceController::class, 'fromEvents']);
        });
        Route::middleware('permission:billing.manage')->group(function () {
            Route::post('/billable-events/{billableEvent}/cancel', [BillableEventController::class, 'cancel']);
            Route::post('/invoices/{invoice}/discard', [InvoiceController::class, 'discard']);
        });
        // Emitir y corregir es de quien responde por la facturación, no de quien prepara el borrador.
        Route::middleware('permission:billing.approve')->group(function () {
            Route::post('/invoices/{invoice}/issue', [InvoiceController::class, 'issue']);
            Route::post('/invoices/{invoice}/credit-note', [InvoiceController::class, 'creditNote']);
            Route::post('/invoices/{invoice}/fiscal-retry', [InvoiceController::class, 'fiscalRetry']);
        });

        // Monedas y tipo de cambio. El vigente lo lee también quien cotiza en dólares.
        Route::get('/exchange-rates/current', [ExchangeRateController::class, 'current']);
        Route::middleware('permission:fiscal.view')->group(function () {
            Route::get('/exchange-rates', [ExchangeRateController::class, 'index']);
            Route::get('/exchange-rates/summary', [ExchangeRateController::class, 'summary']);
        });
        Route::middleware('permission:fiscal.manage')->group(function () {
            Route::post('/exchange-rates', [ExchangeRateController::class, 'store']);
            Route::post('/exchange-rates/sync', [ExchangeRateController::class, 'sync']);
        });

        // Facturación electrónica: configuración fiscal, series, contingencia y bajas
        Route::middleware('permission:fiscal.view')->group(function () {
            Route::get('/fiscal/summary', [FiscalController::class, 'summary']);
            Route::get('/fiscal/settings', [FiscalController::class, 'settings']);
            Route::get('/fiscal/contingency', [FiscalController::class, 'contingency']);
            Route::get('/fiscal/voids', [FiscalController::class, 'voids']);
        });
        // Configurar es aparte de registrar el tipo de cambio: cambia cómo emite toda la empresa.
        Route::middleware('permission:fiscal.configure')->group(function () {
            Route::put('/fiscal/settings', [FiscalController::class, 'updateSettings']);
            Route::put('/fiscal/series', [FiscalController::class, 'updateSeries']);
            Route::post('/fiscal/contingency/start', [FiscalController::class, 'startContingency']);
            Route::post('/fiscal/contingency/end', [FiscalController::class, 'endContingency']);
        });
        Route::middleware('permission:billing.manage')->put('/invoices/{invoice}/withholding', [InvoiceController::class, 'withholdingMode']);
        Route::middleware('permission:receivables.manage')->post('/invoices/{invoice}/withholding-receipt', [InvoiceController::class, 'withholdingReceipt']);
        Route::middleware('permission:billing.approve')->post('/invoices/{invoice}/void', [InvoiceController::class, 'void']);

        // Cuentas por cobrar
        Route::middleware('permission:receivables.view')->group(function () {
            Route::get('/receivables', [ReceivableController::class, 'index']);
            Route::get('/receivables/summary', [ReceivableController::class, 'summary']);
            Route::get('/receivables/{receivable}', [ReceivableController::class, 'show']);
        });

        // Cobros
        Route::middleware('permission:payments.view')->group(function () {
            Route::get('/payments', [PaymentController::class, 'index']);
            Route::get('/payments/options', [PaymentController::class, 'options']);
            Route::get('/payments/refunds', [PaymentController::class, 'refunds']);
            Route::get('/payments/{payment}', [PaymentController::class, 'show']);
        });
        Route::middleware('permission:payments.create')->post('/payments', [PaymentController::class, 'store']);
        Route::middleware('permission:payments.manage')->post('/payments/{payment}/allocate', [PaymentController::class, 'allocate']);
        // Conciliar y reembolsar es de quien supervisa la caja, no de quien registra el cobro.
        Route::middleware('permission:payments.approve')->group(function () {
            Route::post('/payments/{payment}/reconcile', [PaymentController::class, 'reconcile']);
            Route::post('/payments/{payment}/refund', [PaymentController::class, 'refund']);
        });

        // Servicios recurrentes: instancias, ciclos y renovaciones
        Route::middleware('permission:recurring.view')->group(function () {
            Route::get('/recurring', [RecurringController::class, 'index']);
            Route::get('/recurring/summary', [RecurringController::class, 'summary']);
            Route::get('/recurring/{recurring}', [RecurringController::class, 'show']);
        });
        Route::middleware('permission:recurring.create')->post('/recurring/run-cycles', [RecurringController::class, 'runCycles']);
        Route::middleware('permission:recurring.manage')->group(function () {
            Route::post('/recurring/{recurring}/renew', [RecurringController::class, 'renew']);
            Route::post('/recurring/{recurring}/suspend', [RecurringController::class, 'suspend']);
            Route::post('/recurring/{recurring}/reactivate', [RecurringController::class, 'reactivate']);
            Route::post('/recurring/{recurring}/cancel', [RecurringController::class, 'cancel']);
        });

        // Bancos y caja: cuentas, movimientos, conciliación y caja chica
        Route::middleware('permission:treasury.view')->group(function () {
            Route::get('/bank-accounts', [TreasuryController::class, 'accounts']);
            Route::get('/bank-accounts/summary', [TreasuryController::class, 'summary']);
            Route::get('/bank-movements', [TreasuryController::class, 'movements']);
            Route::get('/bank-statements', [TreasuryController::class, 'reconciliation']);
            Route::get('/cash-expenses', [TreasuryController::class, 'cashExpenses']);
            Route::get('/cash-expenses/options', [TreasuryController::class, 'cashExpenseOptions']);
            Route::get('/bank-statements/{statement}/file-url', [TreasuryController::class, 'statementFile']);
            Route::get('/cash-counts', [TreasuryController::class, 'cashCounts']);
        });
        Route::middleware('permission:treasury.create')->group(function () {
            Route::post('/bank-transfers', [TreasuryController::class, 'transfer']);
            Route::post('/bank-accounts/{bankAccount}/refill', [TreasuryController::class, 'refill']);
            Route::get('/bank-accounts/{bankAccount}/statements/template', [TreasuryController::class, 'statementTemplate']);
            Route::post('/bank-accounts/{bankAccount}/statements/import', [TreasuryController::class, 'importStatement']);
            Route::post('/cash-expenses', [TreasuryController::class, 'storeCashExpense']);
            Route::post('/cash-counts', [TreasuryController::class, 'storeCashCount']);
        });
        // Crear cuentas y conciliar a mano es de quien responde por la tesorería.
        Route::middleware('permission:treasury.manage')->group(function () {
            Route::post('/bank-accounts', [TreasuryController::class, 'storeAccount']);
            Route::put('/bank-accounts/{bankAccount}', [TreasuryController::class, 'updateAccount']);
            Route::post('/bank-statement-lines/{line}/match', [TreasuryController::class, 'matchLine']);
            Route::post('/bank-statement-lines/{line}/resolve', [TreasuryController::class, 'resolveLine']);
            // Corregir la clasificación de un gasto ya registrado es de quien responde por la tesorería.
            Route::post('/cash-expenses/{cashExpense}/reclassify', [TreasuryController::class, 'reclassifyCashExpense']);
        });

        // Compras: órdenes de compra y su recepción
        Route::middleware('permission:purchases.view')->group(function () {
            Route::get('/purchase-orders', [PurchaseOrderController::class, 'index']);
            Route::get('/purchase-orders/summary', [PurchaseOrderController::class, 'summary']);
            Route::get('/purchase-orders/options', [PurchaseOrderController::class, 'options']);
            Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show']);
        });
        Route::middleware('permission:purchases.create')->post('/purchase-orders', [PurchaseOrderController::class, 'store']);
        Route::middleware('permission:purchases.manage')->group(function () {
            Route::put('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update']);
            Route::post('/purchase-orders/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submit']);
            Route::post('/purchase-orders/{purchaseOrder}/reopen', [PurchaseOrderController::class, 'reopen']);
            Route::post('/purchase-orders/{purchaseOrder}/receipts', [PurchaseOrderController::class, 'receive']);
            Route::post('/purchase-orders/{purchaseOrder}/close', [PurchaseOrderController::class, 'close']);
            Route::post('/purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel']);
        });
        // Aprobar es de otra persona que quien pide: permiso aparte.
        Route::middleware('permission:purchases.approve')->group(function () {
            Route::post('/purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve']);
            Route::post('/purchase-orders/{purchaseOrder}/reject', [PurchaseOrderController::class, 'reject']);
        });
        // Centros de costo: se dan de alta en Contabilidad y, rápido, desde los formularios de Compras.
        Route::middleware('permission:budgets.manage')->group(function () {
            Route::post('/cost-centers', [CostCenterController::class, 'store']);
            Route::put('/cost-centers/{costCenter}', [BudgetController::class, 'updateCenter']);
        });

        // Compras: comprobantes de proveedor, cuentas por pagar y pagos a proveedores
        Route::middleware('permission:payables.view')->group(function () {
            Route::get('/supplier-invoices', [SupplierInvoiceController::class, 'index']);
            Route::get('/supplier-invoices/summary', [SupplierInvoiceController::class, 'summary']);
            Route::get('/supplier-invoices/options', [SupplierInvoiceController::class, 'options']);
            Route::get('/supplier-invoices/{supplierInvoice}', [SupplierInvoiceController::class, 'show']);
            Route::get('/payables', [PayableController::class, 'index']);
            Route::get('/payables/summary', [PayableController::class, 'summary']);
            Route::get('/payables/{payable}', [PayableController::class, 'show']);
            Route::get('/supplier-payments', [PayableController::class, 'payments']);
            Route::get('/supplier-payments/{supplierPayment}', [PayableController::class, 'showPayment'])->whereUuid('supplierPayment');
        });
        Route::middleware('permission:payables.create')->post('/supplier-invoices', [SupplierInvoiceController::class, 'store']);
        Route::middleware('permission:payables.manage')->post('/supplier-invoices/{supplierInvoice}/void', [SupplierInvoiceController::class, 'void']);
        // Aprobar un comprobante compromete dinero: permiso aparte de registrar.
        Route::middleware('permission:payables.approve')->group(function () {
            Route::post('/supplier-invoices/{supplierInvoice}/approve', [SupplierInvoiceController::class, 'approve']);
            Route::post('/supplier-invoices/{supplierInvoice}/reject', [SupplierInvoiceController::class, 'reject']);
        });
        // Pagar saca el dinero: es de otra persona que quien aprueba el gasto.
        Route::middleware('permission:payables.pay')->group(function () {
            Route::get('/supplier-payments/options', [PayableController::class, 'paymentOptions']);
            Route::post('/supplier-payments', [PayableController::class, 'pay']);
            Route::post('/supplier-payments/{supplierPayment}/reverse', [PayableController::class, 'reversePayment']);
        });

        // Contabilidad: libro diario, mayor y balance, estados financieros, plan de cuentas y periodos
        Route::middleware('permission:accounting.view')->group(function () {
            Route::get('/journal-entries', [JournalController::class, 'index']);
            Route::get('/journal-entries/summary', [JournalController::class, 'summary']);
            Route::get('/journal-entries/options', [JournalController::class, 'options']);
            // Solo un id: /journal-entries/template es la plantilla de importación.
            Route::get('/journal-entries/{entry}', [JournalController::class, 'show'])->whereUuid('entry');
            Route::get('/ledger/trial-balance', [LedgerController::class, 'trialBalance']);
            Route::get('/ledger/accounts/{code}', [LedgerController::class, 'account']);
            Route::get('/financial-statements', [LedgerController::class, 'statements']);
            Route::get('/accounting-accounts', [ChartController::class, 'accounts']);
            Route::get('/accounting-rules', [ChartController::class, 'rules']);
            Route::get('/accounting-periods', [ChartController::class, 'periods']);
            Route::get('/accounting-years', [FiscalYearController::class, 'index']);
        });
        Route::middleware('permission:accounting.create')->group(function () {
            Route::post('/journal-entries', [JournalController::class, 'store']);
            Route::get('/journal-entries/template', [JournalController::class, 'importTemplate']);
            Route::post('/journal-entries/import', [JournalController::class, 'import']);
        });
        Route::middleware('permission:accounting.manage')->group(function () {
            Route::post('/accounting-accounts', [ChartController::class, 'storeAccount']);
            Route::put('/accounting-accounts/{account}', [ChartController::class, 'updateAccount']);
        });
        // Extornar y cerrar periodos es de quien responde por la contabilidad, no de quien registra.
        Route::middleware('permission:accounting.approve')->group(function () {
            Route::post('/journal-entries/{entry}/reverse', [JournalController::class, 'reverse']);
            Route::post('/accounting-periods/{period}/close', [ChartController::class, 'closePeriod']);
            Route::post('/accounting-periods/{period}/reopen', [ChartController::class, 'reopenPeriod']);
            Route::post('/accounting-years/{year}/close', [FiscalYearController::class, 'close']);
            Route::post('/accounting-years/{year}/reopen', [FiscalYearController::class, 'reopen']);
        });

        // Centros de costo y presupuestos
        Route::middleware('permission:budgets.view')->group(function () {
            Route::get('/budgets/execution', [BudgetController::class, 'execution']);
            Route::get('/budgets/options', [BudgetController::class, 'options']);
            Route::get('/cost-centers/{costCenter}/budget', [BudgetController::class, 'center']);
        });
        Route::middleware('permission:budgets.manage')->group(function () {
            Route::post('/budgets', [BudgetController::class, 'store']);
            Route::put('/budgets/{budget}/lines', [BudgetController::class, 'saveLine']);
            Route::post('/budgets/{budget}/reopen', [BudgetController::class, 'reopen']);
        });
        Route::middleware('permission:budgets.approve')->group(function () {
            Route::post('/budgets/{budget}/approve', [BudgetController::class, 'approve']);
            Route::post('/budgets/{budget}/close', [BudgetController::class, 'close']);
        });

        // Soporte: tickets y SLA. Las rutas anteriores /support-tickets se reemplazaron por estas.
        Route::middleware('permission:tickets.view')->group(function () {
            Route::get('/tickets', [SupportTicketController::class, 'index']);
            Route::get('/tickets/summary', [SupportTicketController::class, 'summary']);
            Route::get('/tickets/options', [SupportTicketController::class, 'options']);
            Route::get('/tickets/{ticket}', [SupportTicketController::class, 'show']);
        });
        Route::middleware('permission:tickets.create')->post('/tickets', [SupportTicketController::class, 'store']);
        Route::middleware('permission:tickets.manage')->group(function () {
            Route::post('/tickets/{ticket}/triage', [SupportTicketController::class, 'triage']);
            Route::post('/tickets/{ticket}/assign', [SupportTicketController::class, 'assign']);
            Route::post('/tickets/{ticket}/comments', [SupportTicketController::class, 'comment']);
            Route::post('/tickets/{ticket}/start', [SupportTicketController::class, 'start']);
            Route::post('/tickets/{ticket}/wait', [SupportTicketController::class, 'wait']);
            Route::post('/tickets/{ticket}/resume', [SupportTicketController::class, 'resume']);
            Route::post('/tickets/{ticket}/resolve', [SupportTicketController::class, 'resolve']);
            Route::post('/tickets/{ticket}/close', [SupportTicketController::class, 'close']);
            Route::post('/tickets/{ticket}/reopen', [SupportTicketController::class, 'reopen']);
        });
        // Escalar sube la prioridad y acorta el plazo: permiso aparte de atender.
        Route::middleware('permission:tickets.approve')->post('/tickets/{ticket}/escalate', [SupportTicketController::class, 'escalate']);
        // Crear la OT del ticket pide ver el ticket y crear órdenes de trabajo.
        Route::middleware(['permission:tickets.view', 'permission:work_orders.create'])
            ->post('/tickets/{ticket}/work-order', [SupportTicketController::class, 'createWorkOrder']);

        // Soporte: reclamos de garantía
        Route::middleware('permission:warranty.view')->group(function () {
            Route::get('/warranty-claims', [WarrantyClaimController::class, 'index']);
            Route::get('/warranty-claims/options', [WarrantyClaimController::class, 'options']);
            Route::get('/warranty-claims/{claim}', [WarrantyClaimController::class, 'show']);
        });
        Route::middleware('permission:warranty.create')->post('/warranty-claims', [WarrantyClaimController::class, 'store']);
        Route::middleware('permission:warranty.manage')->group(function () {
            Route::post('/warranty-claims/{claim}/check-coverage', [WarrantyClaimController::class, 'coverage']);
            Route::post('/warranty-claims/{claim}/resolve', [WarrantyClaimController::class, 'resolve']);
        });
        Route::middleware('permission:warranty.approve')->group(function () {
            Route::post('/warranty-claims/{claim}/approve', [WarrantyClaimController::class, 'approve']);
            Route::post('/warranty-claims/{claim}/reject', [WarrantyClaimController::class, 'reject']);
        });

        // Administración: calendario laboral y SLA
        Route::middleware('permission:sla.view')->get('/sla-settings', [SlaSettingsController::class, 'show']);
        Route::middleware('permission:sla.manage')->group(function () {
            Route::put('/sla-settings/calendar', [SlaSettingsController::class, 'updateCalendar']);
            Route::put('/sla-settings/policies', [SlaSettingsController::class, 'updatePolicies']);
            Route::post('/sla-settings/holidays', [SlaSettingsController::class, 'addHoliday']);
            Route::delete('/sla-settings/holidays/{holiday}', [SlaSettingsController::class, 'removeHoliday']);
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
        Route::middleware(['module:crm', 'permission:leads.view'])->group(function () {
            Route::get('/crm/leads', [CrmLeadController::class, 'index']);
            Route::get('/crm/leads/summary', [CrmLeadController::class, 'summary']);
            Route::get('/crm/leads/options', [CrmLeadController::class, 'options']);
            Route::get('/crm/leads/{lead}', [CrmLeadController::class, 'show']);
        });
        Route::middleware(['module:crm', 'permission:leads.manage'])->group(function () {
            Route::post('/crm/leads', [CrmLeadController::class, 'store']);
            Route::put('/crm/leads/{lead}', [CrmLeadController::class, 'update']);
            Route::post('/crm/leads/{lead}/contact', [CrmLeadController::class, 'contact']);
            Route::post('/crm/leads/{lead}/qualify', [CrmLeadController::class, 'qualify']);
            Route::post('/crm/leads/{lead}/disqualify', [CrmLeadController::class, 'disqualify']);
            // Desconectado (2026-10-08): convertía el lead en cliente sin buscar duplicados. El cliente
            // se crea o se reutiliza al convertir la oportunidad ganada (/crm/opportunities/{id}/convert).
            // Route::post('/crm/leads/{lead}/convert', [CrmLeadController::class, 'convert']);
        });

        // Pipeline de oportunidades
        // Actividades comerciales (respetan el alcance del rol)
        Route::middleware(['module:crm', 'permission:activities.view'])->group(function () {
            Route::get('/crm/activities', [CrmActivityController::class, 'index']);
            Route::get('/crm/activities/summary', [CrmActivityController::class, 'summary']);
            Route::get('/crm/activities/options', [CrmActivityController::class, 'options']);
        });
        Route::middleware(['module:crm', 'permission:activities.manage'])->group(function () {
            Route::post('/crm/activities', [CrmActivityController::class, 'store']);
            Route::put('/crm/activities/{activity}', [CrmActivityController::class, 'update']);
            Route::post('/crm/activities/{activity}/toggle', [CrmActivityController::class, 'toggle']);
        });

        // Campañas y sus miembros
        Route::middleware(['module:crm', 'permission:campaigns.view'])->group(function () {
            Route::get('/crm/campaigns', [CrmCampaignController::class, 'index']);
            Route::get('/crm/campaigns/summary', [CrmCampaignController::class, 'summary']);
            Route::get('/crm/campaigns/{campaign}', [CrmCampaignController::class, 'show']);
        });
        Route::middleware(['module:crm', 'permission:campaigns.manage'])->group(function () {
            Route::post('/crm/campaigns', [CrmCampaignController::class, 'store']);
            Route::put('/crm/campaigns/{campaign}', [CrmCampaignController::class, 'update']);
            Route::post('/crm/campaigns/{campaign}/launch', [CrmCampaignController::class, 'launch']);
            Route::post('/crm/campaigns/{campaign}/complete', [CrmCampaignController::class, 'complete']);
            Route::post('/crm/campaigns/{campaign}/cancel', [CrmCampaignController::class, 'cancel']);
            Route::post('/crm/campaigns/{campaign}/members', [CrmCampaignController::class, 'addMember']);
            Route::post('/crm/campaigns/{campaign}/members/{member}/exclude', [CrmCampaignController::class, 'excludeMember']);
        });

        // Puntuación de leads: verla pide ver leads; cambiarla es un permiso propio
        Route::middleware(['module:crm', 'permission:leads.view'])->get('/crm/scoring', [CrmScoringController::class, 'show']);
        Route::middleware(['module:crm', 'permission:scoring.manage'])->group(function () {
            Route::put('/crm/scoring/rules', [CrmScoringController::class, 'updateRules']);
            Route::post('/crm/scoring/rescore', [CrmScoringController::class, 'rescore']);
        });

        Route::middleware(['module:crm', 'permission:opportunities.view'])->group(function () {
            Route::get('/crm/opportunities', [CrmOpportunityController::class, 'index']);
            Route::get('/crm/opportunities/summary', [CrmOpportunityController::class, 'summary']);
            Route::get('/crm/opportunities/options', [CrmOpportunityController::class, 'options']);
            Route::get('/crm/opportunities/{opportunity}', [CrmOpportunityController::class, 'show']);
        });
        Route::middleware(['module:crm', 'permission:opportunities.manage'])->group(function () {
            // Calificar un lead y crear su oportunidad.
            Route::post('/crm/leads/{lead}/opportunity', [CrmLeadController::class, 'createOpportunity']);
            Route::post('/crm/opportunities', [CrmOpportunityController::class, 'store']);
            Route::put('/crm/opportunities/{opportunity}', [CrmOpportunityController::class, 'update']);
            Route::post('/crm/opportunities/{opportunity}/stage', [CrmOpportunityController::class, 'moveStage']);
            Route::post('/crm/opportunities/{opportunity}/win', [CrmOpportunityController::class, 'win']);
            Route::post('/crm/opportunities/{opportunity}/lose', [CrmOpportunityController::class, 'lose']);
            Route::post('/crm/opportunities/{opportunity}/convert', [CrmOpportunityController::class, 'convert']);
        });

        // Rutas de prueba de permisos: solo en desarrollo y en las pruebas automáticas.
        if (app()->environment('local', 'testing')) {
            Route::middleware('permission:users.view')->get('/test/users-permission', function () {
                return response()->json(['message' => 'Tienes permiso para ver usuarios.']);
            });

            // Requiere un permiso que tu usuario NO tiene
            Route::middleware('permission:system.admin')->get('/test/no-permission', function () {
                return response()->json(['message' => 'No deberías poder ver esto.']);
            });
        }
    });

    Route::middleware(['auth:sanctum', 'super.admin'])->prefix('superadmin')->group(function () {
        // Panel de la consola: solo totales de plataforma, leídos con el usuario de base de la consola.
        Route::get('/dashboard', [\App\Modules\Subscription\Http\Controllers\SuperAdmin\PlatformDashboardController::class, 'show']);
        Route::post('/dashboard/exports', [\App\Modules\Subscription\Http\Controllers\SuperAdmin\PlatformDashboardController::class, 'export'])->middleware('throttle:30,1,platform-export');

        // Gestión de Planes SaaS (CRUD completo)
        Route::get('/plans', [SaasPlanController::class, 'adminIndex']);
        Route::post('/plans', [SaasPlanController::class, 'store']);
        Route::put('/plans/{id}', [SaasPlanController::class, 'update']);
        Route::delete('/plans/{id}', [SaasPlanController::class, 'destroy']);

        // Catálogo de módulos del ERP (ventana de planes y fichas)
        Route::get('/modules', [\App\Modules\Subscription\Http\Controllers\SuperAdmin\PlatformTenantController::class, 'modules']);

        // Tenants / Empresas: lista, ficha, aprovisionar, estado, plan, módulos e invitación
        Route::get('/tenants', [\App\Modules\Subscription\Http\Controllers\SuperAdmin\PlatformTenantController::class, 'index']);
        Route::get('/tenants/options', [\App\Modules\Subscription\Http\Controllers\SuperAdmin\PlatformTenantController::class, 'options']);
        Route::post('/tenants', [\App\Modules\Subscription\Http\Controllers\SuperAdmin\PlatformTenantController::class, 'store'])->middleware('throttle:20,1,platform-provision');
        Route::get('/tenants/{tenant}', [\App\Modules\Subscription\Http\Controllers\SuperAdmin\PlatformTenantController::class, 'show']);
        Route::post('/tenants/{tenant}/transitions', [\App\Modules\Subscription\Http\Controllers\SuperAdmin\PlatformTenantController::class, 'transition']);
        Route::put('/tenants/{tenant}/plan', [\App\Modules\Subscription\Http\Controllers\SuperAdmin\PlatformTenantController::class, 'changePlan']);
        Route::post('/tenants/{tenant}/extend-subscription', [\App\Modules\Subscription\Http\Controllers\SuperAdmin\PlatformTenantController::class, 'extendSubscription']);
        Route::post('/tenants/{tenant}/apply-plan-modules', [\App\Modules\Subscription\Http\Controllers\SuperAdmin\PlatformTenantController::class, 'applyPlanModules']);
        Route::post('/tenants/{tenant}/admins/{user}/invitation', [\App\Modules\Subscription\Http\Controllers\SuperAdmin\PlatformTenantController::class, 'resendInvitation'])->middleware('throttle:10,1,platform-invite');

        // Suscripciones de todas las empresas
        Route::get('/subscriptions', [\App\Modules\Subscription\Http\Controllers\SuperAdmin\PlatformAuditController::class, 'subscriptions']);

        // Configuración global de la plataforma (soporte, canales, etc.)
        Route::get('/settings', [\App\Modules\Subscription\Http\Controllers\SuperAdmin\PlatformSettingsController::class, 'show']);
        Route::put('/settings', [\App\Modules\Subscription\Http\Controllers\SuperAdmin\PlatformSettingsController::class, 'update']);

        // Auditoría de plataforma (acciones del super admin)
        Route::get('/audit', [\App\Modules\Subscription\Http\Controllers\SuperAdmin\PlatformAuditController::class, 'index']);
        Route::get('/audit/options', [\App\Modules\Subscription\Http\Controllers\SuperAdmin\PlatformAuditController::class, 'options']);
    });

    // Activar la invitación al portal (sin sesión): código del correo + contraseña.
    Route::post('/portal/activate', [PortalController::class, 'activate'])->middleware('throttle:10,1,portal-activate');

    // Portal del cliente: su propio contexto (portal.user); nunca pasa por tenant.context.
    // portal.section:seccion exige el portal activo en la empresa y la sección en el rol del portal.
    Route::prefix('portal')->middleware(['auth:sanctum', 'portal.user', 'idempotency', 'record.version'])->group(function () {
        Route::get('/me', [PortalController::class, 'me']);
        // La marca de la empresa, para la barra del portal y sus documentos.
        Route::get('/branding', [\App\Http\Controllers\BrandingController::class, 'show']);
        Route::post('/me/password', [\App\Http\Controllers\Auth\AuthController::class, 'changePassword'])->middleware('throttle:5,1,password');
        Route::middleware('portal.section:home')->get('/home', [PortalController::class, 'home']);

        Route::middleware('portal.section:quotes')->group(function () {
            Route::get('/quotations', [PortalCommercialController::class, 'quotations']);
            Route::get('/quotations/{quotation}', [PortalCommercialController::class, 'quotation']);
        });
        Route::middleware('portal.section:approve')->post('/quotations/{quotation}/decision', [PortalCommercialController::class, 'decide']);

        Route::middleware('portal.section:services')->get('/services', [PortalCommercialController::class, 'services']);
        // PDF de un contrato: desde Mis servicios o desde Documentos (el controlador pide una de las dos secciones).
        Route::get('/contracts/{contract}', [PortalCommercialController::class, 'contract']);
        Route::middleware('portal.section:sign')->group(function () {
            Route::get('/signatures', [PortalCommercialController::class, 'signatures']);
            Route::post('/signatures/{signatureRequest}/code', [PortalCommercialController::class, 'signatureCode'])->middleware('throttle:5,1,sign-code');
            Route::post('/signatures/{signatureRequest}/sign', [PortalCommercialController::class, 'sign']);
            Route::post('/signatures/{signatureRequest}/decline', [PortalCommercialController::class, 'declineSignature']);
        });
        Route::middleware('portal.section:requests')->group(function () {
            Route::post('/services/{serviceLine}/renewal', [PortalCommercialController::class, 'renewal']);
            Route::get('/catalog', [PortalCommercialController::class, 'catalog']);
            Route::get('/requests', [PortalCommercialController::class, 'requests']);
            Route::post('/requests', [PortalCommercialController::class, 'storeRequest']);
        });

        Route::middleware('portal.section:documents')->group(function () {
            Route::get('/documents', [PortalFinanceController::class, 'documents']);
            // {file}, no {document}: descargar no cambia la versión del documento que se edita en el sistema.
            Route::post('/documents/{file}/temporary-url', [PortalFinanceController::class, 'documentUrl']);
        });
        Route::middleware('portal.section:invoices')->group(function () {
            Route::get('/invoices', [PortalFinanceController::class, 'invoices']);
            Route::get('/invoices/{invoice}', [PortalFinanceController::class, 'invoice']);
            Route::get('/payments/{payment}', [PortalFinanceController::class, 'payment']);
            Route::get('/statement', [PortalFinanceController::class, 'statement']);
        });

        Route::middleware('portal.section:support')->group(function () {
            Route::get('/tickets', [PortalSupportController::class, 'index']);
            Route::get('/tickets/{ticket}', [PortalSupportController::class, 'show']);
        });
        // Crear y responder tickets no es de "Solo lectura": solo los roles con support_write.
        Route::middleware('portal.section:support_write')->group(function () {
            Route::post('/tickets', [PortalSupportController::class, 'store']);
            Route::post('/tickets/{ticket}/comments', [PortalSupportController::class, 'comment']);
        });

        Route::middleware('portal.section:users')->group(function () {
            Route::get('/users', [PortalTeamController::class, 'index']);
            Route::post('/users', [PortalTeamController::class, 'store']);
            Route::patch('/users/{portalUser}', [PortalTeamController::class, 'update']);
            Route::post('/users/{portalUser}/resend', [PortalTeamController::class, 'resend']);
        });
    });
});