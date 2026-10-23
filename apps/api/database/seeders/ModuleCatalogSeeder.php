<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ModuleCatalogSeeder extends Seeder
{
    /**
     * Catálogo maestro de módulos: uno por grupo de pantallas del menú del ERP.
     * Es la misma lista que fija la migración 2026_10_23_000200 (ver ahí el
     * detalle): código, nombre, descripción, categoría, is_core y los prefijos de
     * permisos que el módulo otorga. is_core = obligatorio en todos los tenants.
     */
    private const MODULES = [
        ['CLIENTES', 'Clientes y actores', 'Clientes, proveedores, empleados y contactos', 'CORE', true, ['core']],
        ['SERVICIOS', 'Servicios', 'Catálogo de servicios y precios', 'COMERCIAL', false, ['services']],
        ['COTIZACIONES', 'Cotizaciones', 'Cotizaciones y solicitudes del portal', 'COMERCIAL', false, ['quotations', 'portal']],
        ['CONTRATOS', 'Contratos', 'Contratos y órdenes', 'COMERCIAL', false, ['contracts', 'orders']],
        ['ORDENES_TRABAJO', 'Órdenes de trabajo', 'Órdenes de trabajo, servicios contratados, checklists y app del técnico', 'OPERACIONES', false, ['operations', 'work_orders', 'contracted_services', 'checklists']],
        ['PROYECTOS', 'Proyectos', 'Proyectos, tareas y entregables', 'OPERACIONES', false, ['projects', 'tasks', 'deliverables', 'contracted_services']],
        ['TIMESHEETS', 'Horas y costos', 'Horas trabajadas y costos', 'OPERACIONES', false, ['operations', 'time_entries', 'cost_entries']],
        ['AGENDA', 'Agenda', 'Citas y programación', 'OPERACIONES', false, ['agenda']],
        ['FINANZAS', 'Finanzas', 'Por facturar, comprobantes, cuentas por cobrar, cobros, recurrencia, bancos y caja, facturación electrónica y tipo de cambio', 'FINANZAS', false, ['billing', 'finance', 'receivables', 'payments', 'fiscal', 'recurring', 'treasury']],
        ['COMPRAS', 'Compras', 'Órdenes de compra, comprobantes de proveedor y cuentas por pagar', 'COMPRAS', false, ['purchases', 'payables']],
        ['CONTABILIDAD', 'Contabilidad', 'Libro diario, mayor y balance, estados financieros, plan de cuentas, centros de costo y presupuestos', 'CONTABILIDAD', false, ['accounting', 'budgets']],
        ['SOPORTE', 'Soporte', 'Tickets y SLA, garantías y calendario laboral', 'SOPORTE', false, ['tickets', 'warranty', 'sla']],
        ['CRM', 'CRM', 'Leads, pipeline, actividades, campañas y puntuación', 'CRM', false, ['leads', 'opportunities', 'activities', 'campaigns', 'scoring']],
        ['PORTAL', 'Portal de clientes', 'Acceso externo para los clientes: cotizaciones, servicios, documentos, facturas, solicitudes y soporte.', 'PORTAL', false, ['portal_users']],
        ['DOCUMENTOS', 'Documentos', 'Gestión documental con versiones', 'CONTROL', false, ['documents']],
    ];

    /** Módulos retirados (no abrían pantallas propias): quedan inactivos. */
    private const RETIRED = ['PROVEEDORES', 'EMPLEADOS', 'TAREAS', 'CHECKLISTS', 'RECURSOS', 'ENTREGABLES', 'RENTABILIDAD', 'TICKETS', 'FACTURACION_ELECTRONICA', 'PAGOS_COBRANZAS'];

    public function run(): void
    {
        foreach (self::MODULES as $order => [$code, $name, $description, $category, $isCore, $prefixes]) {
            $module = [
                'code' => $code,
                'name' => $name,
                'description' => $description,
                'category' => $category,
                'is_core' => $isCore,
                'permission_prefixes' => json_encode($prefixes),
                'display_order' => $order + 1,
                'updated_at' => now(),
            ];

            if (DB::table('module_catalog')->where('code', $code)->exists()) {
                DB::table('module_catalog')->where('code', $code)->update($module);
                continue;
            }

            DB::table('module_catalog')->insert($module + [
                'id' => (string) Str::uuid(),
                'active' => true,
                'created_at' => now(),
            ]);
        }

        DB::table('module_catalog')->whereIn('code', self::RETIRED)->update(['active' => false, 'is_core' => false, 'updated_at' => now()]);
    }
}
