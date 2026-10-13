<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Operaciones, segunda parte: Proyectos (tareas y entregables), Horas y Costos.
 *
 * Las tablas `projects`, `tasks`, `time_entries`, `cost_entries` y `deliverables` ya existían;
 * se conservan y solo se les añade lo que faltaba. Solo añade: no borra datos.
 *
 * Lo que la base garantiza por sí sola:
 *   - un proyecto en pausa o cancelado lleva su motivo;
 *   - las horas de una persona no se solapan, van atadas a un proyecto o a una orden de trabajo,
 *     duran como mucho 12 horas y nadie aprueba las suyas;
 *   - un rechazo (de horas o de un costo) lleva su motivo;
 *   - un entregable no pasa a revisión sin su documento, y devuelto lleva la observación.
 *
 * Se ejecuta con el usuario dueño de las tablas (erp_user): crea la extensión btree_gist.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE EXTENSION IF NOT EXISTS btree_gist;

            -- 1. Proyectos: origen, presupuesto, motivos, quién lo creó, sede y versión.
            ALTER TABLE projects
                ADD COLUMN IF NOT EXISTS contracted_service_id uuid REFERENCES contracted_services(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS budget_revenue numeric(18,4) NOT NULL DEFAULT 0,
                ADD COLUMN IF NOT EXISTS budget_cost numeric(18,4) NOT NULL DEFAULT 0,
                ADD COLUMN IF NOT EXISTS budget_hours numeric(12,2) NOT NULL DEFAULT 0,
                ADD COLUMN IF NOT EXISTS hold_reason text,
                ADD COLUMN IF NOT EXISTS cancel_reason text,
                ADD COLUMN IF NOT EXISTS created_by uuid REFERENCES users(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS branch_id uuid REFERENCES branches(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS lock_version integer NOT NULL DEFAULT 0;
            ALTER TABLE projects DROP CONSTRAINT IF EXISTS projects_status_check;
            ALTER TABLE projects ADD CONSTRAINT projects_status_check
                CHECK (status IN ('DRAFT', 'PLANNED', 'IN_PROGRESS', 'ON_HOLD', 'COMPLETED', 'CANCELLED'));
            ALTER TABLE projects DROP CONSTRAINT IF EXISTS projects_hold_reason_check;
            ALTER TABLE projects ADD CONSTRAINT projects_hold_reason_check
                CHECK (status <> 'ON_HOLD' OR btrim(coalesce(hold_reason, '')) <> '');
            ALTER TABLE projects DROP CONSTRAINT IF EXISTS projects_cancel_reason_check;
            ALTER TABLE projects ADD CONSTRAINT projects_cancel_reason_check
                CHECK (status <> 'CANCELLED' OR btrim(coalesce(cancel_reason, '')) <> '');
            ALTER TABLE projects DROP CONSTRAINT IF EXISTS projects_dates_check;
            ALTER TABLE projects ADD CONSTRAINT projects_dates_check
                CHECK (start_date IS NULL OR end_date IS NULL OR end_date >= start_date);
            CREATE INDEX IF NOT EXISTS idx_projects_manager ON projects (tenant_id, manager_user_id);
            CREATE INDEX IF NOT EXISTS idx_projects_branch ON projects (tenant_id, branch_id);

            -- 2. Tareas: horas estimadas, control obligatorio antes de cerrar, motivo de bloqueo.
            ALTER TABLE tasks
                ADD COLUMN IF NOT EXISTS estimate_hours numeric(10,2) NOT NULL DEFAULT 0,
                ADD COLUMN IF NOT EXISTS required_check varchar(255),
                ADD COLUMN IF NOT EXISTS required_check_done boolean NOT NULL DEFAULT false,
                ADD COLUMN IF NOT EXISTS blocked_reason text,
                ADD COLUMN IF NOT EXISTS created_by uuid REFERENCES users(id) ON DELETE SET NULL;
            UPDATE tasks SET priority = 'NORMAL' WHERE priority NOT IN ('LOW', 'NORMAL', 'HIGH');
            ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_priority_check;
            ALTER TABLE tasks ADD CONSTRAINT tasks_priority_check CHECK (priority IN ('LOW', 'NORMAL', 'HIGH'));
            -- Una tarea con control obligatorio no queda hecha sin él.
            ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_required_check_check;
            ALTER TABLE tasks ADD CONSTRAINT tasks_required_check_check
                CHECK (status <> 'DONE' OR required_check IS NULL OR required_check_done);
            CREATE INDEX IF NOT EXISTS idx_tasks_project ON tasks (project_id) WHERE project_id IS NOT NULL;
            CREATE INDEX IF NOT EXISTS idx_tasks_assignee ON tasks (tenant_id, assigned_user_id);

            -- 3. Horas: estado, descripción, quién aprobó, motivo del rechazo, sede y versión.
            ALTER TABLE time_entries
                ADD COLUMN IF NOT EXISTS status varchar(30) NOT NULL DEFAULT 'SUBMITTED',
                ADD COLUMN IF NOT EXISTS description text,
                ADD COLUMN IF NOT EXISTS approved_by uuid REFERENCES users(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS approved_at timestamptz,
                ADD COLUMN IF NOT EXISTS reject_reason text,
                ADD COLUMN IF NOT EXISTS branch_id uuid REFERENCES branches(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS updated_at timestamptz NOT NULL DEFAULT now(),
                ADD COLUMN IF NOT EXISTS lock_version integer NOT NULL DEFAULT 0;
            -- Las que ya estaban aprobadas lo siguen estando.
            UPDATE time_entries SET status = 'APPROVED' WHERE approved AND status = 'SUBMITTED';
            ALTER TABLE time_entries DROP CONSTRAINT IF EXISTS time_entries_status_check;
            ALTER TABLE time_entries ADD CONSTRAINT time_entries_status_check CHECK (status IN ('SUBMITTED', 'APPROVED', 'REJECTED'));
            ALTER TABLE time_entries DROP CONSTRAINT IF EXISTS time_entries_reject_reason_check;
            ALTER TABLE time_entries ADD CONSTRAINT time_entries_reject_reason_check
                CHECK (status <> 'REJECTED' OR btrim(coalesce(reject_reason, '')) <> '');
            ALTER TABLE time_entries DROP CONSTRAINT IF EXISTS time_entries_context_check;
            ALTER TABLE time_entries ADD CONSTRAINT time_entries_context_check
                CHECK (project_id IS NOT NULL OR work_order_id IS NOT NULL);
            ALTER TABLE time_entries DROP CONSTRAINT IF EXISTS time_entries_duration_check;
            ALTER TABLE time_entries ADD CONSTRAINT time_entries_duration_check
                CHECK (ended_at > started_at AND ended_at <= started_at + interval '12 hours');
            ALTER TABLE time_entries DROP CONSTRAINT IF EXISTS time_entries_self_approval_check;
            ALTER TABLE time_entries ADD CONSTRAINT time_entries_self_approval_check
                CHECK (approved_by IS NULL OR user_id IS NULL OR approved_by <> user_id);
            ALTER TABLE time_entries DROP CONSTRAINT IF EXISTS time_entries_no_overlap;
            ALTER TABLE time_entries ADD CONSTRAINT time_entries_no_overlap
                EXCLUDE USING gist (tenant_id WITH =, user_id WITH =, tstzrange(started_at, ended_at) WITH &&)
                WHERE (status <> 'REJECTED');
            CREATE INDEX IF NOT EXISTS idx_time_entries_user ON time_entries (tenant_id, user_id);
            CREATE INDEX IF NOT EXISTS idx_time_entries_project ON time_entries (project_id) WHERE project_id IS NOT NULL;
            CREATE INDEX IF NOT EXISTS idx_time_entries_status ON time_entries (tenant_id, status);

            -- 4. Costos: cantidad y costo unitario, estado, quién lo registró y quién lo aprobó.
            ALTER TABLE cost_entries
                ADD COLUMN IF NOT EXISTS quantity numeric(18,4) NOT NULL DEFAULT 1,
                ADD COLUMN IF NOT EXISTS unit_cost numeric(18,4) NOT NULL DEFAULT 0,
                ADD COLUMN IF NOT EXISTS status varchar(30) NOT NULL DEFAULT 'SUBMITTED',
                ADD COLUMN IF NOT EXISTS created_by uuid REFERENCES users(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS approved_by uuid REFERENCES users(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS approved_at timestamptz,
                ADD COLUMN IF NOT EXISTS reject_reason text,
                ADD COLUMN IF NOT EXISTS branch_id uuid REFERENCES branches(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS updated_at timestamptz NOT NULL DEFAULT now(),
                ADD COLUMN IF NOT EXISTS lock_version integer NOT NULL DEFAULT 0;
            UPDATE cost_entries SET status = 'APPROVED' WHERE approved AND status = 'SUBMITTED';
            UPDATE cost_entries SET unit_cost = amount WHERE unit_cost = 0 AND amount > 0;
            ALTER TABLE cost_entries DROP CONSTRAINT IF EXISTS cost_entries_status_check;
            ALTER TABLE cost_entries ADD CONSTRAINT cost_entries_status_check CHECK (status IN ('SUBMITTED', 'APPROVED', 'REJECTED'));
            ALTER TABLE cost_entries DROP CONSTRAINT IF EXISTS cost_entries_reject_reason_check;
            ALTER TABLE cost_entries ADD CONSTRAINT cost_entries_reject_reason_check
                CHECK (status <> 'REJECTED' OR btrim(coalesce(reject_reason, '')) <> '');
            ALTER TABLE cost_entries DROP CONSTRAINT IF EXISTS cost_entries_quantity_check;
            ALTER TABLE cost_entries ADD CONSTRAINT cost_entries_quantity_check CHECK (quantity > 0 AND unit_cost >= 0);
            CREATE INDEX IF NOT EXISTS idx_cost_entries_project ON cost_entries (project_id) WHERE project_id IS NOT NULL;
            CREATE INDEX IF NOT EXISTS idx_cost_entries_status ON cost_entries (tenant_id, status);

            -- 5. Entregables: documento, revisión y entrega.
            ALTER TABLE deliverables
                ADD COLUMN IF NOT EXISTS document_ref text,
                ADD COLUMN IF NOT EXISTS review_note text,
                ADD COLUMN IF NOT EXISTS created_by uuid REFERENCES users(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS reviewed_by uuid REFERENCES users(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS reviewed_at timestamptz,
                ADD COLUMN IF NOT EXISTS delivered_at timestamptz,
                ADD COLUMN IF NOT EXISTS updated_at timestamptz NOT NULL DEFAULT now();
            UPDATE deliverables SET status = 'DRAFT' WHERE status NOT IN ('DRAFT', 'IN_REVIEW', 'RETURNED', 'APPROVED', 'DELIVERED');
            ALTER TABLE deliverables ALTER COLUMN status SET DEFAULT 'DRAFT';
            ALTER TABLE deliverables DROP CONSTRAINT IF EXISTS deliverables_status_check;
            ALTER TABLE deliverables ADD CONSTRAINT deliverables_status_check
                CHECK (status IN ('DRAFT', 'IN_REVIEW', 'RETURNED', 'APPROVED', 'DELIVERED'));
            ALTER TABLE deliverables DROP CONSTRAINT IF EXISTS deliverables_document_check;
            ALTER TABLE deliverables ADD CONSTRAINT deliverables_document_check
                CHECK (status IN ('DRAFT', 'RETURNED') OR btrim(coalesce(document_ref, '')) <> '');
            ALTER TABLE deliverables DROP CONSTRAINT IF EXISTS deliverables_review_note_check;
            ALTER TABLE deliverables ADD CONSTRAINT deliverables_review_note_check
                CHECK (status <> 'RETURNED' OR btrim(coalesce(review_note, '')) <> '');
            CREATE INDEX IF NOT EXISTS idx_deliverables_project ON deliverables (project_id) WHERE project_id IS NOT NULL;

            -- 6. Costo por hora de cada persona en la empresa: lo toman sus horas al registrarse.
            ALTER TABLE tenant_users ADD COLUMN IF NOT EXISTS hourly_cost numeric(18,4) NOT NULL DEFAULT 0;
            ALTER TABLE tenant_users DROP CONSTRAINT IF EXISTS tenant_users_hourly_cost_check;
            ALTER TABLE tenant_users ADD CONSTRAINT tenant_users_hourly_cost_check CHECK (hourly_cost >= 0);

            -- 7. Permisos por recurso.
            INSERT INTO permissions (code, name, description) VALUES
                ('projects.view', 'Ver proyectos', 'Proyectos, sus tareas, su avance y su margen.'),
                ('projects.create', 'Crear proyectos', 'Crear un proyecto desde un servicio contratado activo.'),
                ('projects.manage', 'Gestionar proyectos', 'Editar el proyecto, planificarlo, iniciarlo, pausarlo y cancelarlo, y gestionar sus tareas.'),
                ('projects.approve', 'Cerrar proyectos', 'Cerrar un proyecto con sus tareas cerradas y sus entregables aprobados.'),
                ('projects.export', 'Exportar proyectos', 'Descargar el listado en Excel o CSV.'),
                ('time_entries.view', 'Ver horas', 'Registros de horas de proyectos y órdenes de trabajo.'),
                ('time_entries.create', 'Registrar horas', 'Registrar las horas propias en un proyecto o en una orden de trabajo.'),
                ('time_entries.approve', 'Aprobar horas', 'Aprobar o rechazar las horas de otras personas.'),
                ('time_entries.export', 'Exportar horas', 'Descargar el listado en Excel o CSV.'),
                ('cost_entries.view', 'Ver costos', 'Costos directos de proyectos y órdenes de trabajo.'),
                ('cost_entries.create', 'Registrar costos', 'Registrar un costo directo en un proyecto.'),
                ('cost_entries.approve', 'Aprobar costos', 'Aprobar o rechazar costos.'),
                ('cost_entries.export', 'Exportar costos', 'Descargar el listado en Excel o CSV.'),
                ('deliverables.view', 'Ver entregables', 'Entregables de los proyectos y su estado.'),
                ('deliverables.manage', 'Gestionar entregables', 'Crear y editar entregables, enviarlos a revisión y entregarlos al cliente.'),
                ('deliverables.approve', 'Revisar entregables', 'Aprobar o devolver un entregable en revisión.')
            ON CONFLICT (code) DO NOTHING;

            UPDATE module_catalog SET permission_prefixes = '["projects", "deliverables", "contracted_services"]'::jsonb WHERE upper(code) = 'PROYECTOS';
            UPDATE module_catalog SET permission_prefixes = '["operations", "time_entries", "cost_entries"]'::jsonb WHERE upper(code) = 'TIMESHEETS';

            -- 8. Alcance por rol: también lo llevan proyectos, horas y costos.
            ALTER TABLE role_scopes DROP CONSTRAINT IF EXISTS role_scopes_resource_check;
            ALTER TABLE role_scopes ADD CONSTRAINT role_scopes_resource_check
                CHECK (resource IN ('quotations', 'contracts', 'orders', 'portal', 'leads', 'opportunities', 'activities',
                                    'contracted_services', 'work_orders', 'projects', 'time_entries', 'cost_entries'));

            -- 9. Reparto de la matriz del prototipo.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r
            JOIN permissions p ON p.code LIKE 'projects.%' OR p.code LIKE 'time_entries.%' OR p.code LIKE 'cost_entries.%' OR p.code LIKE 'deliverables.%'
            WHERE r.tenant_id IS NOT NULL AND (r.is_system OR upper(r.code) = 'ADMIN')
              AND EXISTS (SELECT 1 FROM role_permissions rp JOIN permissions q ON q.id = rp.permission_id
                          WHERE rp.role_id = r.id AND q.code IN ('operations.view', 'contracts.view'))
            ON CONFLICT DO NOTHING;

            -- Gerencia: ve, cierra y exporta proyectos; aprueba horas, costos y entregables. No ejecuta.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r
            JOIN permissions p ON p.code IN ('projects.view', 'projects.approve', 'projects.export',
                                             'time_entries.view', 'time_entries.approve', 'time_entries.export',
                                             'cost_entries.view', 'cost_entries.approve', 'cost_entries.export',
                                             'deliverables.view', 'deliverables.approve')
            WHERE upper(r.code) = 'GERENCIA' AND r.tenant_id IS NOT NULL
              AND EXISTS (SELECT 1 FROM role_permissions rp JOIN permissions q ON q.id = rp.permission_id
                          WHERE rp.role_id = r.id AND q.code = 'contracts.view')
            ON CONFLICT DO NOTHING;

            INSERT INTO role_scopes (role_id, tenant_id, resource, scope)
            SELECT r.id, r.tenant_id, s.resource, 'TENANT'
            FROM roles r CROSS JOIN (VALUES ('projects'), ('time_entries'), ('cost_entries')) AS s(resource)
            WHERE upper(r.code) = 'GERENCIA' AND r.tenant_id IS NOT NULL
            ON CONFLICT DO NOTHING;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM role_scopes WHERE resource IN ('projects', 'time_entries', 'cost_entries');
            ALTER TABLE role_scopes DROP CONSTRAINT IF EXISTS role_scopes_resource_check;
            ALTER TABLE role_scopes ADD CONSTRAINT role_scopes_resource_check
                CHECK (resource IN ('quotations', 'contracts', 'orders', 'portal', 'leads', 'opportunities', 'activities',
                                    'contracted_services', 'work_orders'));

            DELETE FROM permissions WHERE code LIKE 'projects.%' OR code LIKE 'time_entries.%' OR code LIKE 'cost_entries.%' OR code LIKE 'deliverables.%';
            UPDATE module_catalog SET permission_prefixes = '[]'::jsonb WHERE upper(code) = 'PROYECTOS';
            UPDATE module_catalog SET permission_prefixes = '["operations"]'::jsonb WHERE upper(code) = 'TIMESHEETS';

            ALTER TABLE tenant_users DROP CONSTRAINT IF EXISTS tenant_users_hourly_cost_check;
            ALTER TABLE tenant_users DROP COLUMN IF EXISTS hourly_cost;

            ALTER TABLE deliverables DROP CONSTRAINT IF EXISTS deliverables_status_check;
            ALTER TABLE deliverables DROP CONSTRAINT IF EXISTS deliverables_document_check;
            ALTER TABLE deliverables DROP CONSTRAINT IF EXISTS deliverables_review_note_check;
            ALTER TABLE deliverables
                DROP COLUMN IF EXISTS document_ref, DROP COLUMN IF EXISTS review_note, DROP COLUMN IF EXISTS created_by,
                DROP COLUMN IF EXISTS reviewed_by, DROP COLUMN IF EXISTS reviewed_at, DROP COLUMN IF EXISTS delivered_at, DROP COLUMN IF EXISTS updated_at;

            ALTER TABLE cost_entries DROP CONSTRAINT IF EXISTS cost_entries_status_check;
            ALTER TABLE cost_entries DROP CONSTRAINT IF EXISTS cost_entries_reject_reason_check;
            ALTER TABLE cost_entries DROP CONSTRAINT IF EXISTS cost_entries_quantity_check;
            ALTER TABLE cost_entries
                DROP COLUMN IF EXISTS quantity, DROP COLUMN IF EXISTS unit_cost, DROP COLUMN IF EXISTS status, DROP COLUMN IF EXISTS created_by,
                DROP COLUMN IF EXISTS approved_by, DROP COLUMN IF EXISTS approved_at, DROP COLUMN IF EXISTS reject_reason,
                DROP COLUMN IF EXISTS branch_id, DROP COLUMN IF EXISTS updated_at, DROP COLUMN IF EXISTS lock_version;

            ALTER TABLE time_entries DROP CONSTRAINT IF EXISTS time_entries_no_overlap;
            ALTER TABLE time_entries DROP CONSTRAINT IF EXISTS time_entries_status_check;
            ALTER TABLE time_entries DROP CONSTRAINT IF EXISTS time_entries_reject_reason_check;
            ALTER TABLE time_entries DROP CONSTRAINT IF EXISTS time_entries_context_check;
            ALTER TABLE time_entries DROP CONSTRAINT IF EXISTS time_entries_duration_check;
            ALTER TABLE time_entries DROP CONSTRAINT IF EXISTS time_entries_self_approval_check;
            ALTER TABLE time_entries
                DROP COLUMN IF EXISTS status, DROP COLUMN IF EXISTS description, DROP COLUMN IF EXISTS approved_by, DROP COLUMN IF EXISTS approved_at,
                DROP COLUMN IF EXISTS reject_reason, DROP COLUMN IF EXISTS branch_id, DROP COLUMN IF EXISTS updated_at, DROP COLUMN IF EXISTS lock_version;

            ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_priority_check;
            ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_required_check_check;
            ALTER TABLE tasks
                DROP COLUMN IF EXISTS estimate_hours, DROP COLUMN IF EXISTS required_check, DROP COLUMN IF EXISTS required_check_done,
                DROP COLUMN IF EXISTS blocked_reason, DROP COLUMN IF EXISTS created_by;

            ALTER TABLE projects DROP CONSTRAINT IF EXISTS projects_hold_reason_check;
            ALTER TABLE projects DROP CONSTRAINT IF EXISTS projects_cancel_reason_check;
            ALTER TABLE projects DROP CONSTRAINT IF EXISTS projects_dates_check;
            ALTER TABLE projects
                DROP COLUMN IF EXISTS contracted_service_id, DROP COLUMN IF EXISTS budget_revenue, DROP COLUMN IF EXISTS budget_cost,
                DROP COLUMN IF EXISTS budget_hours, DROP COLUMN IF EXISTS hold_reason, DROP COLUMN IF EXISTS cancel_reason,
                DROP COLUMN IF EXISTS created_by, DROP COLUMN IF EXISTS branch_id, DROP COLUMN IF EXISTS lock_version;
        SQL);
    }
};
