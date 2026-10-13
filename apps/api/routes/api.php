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
use App\Modules\Operations\Http\Controllers\ContractedServiceController;
use App\Modules\Operations\Http\Controllers\CostEntryController;
use App\Modules\Operations\Http\Controllers\AgendaController;
use App\Modules\Operations\Http\Controllers\FieldController;
use App\Modules\Operations\Http\Controllers\ProjectController;
use App\Modules\Operations\Http\Controllers\WorkOrderController;
use App\Modules\Operations\Http\Controllers\TaskController;
use App\Modules\Operations\Http\Controllers\ChecklistController;
use App\Modules\Operations\Http\Controllers\TimeEntryController;
use App\Modules\Operations\Http\Controllers\ResourceController;
use App\Modules\Documents\Http\Controllers\DocumentController;
use App\Modules\Finance\Http\Controllers\BillableEventController;
use App\Modules\Finance\Http\Controllers\ExchangeRateController;
use App\Modules\Finance\Http\Controllers\InvoiceController;
use App\Modules\Finance\Http\Controllers\PaymentController;
use App\Modules\Finance\Http\Controllers\ReceivableController;
use App\Modules\Support\Http\Controllers\SupportTicketController;
use App\Modules\Credentials\Http\Controllers\CredentialController;
use App\Modules\Crm\Http\Controllers\CrmActivityController;
use App\Modules\Crm\Http\Controllers\CrmCampaignController;
use App\Modules\Crm\Http\Controllers\CrmLeadController;
use App\Modules\Crm\Http\Controllers\CrmScoringController;
use App\Modules\Crm\Http\Controllers\CrmOpportunityController;
use App\Modules\Core\Http\Controllers\PartyController;
use App\Modules\Portal\Http\Controllers\PortalController;
use App\Modules\Portal\Http\Controllers\PortalRequestController;
use App\Modules\Core\Http\Controllers\EmployeeController;
use App\Modules\Core\Http\Controllers\SearchController;
use App\Modules\Core\Http\Controllers\AuditController;
use App\Modules\Core\Http\Controllers\OrgUnitController;
use App\Modules\Core\Http\Controllers\RoleController;
use App\Modules\Core\Http\Controllers\UserController;
use App\Modules\Core\Http\Controllers\TenantSettingsController;

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
        Route::post('/me/sessions/revoke-others', [AuthController::class, 'revokeOtherSessions']);
        Route::put('/me/notification-preferences', [AuthController::class, 'updateNotificationPreferences']);

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
            // App del técnico: deja en el historial los cambios sin conexión que no se pudieron aplicar.
            Route::post('/field/sync-log', [FieldController::class, 'syncLog']);
        });
        // Asignar el técnico y revisar el checklist son de quien supervisa, no de quien ejecuta.
        Route::middleware('permission:work_orders.approve')->group(function () {
            Route::post('/work-orders/{workOrder}/assign', [WorkOrderController::class, 'assign']);
            Route::post('/work-orders/{workOrder}/checklist/approve', [WorkOrderController::class, 'approveChecklist']);
            Route::post('/work-orders/{workOrder}/checklist/return', [WorkOrderController::class, 'returnChecklist']);
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

        // Documentos
        Route::middleware('permission:documents.view')->group(function () {
            Route::get('/documents', [DocumentController::class, 'index']);
            Route::get('/documents/{document}', [DocumentController::class, 'show']);
            Route::get('/documents/{document}/download-url', [DocumentController::class, 'downloadUrl']);
        });
        Route::middleware('permission:documents.manage')->post('/documents', [DocumentController::class, 'store']);

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
        Route::middleware('permission:fiscal.manage')->post('/exchange-rates', [ExchangeRateController::class, 'store']);

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