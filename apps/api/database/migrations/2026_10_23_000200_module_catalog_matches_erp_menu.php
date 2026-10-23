<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    // El catálogo de módulos pasa a ser el del menú real del ERP: un módulo por
    // grupo de pantallas, con Compras y Contabilidad aparte de Finanzas. Los que
    // no abrían nada propio se desactivan (no se borran). Para que nadie pierda
    // acceso, toda empresa y todo plan con Finanzas reciben Compras y Contabilidad.
    // Correr como erp_user.

    /** código, nombre, descripción, categoría, obligatorio, prefijos de permisos. */
    public const MODULES = [
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

    /** No abrían pantallas propias: se desactivan, sus filas se conservan. */
    public const RETIRED = ['PROVEEDORES', 'EMPLEADOS', 'TAREAS', 'CHECKLISTS', 'RECURSOS', 'ENTREGABLES', 'RENTABILIDAD', 'TICKETS', 'FACTURACION_ELECTRONICA', 'PAGOS_COBRANZAS'];

    public function up(): void
    {
        foreach (self::MODULES as $order => [$code, $name, $description, $category, $core, $prefixes]) {
            $row = [
                'name' => $name, 'description' => $description, 'category' => $category, 'is_core' => $core, 'active' => true,
                'display_order' => $order + 1, 'permission_prefixes' => json_encode($prefixes), 'updated_at' => now(),
            ];
            if (DB::table('module_catalog')->where('code', $code)->exists()) {
                DB::table('module_catalog')->where('code', $code)->update($row);
            } else {
                DB::table('module_catalog')->insert($row + ['code' => $code, 'created_at' => now()]);
            }
        }

        DB::table('module_catalog')->whereIn('code', self::RETIRED)
            ->update(['active' => false, 'is_core' => false, 'display_order' => 99, 'updated_at' => now()]);

        // Empresas con Finanzas encendido: también Compras y Contabilidad (lo que ya usaban).
        DB::statement(<<<'SQL'
            INSERT INTO tenant_modules (tenant_id, module_code, enabled, activated_at, configuration, created_at, updated_at)
            SELECT tm.tenant_id, extra.code, true, now(), '{}'::jsonb, now(), now()
            FROM tenant_modules tm
            CROSS JOIN (VALUES ('COMPRAS'), ('CONTABILIDAD')) AS extra(code)
            WHERE upper(tm.module_code) = 'FINANZAS' AND tm.enabled
            ON CONFLICT (tenant_id, module_code) DO NOTHING
        SQL);

        // Planes: sin los módulos retirados ni los que no existen; con Finanzas, también Compras y Contabilidad.
        $active = array_column(self::MODULES, 0);
        foreach (DB::table('saas_plans')->get(['id', 'modules']) as $plan) {
            $modules = collect(json_decode($plan->modules, true) ?: [])
                ->mapWithKeys(fn ($on, $code) => [strtoupper($code) => (bool) $on])
                ->filter(fn ($on, $code) => $on && in_array($code, $active, true));
            if ($modules->get('FINANZAS')) {
                $modules = $modules->merge(['COMPRAS' => true, 'CONTABILIDAD' => true]);
            }
            $modules = $modules->merge(['CLIENTES' => true]);
            DB::table('saas_plans')->where('id', $plan->id)->update(['modules' => $modules->toJson(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('module_catalog')->whereIn('code', self::RETIRED)->update(['active' => true]);
        DB::table('module_catalog')->whereIn('code', ['PROVEEDORES', 'EMPLEADOS'])->update(['is_core' => true]);
        DB::table('module_catalog')->where('code', 'FINANZAS')->update(['permission_prefixes' => json_encode(
            ['billing', 'finance', 'receivables', 'payments', 'fiscal', 'recurring', 'treasury', 'purchases', 'payables', 'budgets', 'accounting']
        )]);
        DB::table('module_catalog')->whereIn('code', ['COMPRAS', 'CONTABILIDAD'])->update(['active' => false]);
    }
};
