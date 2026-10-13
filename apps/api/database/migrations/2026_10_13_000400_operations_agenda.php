<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Operaciones: Agenda. Eventos (reuniones, visitas, mantenimientos, sesiones) con responsable,
 * horario y, si aplica, cliente. Las órdenes de trabajo no se copian aquí: la agenda las lee de
 * su propia tabla, así que no hay dos fechas que mantener iguales.
 *
 * Lo que la base garantiza: el fin es posterior al inicio, un evento dura como mucho 24 horas y
 * cancelar pide motivo. Cada empresa solo ve sus eventos.
 *
 * Solo añade. Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS agenda_events (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                title varchar(255) NOT NULL,
                event_type varchar(30) NOT NULL DEFAULT 'MEETING',
                status varchar(30) NOT NULL DEFAULT 'SCHEDULED',
                starts_at timestamptz NOT NULL,
                ends_at timestamptz NOT NULL,
                assigned_user_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                customer_id uuid REFERENCES customers(id) ON DELETE SET NULL,
                location varchar(255),
                notes text,
                cancel_reason text,
                branch_id uuid REFERENCES branches(id) ON DELETE SET NULL,
                created_by uuid REFERENCES users(id) ON DELETE SET NULL,
                lock_version integer NOT NULL DEFAULT 0,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT agenda_events_type_check CHECK (event_type IN ('MEETING', 'VISIT', 'MAINTENANCE', 'SESSION')),
                CONSTRAINT agenda_events_status_check CHECK (status IN ('SCHEDULED', 'DONE', 'CANCELLED')),
                CONSTRAINT agenda_events_period_check CHECK (ends_at > starts_at AND ends_at <= starts_at + interval '24 hours'),
                CONSTRAINT agenda_events_cancel_reason_check CHECK (status <> 'CANCELLED' OR btrim(COALESCE(cancel_reason, '')) <> '')
            );
            CREATE INDEX IF NOT EXISTS idx_agenda_events_tenant_start ON agenda_events (tenant_id, starts_at);
            CREATE INDEX IF NOT EXISTS idx_agenda_events_assignee ON agenda_events (tenant_id, assigned_user_id, starts_at);

            ALTER TABLE agenda_events ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON agenda_events;
            CREATE POLICY tenant_isolation ON agenda_events
                USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());

            -- Los eventos no se borran: se cancelan.
            REVOKE ALL ON agenda_events FROM erp_app;
            GRANT SELECT, INSERT, UPDATE ON agenda_events TO erp_app;

            INSERT INTO permissions (code, name, description) VALUES
                ('agenda.view', 'Ver agenda', 'Agenda semanal: eventos y órdenes de trabajo programadas.'),
                ('agenda.create', 'Crear eventos de agenda', 'Agendar reuniones, visitas, mantenimientos y sesiones.'),
                ('agenda.manage', 'Gestionar eventos de agenda', 'Editar un evento, marcarlo realizado o cancelarlo.')
            ON CONFLICT (code) DO NOTHING;

            UPDATE module_catalog SET permission_prefixes = '["agenda"]'::jsonb WHERE upper(code) = 'AGENDA';

            ALTER TABLE role_scopes DROP CONSTRAINT IF EXISTS role_scopes_resource_check;
            ALTER TABLE role_scopes ADD CONSTRAINT role_scopes_resource_check
                CHECK (resource IN ('quotations', 'contracts', 'orders', 'portal', 'leads', 'opportunities', 'activities',
                                    'contracted_services', 'work_orders', 'projects', 'time_entries', 'cost_entries', 'agenda'));

            -- Reparto de la matriz del prototipo. El Administrador de cada empresa: todo (solo vale si su plan tiene el módulo Agenda).
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r
            JOIN permissions p ON p.code LIKE 'agenda.%'
            WHERE r.tenant_id IS NOT NULL AND (r.is_system OR upper(r.code) = 'ADMIN')
            ON CONFLICT DO NOTHING;

            -- Gerencia ve la agenda de toda la empresa; no agenda.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code = 'agenda.view'
            WHERE upper(r.code) = 'GERENCIA' AND r.tenant_id IS NOT NULL
            ON CONFLICT DO NOTHING;
            INSERT INTO role_scopes (role_id, tenant_id, resource, scope)
            SELECT r.id, r.tenant_id, 'agenda', 'TENANT' FROM roles r
            WHERE upper(r.code) = 'GERENCIA' AND r.tenant_id IS NOT NULL
            ON CONFLICT DO NOTHING;

            -- Ventas agenda y gestiona lo suyo (sin fila de alcance: lo propio).
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code LIKE 'agenda.%'
            WHERE upper(r.code) = 'VENTAS' AND r.tenant_id IS NOT NULL
            ON CONFLICT DO NOTHING;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM role_scopes WHERE resource = 'agenda';
            ALTER TABLE role_scopes DROP CONSTRAINT IF EXISTS role_scopes_resource_check;
            ALTER TABLE role_scopes ADD CONSTRAINT role_scopes_resource_check
                CHECK (resource IN ('quotations', 'contracts', 'orders', 'portal', 'leads', 'opportunities', 'activities',
                                    'contracted_services', 'work_orders', 'projects', 'time_entries', 'cost_entries'));
            UPDATE module_catalog SET permission_prefixes = '[]'::jsonb WHERE upper(code) = 'AGENDA';
            DELETE FROM role_permissions WHERE permission_id IN (SELECT id FROM permissions WHERE code LIKE 'agenda.%');
            DELETE FROM permissions WHERE code LIKE 'agenda.%';
            DROP TABLE IF EXISTS agenda_events;
        SQL);
    }
};
