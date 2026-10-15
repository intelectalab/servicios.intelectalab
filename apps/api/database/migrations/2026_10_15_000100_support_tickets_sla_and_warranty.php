<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Soporte: tickets con SLA, calendario laboral con feriados y reclamos de garantía.
 *
 * - Se conservan las tablas que ya existían (`support_tickets`, `support_ticket_comments`,
 *   `support_slas`, `warranty_claims`) y se amplían con lo del kit y el prototipo. Las que nadie
 *   usaba (`support_sla_runs`, `support_ticket_events`, `support_ticket_assignments`,
 *   `warranty_policies`) se dejan como están.
 * - Calendario laboral por empresa (`support_calendars`) con su horario por día, zona horaria y el
 *   factor del contrato Premium; feriados (`support_holidays`) que no se borran: se retiran.
 * - Políticas SLA por prioridad P1–P4 (`support_slas`), en minutos y con su modo de cálculo
 *   (horario hábil o 24×7). Las crea la aplicación la primera vez que la empresa entra.
 * - La base garantiza: resolver o cerrar con texto de resolución, reabrir con motivo, ticket en
 *   atención con responsable, pausa solo en "Esperando cliente", reclamo cubierto solo con la
 *   cobertura validada y rechazado con motivo; ni tickets, ni mensajes, ni reclamos se borran, y
 *   un mensaje no se edita.
 * - Permisos `tickets.*`, `warranty.*` y `sla.*` (los `support.*` se renombran: quien los tenía
 *   no pierde nada) con el reparto de la matriz del prototipo, y alcance por rol en tickets y
 *   garantías.
 * - Condiciones de garantía en el catálogo de servicios, junto a sus meses.
 *
 * Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- 1. Calendario laboral y feriados.
            CREATE TABLE IF NOT EXISTS support_calendars (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                name varchar(150) NOT NULL DEFAULT 'Horario de atención',
                timezone varchar(64) NOT NULL DEFAULT 'America/Lima',
                hours jsonb NOT NULL,
                premium_factor numeric(5,4) NOT NULL DEFAULT 0.5,
                lock_version integer NOT NULL DEFAULT 0,
                updated_by uuid REFERENCES users(id) ON DELETE SET NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT support_calendars_tenant_key UNIQUE (tenant_id),
                CONSTRAINT support_calendars_factor_check CHECK (premium_factor > 0 AND premium_factor <= 1),
                CONSTRAINT support_calendars_hours_check CHECK (jsonb_typeof(hours) = 'object')
            );
            ALTER TABLE support_calendars ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON support_calendars;
            CREATE POLICY tenant_isolation ON support_calendars USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
            REVOKE ALL ON support_calendars FROM erp_app;
            GRANT SELECT, INSERT, UPDATE ON support_calendars TO erp_app;

            CREATE TABLE IF NOT EXISTS support_holidays (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                calendar_id uuid NOT NULL REFERENCES support_calendars(id) ON DELETE CASCADE,
                holiday_date date NOT NULL,
                name varchar(150) NOT NULL,
                created_by uuid REFERENCES users(id) ON DELETE SET NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                removed_at timestamptz,
                removed_by uuid REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT support_holidays_name_check CHECK (btrim(name) <> '')
            );
            CREATE UNIQUE INDEX IF NOT EXISTS support_holidays_current_key ON support_holidays (calendar_id, holiday_date) WHERE removed_at IS NULL;
            ALTER TABLE support_holidays ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON support_holidays;
            CREATE POLICY tenant_isolation ON support_holidays USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
            REVOKE ALL ON support_holidays FROM erp_app;
            GRANT SELECT, INSERT, UPDATE ON support_holidays TO erp_app;

            -- Un feriado retirado no vuelve ni cambia; uno vigente solo se retira.
            CREATE OR REPLACE FUNCTION check_support_holiday_update() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.removed_at IS NOT NULL THEN
                    RAISE EXCEPTION 'Un feriado retirado no se modifica.' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.holiday_date IS DISTINCT FROM OLD.holiday_date OR NEW.name IS DISTINCT FROM OLD.name OR NEW.calendar_id IS DISTINCT FROM OLD.calendar_id THEN
                    RAISE EXCEPTION 'Un feriado no se edita: se retira y se agrega otro.' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$;
            DROP TRIGGER IF EXISTS trg_support_holidays_update ON support_holidays;
            CREATE TRIGGER trg_support_holidays_update BEFORE UPDATE ON support_holidays FOR EACH ROW EXECUTE FUNCTION check_support_holiday_update();

            -- 2. Políticas SLA por prioridad (la tabla existía vacía).
            ALTER TABLE support_slas ADD COLUMN IF NOT EXISTS calendar_mode varchar(10) NOT NULL DEFAULT 'BUSINESS';
            ALTER TABLE support_slas ADD COLUMN IF NOT EXISTS updated_at timestamptz NOT NULL DEFAULT now();
            ALTER TABLE support_slas DROP CONSTRAINT IF EXISTS support_slas_calendar_mode_check;
            ALTER TABLE support_slas ADD CONSTRAINT support_slas_calendar_mode_check CHECK (calendar_mode IN ('BUSINESS', '24X7'));
            ALTER TABLE support_slas DROP CONSTRAINT IF EXISTS support_slas_priority_check;
            ALTER TABLE support_slas ADD CONSTRAINT support_slas_priority_check CHECK (priority IN ('P1', 'P2', 'P3', 'P4'));
            ALTER TABLE support_slas ALTER COLUMN priority SET NOT NULL;
            ALTER TABLE support_slas DROP CONSTRAINT IF EXISTS support_slas_minutes_check;
            ALTER TABLE support_slas ADD CONSTRAINT support_slas_minutes_check
                CHECK (response_minutes > 0 AND resolution_minutes >= response_minutes AND resolution_minutes <= 43200);
            CREATE UNIQUE INDEX IF NOT EXISTS support_slas_tenant_priority_key ON support_slas (tenant_id, priority);
            REVOKE DELETE ON support_slas FROM erp_app;

            -- 3. Tickets.
            ALTER TABLE support_tickets ADD COLUMN IF NOT EXISTS channel varchar(20) NOT NULL DEFAULT 'BACKOFFICE';
            ALTER TABLE support_tickets ADD COLUMN IF NOT EXISTS category varchar(20) NOT NULL DEFAULT 'INCIDENT';
            ALTER TABLE support_tickets ADD COLUMN IF NOT EXISTS service_id uuid REFERENCES services(id) ON DELETE SET NULL;
            ALTER TABLE support_tickets ADD COLUMN IF NOT EXISTS team_id uuid REFERENCES teams(id) ON DELETE SET NULL;
            ALTER TABLE support_tickets ADD COLUMN IF NOT EXISTS branch_id uuid REFERENCES branches(id) ON DELETE SET NULL;
            ALTER TABLE support_tickets ADD COLUMN IF NOT EXISTS created_by uuid REFERENCES users(id) ON DELETE SET NULL;
            ALTER TABLE support_tickets ADD COLUMN IF NOT EXISTS lock_version integer NOT NULL DEFAULT 0;
            ALTER TABLE support_tickets ADD COLUMN IF NOT EXISTS triaged_at timestamptz;
            -- Desde cuándo corre el reloj: el inicio del ticket o su reapertura.
            ALTER TABLE support_tickets ADD COLUMN IF NOT EXISTS sla_started_at timestamptz;
            ALTER TABLE support_tickets ADD COLUMN IF NOT EXISTS sla_calendar_mode varchar(10);
            ALTER TABLE support_tickets ADD COLUMN IF NOT EXISTS sla_factor numeric(5,4);
            ALTER TABLE support_tickets ADD COLUMN IF NOT EXISTS sla_response_minutes integer;
            ALTER TABLE support_tickets ADD COLUMN IF NOT EXISTS sla_resolution_minutes integer;
            ALTER TABLE support_tickets ADD COLUMN IF NOT EXISTS response_due_at timestamptz;
            ALTER TABLE support_tickets ADD COLUMN IF NOT EXISTS resolution_due_at timestamptz;
            ALTER TABLE support_tickets ADD COLUMN IF NOT EXISTS first_response_at timestamptz;
            ALTER TABLE support_tickets ADD COLUMN IF NOT EXISTS paused_at timestamptz;
            ALTER TABLE support_tickets ADD COLUMN IF NOT EXISTS paused_seconds bigint NOT NULL DEFAULT 0;
            ALTER TABLE support_tickets ADD COLUMN IF NOT EXISTS resolution text;
            ALTER TABLE support_tickets ADD COLUMN IF NOT EXISTS reopen_reason text;
            ALTER TABLE support_tickets ADD COLUMN IF NOT EXISTS work_order_id uuid REFERENCES work_orders(id) ON DELETE SET NULL;

            -- La prioridad libre de antes (HIGH, NORMAL…) pasa a P1–P4; sin clasificar queda vacía.
            ALTER TABLE support_tickets ALTER COLUMN priority DROP DEFAULT;
            ALTER TABLE support_tickets ALTER COLUMN priority DROP NOT NULL;
            UPDATE support_tickets SET priority = CASE upper(priority)
                WHEN 'URGENT' THEN 'P1' WHEN 'CRITICAL' THEN 'P1' WHEN 'HIGH' THEN 'P2' WHEN 'NORMAL' THEN 'P3' WHEN 'MEDIUM' THEN 'P3' WHEN 'LOW' THEN 'P4'
                ELSE priority END
            WHERE priority IS NOT NULL AND priority NOT IN ('P1', 'P2', 'P3', 'P4');
            -- Lo resuelto o cerrado antes de esta pantalla no tenía texto de resolución.
            UPDATE support_tickets SET resolution = 'Registrado antes de la pantalla de Soporte, sin resolución escrita.'
            WHERE status IN ('RESOLVED', 'CLOSED') AND btrim(coalesce(resolution, '')) = '';
            UPDATE support_tickets SET reopen_reason = 'Reabierto antes de la pantalla de Soporte.'
            WHERE status = 'REOPENED' AND btrim(coalesce(reopen_reason, '')) = '';

            ALTER TABLE support_tickets DROP CONSTRAINT IF EXISTS support_tickets_status_check;
            ALTER TABLE support_tickets ADD CONSTRAINT support_tickets_status_check
                CHECK (status IN ('OPEN', 'TRIAGED', 'ASSIGNED', 'IN_PROGRESS', 'WAITING_CUSTOMER', 'RESOLVED', 'CLOSED', 'REOPENED'));
            ALTER TABLE support_tickets DROP CONSTRAINT IF EXISTS support_tickets_priority_check;
            ALTER TABLE support_tickets ADD CONSTRAINT support_tickets_priority_check CHECK (priority IS NULL OR priority IN ('P1', 'P2', 'P3', 'P4'));
            ALTER TABLE support_tickets DROP CONSTRAINT IF EXISTS support_tickets_channel_check;
            ALTER TABLE support_tickets ADD CONSTRAINT support_tickets_channel_check CHECK (channel IN ('BACKOFFICE', 'PORTAL', 'EMAIL', 'PHONE'));
            ALTER TABLE support_tickets DROP CONSTRAINT IF EXISTS support_tickets_category_check;
            ALTER TABLE support_tickets ADD CONSTRAINT support_tickets_category_check CHECK (category IN ('INCIDENT', 'REQUEST', 'QUESTION'));
            ALTER TABLE support_tickets DROP CONSTRAINT IF EXISTS support_tickets_calendar_mode_check;
            ALTER TABLE support_tickets ADD CONSTRAINT support_tickets_calendar_mode_check CHECK (sla_calendar_mode IS NULL OR sla_calendar_mode IN ('BUSINESS', '24X7'));
            ALTER TABLE support_tickets DROP CONSTRAINT IF EXISTS support_tickets_subject_check;
            ALTER TABLE support_tickets ADD CONSTRAINT support_tickets_subject_check CHECK (btrim(subject) <> '');
            -- Pasado el triage, el ticket tiene prioridad.
            ALTER TABLE support_tickets DROP CONSTRAINT IF EXISTS support_tickets_triaged_check;
            ALTER TABLE support_tickets ADD CONSTRAINT support_tickets_triaged_check CHECK (status = 'OPEN' OR priority IS NOT NULL);
            -- Asignado, en atención o esperando al cliente: alguien lo lleva.
            ALTER TABLE support_tickets DROP CONSTRAINT IF EXISTS support_tickets_assignee_check;
            ALTER TABLE support_tickets ADD CONSTRAINT support_tickets_assignee_check
                CHECK (status NOT IN ('ASSIGNED', 'IN_PROGRESS', 'WAITING_CUSTOMER') OR assigned_user_id IS NOT NULL);
            -- RN-SUP-04: resolver exige texto de resolución; reabrir exige motivo.
            ALTER TABLE support_tickets DROP CONSTRAINT IF EXISTS support_tickets_resolution_check;
            ALTER TABLE support_tickets ADD CONSTRAINT support_tickets_resolution_check
                CHECK (status NOT IN ('RESOLVED', 'CLOSED') OR btrim(coalesce(resolution, '')) <> '');
            ALTER TABLE support_tickets DROP CONSTRAINT IF EXISTS support_tickets_reopen_check;
            ALTER TABLE support_tickets ADD CONSTRAINT support_tickets_reopen_check
                CHECK (status <> 'REOPENED' OR btrim(coalesce(reopen_reason, '')) <> '');
            -- RN-SUP-03: el reloj solo se pausa esperando al cliente.
            ALTER TABLE support_tickets DROP CONSTRAINT IF EXISTS support_tickets_pause_check;
            ALTER TABLE support_tickets ADD CONSTRAINT support_tickets_pause_check
                CHECK ((paused_at IS NULL OR status = 'WAITING_CUSTOMER') AND paused_seconds >= 0);
            CREATE INDEX IF NOT EXISTS idx_support_tickets_due ON support_tickets (tenant_id, resolution_due_at);
            CREATE INDEX IF NOT EXISTS idx_support_tickets_customer ON support_tickets (tenant_id, customer_id);
            CREATE UNIQUE INDEX IF NOT EXISTS support_tickets_work_order_key ON support_tickets (work_order_id) WHERE work_order_id IS NOT NULL;
            REVOKE DELETE ON support_tickets FROM erp_app;

            -- 4. Conversación: los mensajes no se editan ni se borran.
            ALTER TABLE support_ticket_comments ADD COLUMN IF NOT EXISTS author_type varchar(10) NOT NULL DEFAULT 'STAFF';
            -- La hora exacta de cada mensaje (no la del inicio de la transacción): la conversación se lee en orden.
            ALTER TABLE support_ticket_comments ALTER COLUMN created_at SET DEFAULT clock_timestamp();
            ALTER TABLE support_ticket_comments DROP CONSTRAINT IF EXISTS support_ticket_comments_author_type_check;
            ALTER TABLE support_ticket_comments ADD CONSTRAINT support_ticket_comments_author_type_check CHECK (author_type IN ('STAFF', 'PORTAL'));
            ALTER TABLE support_ticket_comments DROP CONSTRAINT IF EXISTS support_ticket_comments_body_check;
            ALTER TABLE support_ticket_comments ADD CONSTRAINT support_ticket_comments_body_check CHECK (btrim(body) <> '');
            -- RN-SUP-05: el portal nunca escribe notas internas.
            ALTER TABLE support_ticket_comments DROP CONSTRAINT IF EXISTS support_ticket_comments_portal_check;
            ALTER TABLE support_ticket_comments ADD CONSTRAINT support_ticket_comments_portal_check CHECK (NOT (internal AND author_type = 'PORTAL'));
            CREATE INDEX IF NOT EXISTS idx_support_ticket_comments_ticket ON support_ticket_comments (ticket_id, created_at);
            REVOKE ALL ON support_ticket_comments FROM erp_app;
            GRANT SELECT, INSERT ON support_ticket_comments TO erp_app;

            -- 5. Reclamos de garantía (la tabla existía vacía).
            ALTER TABLE warranty_claims ADD COLUMN IF NOT EXISTS contracted_service_id uuid REFERENCES contracted_services(id) ON DELETE RESTRICT;
            ALTER TABLE warranty_claims ADD COLUMN IF NOT EXISTS service_id uuid REFERENCES services(id) ON DELETE SET NULL;
            ALTER TABLE warranty_claims ADD COLUMN IF NOT EXISTS ticket_id uuid REFERENCES support_tickets(id) ON DELETE RESTRICT;
            ALTER TABLE warranty_claims ADD COLUMN IF NOT EXISTS created_by uuid REFERENCES users(id) ON DELETE SET NULL;
            ALTER TABLE warranty_claims ADD COLUMN IF NOT EXISTS branch_id uuid REFERENCES branches(id) ON DELETE SET NULL;
            ALTER TABLE warranty_claims ADD COLUMN IF NOT EXISTS lock_version integer NOT NULL DEFAULT 0;
            ALTER TABLE warranty_claims ADD COLUMN IF NOT EXISTS coverage_checked_at timestamptz;
            ALTER TABLE warranty_claims ADD COLUMN IF NOT EXISTS coverage_valid boolean;
            ALTER TABLE warranty_claims ADD COLUMN IF NOT EXISTS coverage_base_date date;
            ALTER TABLE warranty_claims ADD COLUMN IF NOT EXISTS coverage_until date;
            ALTER TABLE warranty_claims ADD COLUMN IF NOT EXISTS coverage_policy text;
            ALTER TABLE warranty_claims ADD COLUMN IF NOT EXISTS coverage_base text;
            ALTER TABLE warranty_claims ADD COLUMN IF NOT EXISTS reject_reason text;
            ALTER TABLE warranty_claims ADD COLUMN IF NOT EXISTS resolution text;
            ALTER TABLE warranty_claims ADD COLUMN IF NOT EXISTS reviewed_by uuid REFERENCES users(id) ON DELETE SET NULL;
            ALTER TABLE warranty_claims ADD COLUMN IF NOT EXISTS reviewed_at timestamptz;
            ALTER TABLE warranty_claims ADD COLUMN IF NOT EXISTS created_at timestamptz NOT NULL DEFAULT now();
            ALTER TABLE warranty_claims ADD COLUMN IF NOT EXISTS updated_at timestamptz NOT NULL DEFAULT now();
            ALTER TABLE warranty_claims ALTER COLUMN status SET DEFAULT 'SUBMITTED';
            UPDATE warranty_claims SET status = 'SUBMITTED' WHERE status = 'OPEN';
            ALTER TABLE warranty_claims ALTER COLUMN contracted_service_id SET NOT NULL;
            ALTER TABLE warranty_claims ALTER COLUMN description SET NOT NULL;

            ALTER TABLE warranty_claims DROP CONSTRAINT IF EXISTS warranty_claims_status_check;
            ALTER TABLE warranty_claims ADD CONSTRAINT warranty_claims_status_check
                CHECK (status IN ('SUBMITTED', 'UNDER_REVIEW', 'APPROVED', 'REJECTED', 'RESOLVED'));
            ALTER TABLE warranty_claims DROP CONSTRAINT IF EXISTS warranty_claims_description_check;
            ALTER TABLE warranty_claims ADD CONSTRAINT warranty_claims_description_check CHECK (btrim(description) <> '');
            -- RN-SUP-06: aprobar exige la cobertura validada y dentro de plazo.
            ALTER TABLE warranty_claims DROP CONSTRAINT IF EXISTS warranty_claims_coverage_check;
            ALTER TABLE warranty_claims ADD CONSTRAINT warranty_claims_coverage_check
                CHECK (status NOT IN ('APPROVED', 'RESOLVED') OR (coverage_valid IS TRUE AND coverage_checked_at IS NOT NULL));
            ALTER TABLE warranty_claims DROP CONSTRAINT IF EXISTS warranty_claims_review_check;
            ALTER TABLE warranty_claims ADD CONSTRAINT warranty_claims_review_check
                CHECK (status IN ('SUBMITTED') OR coverage_checked_at IS NOT NULL);
            ALTER TABLE warranty_claims DROP CONSTRAINT IF EXISTS warranty_claims_reject_check;
            ALTER TABLE warranty_claims ADD CONSTRAINT warranty_claims_reject_check
                CHECK (status <> 'REJECTED' OR btrim(coalesce(reject_reason, '')) <> '');
            ALTER TABLE warranty_claims DROP CONSTRAINT IF EXISTS warranty_claims_resolution_check;
            ALTER TABLE warranty_claims ADD CONSTRAINT warranty_claims_resolution_check
                CHECK (status <> 'RESOLVED' OR btrim(coalesce(resolution, '')) <> '');
            -- Un reclamo por ticket.
            CREATE UNIQUE INDEX IF NOT EXISTS warranty_claims_ticket_key ON warranty_claims (ticket_id) WHERE ticket_id IS NOT NULL;
            CREATE INDEX IF NOT EXISTS idx_warranty_claims_contracted ON warranty_claims (tenant_id, contracted_service_id);
            DROP TRIGGER IF EXISTS trg_warranty_claims_updated_at ON warranty_claims;
            CREATE TRIGGER trg_warranty_claims_updated_at BEFORE UPDATE ON warranty_claims FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            REVOKE DELETE ON warranty_claims FROM erp_app;

            -- 6. Condiciones de garantía del servicio, junto a sus meses.
            ALTER TABLE services ADD COLUMN IF NOT EXISTS warranty_conditions text;

            -- 7. Permisos. Los "support.*" de antes se renombran: quien los tenía los conserva.
            UPDATE permissions SET code = 'tickets.view', name = 'Ver tickets', description = 'Tickets de soporte, su SLA y su conversación.'
            WHERE code = 'support.view' AND NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'tickets.view');
            UPDATE permissions SET code = 'tickets.manage', name = 'Atender tickets', description = 'Clasificar, asignar, responder, cambiar de estado, resolver, cerrar y reabrir tickets.'
            WHERE code = 'support.manage' AND NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'tickets.manage');

            INSERT INTO permissions (code, name, description) VALUES
                ('tickets.view', 'Ver tickets', 'Tickets de soporte, su SLA y su conversación.'),
                ('tickets.create', 'Crear tickets', 'Registrar tickets de soporte para un cliente.'),
                ('tickets.manage', 'Atender tickets', 'Clasificar, asignar, responder, cambiar de estado, resolver, cerrar y reabrir tickets.'),
                ('tickets.approve', 'Escalar tickets', 'Subir la prioridad de un ticket y recalcular su SLA.'),
                ('tickets.export', 'Exportar tickets', 'Descargar el listado de tickets en Excel o CSV.'),
                ('warranty.view', 'Ver reclamos de garantía', 'Reclamos de garantía y su cobertura.'),
                ('warranty.create', 'Registrar reclamos de garantía', 'Registrar un reclamo de garantía de un servicio contratado.'),
                ('warranty.manage', 'Gestionar reclamos de garantía', 'Validar la cobertura y marcar resuelto un reclamo de garantía.'),
                ('warranty.approve', 'Aprobar reclamos de garantía', 'Aprobar o rechazar la cobertura de un reclamo de garantía.'),
                ('warranty.export', 'Exportar reclamos de garantía', 'Descargar el listado de reclamos en Excel o CSV.'),
                ('sla.view', 'Ver calendario laboral y SLA', 'Horario de atención, feriados y tiempos de SLA por prioridad.'),
                ('sla.manage', 'Gestionar calendario laboral y SLA', 'Cambiar el horario de atención, los feriados y los tiempos de SLA.')
            ON CONFLICT (code) DO NOTHING;

            -- Quien atendía tickets con el permiso anterior conserva lo que hacía (crear incluido).
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT rp.role_id, p.id FROM role_permissions rp
            JOIN permissions old ON old.id = rp.permission_id AND old.code = 'tickets.manage'
            JOIN permissions p ON p.code = 'tickets.create'
            ON CONFLICT DO NOTHING;

            UPDATE module_catalog SET permission_prefixes = '["tickets", "warranty", "sla"]'::jsonb WHERE upper(code) = 'SOPORTE';
            UPDATE module_catalog SET permission_prefixes = '["tickets"]'::jsonb WHERE upper(code) = 'TICKETS';

            -- 8. Reparto de la matriz del prototipo. El Administrador de cada empresa: todo.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code LIKE 'tickets.%' OR p.code LIKE 'warranty.%' OR p.code LIKE 'sla.%'
            WHERE r.tenant_id IS NOT NULL AND (r.is_system OR upper(r.code) = 'ADMIN')
            ON CONFLICT DO NOTHING;
            -- Gerencia: ve y exporta tickets, ve y aprueba garantías, ve el calendario.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN ('tickets.view', 'tickets.export', 'warranty.view', 'warranty.approve', 'sla.view')
            WHERE r.tenant_id IS NOT NULL AND upper(r.code) = 'GERENCIA'
            ON CONFLICT DO NOTHING;
            -- Ventas: ve los tickets de lo suyo.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code = 'tickets.view'
            WHERE r.tenant_id IS NOT NULL AND upper(r.code) = 'VENTAS'
            ON CONFLICT DO NOTHING;
            -- Operaciones: ve y atiende los tickets de su sede; ve sus garantías.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN ('tickets.view', 'tickets.manage', 'warranty.view')
            WHERE r.tenant_id IS NOT NULL AND upper(r.code) = 'OPERACIONES'
            ON CONFLICT DO NOTHING;
            -- Técnico: ve y atiende sus tickets.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN ('tickets.view', 'tickets.manage')
            WHERE r.tenant_id IS NOT NULL AND upper(r.code) = 'TECNICO'
            ON CONFLICT DO NOTHING;

            -- 9. Alcance por rol en tickets y garantías.
            ALTER TABLE role_scopes DROP CONSTRAINT IF EXISTS role_scopes_resource_check;
            ALTER TABLE role_scopes ADD CONSTRAINT role_scopes_resource_check
                CHECK (resource IN ('quotations', 'contracts', 'orders', 'portal', 'leads', 'opportunities', 'activities',
                                    'contracted_services', 'work_orders', 'projects', 'time_entries', 'cost_entries', 'agenda',
                                    'billing', 'receivables', 'payments', 'recurring', 'purchases', 'payables', 'budgets',
                                    'tickets', 'warranty'));
            INSERT INTO role_scopes (role_id, tenant_id, resource, scope)
            SELECT r.id, r.tenant_id, x.resource, 'TENANT' FROM roles r CROSS JOIN (VALUES ('tickets'), ('warranty')) AS x(resource)
            WHERE upper(r.code) = 'GERENCIA' AND r.tenant_id IS NOT NULL
            ON CONFLICT DO NOTHING;
            INSERT INTO role_scopes (role_id, tenant_id, resource, scope)
            SELECT r.id, r.tenant_id, x.resource, 'BRANCH' FROM roles r CROSS JOIN (VALUES ('tickets'), ('warranty')) AS x(resource)
            WHERE upper(r.code) = 'OPERACIONES' AND r.tenant_id IS NOT NULL
            ON CONFLICT DO NOTHING;
            INSERT INTO role_scopes (role_id, tenant_id, resource, scope)
            SELECT r.id, r.tenant_id, 'tickets', 'OWN' FROM roles r
            WHERE upper(r.code) IN ('VENTAS', 'TECNICO') AND r.tenant_id IS NOT NULL
            ON CONFLICT DO NOTHING;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM role_scopes WHERE resource IN ('tickets', 'warranty');
            ALTER TABLE role_scopes DROP CONSTRAINT IF EXISTS role_scopes_resource_check;
            ALTER TABLE role_scopes ADD CONSTRAINT role_scopes_resource_check
                CHECK (resource IN ('quotations', 'contracts', 'orders', 'portal', 'leads', 'opportunities', 'activities',
                                    'contracted_services', 'work_orders', 'projects', 'time_entries', 'cost_entries', 'agenda',
                                    'billing', 'receivables', 'payments', 'recurring', 'purchases', 'payables', 'budgets'));

            DELETE FROM role_permissions WHERE permission_id IN (SELECT id FROM permissions
                WHERE code LIKE 'warranty.%' OR code LIKE 'sla.%' OR code IN ('tickets.create', 'tickets.approve', 'tickets.export'));
            DELETE FROM permissions WHERE code LIKE 'warranty.%' OR code LIKE 'sla.%' OR code IN ('tickets.create', 'tickets.approve', 'tickets.export');
            DELETE FROM role_permissions WHERE permission_id IN (SELECT id FROM permissions WHERE code IN ('tickets.view', 'tickets.manage'))
                AND role_id NOT IN (SELECT id FROM roles WHERE is_system OR upper(code) = 'ADMIN');
            UPDATE permissions SET code = 'support.view' WHERE code = 'tickets.view';
            UPDATE permissions SET code = 'support.manage' WHERE code = 'tickets.manage';
            UPDATE module_catalog SET permission_prefixes = '["support"]'::jsonb WHERE upper(code) IN ('SOPORTE', 'TICKETS');

            ALTER TABLE services DROP COLUMN IF EXISTS warranty_conditions;

            GRANT DELETE ON warranty_claims TO erp_app;
            DROP TRIGGER IF EXISTS trg_warranty_claims_updated_at ON warranty_claims;
            DROP INDEX IF EXISTS idx_warranty_claims_contracted;
            DROP INDEX IF EXISTS warranty_claims_ticket_key;
            ALTER TABLE warranty_claims DROP CONSTRAINT IF EXISTS warranty_claims_resolution_check;
            ALTER TABLE warranty_claims DROP CONSTRAINT IF EXISTS warranty_claims_reject_check;
            ALTER TABLE warranty_claims DROP CONSTRAINT IF EXISTS warranty_claims_review_check;
            ALTER TABLE warranty_claims DROP CONSTRAINT IF EXISTS warranty_claims_coverage_check;
            ALTER TABLE warranty_claims DROP CONSTRAINT IF EXISTS warranty_claims_description_check;
            ALTER TABLE warranty_claims DROP CONSTRAINT IF EXISTS warranty_claims_status_check;
            ALTER TABLE warranty_claims ALTER COLUMN description DROP NOT NULL;
            ALTER TABLE warranty_claims ALTER COLUMN status SET DEFAULT 'OPEN';
            ALTER TABLE warranty_claims DROP COLUMN IF EXISTS updated_at, DROP COLUMN IF EXISTS created_at, DROP COLUMN IF EXISTS reviewed_at,
                DROP COLUMN IF EXISTS reviewed_by, DROP COLUMN IF EXISTS resolution, DROP COLUMN IF EXISTS reject_reason,
                DROP COLUMN IF EXISTS coverage_base, DROP COLUMN IF EXISTS coverage_policy, DROP COLUMN IF EXISTS coverage_until,
                DROP COLUMN IF EXISTS coverage_base_date, DROP COLUMN IF EXISTS coverage_valid, DROP COLUMN IF EXISTS coverage_checked_at,
                DROP COLUMN IF EXISTS lock_version, DROP COLUMN IF EXISTS branch_id, DROP COLUMN IF EXISTS created_by,
                DROP COLUMN IF EXISTS ticket_id, DROP COLUMN IF EXISTS service_id, DROP COLUMN IF EXISTS contracted_service_id;

            GRANT UPDATE, DELETE ON support_ticket_comments TO erp_app;
            DROP INDEX IF EXISTS idx_support_ticket_comments_ticket;
            ALTER TABLE support_ticket_comments DROP CONSTRAINT IF EXISTS support_ticket_comments_portal_check;
            ALTER TABLE support_ticket_comments DROP CONSTRAINT IF EXISTS support_ticket_comments_body_check;
            ALTER TABLE support_ticket_comments DROP CONSTRAINT IF EXISTS support_ticket_comments_author_type_check;
            ALTER TABLE support_ticket_comments DROP COLUMN IF EXISTS author_type;

            GRANT DELETE ON support_tickets TO erp_app;
            DROP INDEX IF EXISTS support_tickets_work_order_key;
            DROP INDEX IF EXISTS idx_support_tickets_customer;
            DROP INDEX IF EXISTS idx_support_tickets_due;
            ALTER TABLE support_tickets DROP CONSTRAINT IF EXISTS support_tickets_pause_check;
            ALTER TABLE support_tickets DROP CONSTRAINT IF EXISTS support_tickets_reopen_check;
            ALTER TABLE support_tickets DROP CONSTRAINT IF EXISTS support_tickets_resolution_check;
            ALTER TABLE support_tickets DROP CONSTRAINT IF EXISTS support_tickets_assignee_check;
            ALTER TABLE support_tickets DROP CONSTRAINT IF EXISTS support_tickets_triaged_check;
            ALTER TABLE support_tickets DROP CONSTRAINT IF EXISTS support_tickets_subject_check;
            ALTER TABLE support_tickets DROP CONSTRAINT IF EXISTS support_tickets_calendar_mode_check;
            ALTER TABLE support_tickets DROP CONSTRAINT IF EXISTS support_tickets_category_check;
            ALTER TABLE support_tickets DROP CONSTRAINT IF EXISTS support_tickets_channel_check;
            ALTER TABLE support_tickets DROP CONSTRAINT IF EXISTS support_tickets_priority_check;
            ALTER TABLE support_tickets DROP CONSTRAINT IF EXISTS support_tickets_status_check;
            UPDATE support_tickets SET status = 'IN_PROGRESS' WHERE status = 'WAITING_CUSTOMER';
            ALTER TABLE support_tickets ADD CONSTRAINT support_tickets_status_check
                CHECK (status IN ('OPEN', 'TRIAGED', 'ASSIGNED', 'IN_PROGRESS', 'RESOLVED', 'CLOSED', 'REOPENED'));
            UPDATE support_tickets SET priority = CASE priority WHEN 'P1' THEN 'URGENT' WHEN 'P2' THEN 'HIGH' WHEN 'P4' THEN 'LOW' ELSE 'NORMAL' END;
            ALTER TABLE support_tickets ALTER COLUMN priority SET NOT NULL;
            ALTER TABLE support_tickets ALTER COLUMN priority SET DEFAULT 'NORMAL';
            ALTER TABLE support_tickets DROP COLUMN IF EXISTS work_order_id, DROP COLUMN IF EXISTS reopen_reason, DROP COLUMN IF EXISTS resolution,
                DROP COLUMN IF EXISTS paused_seconds, DROP COLUMN IF EXISTS paused_at, DROP COLUMN IF EXISTS first_response_at,
                DROP COLUMN IF EXISTS resolution_due_at, DROP COLUMN IF EXISTS response_due_at, DROP COLUMN IF EXISTS sla_resolution_minutes,
                DROP COLUMN IF EXISTS sla_response_minutes, DROP COLUMN IF EXISTS sla_factor, DROP COLUMN IF EXISTS sla_calendar_mode, DROP COLUMN IF EXISTS sla_started_at,
                DROP COLUMN IF EXISTS triaged_at, DROP COLUMN IF EXISTS lock_version, DROP COLUMN IF EXISTS created_by,
                DROP COLUMN IF EXISTS branch_id, DROP COLUMN IF EXISTS team_id, DROP COLUMN IF EXISTS service_id,
                DROP COLUMN IF EXISTS category, DROP COLUMN IF EXISTS channel;

            GRANT DELETE ON support_slas TO erp_app;
            DROP INDEX IF EXISTS support_slas_tenant_priority_key;
            ALTER TABLE support_slas DROP CONSTRAINT IF EXISTS support_slas_minutes_check;
            ALTER TABLE support_slas DROP CONSTRAINT IF EXISTS support_slas_priority_check;
            ALTER TABLE support_slas DROP CONSTRAINT IF EXISTS support_slas_calendar_mode_check;
            ALTER TABLE support_slas ALTER COLUMN priority DROP NOT NULL;
            ALTER TABLE support_slas DROP COLUMN IF EXISTS updated_at, DROP COLUMN IF EXISTS calendar_mode;

            DROP TABLE IF EXISTS support_holidays;
            DROP FUNCTION IF EXISTS check_support_holiday_update();
            DROP TABLE IF EXISTS support_calendars;
        SQL);
    }
};
