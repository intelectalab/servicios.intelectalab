<?php

namespace App\Modules\Portal\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Qué puede hacer cada rol del portal (la matriz del prototipo) y qué secciones abre la empresa.
 *
 * Una sección se abre si el rol la incluye y si la empresa tiene el módulo del que salen sus
 * datos: sin Finanzas no hay facturas que mostrar, sin Soporte no hay tickets.
 */
class PortalAccess
{
    public const MODULE = 'PORTAL';

    public const ROLES = [
        'PORTAL_ADMIN' => ['Portal Admin', ['home', 'quotes', 'approve', 'services', 'sign', 'requests', 'documents', 'invoices', 'pay', 'support', 'users']],
        'PORTAL_FINANCE' => ['Portal Finanzas', ['home', 'invoices', 'pay', 'documents']],
        'PORTAL_VIEWER' => ['Portal Solo lectura', ['home', 'quotes', 'services', 'documents', 'invoices', 'support']],
    ];

    /** Sección => módulos de la empresa de los que salen sus datos (basta uno). */
    private const SECTION_MODULES = [
        'quotes' => ['COTIZACIONES'],
        'approve' => ['COTIZACIONES'],
        'services' => ['CONTRATOS'],
        'sign' => ['CONTRATOS'],
        'requests' => ['COTIZACIONES'],
        'invoices' => ['FINANZAS'],
        'pay' => ['FINANZAS'],
        'support' => ['SOPORTE', 'TICKETS'],
    ];

    public const SECTION_LABELS = [
        'home' => 'Inicio', 'quotes' => 'Cotizaciones', 'approve' => 'Aprobar cotizaciones', 'services' => 'Mis servicios',
        'sign' => 'Firmar contratos', 'requests' => 'Solicitudes', 'documents' => 'Documentos', 'invoices' => 'Facturas',
        'pay' => 'Pagar en línea', 'support' => 'Soporte', 'users' => 'Usuarios',
    ];

    /**
     * Rol "Cliente (Portal)" del prototipo, para mostrarlo en Roles y permisos (solo lectura):
     * recurso => [acciones que el portal permite, qué ve el cliente]. Siempre solo lo de su
     * empresa; qué secciones abre cada persona lo decide su rol del portal. Refleja lo que hace
     * el portal hoy: lo que el prototipo da y aquí no existe va sin acciones y con su nota.
     */
    public const CUSTOMER_MATRIX = [
        'quotations' => [['view', 'approve'], 'Las suyas, sin borradores. Aprueba o rechaza con motivo (Portal Admin).'],
        'contracts' => [['view'], 'Sus contratos, sin borradores.'],
        'contracted_services' => [['view'], 'Sus servicios contratados y su renovación.'],
        'recurring' => [['view'], 'Fecha de renovación de sus servicios recurrentes.'],
        'work_orders' => [['view'], 'Sus trabajos en curso (asignados o en ejecución).'],
        'projects' => [[], 'Aún no en el portal: solo ve los entregables aprobados de sus proyectos.'],
        'deliverables' => [['view'], 'Los aprobados o entregados, con enlace temporal de descarga.'],
        'documents' => [['view'], 'Los marcados como visibles en el portal, con enlace temporal.'],
        'billing' => [['view'], 'Sus comprobantes emitidos, con su PDF.'],
        'receivables' => [['view'], 'Sus saldos pendientes y el estado de cuenta.'],
        'payments' => [['view'], 'Sus pagos, con su recibo. Pagar en línea: sin pasarela conectada.'],
        'tickets' => [['view', 'create', 'manage'], 'Los suyos: crear y responder. Nunca ve las notas internas.'],
        'warranty' => [[], 'Aún no en el portal: los reclamos los registra la empresa.'],
        'portal' => [['view', 'create'], 'Sus solicitudes de servicio y de renovación.'],
        'opportunities' => [['create'], 'Con CRM, cada solicitud crea una oportunidad (no la ve).'],
        'portal_users' => [['view', 'create', 'manage'], 'Los de su empresa: invitar, cambiar rol, desactivar (Portal Admin).'],
    ];

    public static function roleName(string $role): string
    {
        return self::ROLES[$role][0] ?? $role;
    }

    /** Módulos activos de la empresa (en mayúsculas). */
    public static function modules(string $tenantId): array
    {
        return DB::table('tenant_modules')->where('tenant_id', $tenantId)->where('enabled', true)
            ->pluck('module_code')->map(fn ($code) => strtoupper($code))->all();
    }

    /** Secciones que abre este rol en esta empresa. */
    public static function sections(string $role, array $modules): array
    {
        return array_values(array_filter(
            self::ROLES[$role][1] ?? [],
            fn (string $section) => ! isset(self::SECTION_MODULES[$section])
                || array_intersect(self::SECTION_MODULES[$section], $modules),
        ));
    }

    /** ¿La persona del portal de esta petición abre la sección? */
    public static function can(Request $request, string $section): bool
    {
        return in_array($section, $request->attributes->get('portal_sections', []), true);
    }
}
