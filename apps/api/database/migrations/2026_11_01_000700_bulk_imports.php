<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Importación masiva (Administración): historial de cada importación y permisos propios.
 *
 * - import_jobs: una fila por archivo importado (desde la pantalla o desde el listado de cada
 *   módulo), con cuántas filas entraron, se actualizaron, se omitieron o tuvieron error, y el
 *   detalle de las que no entraron. Aislada por empresa; la app solo agrega y lee.
 * - Permisos imports.view / imports.create / imports.export. Importar pide imports.create además
 *   del permiso de crear de cada entidad. Quien ya importaba algo conserva importar; Gerencia ve
 *   (matriz del prototipo). Exportar el historial: quien lo ve, salvo Ventas.
 * - Alcance "Importaciones": lo propio es lo que la persona importó. Gerencia y Finanzas, toda la
 *   empresa; los demás, lo propio.
 *
 * Solo añade. Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS import_jobs (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                entity varchar(40) NOT NULL,
                file_name varchar(255) NOT NULL,
                total_rows integer NOT NULL DEFAULT 0 CHECK (total_rows >= 0),
                created_count integer NOT NULL DEFAULT 0 CHECK (created_count >= 0),
                updated_count integer NOT NULL DEFAULT 0 CHECK (updated_count >= 0),
                skipped_count integer NOT NULL DEFAULT 0 CHECK (skipped_count >= 0),
                error_count integer NOT NULL DEFAULT 0 CHECK (error_count >= 0),
                status varchar(20) NOT NULL CHECK (status IN ('COMPLETED', 'PARTIAL', 'FAILED')),
                details jsonb NOT NULL DEFAULT '[]'::jsonb,
                created_by uuid REFERENCES users(id) ON DELETE SET NULL,
                -- La sede de quien importó: para el alcance "Sede".
                branch_id uuid REFERENCES branches(id) ON DELETE SET NULL,
                created_at timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX IF NOT EXISTS import_jobs_tenant ON import_jobs (tenant_id, created_at DESC);
            ALTER TABLE import_jobs ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON import_jobs;
            CREATE POLICY tenant_isolation ON import_jobs
                USING (tenant_id = app_tenant_id())
                WITH CHECK (tenant_id = app_tenant_id());
            REVOKE ALL ON import_jobs FROM erp_app;
            GRANT SELECT, INSERT ON import_jobs TO erp_app;

            INSERT INTO permissions (code, name, description) VALUES
                ('imports.view', 'Ver importaciones', 'Abrir Importación masiva y ver el historial de importaciones.'),
                ('imports.create', 'Importar', 'Importar desde Excel o CSV. Pide además el permiso de crear de cada entidad.'),
                ('imports.export', 'Exportar importaciones', 'Exportar el historial de importaciones.')
            ON CONFLICT (code) DO NOTHING;

            -- Quien ya importaba (Clientes y actores, Servicios, Leads, Plantillas de checklist, Asientos) lo conserva.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT DISTINCT rp.role_id, p.id
            FROM role_permissions rp
            JOIN permissions q ON q.id = rp.permission_id AND q.code IN ('core.manage', 'services.manage', 'leads.manage', 'checklists.manage', 'accounting.create')
            JOIN roles r ON r.id = rp.role_id AND r.tenant_id IS NOT NULL
            JOIN permissions p ON p.code IN ('imports.view', 'imports.create')
            ON CONFLICT DO NOTHING;

            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN ('imports.view', 'imports.create', 'imports.export')
            WHERE r.tenant_id IS NOT NULL AND (r.is_system OR upper(r.code) = 'ADMIN')
            ON CONFLICT DO NOTHING;

            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN ('imports.view', 'imports.export')
            WHERE r.tenant_id IS NOT NULL AND upper(r.code) IN ('GERENCIA', 'FINANZAS')
            ON CONFLICT DO NOTHING;

            ALTER TABLE role_scopes DROP CONSTRAINT IF EXISTS role_scopes_resource_check;
            ALTER TABLE role_scopes ADD CONSTRAINT role_scopes_resource_check
                CHECK (resource IN ('quotations', 'contracts', 'orders', 'portal', 'leads', 'opportunities', 'activities',
                                    'contracted_services', 'work_orders', 'projects', 'time_entries', 'cost_entries', 'agenda',
                                    'billing', 'receivables', 'payments', 'recurring', 'purchases', 'payables', 'budgets',
                                    'tickets', 'warranty', 'documents', 'imports'));
            INSERT INTO role_scopes (role_id, tenant_id, resource, scope)
            SELECT r.id, r.tenant_id, 'imports', 'TENANT' FROM roles r
            WHERE upper(r.code) IN ('GERENCIA', 'FINANZAS') AND r.tenant_id IS NOT NULL
            ON CONFLICT DO NOTHING;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM role_scopes WHERE resource = 'imports';
            ALTER TABLE role_scopes DROP CONSTRAINT IF EXISTS role_scopes_resource_check;
            ALTER TABLE role_scopes ADD CONSTRAINT role_scopes_resource_check
                CHECK (resource IN ('quotations', 'contracts', 'orders', 'portal', 'leads', 'opportunities', 'activities',
                                    'contracted_services', 'work_orders', 'projects', 'time_entries', 'cost_entries', 'agenda',
                                    'billing', 'receivables', 'payments', 'recurring', 'purchases', 'payables', 'budgets',
                                    'tickets', 'warranty', 'documents'));
            DELETE FROM role_permissions WHERE permission_id IN (SELECT id FROM permissions WHERE code LIKE 'imports.%');
            DELETE FROM permissions WHERE code LIKE 'imports.%';
            DROP TABLE IF EXISTS import_jobs;
        SQL);
    }
};
