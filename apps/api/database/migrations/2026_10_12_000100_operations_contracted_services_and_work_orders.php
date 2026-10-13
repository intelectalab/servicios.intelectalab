<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Operaciones, primera parte: Servicios contratados y Órdenes de trabajo con su checklist.
 *
 * Las tablas `contracted_services` y `work_orders` ya existían; se conservan y solo se les
 * añade lo que faltaba. Solo añade: no borra datos.
 *
 * Lo que la base garantiza por sí sola:
 *   - un servicio contratado suspendido o cancelado lleva su motivo;
 *   - una orden de trabajo asignada o en ejecución tiene técnico; bloqueada o cancelada, su motivo;
 *   - un servicio contratado tiene una sola instancia recurrente;
 *   - los ítems del checklist de una orden son una copia propia (cambiar la plantilla no los
 *     altera) y la aplicación no puede borrarlos.
 *
 * Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- 1. Servicios contratados: línea de origen, responsable, sede, motivo y versión.
            ALTER TABLE contracted_services
                ADD COLUMN IF NOT EXISTS contract_item_id uuid REFERENCES contract_items(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS owner_user_id uuid REFERENCES users(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS branch_id uuid REFERENCES branches(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS status_reason text,
                ADD COLUMN IF NOT EXISTS lock_version integer NOT NULL DEFAULT 0;
            -- Los que ya existían heredan responsable y sede de su contrato.
            UPDATE contracted_services cs
            SET owner_user_id = COALESCE(cs.owner_user_id, c.created_by), branch_id = COALESCE(cs.branch_id, c.branch_id)
            FROM contracts c
            WHERE c.id = cs.contract_id AND (cs.owner_user_id IS NULL OR cs.branch_id IS NULL);
            ALTER TABLE contracted_services DROP CONSTRAINT IF EXISTS contracted_services_status_check;
            ALTER TABLE contracted_services ADD CONSTRAINT contracted_services_status_check
                CHECK (status IN ('PENDING', 'ACTIVE', 'SUSPENDED', 'COMPLETED', 'CANCELLED'));
            ALTER TABLE contracted_services DROP CONSTRAINT IF EXISTS contracted_services_reason_check;
            ALTER TABLE contracted_services ADD CONSTRAINT contracted_services_reason_check
                CHECK (status NOT IN ('SUSPENDED', 'CANCELLED') OR btrim(coalesce(status_reason, '')) <> '');
            CREATE INDEX IF NOT EXISTS idx_contracted_services_tenant_status ON contracted_services (tenant_id, status);
            CREATE INDEX IF NOT EXISTS idx_contracted_services_contract ON contracted_services (contract_id);
            CREATE INDEX IF NOT EXISTS idx_contracted_services_owner ON contracted_services (tenant_id, owner_user_id);
            CREATE INDEX IF NOT EXISTS idx_contracted_services_branch ON contracted_services (tenant_id, branch_id);

            -- Activar un servicio recurrente crea su instancia una sola vez.
            CREATE UNIQUE INDEX IF NOT EXISTS uq_recurring_instances_contracted_service
                ON recurring_service_instances (contracted_service_id);

            -- 2. Órdenes de trabajo: título, origen, lugar, motivos, quién la creó, sede, checklist y versión.
            ALTER TABLE work_orders
                ADD COLUMN IF NOT EXISTS title varchar(255),
                ADD COLUMN IF NOT EXISTS contract_id uuid REFERENCES contracts(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS service_id uuid REFERENCES services(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS location varchar(255),
                ADD COLUMN IF NOT EXISTS blocked_reason text,
                ADD COLUMN IF NOT EXISTS cancel_reason text,
                ADD COLUMN IF NOT EXISTS started_at timestamptz,
                ADD COLUMN IF NOT EXISTS completed_at timestamptz,
                ADD COLUMN IF NOT EXISTS created_by uuid REFERENCES users(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS branch_id uuid REFERENCES branches(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS checklist_id uuid REFERENCES checklists(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS checklist_name varchar(255),
                ADD COLUMN IF NOT EXISTS checklist_status varchar(30),
                ADD COLUMN IF NOT EXISTS checklist_reviewed_by uuid REFERENCES users(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS checklist_reviewed_at timestamptz,
                ADD COLUMN IF NOT EXISTS checklist_review_note text,
                ADD COLUMN IF NOT EXISTS lock_version integer NOT NULL DEFAULT 0;
            -- Las que ya existían no tenían título: toman su descripción o su número.
            UPDATE work_orders SET title = COALESCE(NULLIF(left(btrim(description), 255), ''), code) WHERE title IS NULL;
            ALTER TABLE work_orders ALTER COLUMN title SET NOT NULL;
            UPDATE work_orders SET priority = 'NORMAL' WHERE priority NOT IN ('LOW', 'NORMAL', 'HIGH');
            UPDATE work_orders SET completed_at = updated_at WHERE status = 'COMPLETED' AND completed_at IS NULL;

            ALTER TABLE work_orders DROP CONSTRAINT IF EXISTS work_orders_status_check;
            ALTER TABLE work_orders ADD CONSTRAINT work_orders_status_check
                CHECK (status IN ('PLANNED', 'ASSIGNED', 'IN_PROGRESS', 'BLOCKED', 'COMPLETED', 'CANCELLED'));
            ALTER TABLE work_orders DROP CONSTRAINT IF EXISTS work_orders_priority_check;
            ALTER TABLE work_orders ADD CONSTRAINT work_orders_priority_check
                CHECK (priority IN ('LOW', 'NORMAL', 'HIGH'));
            ALTER TABLE work_orders DROP CONSTRAINT IF EXISTS work_orders_assignee_check;
            ALTER TABLE work_orders ADD CONSTRAINT work_orders_assignee_check
                CHECK (status NOT IN ('ASSIGNED', 'IN_PROGRESS') OR assigned_user_id IS NOT NULL);
            ALTER TABLE work_orders DROP CONSTRAINT IF EXISTS work_orders_blocked_reason_check;
            ALTER TABLE work_orders ADD CONSTRAINT work_orders_blocked_reason_check
                CHECK (status <> 'BLOCKED' OR btrim(coalesce(blocked_reason, '')) <> '');
            ALTER TABLE work_orders DROP CONSTRAINT IF EXISTS work_orders_cancel_reason_check;
            ALTER TABLE work_orders ADD CONSTRAINT work_orders_cancel_reason_check
                CHECK (status <> 'CANCELLED' OR btrim(coalesce(cancel_reason, '')) <> '');
            ALTER TABLE work_orders DROP CONSTRAINT IF EXISTS work_orders_schedule_check;
            ALTER TABLE work_orders ADD CONSTRAINT work_orders_schedule_check
                CHECK (scheduled_start IS NULL OR scheduled_end IS NULL OR scheduled_end >= scheduled_start);
            ALTER TABLE work_orders DROP CONSTRAINT IF EXISTS work_orders_checklist_status_check;
            ALTER TABLE work_orders ADD CONSTRAINT work_orders_checklist_status_check
                CHECK (checklist_status IS NULL OR checklist_status IN ('PENDING', 'IN_PROGRESS', 'COMPLETED', 'APPROVED'));
            CREATE INDEX IF NOT EXISTS idx_work_orders_assignee ON work_orders (tenant_id, assigned_user_id);
            CREATE INDEX IF NOT EXISTS idx_work_orders_creator ON work_orders (tenant_id, created_by);
            CREATE INDEX IF NOT EXISTS idx_work_orders_branch ON work_orders (tenant_id, branch_id);
            CREATE INDEX IF NOT EXISTS idx_work_orders_contracted_service ON work_orders (contracted_service_id) WHERE contracted_service_id IS NOT NULL;

            -- 3. Checklist de la orden: copia de los ítems de la plantilla elegida al crearla.
            CREATE TABLE IF NOT EXISTS work_order_checklist_items (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                work_order_id uuid NOT NULL REFERENCES work_orders(id) ON DELETE CASCADE,
                position integer NOT NULL,
                label varchar(500) NOT NULL,
                required boolean NOT NULL DEFAULT false,
                done boolean NOT NULL DEFAULT false,
                done_by uuid REFERENCES users(id) ON DELETE SET NULL,
                done_at timestamptz,
                evidence_note text,
                UNIQUE (work_order_id, position)
            );
            CREATE INDEX IF NOT EXISTS idx_work_order_checklist_items_tenant ON work_order_checklist_items (tenant_id);
            ALTER TABLE work_order_checklist_items ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON work_order_checklist_items;
            CREATE POLICY tenant_isolation ON work_order_checklist_items
                USING (tenant_id = app_tenant_id())
                WITH CHECK (tenant_id = app_tenant_id());
            -- Los ítems se marcan y se desmarcan; no se borran.
            REVOKE DELETE ON work_order_checklist_items FROM erp_app;

            -- 4. Permisos por recurso, como el resto del sistema.
            INSERT INTO permissions (code, name, description) VALUES
                ('contracted_services.view', 'Ver servicios contratados', 'Servicios que el cliente tiene contratados y su estado.'),
                ('contracted_services.manage', 'Gestionar servicios contratados', 'Activar, suspender, reactivar, completar y cancelar servicios contratados.'),
                ('contracted_services.export', 'Exportar servicios contratados', 'Descargar el listado en Excel o CSV.'),
                ('work_orders.view', 'Ver órdenes de trabajo', 'Órdenes de trabajo, su checklist y su historial.'),
                ('work_orders.create', 'Crear órdenes de trabajo', 'Crear una orden de trabajo desde un servicio contratado activo.'),
                ('work_orders.manage', 'Gestionar órdenes de trabajo', 'Editar, iniciar, bloquear, completar y cancelar órdenes de trabajo, y marcar su checklist.'),
                ('work_orders.approve', 'Asignar órdenes de trabajo y aprobar checklists', 'Asignar o reasignar el técnico y la fecha, y aprobar o devolver el checklist.'),
                ('work_orders.export', 'Exportar órdenes de trabajo', 'Descargar el listado en Excel o CSV.')
            ON CONFLICT (code) DO NOTHING;

            UPDATE module_catalog
            SET permission_prefixes = '["operations", "work_orders", "contracted_services"]'::jsonb
            WHERE upper(code) = 'ORDENES_TRABAJO';

            -- 5. Alcance por rol: también lo llevan los servicios contratados y las órdenes de trabajo.
            ALTER TABLE role_scopes DROP CONSTRAINT IF EXISTS role_scopes_resource_check;
            ALTER TABLE role_scopes ADD CONSTRAINT role_scopes_resource_check
                CHECK (resource IN ('quotations', 'contracts', 'orders', 'portal', 'leads', 'opportunities', 'activities',
                                    'contracted_services', 'work_orders'));

            -- 6. Reparto de la matriz del prototipo.
            -- Administrador: todo (donde ya tenía el permiso anterior de operaciones).
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r
            JOIN permissions p ON p.code IN ('contracted_services.view', 'contracted_services.manage', 'contracted_services.export',
                                             'work_orders.view', 'work_orders.create', 'work_orders.manage', 'work_orders.approve', 'work_orders.export')
            WHERE r.tenant_id IS NOT NULL AND (r.is_system OR upper(r.code) = 'ADMIN')
              AND EXISTS (SELECT 1 FROM role_permissions rp JOIN permissions q ON q.id = rp.permission_id
                          WHERE rp.role_id = r.id AND q.code IN ('operations.view', 'contracts.view'))
            ON CONFLICT DO NOTHING;

            -- Gerencia: ve y gestiona los servicios contratados; ve, asigna y exporta las órdenes de trabajo.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r
            JOIN permissions p ON p.code IN ('contracted_services.view', 'contracted_services.manage',
                                             'work_orders.view', 'work_orders.approve', 'work_orders.export')
            WHERE upper(r.code) = 'GERENCIA' AND r.tenant_id IS NOT NULL
              AND EXISTS (SELECT 1 FROM role_permissions rp JOIN permissions q ON q.id = rp.permission_id
                          WHERE rp.role_id = r.id AND q.code = 'contracts.view')
            ON CONFLICT DO NOTHING;

            -- Ventas: ve los servicios contratados de sus ventas.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r
            JOIN permissions p ON p.code = 'contracted_services.view'
            WHERE upper(r.code) = 'VENTAS' AND r.tenant_id IS NOT NULL
              AND EXISTS (SELECT 1 FROM role_permissions rp JOIN permissions q ON q.id = rp.permission_id
                          WHERE rp.role_id = r.id AND q.code = 'contracts.view')
            ON CONFLICT DO NOTHING;

            -- Alcance: en servicios contratados, el que el rol ya tiene en contratos; en órdenes de
            -- trabajo, Gerencia toda la empresa (los demás roles, lo propio hasta que se defina).
            INSERT INTO role_scopes (role_id, tenant_id, resource, scope)
            SELECT rs.role_id, rs.tenant_id, 'contracted_services', rs.scope
            FROM role_scopes rs
            WHERE rs.resource = 'contracts'
            ON CONFLICT DO NOTHING;

            INSERT INTO role_scopes (role_id, tenant_id, resource, scope)
            SELECT r.id, r.tenant_id, 'work_orders', 'TENANT'
            FROM roles r
            WHERE upper(r.code) = 'GERENCIA' AND r.tenant_id IS NOT NULL
            ON CONFLICT DO NOTHING;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM role_scopes WHERE resource IN ('contracted_services', 'work_orders');
            ALTER TABLE role_scopes DROP CONSTRAINT IF EXISTS role_scopes_resource_check;
            ALTER TABLE role_scopes ADD CONSTRAINT role_scopes_resource_check
                CHECK (resource IN ('quotations', 'contracts', 'orders', 'portal', 'leads', 'opportunities', 'activities'));

            DELETE FROM permissions WHERE code IN ('contracted_services.view', 'contracted_services.manage', 'contracted_services.export',
                'work_orders.view', 'work_orders.create', 'work_orders.manage', 'work_orders.approve', 'work_orders.export');
            UPDATE module_catalog SET permission_prefixes = '["operations"]'::jsonb WHERE upper(code) = 'ORDENES_TRABAJO';

            DROP TABLE IF EXISTS work_order_checklist_items;

            ALTER TABLE work_orders DROP CONSTRAINT IF EXISTS work_orders_priority_check;
            ALTER TABLE work_orders DROP CONSTRAINT IF EXISTS work_orders_assignee_check;
            ALTER TABLE work_orders DROP CONSTRAINT IF EXISTS work_orders_blocked_reason_check;
            ALTER TABLE work_orders DROP CONSTRAINT IF EXISTS work_orders_cancel_reason_check;
            ALTER TABLE work_orders DROP CONSTRAINT IF EXISTS work_orders_schedule_check;
            ALTER TABLE work_orders DROP CONSTRAINT IF EXISTS work_orders_checklist_status_check;
            DROP INDEX IF EXISTS idx_work_orders_assignee;
            DROP INDEX IF EXISTS idx_work_orders_creator;
            DROP INDEX IF EXISTS idx_work_orders_branch;
            DROP INDEX IF EXISTS idx_work_orders_contracted_service;
            ALTER TABLE work_orders
                DROP COLUMN IF EXISTS title, DROP COLUMN IF EXISTS contract_id, DROP COLUMN IF EXISTS service_id, DROP COLUMN IF EXISTS location,
                DROP COLUMN IF EXISTS blocked_reason, DROP COLUMN IF EXISTS cancel_reason, DROP COLUMN IF EXISTS started_at,
                DROP COLUMN IF EXISTS completed_at, DROP COLUMN IF EXISTS created_by, DROP COLUMN IF EXISTS branch_id,
                DROP COLUMN IF EXISTS checklist_id, DROP COLUMN IF EXISTS checklist_name, DROP COLUMN IF EXISTS checklist_status,
                DROP COLUMN IF EXISTS checklist_reviewed_by, DROP COLUMN IF EXISTS checklist_reviewed_at,
                DROP COLUMN IF EXISTS checklist_review_note, DROP COLUMN IF EXISTS lock_version;

            DROP INDEX IF EXISTS uq_recurring_instances_contracted_service;
            ALTER TABLE contracted_services DROP CONSTRAINT IF EXISTS contracted_services_status_check;
            ALTER TABLE contracted_services DROP CONSTRAINT IF EXISTS contracted_services_reason_check;
            DROP INDEX IF EXISTS idx_contracted_services_tenant_status;
            DROP INDEX IF EXISTS idx_contracted_services_contract;
            DROP INDEX IF EXISTS idx_contracted_services_owner;
            DROP INDEX IF EXISTS idx_contracted_services_branch;
            ALTER TABLE contracted_services
                DROP COLUMN IF EXISTS contract_item_id, DROP COLUMN IF EXISTS owner_user_id, DROP COLUMN IF EXISTS branch_id,
                DROP COLUMN IF EXISTS status_reason, DROP COLUMN IF EXISTS lock_version;
        SQL);
    }
};
