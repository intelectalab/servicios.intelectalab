<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ModuleCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $modules = [
            // CORE — base del ERP (obligatorios)
            ['code' => 'CLIENTES',       'name' => 'Clientes',       'description' => 'Gestión de clientes',            'category' => 'CORE', 'is_core' => true,  'display_order' => 1],
            ['code' => 'PROVEEDORES',    'name' => 'Proveedores',    'description' => 'Gestión de proveedores',          'category' => 'CORE', 'is_core' => true,  'display_order' => 2],
            ['code' => 'EMPLEADOS',      'name' => 'Empleados',      'description' => 'Gestión de empleados',            'category' => 'CORE', 'is_core' => true,  'display_order' => 3],
            ['code' => 'SERVICIOS',      'name' => 'Servicios',      'description' => 'Catálogo y motor de servicios',   'category' => 'CORE', 'is_core' => true,  'display_order' => 4],
            ['code' => 'COTIZACIONES',   'name' => 'Cotizaciones',   'description' => 'Propuestas comerciales',          'category' => 'CORE', 'is_core' => true,  'display_order' => 5],
            ['code' => 'CONTRATOS',      'name' => 'Contratos',      'description' => 'Gestión contractual',             'category' => 'CORE', 'is_core' => true,  'display_order' => 6],
            ['code' => 'ORDENES_TRABAJO','name' => 'Órdenes de trabajo', 'description' => 'Operación y ejecución',       'category' => 'CORE', 'is_core' => true,  'display_order' => 7],
            ['code' => 'PROYECTOS',      'name' => 'Proyectos',      'description' => 'Planificación de proyectos',      'category' => 'CORE', 'is_core' => true,  'display_order' => 8],
            ['code' => 'TAREAS',         'name' => 'Tareas',         'description' => 'Actividades y seguimiento',       'category' => 'CORE', 'is_core' => true,  'display_order' => 9],
            ['code' => 'CHECKLISTS',     'name' => 'Checklists',     'description' => 'Listas de verificación',          'category' => 'CORE', 'is_core' => true,  'display_order' => 10],
            ['code' => 'AGENDA',         'name' => 'Agenda',         'description' => 'Citas y programación',            'category' => 'CORE', 'is_core' => true,  'display_order' => 11],
            ['code' => 'RECURSOS',       'name' => 'Recursos',       'description' => 'Personas y recursos',             'category' => 'CORE', 'is_core' => true,  'display_order' => 12],
            ['code' => 'DOCUMENTOS',     'name' => 'Documentos',     'description' => 'Gestión documental',              'category' => 'CORE', 'is_core' => true,  'display_order' => 13],
            ['code' => 'ENTREGABLES',    'name' => 'Entregables',    'description' => 'Control de entregas',             'category' => 'CORE', 'is_core' => true,  'display_order' => 14],

            // Finanzas y facturación (opcionales)
            ['code' => 'FINANZAS',            'name' => 'Finanzas',            'description' => 'Ingresos, gastos y control', 'category' => 'FINANZAS_FACTURACION', 'is_core' => false, 'display_order' => 15],
            ['code' => 'FACTURACION_ELECTRONICA', 'name' => 'Facturación electrónica', 'description' => 'Facturación / SUNAT', 'category' => 'FINANZAS_FACTURACION', 'is_core' => false, 'display_order' => 16],
            ['code' => 'PAGOS_COBRANZAS',     'name' => 'Pagos y cobranzas',   'description' => 'Gestión de pagos',            'category' => 'FINANZAS_FACTURACION', 'is_core' => false, 'display_order' => 17],
            ['code' => 'RENTABILIDAD',        'name' => 'Rentabilidad',        'description' => 'Costos y margen',             'category' => 'FINANZAS_FACTURACION', 'is_core' => false, 'display_order' => 18],

            // Soporte y atención al cliente (opcionales)
            ['code' => 'SOPORTE',          'name' => 'Soporte',          'description' => 'Atención al cliente',         'category' => 'SOPORTE_ATENCION_CLIENTE', 'is_core' => false, 'display_order' => 19],
            ['code' => 'TICKETS',          'name' => 'Tickets',          'description' => 'Gestión de tickets de soporte','category' => 'SOPORTE_ATENCION_CLIENTE', 'is_core' => false, 'display_order' => 20],
            ['code' => 'TIMESHEETS',  'name' => 'Timesheets',  'description' => 'Horas trabajadas',    'category' => 'OPERACIONES_SERVICIO', 'is_core' => false, 'display_order' => 21],
        ];

        foreach ($modules as $module) {
            $exists = DB::table('module_catalog')->where('code', $module['code'])->exists();

            if ($exists) {
                DB::table('module_catalog')->where('code', $module['code'])->update(
                    array_merge($module, ['updated_at' => now()])
                );
                continue;
            }

            DB::table('module_catalog')->insert(array_merge($module, [
                'id' => (string) Str::uuid(),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }
}
