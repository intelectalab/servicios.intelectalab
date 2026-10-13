<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Cómo se lee en pantalla lo que guarda la auditoría: `entity_type` ("Quotation") y `action`
 * ("quotation.approve") son códigos del sistema. Los textos viven aquí, en un solo lugar, para
 * que la tabla, el buscador, el orden y el archivo exportado digan lo mismo.
 */
class AuditCatalog
{
    /** entity_type => nombre visible. */
    public const ENTITIES = [
        'BillableEvent' => 'Evento facturable',
        'Branch' => 'Sede',
        'Checklist' => 'Checklist',
        'Contact' => 'Dato de contacto',
        'Contract' => 'Contrato',
        'ContractedService' => 'Servicio contratado',
        'CostEntry' => 'Costo',
        'CrmActivity' => 'Actividad',
        'CrmCampaign' => 'Campaña',
        'CrmScoring' => 'Puntuación de leads',
        'CredentialRecord' => 'Credencial',
        'CrmLead' => 'Lead',
        'CrmOpportunity' => 'Oportunidad',
        'Customer' => 'Cliente',
        'CustomFieldDefinition' => 'Columna personalizada',
        'CustomFieldValue' => 'Valor de columna personalizada',
        'Document' => 'Documento',
        'Employee' => 'Empleado',
        'ExchangeRate' => 'Tipo de cambio',
        'Export' => 'Exportación de listado',
        'Invoice' => 'Comprobante',
        'Order' => 'Orden',
        'Party' => 'Cliente o actor',
        'Project' => 'Proyecto',
        'AgendaEvent' => 'Evento de agenda',
        'Payment' => 'Cobro',
        'PortalRequest' => 'Solicitud del portal',
        'Quotation' => 'Cotización',
        'Resource' => 'Recurso',
        'ResourceAssignment' => 'Asignación de recurso',
        'Role' => 'Rol',
        'Service' => 'Servicio',
        'ServiceCategory' => 'Categoría de servicio',
        'Supplier' => 'Proveedor',
        'SupportTicket' => 'Ticket',
        'Task' => 'Tarea',
        'TaskChecklistItem' => 'Ítem de checklist',
        'Team' => 'Equipo',
        'Tenant' => 'Empresa',
        'TimeEntry' => 'Registro de horas',
        'User' => 'Usuario',
        'WorkOrder' => 'Orden de trabajo',
    ];

    /** Acciones cuyo texto no sale del verbo final. */
    public const ACTIONS = [
        'auth.login' => 'Inicio de sesión',
        'auth.logout' => 'Cierre de sesión',
        'auth.account_locked' => 'Cuenta bloqueada por intentos fallidos',
        'auth.password_changed' => 'Cambio de contraseña',
        'auth.password_reset' => 'Restablecimiento de contraseña',
        'auth.sessions_revoked' => 'Cierre de las demás sesiones',
        'role.permissions.update' => 'Cambio de permisos del rol',
        'role.scopes.update' => 'Cambio de alcance del rol',
        'tenant.settings.update' => 'Cambio de reglas de la empresa',
        'user.status' => 'Cambio de acceso del usuario',
        'user.notification_preferences' => 'Cambio de preferencias de notificación',
        'party.role.add' => 'Rol agregado',
        'service.price.create' => 'Nueva versión de precio',
        'quotation.version' => 'Nueva versión',
        'crm_lead.contact' => 'Lead contactado',
        'crm_lead.convert' => 'Conversión a oportunidad',
        'crm_opportunity.convert' => 'Conversión a cliente',
        'crm_opportunity.stage' => 'Cambio de etapa',
        'crm_activity.complete' => 'Actividad realizada',
        'crm_activity.reopen' => 'Actividad reabierta',
        'crm_campaign.launch' => 'Lanzamiento de campaña',
        'crm_campaign.member.add' => 'Miembro agregado',
        'crm_campaign.member.exclude' => 'Miembro excluido',
        'crm_scoring.update' => 'Cambio de reglas de puntuación',
        'crm_scoring.rescore' => 'Recálculo de puntajes',
        'work_order.block' => 'Bloqueo',
        'work_order.unblock' => 'Desbloqueo',
        'work_order.checklist.check' => 'Ítem de checklist marcado',
        'work_order.checklist.uncheck' => 'Ítem de checklist desmarcado',
        'work_order.checklist.approve' => 'Checklist aprobado',
        'work_order.checklist.return' => 'Checklist devuelto',
        'project.plan' => 'Planificación',
        'project.hold' => 'Pausa',
        'project.resume' => 'Reanudación',
        'project.complete' => 'Cierre del proyecto',
        'project.task.create' => 'Tarea creada',
        'project.task.update' => 'Tarea editada',
        'project.task.move' => 'Cambio de estado de tarea',
        'project.task.check' => 'Control de tarea marcado',
        'project.task.uncheck' => 'Control de tarea desmarcado',
        'work_order.checklist.evidence' => 'Foto de evidencia adjuntada',
        'work_order.checklist.evidence_download' => 'Foto de evidencia descargada',
        'work_order.sync_rejected' => 'Cambio sin conexión rechazado al sincronizar',
        'agenda.create' => 'Evento agendado',
        'agenda.update' => 'Evento de agenda editado',
        'agenda.done' => 'Evento marcado como realizado',
        'agenda.cancel' => 'Evento de agenda cancelado',
        'project.deliverable.create' => 'Entregable creado',
        'project.deliverable.update' => 'Entregable editado',
        'project.deliverable.attach' => 'Documento del entregable adjuntado',
        'project.deliverable.download' => 'Documento del entregable descargado',
        'project.deliverable.submit' => 'Entregable enviado a revisión',
        'project.deliverable.approve' => 'Entregable aprobado',
        'project.deliverable.return' => 'Entregable devuelto',
        'project.deliverable.deliver' => 'Entregable entregado al cliente',
        'billable_event.cancel' => 'Evento facturable anulado',
        'invoice.create' => 'Comprobante en borrador generado',
        'invoice.discard' => 'Borrador de comprobante descartado',
        'invoice.credit_note' => 'Nota de crédito emitida',
        'invoice.correct' => 'Comprobante corregido con nota de crédito',
        'invoice.fiscal_retry' => 'Reintento de envío a SUNAT',
        'payment.create' => 'Cobro registrado',
        'payment.allocate' => 'Cobro aplicado a cuentas por cobrar',
        'payment.reconcile' => 'Cobro conciliado',
        'payment.refund' => 'Reembolso de cobro',
        'portal_request.quote' => 'Solicitud cotizada',
        'portal_request.take' => 'Solicitud tomada',
        'document.download_url_generated' => 'Enlace de descarga generado',
    ];

