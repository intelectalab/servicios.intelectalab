<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ModuleCatalogSeeder extends Seeder
{
    /**
     * Catálogo maestro de módulos. Cada fila: código, nombre, descripción,
     * categoría, is_core y los prefijos de permisos que el módulo otorga
     * (tabla 'permissions': "<prefijo>.view" y "<prefijo>.manage").
     *
     * is_core = obligatorio: está activo en todos los tenants sin depender del
     * plan. Son los tres que comparten el permiso "core". El resto de la
     * categoría CORE viene marcado por defecto en los planes, pero sigue al plan.
     */
    private const MODULES = [
        // CORE — base del ERP
        ['CLIENTES', 'Clientes', 'Gestión de clientes', 'CORE', true, ['core']],
        ['PROVEEDORES', 'Proveedores', 'Gestión de proveedores', 'CORE', true, ['core']],
        ['EMPLEADOS', 'Empleados', 'Gestión de empleados', 'CORE', true, ['core']],
        ['SERVICIOS', 'Servicios', 'Catálogo y motor de servicios', 'CORE', false, ['services']],
        ['COTIZACIONES', 'Cotizaciones', 'Propuestas comerciales', 'CORE', false, ['quotations']],
        ['CONTRATOS', 'Contratos', 'Gestión contractual', 'CORE', false, ['contracts', 'orders']],
        ['ORDENES_TRABAJO', 'Órdenes de trabajo', 'Operación y ejecución', 'CORE', false, ['operations']],
        ['PROYECTOS', 'Proyectos', 'Planificación de proyectos', 'CORE', false, []],
        ['TAREAS', 'Tareas', 'Actividades y seguimiento', 'CORE', false, ['operations']],
        ['CHECKLISTS', 'Checklists', 'Listas de verificación', 'CORE', false, ['operations']],
        ['AGENDA', 'Agenda', 'Citas y programación', 'CORE', false, []],
        ['RECURSOS', 'Recursos', 'Personas y recursos', 'CORE', false, ['operations']],
        ['DOCUMENTOS', 'Documentos', 'Gestión documental', 'CORE', false, ['documents']],
        ['ENTREGABLES', 'Entregables', 'Control de entregas', 'CORE', false, []],

        // Finanzas y facturación
        ['FINANZAS', 'Finanzas', 'Ingresos, gastos y control', 'FINANZAS_FACTURACION', false, ['billing', 'finance']],
        ['FACTURACION_ELECTRONICA', 'Facturación electrónica', 'Facturación / SUNAT', 'FINANZAS_FACTURACION', false, ['billing']],
        ['PAGOS_COBRANZAS', 'Pagos y cobranzas', 'Gestión de pagos', 'FINANZAS_FACTURACION', false, ['billing']],
        ['RENTABILIDAD', 'Rentabilidad', 'Costos y margen', 'FINANZAS_FACTURACION', false, []],

        // Soporte y atención al cliente
        ['SOPORTE', 'Soporte', 'Atención al cliente', 'SOPORTE_ATENCION_CLIENTE', false, ['support']],
        ['TICKETS', 'Tickets', 'Gestión de tickets de soporte', 'SOPORTE_ATENCION_CLIENTE', false, ['support']],

        // Operaciones y servicio
        ['TIMESHEETS', 'Timesheets', 'Horas trabajadas', 'OPERACIONES_SERVICIO', false, ['operations']],

        // CRM — opcional
        ['CRM', 'CRM', 'Leads, oportunidades y pipeline', 'CRM', false, ['leads', 'opportunities']],
    ];

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
    }
}
