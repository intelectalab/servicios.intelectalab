<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Personal (kit v1.5, D-44 y D-45): trabajadores, puestos, costo por hora, vacaciones y
 * ausencias, envío mensual de horas a la planilla y conexión con el SaaS de RRHH propio
 * (contrato docs/14-INTEGRACION-RRHH.md del kit).
 *
 * - Módulo PERSONAL en el catálogo, incluido en Profesional y Enterprise y encendido en las
 *   empresas con esos planes vigentes.
 * - Se amplía la tabla `employees` que ya existía (un trabajador = una persona de Clientes y
 *   actores con rol Empleado; su usuario es opcional). Nunca sueldos, AFP ni boletas.
 * - El costo por hora que ya estaba en el usuario pasa también al trabajador vinculado.
 * - La base garantiza: costo por hora positivo, jornada de 1 a 48 horas, cese posterior al
 *   ingreso, un trabajador por persona y por usuario, ausencias aprobadas que no se solapan,
 *   nadie aprueba su propia ausencia, un rechazo con motivo, envíos versionados que no se
 *   reescriben, y nada de esto se borra.
 * - Conexión con el SaaS de RRHH: una por empresa, con su token y su clave de firma en el
 *   Credential Vault; mensajes recibidos (únicos por event_id) y enviados (con reintentos);
 *   ejecuciones de sincronización y bandeja de trabajadores sin pareja.
 * - Permisos employees.*, leaves.* y hr_sync.*, con el reparto de la matriz del kit y alcance
 *   por rol en trabajadores y ausencias.
 *
 * Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- 1. Módulo y planes.
            INSERT INTO module_catalog (code, name, description, category, is_core, active, display_order, permission_prefixes)
            VALUES ('PERSONAL', 'Personal', 'Trabajadores, puestos y costo por hora; vacaciones y ausencias que bloquean la agenda; envío mensual de horas a la planilla y conexión con el SaaS de RRHH.', 'OPERACIONES', false, true, 17, '["employees", "leaves", "hr_sync"]')
            ON CONFLICT (code) DO NOTHING;

            UPDATE saas_plans SET modules = modules || '{"PERSONAL": true}'::jsonb, updated_at = now()
            WHERE code IN ('PRO', 'ENTERPRISE') AND NOT (modules ? 'PERSONAL');

            INSERT INTO tenant_modules (tenant_id, module_code, enabled, activated_at, configuration, created_at, updated_at)
            SELECT DISTINCT s.tenant_id, 'PERSONAL', true, now(), '{}'::jsonb, now(), now()
            FROM tenant_subscriptions s JOIN saas_plans p ON p.id = s.plan_id
            WHERE s.status = 'ACTIVE' AND p.code IN ('PRO', 'ENTERPRISE')
              AND NOT EXISTS (SELECT 1 FROM tenant_modules m WHERE m.tenant_id = s.tenant_id AND upper(m.module_code) = 'PERSONAL');

            -- 2. Puestos.
            CREATE TABLE IF NOT EXISTS hr_positions (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                code varchar(50) NOT NULL,
                name varchar(150) NOT NULL,
                department varchar(150),
                default_hourly_cost numeric(14,2),
                currency char(3) NOT NULL DEFAULT 'PEN',
                active boolean NOT NULL DEFAULT true,
                source varchar(10) NOT NULL DEFAULT 'LOCAL',
                external_id varchar(100),
                external_version integer,
                synced_at timestamptz,
                lock_version integer NOT NULL DEFAULT 0,
                created_by uuid REFERENCES users(id) ON DELETE SET NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT hr_positions_tenant_id_key UNIQUE (tenant_id, id),
                CONSTRAINT hr_positions_code_key UNIQUE (tenant_id, code),
                CONSTRAINT hr_positions_external_key UNIQUE (tenant_id, external_id),
                CONSTRAINT hr_positions_currency_check CHECK (currency IN ('PEN', 'USD')),
                CONSTRAINT hr_positions_source_check CHECK (source IN ('LOCAL', 'HR_SAAS')),
                CONSTRAINT hr_positions_cost_check CHECK (default_hourly_cost IS NULL OR default_hourly_cost > 0),
                CONSTRAINT hr_positions_name_check CHECK (btrim(name) <> '' AND btrim(code) <> ''),
                CONSTRAINT hr_positions_hr_saas_check CHECK (source <> 'HR_SAAS' OR external_id IS NOT NULL)
            );

            -- 3. Trabajadores: la tabla que ya existía.
            ALTER TABLE employees ADD COLUMN IF NOT EXISTS position_id uuid;
            ALTER TABLE employees ADD COLUMN IF NOT EXISTS branch_id uuid REFERENCES branches(id) ON DELETE SET NULL;
            ALTER TABLE employees ADD COLUMN IF NOT EXISTS hire_date date;
            ALTER TABLE employees ADD COLUMN IF NOT EXISTS termination_date date;
            ALTER TABLE employees ADD COLUMN IF NOT EXISTS termination_reason varchar(500);
            ALTER TABLE employees ADD COLUMN IF NOT EXISTS contract_type varchar(20);
            ALTER TABLE employees ADD COLUMN IF NOT EXISTS weekly_hours numeric(5,2) NOT NULL DEFAULT 48;
            ALTER TABLE employees ADD COLUMN IF NOT EXISTS hourly_cost numeric(14,2);
            ALTER TABLE employees ADD COLUMN IF NOT EXISTS currency char(3) NOT NULL DEFAULT 'PEN';
            ALTER TABLE employees ADD COLUMN IF NOT EXISTS payroll_external_id varchar(100);
            ALTER TABLE employees ADD COLUMN IF NOT EXISTS source varchar(10) NOT NULL DEFAULT 'LOCAL';
            ALTER TABLE employees ADD COLUMN IF NOT EXISTS external_version integer;
            ALTER TABLE employees ADD COLUMN IF NOT EXISTS synced_at timestamptz;
            ALTER TABLE employees ADD COLUMN IF NOT EXISTS lock_version integer NOT NULL DEFAULT 0;
            ALTER TABLE employees ADD COLUMN IF NOT EXISTS created_by uuid REFERENCES users(id) ON DELETE SET NULL;

            ALTER TABLE employees ADD CONSTRAINT employees_tenant_id_key UNIQUE (tenant_id, id);
            ALTER TABLE employees ADD CONSTRAINT employees_person_key UNIQUE (tenant_id, person_party_id);
            ALTER TABLE employees ADD CONSTRAINT employees_payroll_key UNIQUE (tenant_id, payroll_external_id);
            CREATE UNIQUE INDEX IF NOT EXISTS employees_user_key ON employees (tenant_id, user_id) WHERE user_id IS NOT NULL;
            CREATE INDEX IF NOT EXISTS employees_branch_idx ON employees (tenant_id, branch_id);
            ALTER TABLE employees ADD CONSTRAINT fk_employees_position_same_tenant FOREIGN KEY (tenant_id, position_id) REFERENCES hr_positions(tenant_id, id);

            ALTER TABLE employees DROP CONSTRAINT IF EXISTS employees_status_check;
            ALTER TABLE employees ADD CONSTRAINT employees_status_check CHECK (status IN ('ACTIVE', 'INACTIVE', 'TERMINATED'));
            ALTER TABLE employees ADD CONSTRAINT employees_contract_type_check CHECK (contract_type IS NULL OR contract_type IN ('INDEFINIDO', 'PLAZO_FIJO', 'TIEMPO_PARCIAL', 'PRACTICAS'));
            ALTER TABLE employees ADD CONSTRAINT employees_currency_check CHECK (currency IN ('PEN', 'USD'));
            ALTER TABLE employees ADD CONSTRAINT employees_source_check CHECK (source IN ('LOCAL', 'HR_SAAS'));
            ALTER TABLE employees ADD CONSTRAINT employees_hourly_cost_check CHECK (hourly_cost IS NULL OR hourly_cost > 0);
            ALTER TABLE employees ADD CONSTRAINT employees_weekly_hours_check CHECK (weekly_hours > 0 AND weekly_hours <= 48);
            ALTER TABLE employees ADD CONSTRAINT employees_termination_check CHECK (termination_date IS NULL OR hire_date IS NULL OR termination_date >= hire_date);
            ALTER TABLE employees ADD CONSTRAINT employees_terminated_check CHECK (status <> 'TERMINATED' OR termination_date IS NOT NULL);
            ALTER TABLE employees ADD CONSTRAINT employees_hr_saas_check CHECK (source <> 'HR_SAAS' OR payroll_external_id IS NOT NULL);

            -- Lo que ya se sabía: la sede y el costo por hora del usuario vinculado.
            UPDATE employees e SET branch_id = tu.default_branch_id,
                hourly_cost = CASE WHEN tu.hourly_cost > 0 THEN round(tu.hourly_cost, 2) ELSE e.hourly_cost END
            FROM tenant_users tu
            WHERE tu.tenant_id = e.tenant_id AND tu.user_id = e.user_id AND e.branch_id IS NULL;

            -- El cargo escrito a mano pasa a ser un puesto (el texto se conserva).
            INSERT INTO hr_positions (tenant_id, code, name, created_at, updated_at)
            SELECT tenant_id, 'PUE-' || lpad(row_number() OVER (PARTITION BY tenant_id ORDER BY name)::text, 3, '0'), name, now(), now()
            FROM (SELECT DISTINCT tenant_id, btrim(position) AS name FROM employees WHERE btrim(coalesce(position, '')) <> '') p
            ON CONFLICT DO NOTHING;
            UPDATE employees e SET position_id = p.id FROM hr_positions p
            WHERE p.tenant_id = e.tenant_id AND p.name = btrim(e.position) AND e.position_id IS NULL;

            -- 4. Tipos de ausencia y solicitudes.
            CREATE TABLE IF NOT EXISTS hr_leave_types (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                code varchar(30) NOT NULL,
                name varchar(120) NOT NULL,
                paid boolean NOT NULL DEFAULT true,
                requires_approval boolean NOT NULL DEFAULT true,
                days_per_year integer,
                active boolean NOT NULL DEFAULT true,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT hr_leave_types_tenant_id_key UNIQUE (tenant_id, id),
                CONSTRAINT hr_leave_types_code_key UNIQUE (tenant_id, code),
                CONSTRAINT hr_leave_types_days_check CHECK (days_per_year IS NULL OR days_per_year BETWEEN 1 AND 366),
                CONSTRAINT hr_leave_types_name_check CHECK (btrim(name) <> '' AND btrim(code) <> '')
            );

            CREATE TABLE IF NOT EXISTS hr_leave_requests (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                employee_id uuid NOT NULL,
                leave_type_id uuid NOT NULL,
                -- Copias del trabajador al pedirla: para el alcance (propio y sede) y para la agenda.
                user_id uuid REFERENCES users(id) ON DELETE SET NULL,
                branch_id uuid REFERENCES branches(id) ON DELETE SET NULL,
                date_from date NOT NULL,
                date_to date NOT NULL,
                days integer NOT NULL,
                reason text,
                status varchar(12) NOT NULL DEFAULT 'REQUESTED',
                decided_by uuid REFERENCES users(id) ON DELETE SET NULL,
                decided_at timestamptz,
                decision_note text,
                source varchar(10) NOT NULL DEFAULT 'LOCAL',
                external_id varchar(100),
                external_version integer,
                lock_version integer NOT NULL DEFAULT 0,
                created_by uuid REFERENCES users(id) ON DELETE SET NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT hr_leave_requests_external_key UNIQUE (tenant_id, external_id),
                CONSTRAINT fk_hr_leave_employee FOREIGN KEY (tenant_id, employee_id) REFERENCES employees(tenant_id, id),
                CONSTRAINT fk_hr_leave_type FOREIGN KEY (tenant_id, leave_type_id) REFERENCES hr_leave_types(tenant_id, id),
                CONSTRAINT hr_leave_requests_status_check CHECK (status IN ('REQUESTED', 'APPROVED', 'REJECTED', 'CANCELLED')),
                CONSTRAINT hr_leave_requests_source_check CHECK (source IN ('LOCAL', 'HR_SAAS')),
                CONSTRAINT hr_leave_requests_dates_check CHECK (date_to >= date_from),
                CONSTRAINT hr_leave_requests_days_check CHECK (days > 0 AND days <= date_to - date_from + 1),
                CONSTRAINT hr_leave_requests_reject_check CHECK (status <> 'REJECTED' OR btrim(coalesce(decision_note, '')) <> ''),
                -- Nadie aprueba ni rechaza su propia solicitud.
                CONSTRAINT hr_leave_requests_self_check CHECK (decided_by IS NULL OR user_id IS NULL OR decided_by <> user_id),
                CONSTRAINT hr_leave_requests_hr_saas_check CHECK (source <> 'HR_SAAS' OR external_id IS NOT NULL)
            );
            CREATE INDEX IF NOT EXISTS hr_leave_requests_employee_idx ON hr_leave_requests (tenant_id, employee_id, date_from);
            CREATE INDEX IF NOT EXISTS hr_leave_requests_user_idx ON hr_leave_requests (tenant_id, user_id, date_from) WHERE status = 'APPROVED';
            CREATE INDEX IF NOT EXISTS hr_leave_requests_status_idx ON hr_leave_requests (tenant_id, status);

            -- Dos ausencias aprobadas del mismo trabajador no se solapan (también con dos aprobaciones a la vez).
            CREATE OR REPLACE FUNCTION check_hr_leave_overlap() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.status = 'APPROVED' THEN
                    PERFORM pg_advisory_xact_lock(hashtext('hr_leave:' || NEW.employee_id::text));
                    IF EXISTS (
                        SELECT 1 FROM hr_leave_requests r
                        WHERE r.tenant_id = NEW.tenant_id AND r.employee_id = NEW.employee_id AND r.id <> NEW.id
                          AND r.status = 'APPROVED' AND r.date_from <= NEW.date_to AND r.date_to >= NEW.date_from
                    ) THEN
                        RAISE EXCEPTION 'HR_LEAVE_OVERLAP' USING ERRCODE = 'check_violation',
                            DETAIL = 'El trabajador ya tiene una ausencia aprobada en esas fechas.';
                    END IF;
                END IF;
                RETURN NEW;
            END $$;
            DROP TRIGGER IF EXISTS trg_hr_leave_overlap ON hr_leave_requests;
            CREATE TRIGGER trg_hr_leave_overlap BEFORE INSERT OR UPDATE ON hr_leave_requests FOR EACH ROW EXECUTE FUNCTION check_hr_leave_overlap();

            -- Una solicitud decidida no vuelve atrás: aprobada solo se anula; rechazada o anulada no cambia.
            CREATE OR REPLACE FUNCTION check_hr_leave_transition() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.status IN ('REJECTED', 'CANCELLED') AND (NEW.status IS DISTINCT FROM OLD.status
                    OR NEW.date_from IS DISTINCT FROM OLD.date_from OR NEW.date_to IS DISTINCT FROM OLD.date_to) THEN
                    -- Lo que llega del SaaS de RRHH manda (una ausencia anulada allí puede volver).
                    IF NEW.source <> 'HR_SAAS' THEN
                        RAISE EXCEPTION 'Una solicitud rechazada o anulada no se modifica.' USING ERRCODE = 'check_violation';
                    END IF;
                END IF;
                IF OLD.status = 'APPROVED' AND NEW.status NOT IN ('APPROVED', 'CANCELLED') AND NEW.source <> 'HR_SAAS' THEN
                    RAISE EXCEPTION 'Una ausencia aprobada solo se anula.' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$;
            DROP TRIGGER IF EXISTS trg_hr_leave_transition ON hr_leave_requests;
            CREATE TRIGGER trg_hr_leave_transition BEFORE UPDATE ON hr_leave_requests FOR EACH ROW EXECUTE FUNCTION check_hr_leave_transition();

            -- 5. Conexión con el SaaS de RRHH (una por empresa).
            CREATE TABLE IF NOT EXISTS hr_connections (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                base_url varchar(300) NOT NULL,
                status varchar(10) NOT NULL DEFAULT 'INACTIVE',
                token_credential_id uuid REFERENCES credential_records(id) ON DELETE SET NULL,
                secret_credential_id uuid REFERENCES credential_records(id) ON DELETE SET NULL,
                initial_match_at timestamptz,
                activated_at timestamptz,
                deactivated_at timestamptz,
                last_event_at timestamptz,
                lock_version integer NOT NULL DEFAULT 0,
                created_by uuid REFERENCES users(id) ON DELETE SET NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT hr_connections_tenant_key UNIQUE (tenant_id),
                CONSTRAINT hr_connections_tenant_id_key UNIQUE (tenant_id, id),
                CONSTRAINT hr_connections_status_check CHECK (status IN ('ACTIVE', 'INACTIVE')),
                CONSTRAINT hr_connections_url_check CHECK (base_url LIKE 'https://%')
            );

            -- El webhook llega sin sesión: solo así se sabe de qué empresa es (y nada más).
            CREATE OR REPLACE FUNCTION hr_connection_tenant(p_connection uuid) RETURNS uuid
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
                SELECT tenant_id FROM hr_connections WHERE id = p_connection AND status = 'ACTIVE';
            $$;
            REVOKE ALL ON FUNCTION hr_connection_tenant(uuid) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION hr_connection_tenant(uuid) TO erp_app;

            -- 6. Mensajes recibidos (únicos por event_id) y enviados.
            CREATE TABLE IF NOT EXISTS hr_inbound_events (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                connection_id uuid NOT NULL,
                event_id varchar(100) NOT NULL,
                event_type varchar(40) NOT NULL,
                occurred_at timestamptz,
                payload jsonb NOT NULL,
                status varchar(10) NOT NULL DEFAULT 'RECEIVED',
                result varchar(500),
                attempts integer NOT NULL DEFAULT 0,
                received_at timestamptz NOT NULL DEFAULT now(),
                processed_at timestamptz,
                CONSTRAINT hr_inbound_events_key UNIQUE (tenant_id, event_id),
                CONSTRAINT fk_hr_inbound_connection FOREIGN KEY (tenant_id, connection_id) REFERENCES hr_connections(tenant_id, id),
                CONSTRAINT hr_inbound_events_status_check CHECK (status IN ('RECEIVED', 'APPLIED', 'SKIPPED', 'UNMATCHED', 'FAILED'))
            );
            CREATE INDEX IF NOT EXISTS hr_inbound_events_pending_idx ON hr_inbound_events (received_at) WHERE status = 'RECEIVED';
            CREATE INDEX IF NOT EXISTS hr_inbound_events_recent_idx ON hr_inbound_events (tenant_id, received_at DESC);

            CREATE TABLE IF NOT EXISTS hr_payroll_exports (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                period char(7) NOT NULL,
                version integer NOT NULL DEFAULT 1,
                destination varchar(10) NOT NULL,
                employee_count integer NOT NULL,
                minutes_total integer NOT NULL DEFAULT 0,
                payload jsonb NOT NULL,
                payload_sha256 char(64) NOT NULL,
                document_id uuid,
                connection_id uuid,
                status varchar(14) NOT NULL DEFAULT 'GENERATED',
                sent_at timestamptz,
                acknowledged_at timestamptz,
                provider_response varchar(1000),
                generated_by uuid REFERENCES users(id) ON DELETE SET NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT hr_payroll_exports_tenant_id_key UNIQUE (tenant_id, id),
                CONSTRAINT hr_payroll_exports_version_key UNIQUE (tenant_id, period, version),
                CONSTRAINT fk_hr_payroll_connection FOREIGN KEY (tenant_id, connection_id) REFERENCES hr_connections(tenant_id, id),
                CONSTRAINT hr_payroll_exports_period_check CHECK (period ~ '^[0-9]{4}-(0[1-9]|1[0-2])$'),
                CONSTRAINT hr_payroll_exports_destination_check CHECK (destination IN ('FILE', 'HR_SAAS')),
                CONSTRAINT hr_payroll_exports_status_check CHECK (status IN ('GENERATED', 'SENT', 'ACKNOWLEDGED', 'FAILED')),
                CONSTRAINT hr_payroll_exports_count_check CHECK (employee_count >= 0 AND minutes_total >= 0 AND version >= 1),
                CONSTRAINT hr_payroll_exports_hr_saas_check CHECK (destination <> 'HR_SAAS' OR connection_id IS NOT NULL)
            );

            -- Lo enviado no se reescribe: solo cambia su estado de entrega.
            CREATE OR REPLACE FUNCTION check_hr_payroll_export_update() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.payload IS DISTINCT FROM OLD.payload OR NEW.payload_sha256 IS DISTINCT FROM OLD.payload_sha256
                    OR NEW.period IS DISTINCT FROM OLD.period OR NEW.version IS DISTINCT FROM OLD.version
                    OR NEW.destination IS DISTINCT FROM OLD.destination OR NEW.employee_count IS DISTINCT FROM OLD.employee_count THEN
                    RAISE EXCEPTION 'Un envío a planilla no se modifica: se genera una versión nueva.' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$;
            DROP TRIGGER IF EXISTS trg_hr_payroll_export_update ON hr_payroll_exports;
            CREATE TRIGGER trg_hr_payroll_export_update BEFORE UPDATE ON hr_payroll_exports FOR EACH ROW EXECUTE FUNCTION check_hr_payroll_export_update();

            CREATE TABLE IF NOT EXISTS hr_outbound_events (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                connection_id uuid NOT NULL,
                event_id varchar(100) NOT NULL,
                event_type varchar(40) NOT NULL,
                payload jsonb NOT NULL,
                export_id uuid,
                status varchar(10) NOT NULL DEFAULT 'PENDING',
                attempts integer NOT NULL DEFAULT 0,
                next_attempt_at timestamptz DEFAULT now(),
                response_code integer,
                last_error varchar(500),
                sent_at timestamptz,
                created_by uuid REFERENCES users(id) ON DELETE SET NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT hr_outbound_events_key UNIQUE (tenant_id, event_id),
                CONSTRAINT fk_hr_outbound_connection FOREIGN KEY (tenant_id, connection_id) REFERENCES hr_connections(tenant_id, id),
                CONSTRAINT fk_hr_outbound_export FOREIGN KEY (tenant_id, export_id) REFERENCES hr_payroll_exports(tenant_id, id),
                CONSTRAINT hr_outbound_events_status_check CHECK (status IN ('PENDING', 'RETRY', 'SENT', 'DEAD'))
            );
            CREATE INDEX IF NOT EXISTS hr_outbound_events_due_idx ON hr_outbound_events (next_attempt_at) WHERE status IN ('PENDING', 'RETRY');

            -- 7. Sincronizaciones y bandeja.
            CREATE TABLE IF NOT EXISTS hr_sync_runs (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                connection_id uuid NOT NULL,
                kind varchar(14) NOT NULL,
                status varchar(10) NOT NULL DEFAULT 'RUNNING',
                received integer NOT NULL DEFAULT 0,
                applied integer NOT NULL DEFAULT 0,
                skipped integer NOT NULL DEFAULT 0,
                unmatched integer NOT NULL DEFAULT 0,
                error varchar(500),
                started_by uuid REFERENCES users(id) ON DELETE SET NULL,
                started_at timestamptz NOT NULL DEFAULT now(),
                finished_at timestamptz,
                CONSTRAINT hr_sync_runs_tenant_id_key UNIQUE (tenant_id, id),
                CONSTRAINT fk_hr_sync_connection FOREIGN KEY (tenant_id, connection_id) REFERENCES hr_connections(tenant_id, id),
                CONSTRAINT hr_sync_runs_kind_check CHECK (kind IN ('INITIAL_MATCH', 'WEBHOOK', 'FULL_RESYNC')),
                CONSTRAINT hr_sync_runs_status_check CHECK (status IN ('RUNNING', 'COMPLETED', 'FAILED')),
                CONSTRAINT hr_sync_runs_counts_check CHECK (applied + skipped + unmatched <= received)
            );
            CREATE INDEX IF NOT EXISTS hr_sync_runs_recent_idx ON hr_sync_runs (tenant_id, started_at DESC);

            CREATE TABLE IF NOT EXISTS hr_sync_unmatched (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                run_id uuid NOT NULL,
                external_id varchar(100) NOT NULL,
                doc_type varchar(20),
                doc_number varchar(30),
                full_name varchar(300) NOT NULL,
                reason varchar(16) NOT NULL,
                candidate_ids jsonb NOT NULL DEFAULT '[]'::jsonb,
                payload jsonb NOT NULL,
                status varchar(10) NOT NULL DEFAULT 'OPEN',
                employee_id uuid,
                resolved_by uuid REFERENCES users(id) ON DELETE SET NULL,
                resolved_at timestamptz,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT fk_hr_unmatched_run FOREIGN KEY (tenant_id, run_id) REFERENCES hr_sync_runs(tenant_id, id),
                CONSTRAINT fk_hr_unmatched_employee FOREIGN KEY (tenant_id, employee_id) REFERENCES employees(tenant_id, id),
                CONSTRAINT hr_sync_unmatched_reason_check CHECK (reason IN ('NO_MATCH', 'MULTIPLE_MATCH', 'CONFLICT')),
                CONSTRAINT hr_sync_unmatched_status_check CHECK (status IN ('OPEN', 'LINKED', 'CREATED', 'IGNORED')),
                CONSTRAINT hr_sync_unmatched_resolved_check CHECK (status = 'OPEN' OR resolved_at IS NOT NULL),
                CONSTRAINT hr_sync_unmatched_employee_check CHECK (status NOT IN ('LINKED', 'CREATED') OR employee_id IS NOT NULL)
            );
            -- Un mismo trabajador de RRHH queda una sola vez abierto en la bandeja.
            CREATE UNIQUE INDEX IF NOT EXISTS hr_sync_unmatched_open_key ON hr_sync_unmatched (tenant_id, external_id) WHERE status = 'OPEN';

            -- 8. Aislamiento por empresa, permisos de la app y nada se borra.
            DO $$
            DECLARE t text;
            BEGIN
                FOREACH t IN ARRAY ARRAY['hr_positions', 'hr_leave_types', 'hr_leave_requests', 'hr_connections', 'hr_inbound_events',
                                         'hr_payroll_exports', 'hr_outbound_events', 'hr_sync_runs', 'hr_sync_unmatched'] LOOP
                    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
                    EXECUTE format('DROP POLICY IF EXISTS tenant_isolation ON %I', t);
                    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id())', t);
                    EXECUTE format('REVOKE ALL ON %I FROM erp_app', t);
                    EXECUTE format('GRANT SELECT, INSERT, UPDATE ON %I TO erp_app', t);
                    EXECUTE format('DROP TRIGGER IF EXISTS trg_%s_updated_at ON %I', t, t);
                END LOOP;
            END $$;
            CREATE TRIGGER trg_hr_positions_updated_at BEFORE UPDATE ON hr_positions FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            CREATE TRIGGER trg_hr_leave_types_updated_at BEFORE UPDATE ON hr_leave_types FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            CREATE TRIGGER trg_hr_leave_requests_updated_at BEFORE UPDATE ON hr_leave_requests FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            CREATE TRIGGER trg_hr_connections_updated_at BEFORE UPDATE ON hr_connections FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            CREATE TRIGGER trg_hr_payroll_exports_updated_at BEFORE UPDATE ON hr_payroll_exports FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            CREATE TRIGGER trg_hr_outbound_events_updated_at BEFORE UPDATE ON hr_outbound_events FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            CREATE TRIGGER trg_hr_sync_unmatched_updated_at BEFORE UPDATE ON hr_sync_unmatched FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            -- Un mensaje recibido no cambia después de procesarse (solo su estado mientras se procesa).
            REVOKE DELETE ON employees FROM erp_app;

            -- 9. Alcance por rol en trabajadores y ausencias.
            ALTER TABLE role_scopes DROP CONSTRAINT IF EXISTS role_scopes_resource_check;
            ALTER TABLE role_scopes ADD CONSTRAINT role_scopes_resource_check
                CHECK (resource IN ('quotations', 'contracts', 'orders', 'portal', 'leads', 'opportunities', 'activities',
                                    'contracted_services', 'work_orders', 'projects', 'time_entries', 'cost_entries', 'agenda',
                                    'billing', 'receivables', 'payments', 'recurring', 'purchases', 'payables', 'budgets',
                                    'tickets', 'warranty', 'documents', 'imports', 'employees', 'leaves'));

            -- 10. Permisos.
            INSERT INTO permissions (code, name, description) VALUES
                ('employees.view', 'Ver trabajadores', 'Ver trabajadores y puestos, con su jornada y costo por hora.'),
                ('employees.create', 'Registrar trabajadores', 'Dar de alta trabajadores y puestos.'),
                ('employees.manage', 'Gestionar trabajadores', 'Editar trabajadores y puestos, vincular su usuario y registrar su cese.'),
                ('employees.export', 'Exportar trabajadores', 'Exportar la lista de trabajadores y puestos.'),
                ('leaves.view', 'Ver ausencias', 'Ver vacaciones y ausencias y el calendario del equipo.'),
                ('leaves.create', 'Pedir ausencias', 'Registrar solicitudes de vacaciones o ausencia.'),
                ('leaves.approve', 'Aprobar ausencias', 'Aprobar o rechazar solicitudes de otras personas.'),
                ('leaves.manage', 'Gestionar ausencias', 'Anular ausencias y configurar los tipos de ausencia.'),
                ('leaves.export', 'Exportar ausencias', 'Exportar la lista de ausencias.'),
                ('hr_sync.view', 'Ver envíos y sincronización', 'Ver los envíos de horas a la planilla y el estado de la conexión con el SaaS de RRHH.'),
                ('hr_sync.create', 'Generar envíos a planilla', 'Generar el envío mensual de horas (y su versión nueva).'),
                ('hr_sync.manage', 'Gestionar la conexión con RRHH', 'Configurar la conexión con el SaaS de RRHH, enviar o reenviar y resolver la bandeja.'),
                ('hr_sync.approve', 'Sincronizar con RRHH', 'Activar la conexión, hacer la carga inicial y releer todo desde el SaaS de RRHH.'),
                ('hr_sync.export', 'Exportar envíos', 'Exportar la lista de envíos y descargar su archivo.')
            ON CONFLICT (code) DO NOTHING;

            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r
            JOIN permissions p ON p.code LIKE 'employees.%' OR p.code LIKE 'leaves.%' OR p.code LIKE 'hr_sync.%'
            WHERE r.tenant_id IS NOT NULL AND (r.is_system OR upper(r.code) = 'ADMIN')
            ON CONFLICT DO NOTHING;
        SQL);

        // Los roles base que ya existen reciben lo suyo de la matriz del kit (solo si la empresa tiene Personal).
        $tenants = DB::table('tenant_modules')->whereRaw("upper(module_code) = 'PERSONAL'")->where('enabled', true)->pluck('tenant_id');
        $permissionIds = DB::table('permissions')->pluck('id', 'code');
        foreach ($tenants as $tenantId) {
            $modules = DB::table('tenant_modules')->where('tenant_id', $tenantId)->where('enabled', true)->pluck('module_code')->all();
            foreach (array_keys(\App\Modules\Subscription\Support\BaseRoles::ROLES) as $code) {
                $role = DB::table('roles')->where('tenant_id', $tenantId)->where('code', $code)->first();
                if (! $role) {
                    continue;
                }
                $definition = \App\Modules\Subscription\Support\BaseRoles::definition($code, $modules);
                foreach ($definition['permissions'] as $permission) {
                    if (preg_match('/^(employees|leaves|hr_sync)\./', $permission)) {
                        DB::table('role_permissions')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $permissionIds[$permission]]);
                    }
                }
                foreach (['employees', 'leaves'] as $resource) {
                    if (isset($definition['scopes'][$resource]) && ! DB::table('role_scopes')->where('role_id', $role->id)->where('resource', $resource)->exists()) {
                        DB::table('role_scopes')->insert(['role_id' => $role->id, 'tenant_id' => $tenantId, 'resource' => $resource, 'scope' => $definition['scopes'][$resource], 'updated_at' => now()]);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM role_scopes WHERE resource IN ('employees', 'leaves');
            ALTER TABLE role_scopes DROP CONSTRAINT IF EXISTS role_scopes_resource_check;
            ALTER TABLE role_scopes ADD CONSTRAINT role_scopes_resource_check
                CHECK (resource IN ('quotations', 'contracts', 'orders', 'portal', 'leads', 'opportunities', 'activities',
                                    'contracted_services', 'work_orders', 'projects', 'time_entries', 'cost_entries', 'agenda',
                                    'billing', 'receivables', 'payments', 'recurring', 'purchases', 'payables', 'budgets',
                                    'tickets', 'warranty', 'documents', 'imports'));
            DELETE FROM role_permissions WHERE permission_id IN (SELECT id FROM permissions WHERE code LIKE 'employees.%' OR code LIKE 'leaves.%' OR code LIKE 'hr_sync.%');
            DELETE FROM permissions WHERE code LIKE 'employees.%' OR code LIKE 'leaves.%' OR code LIKE 'hr_sync.%';
            DROP TABLE IF EXISTS hr_sync_unmatched, hr_sync_runs, hr_outbound_events, hr_payroll_exports, hr_inbound_events;
            DROP FUNCTION IF EXISTS hr_connection_tenant(uuid);
            DROP TABLE IF EXISTS hr_connections, hr_leave_requests, hr_leave_types;
            DROP FUNCTION IF EXISTS check_hr_leave_overlap(), check_hr_leave_transition(), check_hr_payroll_export_update();
            GRANT DELETE ON employees TO erp_app;
            ALTER TABLE employees DROP CONSTRAINT IF EXISTS fk_employees_position_same_tenant, DROP CONSTRAINT IF EXISTS employees_status_check,
                DROP CONSTRAINT IF EXISTS employees_contract_type_check, DROP CONSTRAINT IF EXISTS employees_currency_check,
                DROP CONSTRAINT IF EXISTS employees_source_check, DROP CONSTRAINT IF EXISTS employees_hourly_cost_check,
                DROP CONSTRAINT IF EXISTS employees_weekly_hours_check, DROP CONSTRAINT IF EXISTS employees_termination_check,
                DROP CONSTRAINT IF EXISTS employees_terminated_check, DROP CONSTRAINT IF EXISTS employees_hr_saas_check,
                DROP CONSTRAINT IF EXISTS employees_payroll_key, DROP CONSTRAINT IF EXISTS employees_person_key;
            DROP INDEX IF EXISTS employees_user_key;
            DROP INDEX IF EXISTS employees_branch_idx;
            ALTER TABLE employees DROP CONSTRAINT IF EXISTS employees_tenant_id_key;
            ALTER TABLE employees DROP COLUMN IF EXISTS position_id, DROP COLUMN IF EXISTS branch_id, DROP COLUMN IF EXISTS hire_date,
                DROP COLUMN IF EXISTS termination_date, DROP COLUMN IF EXISTS termination_reason, DROP COLUMN IF EXISTS contract_type,
                DROP COLUMN IF EXISTS weekly_hours, DROP COLUMN IF EXISTS hourly_cost, DROP COLUMN IF EXISTS currency,
                DROP COLUMN IF EXISTS payroll_external_id, DROP COLUMN IF EXISTS source, DROP COLUMN IF EXISTS external_version,
                DROP COLUMN IF EXISTS synced_at, DROP COLUMN IF EXISTS lock_version, DROP COLUMN IF EXISTS created_by;
            DROP TABLE IF EXISTS hr_positions;
            DELETE FROM tenant_modules WHERE upper(module_code) = 'PERSONAL';
            UPDATE saas_plans SET modules = modules - 'PERSONAL' WHERE code IN ('PRO', 'ENTERPRISE');
            DELETE FROM module_catalog WHERE code = 'PERSONAL';
        SQL);
    }
};