    /** Último tramo de la acción ("quotation.approve" → "approve") => texto. */
    public const VERBS = [
        'create' => 'Alta',
        'update' => 'Edición',
        'delete' => 'Eliminación',
        'save' => 'Registro',
        'set' => 'Edición de valor',
        'activate' => 'Activación',
        'deactivate' => 'Inactivación',
        'reactivate' => 'Reactivación',
        'bulk_activate' => 'Activación masiva',
        'bulk_deactivate' => 'Inactivación masiva',
        'import' => 'Importación',
        'export' => 'Exportación',
        'submit' => 'Envío a aprobación',
        'approve' => 'Aprobación',
        'reject' => 'Rechazo',
        'cancel' => 'Cancelación',
        'expire' => 'Vencimiento',
        'generate' => 'Generación',
        'suspend' => 'Suspensión',
        'terminate' => 'Terminación',
        'confirm' => 'Confirmación',
        'start' => 'Inicio de ejecución',
        'complete' => 'Finalización',
        'qualify' => 'Calificación',
        'disqualify' => 'Descarte',
        'win' => 'Cierre ganado',
        'lose' => 'Cierre perdido',
        'assign' => 'Asignación',
        'comment' => 'Comentario',
        'resolve' => 'Resolución',
        'close' => 'Cierre',
        'reopen' => 'Reapertura',
        'upload' => 'Carga de archivo',
        'register' => 'Registro',
        'issue' => 'Emisión',
        'reveal' => 'Revelado de secreto',
        'rotate' => 'Rotación',
        'revoke' => 'Revocación',
    ];

    /** Expresión SQL que da el último tramo de la acción. */
    public const VERB_SQL = "substring(audit_logs.action from '[^.]+$')";

    public static function entityLabel(?string $type): ?string
    {
        return $type === null ? null : (self::ENTITIES[$type] ?? $type);
    }

    public static function actionLabel(string $action): string
    {
        return self::ACTIONS[$action] ?? self::VERBS[Str::afterLast($action, '.')] ?? $action;
    }

    /** Expresión SQL con el texto visible de la acción, para ordenar y filtrar por lo que se lee. */
    public static function actionLabelSql(): string
    {
        $cases = fn (array $labels) => implode('', array_map(
            fn (string $code, string $label) => " WHEN '{$code}' THEN '{$label}'", array_keys($labels), $labels,
        ));

        return 'CASE audit_logs.action'.$cases(self::ACTIONS)
            .' ELSE CASE '.self::VERB_SQL.$cases(self::VERBS).' ELSE audit_logs.action END END';
    }

    /**
     * Añade a una búsqueda las filas cuyo texto visible (entidad o acción) contiene lo buscado,
     * para que "cotización" o "aprobación" encuentren lo que la tabla muestra con esas palabras.
     *
     * @param  \Illuminate\Database\Query\Builder  $where  grupo OR de la búsqueda
     */
    public static function orWhereLabelMatches($where, string $search): void
    {
        $needle = self::plain($search);
        $matching = fn (array $labels) => array_keys(array_filter($labels, fn (string $label) => str_contains(self::plain($label), $needle)));

        if ($entities = $matching(self::ENTITIES)) {
            $where->orWhereIn('audit_logs.entity_type', $entities);
        }
        if ($actions = $matching(self::ACTIONS)) {
            $where->orWhereIn('audit_logs.action', $actions);
        }
        if ($verbs = $matching(self::VERBS)) {
            // El verbo solo da el texto de las acciones que no tienen uno propio.
            $where->orWhere(fn ($q) => $q
                ->whereIn(\Illuminate\Support\Facades\DB::raw(self::VERB_SQL), $verbs)
                ->whereNotIn('audit_logs.action', array_keys(self::ACTIONS)));
        }
    }

    /** Sin tildes ni mayúsculas: solo para comparar. */
    private static function plain(string $text): string
    {
        return mb_strtolower(Str::ascii($text));
    }
}
