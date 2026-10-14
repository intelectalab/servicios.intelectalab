<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Contabilidad: plan de cuentas por empresa, periodos, libro diario (asientos y sus líneas),
 * centros de costo con tipo, sede y responsable, y presupuestos por centro de costo y cuenta.
 *
 * Usa las tablas que ya existían (`cost_centers`, `budgets`, `budget_lines`) y crea las suyas.
 *
 * Lo que la base garantiza por sí sola:
 *   - un asiento cuadra (debe = haber) y tiene al menos dos líneas, al cerrar la operación;
 *   - cada línea es debe o haber, nunca las dos, y usa una cuenta imputable y activa del plan;
 *   - las líneas se escriben junto con su asiento y después no cambian ni se borran;
 *   - un asiento no se modifica: solo pasa una vez a «Extornado», con su extorno y su motivo;
 *   - un periodo cerrado no admite asientos;
 *   - un documento genera un único asiento vigente;
 *   - las líneas de un presupuesto solo cambian en borrador;
 *   - nada de esto se borra.
 *
 * Solo añade: no borra datos. Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- 1. Plan de cuentas de la empresa (se siembra con el PCGE la primera vez que se usa).
            CREATE TABLE IF NOT EXISTS acc_accounts (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                code varchar(20) NOT NULL,
                name varchar(255) NOT NULL,
                account_type varchar(20) NOT NULL,
                nature char(1) NOT NULL,
                postable boolean NOT NULL DEFAULT true,
                parent_code varchar(20),
                active boolean NOT NULL DEFAULT true,
                is_system boolean NOT NULL DEFAULT false,
                created_by uuid REFERENCES users(id) ON DELETE SET NULL,
                lock_version integer NOT NULL DEFAULT 0,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT acc_accounts_code_key UNIQUE (tenant_id, code),
                CONSTRAINT acc_accounts_code_check CHECK (code ~ '^[0-9]{2,10}$'),
                CONSTRAINT acc_accounts_type_check CHECK (account_type IN ('ASSET', 'LIABILITY', 'EQUITY', 'INCOME', 'EXPENSE')),
                CONSTRAINT acc_accounts_nature_check CHECK (nature IN ('D', 'C')),
                CONSTRAINT acc_accounts_name_check CHECK (btrim(name) <> '')
            );
            DROP TRIGGER IF EXISTS trg_acc_accounts_updated_at ON acc_accounts;
            CREATE TRIGGER trg_acc_accounts_updated_at BEFORE UPDATE ON acc_accounts FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            ALTER TABLE acc_accounts ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON acc_accounts;
            CREATE POLICY tenant_isolation ON acc_accounts USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
            -- Una cuenta no se borra: se desactiva.
            REVOKE ALL ON acc_accounts FROM erp_app;
            GRANT SELECT, INSERT, UPDATE ON acc_accounts TO erp_app;

            -- El código y el tipo de una cuenta no cambian; una cuenta con movimientos no deja de ser imputable.
            CREATE OR REPLACE FUNCTION protect_acc_account() RETURNS trigger AS $fn$
            BEGIN
                IF NEW.code IS DISTINCT FROM OLD.code OR NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
                   OR NEW.account_type IS DISTINCT FROM OLD.account_type OR NEW.nature IS DISTINCT FROM OLD.nature THEN
                    RAISE EXCEPTION 'El código, el tipo y la naturaleza de una cuenta contable no se modifican' USING ERRCODE = 'check_violation';
                END IF;
                IF OLD.postable AND NOT NEW.postable
                   AND EXISTS (SELECT 1 FROM acc_journal_lines l WHERE l.tenant_id = OLD.tenant_id AND l.account_code = OLD.code) THEN
                    RAISE EXCEPTION 'Una cuenta con movimientos sigue siendo imputable' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $fn$ LANGUAGE plpgsql;

            -- 2. Periodos contables (AAAA-MM). Cerrado = sin asientos nuevos.
            CREATE TABLE IF NOT EXISTS acc_periods (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                period char(7) NOT NULL,
                status varchar(10) NOT NULL DEFAULT 'OPEN',
                closed_at timestamptz,
                closed_by uuid REFERENCES users(id) ON DELETE SET NULL,
                reason text,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT acc_periods_key UNIQUE (tenant_id, period),
                CONSTRAINT acc_periods_period_check CHECK (period ~ '^[0-9]{4}-(0[1-9]|1[0-2])$'),
                CONSTRAINT acc_periods_status_check CHECK (status IN ('OPEN', 'CLOSED')),
                CONSTRAINT acc_periods_closed_check CHECK (status = 'OPEN' OR closed_at IS NOT NULL)
            );
            DROP TRIGGER IF EXISTS trg_acc_periods_updated_at ON acc_periods;
            CREATE TRIGGER trg_acc_periods_updated_at BEFORE UPDATE ON acc_periods FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            ALTER TABLE acc_periods ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON acc_periods;
            CREATE POLICY tenant_isolation ON acc_periods USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
            REVOKE ALL ON acc_periods FROM erp_app;
            GRANT SELECT, INSERT, UPDATE ON acc_periods TO erp_app;

            -- 3. Asientos del libro diario.
            CREATE TABLE IF NOT EXISTS acc_journal_entries (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                entry_number varchar(30) NOT NULL,
                entry_date date NOT NULL,
                period char(7) NOT NULL,
                description text NOT NULL,
                source_type varchar(30) NOT NULL,
                source_id uuid,
                source_number varchar(100),
                status varchar(10) NOT NULL DEFAULT 'POSTED',
                reversal_of uuid REFERENCES acc_journal_entries(id) ON DELETE RESTRICT,
                reversed_by uuid REFERENCES acc_journal_entries(id) ON DELETE RESTRICT,
                reason text,
                created_by uuid REFERENCES users(id) ON DELETE SET NULL,
                -- Hora de la operación que lo escribió: sus líneas solo se escriben en esa misma operación.
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT acc_journal_entries_number_key UNIQUE (tenant_id, entry_number),
                CONSTRAINT acc_journal_entries_period_check CHECK (period = to_char(entry_date, 'YYYY-MM')),
                CONSTRAINT acc_journal_entries_status_check CHECK (status IN ('POSTED', 'REVERSED')),
                CONSTRAINT acc_journal_entries_source_check CHECK (source_type IN (
                    'MANUAL', 'REVERSAL', 'OPENING', 'INVOICE', 'PAYMENT', 'REFUND', 'WITHHOLDING', 'FX',
                    'SUPPLIER_INVOICE', 'SUPPLIER_PAYMENT', 'CASH_EXPENSE', 'BANK_FEE', 'UNIDENTIFIED', 'TRANSFER')),
                CONSTRAINT acc_journal_entries_description_check CHECK (btrim(description) <> ''),
                CONSTRAINT acc_journal_entries_reversal_check CHECK (source_type <> 'REVERSAL' OR reversal_of IS NOT NULL),
                CONSTRAINT acc_journal_entries_reversed_check CHECK (status = 'POSTED' OR (reversed_by IS NOT NULL AND btrim(coalesce(reason, '')) <> ''))
            );
            -- Un documento genera un único asiento vigente.
            CREATE UNIQUE INDEX IF NOT EXISTS ux_acc_journal_entries_source ON acc_journal_entries (tenant_id, source_type, source_id)
                WHERE status = 'POSTED' AND source_id IS NOT NULL AND source_type NOT IN ('MANUAL', 'REVERSAL');
            -- Un asiento se extorna una sola vez.
            CREATE UNIQUE INDEX IF NOT EXISTS ux_acc_journal_entries_reversal ON acc_journal_entries (reversal_of) WHERE reversal_of IS NOT NULL;
            CREATE INDEX IF NOT EXISTS idx_acc_journal_entries_period ON acc_journal_entries (tenant_id, period, entry_date);
            CREATE INDEX IF NOT EXISTS idx_acc_journal_entries_source ON acc_journal_entries (tenant_id, source_id) WHERE source_id IS NOT NULL;
            ALTER TABLE acc_journal_entries ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON acc_journal_entries;
            CREATE POLICY tenant_isolation ON acc_journal_entries USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
            REVOKE ALL ON acc_journal_entries FROM erp_app;
            GRANT SELECT, INSERT, UPDATE ON acc_journal_entries TO erp_app;

            -- Un periodo cerrado no admite asientos.
            CREATE OR REPLACE FUNCTION check_acc_entry_period() RETURNS trigger AS $fn$
            BEGIN
                IF EXISTS (SELECT 1 FROM acc_periods p WHERE p.tenant_id = NEW.tenant_id AND p.period = NEW.period AND p.status = 'CLOSED') THEN
                    RAISE EXCEPTION 'El periodo % está cerrado: no admite asientos', NEW.period USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $fn$ LANGUAGE plpgsql;
            DROP TRIGGER IF EXISTS trg_acc_journal_entries_period ON acc_journal_entries;
            CREATE TRIGGER trg_acc_journal_entries_period BEFORE INSERT ON acc_journal_entries
                FOR EACH ROW EXECUTE FUNCTION check_acc_entry_period();

            -- Un asiento contabilizado no se edita: solo se marca extornado, una vez.
            CREATE OR REPLACE FUNCTION protect_acc_journal_entry() RETURNS trigger AS $fn$
            BEGIN
                IF NEW.entry_number IS DISTINCT FROM OLD.entry_number OR NEW.entry_date IS DISTINCT FROM OLD.entry_date
                   OR NEW.period IS DISTINCT FROM OLD.period OR NEW.description IS DISTINCT FROM OLD.description
                   OR NEW.source_type IS DISTINCT FROM OLD.source_type OR NEW.source_id IS DISTINCT FROM OLD.source_id
                   OR NEW.source_number IS DISTINCT FROM OLD.source_number OR NEW.reversal_of IS DISTINCT FROM OLD.reversal_of
                   OR NEW.created_by IS DISTINCT FROM OLD.created_by OR NEW.created_at IS DISTINCT FROM OLD.created_at
                   OR NEW.tenant_id IS DISTINCT FROM OLD.tenant_id THEN
                    RAISE EXCEPTION 'Un asiento contabilizado no se edita: se extorna' USING ERRCODE = 'check_violation';
                END IF;
                IF OLD.status = 'REVERSED' OR (NEW.status = 'POSTED' AND (NEW.reversed_by IS DISTINCT FROM OLD.reversed_by OR NEW.reason IS DISTINCT FROM OLD.reason)) THEN
                    RAISE EXCEPTION 'Un asiento se extorna una sola vez' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $fn$ LANGUAGE plpgsql;
            DROP TRIGGER IF EXISTS trg_acc_journal_entries_protect ON acc_journal_entries;
            CREATE TRIGGER trg_acc_journal_entries_protect BEFORE UPDATE ON acc_journal_entries
                FOR EACH ROW EXECUTE FUNCTION protect_acc_journal_entry();

            -- 4. Centros de costo: tipo, sede y responsable (la tabla ya existía).
            ALTER TABLE cost_centers ADD COLUMN IF NOT EXISTS kind varchar(10) NOT NULL DEFAULT 'COST';
            ALTER TABLE cost_centers ADD COLUMN IF NOT EXISTS branch_id uuid REFERENCES branches(id) ON DELETE SET NULL;
            ALTER TABLE cost_centers ADD COLUMN IF NOT EXISTS manager_user_id uuid REFERENCES users(id) ON DELETE SET NULL;
            ALTER TABLE cost_centers ADD COLUMN IF NOT EXISTS created_by uuid REFERENCES users(id) ON DELETE SET NULL;
            ALTER TABLE cost_centers ADD COLUMN IF NOT EXISTS lock_version integer NOT NULL DEFAULT 0;
            ALTER TABLE cost_centers ADD COLUMN IF NOT EXISTS created_at timestamptz NOT NULL DEFAULT now();
            ALTER TABLE cost_centers ADD COLUMN IF NOT EXISTS updated_at timestamptz NOT NULL DEFAULT now();
            ALTER TABLE cost_centers DROP CONSTRAINT IF EXISTS cost_centers_kind_check;
            ALTER TABLE cost_centers ADD CONSTRAINT cost_centers_kind_check CHECK (kind IN ('COST', 'REVENUE'));
            DROP TRIGGER IF EXISTS trg_cost_centers_updated_at ON cost_centers;
            CREATE TRIGGER trg_cost_centers_updated_at BEFORE UPDATE ON cost_centers FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            -- El código de un centro de costo no cambia: lo usan documentos ya registrados.
            CREATE OR REPLACE FUNCTION protect_cost_center() RETURNS trigger AS $fn$
            BEGIN
                IF NEW.code IS DISTINCT FROM OLD.code OR NEW.tenant_id IS DISTINCT FROM OLD.tenant_id THEN
                    RAISE EXCEPTION 'El código de un centro de costo no se modifica' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $fn$ LANGUAGE plpgsql;
            DROP TRIGGER IF EXISTS trg_cost_centers_protect ON cost_centers;
            CREATE TRIGGER trg_cost_centers_protect BEFORE UPDATE ON cost_centers FOR EACH ROW EXECUTE FUNCTION protect_cost_center();

            -- 5. Líneas del asiento: debe o haber, con su cuenta imputable, centro de costo y tercero.
            CREATE TABLE IF NOT EXISTS acc_journal_lines (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                entry_id uuid NOT NULL REFERENCES acc_journal_entries(id) ON DELETE CASCADE,
                position integer NOT NULL DEFAULT 1,
                account_code varchar(20) NOT NULL,
                debit numeric(18,2) NOT NULL DEFAULT 0,
                credit numeric(18,2) NOT NULL DEFAULT 0,
                cost_center_id uuid REFERENCES cost_centers(id) ON DELETE RESTRICT,
                party_id uuid REFERENCES parties(id) ON DELETE SET NULL,
                note text,
                CONSTRAINT acc_journal_lines_account_fkey FOREIGN KEY (tenant_id, account_code) REFERENCES acc_accounts(tenant_id, code) ON DELETE RESTRICT,
                CONSTRAINT acc_journal_lines_amount_check CHECK (debit >= 0 AND credit >= 0 AND (debit > 0) <> (credit > 0))
            );
            CREATE INDEX IF NOT EXISTS idx_acc_journal_lines_entry ON acc_journal_lines (entry_id, position);
            CREATE INDEX IF NOT EXISTS idx_acc_journal_lines_account ON acc_journal_lines (tenant_id, account_code);
            CREATE INDEX IF NOT EXISTS idx_acc_journal_lines_cost_center ON acc_journal_lines (tenant_id, cost_center_id) WHERE cost_center_id IS NOT NULL;
            ALTER TABLE acc_journal_lines ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON acc_journal_lines;
            CREATE POLICY tenant_isolation ON acc_journal_lines USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
            -- Una línea escrita ni se edita ni se borra.
            REVOKE ALL ON acc_journal_lines FROM erp_app;
            GRANT SELECT, INSERT ON acc_journal_lines TO erp_app;

            -- La línea usa una cuenta imputable y activa, y se escribe en la misma operación que su asiento.
            CREATE OR REPLACE FUNCTION check_acc_journal_line() RETURNS trigger AS $fn$
            DECLARE
                entry_created timestamptz;
                account_ok boolean;
            BEGIN
                SELECT created_at INTO entry_created FROM acc_journal_entries WHERE id = NEW.entry_id;
                IF NOT FOUND OR entry_created <> transaction_timestamp() THEN
                    RAISE EXCEPTION 'Las líneas de un asiento se registran junto con el asiento' USING ERRCODE = 'check_violation';
                END IF;
                SELECT postable AND active INTO account_ok FROM acc_accounts WHERE tenant_id = NEW.tenant_id AND code = NEW.account_code;
                IF NOT coalesce(account_ok, false) THEN
                    RAISE EXCEPTION 'La cuenta % no es imputable o está inactiva', NEW.account_code USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $fn$ LANGUAGE plpgsql;
            DROP TRIGGER IF EXISTS trg_acc_journal_lines_check ON acc_journal_lines;
            CREATE TRIGGER trg_acc_journal_lines_check BEFORE INSERT ON acc_journal_lines
                FOR EACH ROW EXECUTE FUNCTION check_acc_journal_line();

            -- Al cerrar la operación, cada asiento escrito cuadra y tiene al menos dos líneas.
            CREATE OR REPLACE FUNCTION check_acc_entry_balanced() RETURNS trigger AS $fn$
            DECLARE
                target uuid;
                lines integer;
                difference numeric;
            BEGIN
                -- Cada rama lee solo el campo de su tabla.
                IF TG_TABLE_NAME = 'acc_journal_entries' THEN
                    target := NEW.id;
                ELSE
                    target := NEW.entry_id;
                END IF;
                SELECT count(*), coalesce(sum(debit), 0) - coalesce(sum(credit), 0) INTO lines, difference
                FROM acc_journal_lines WHERE entry_id = target;
                IF lines < 2 THEN
                    RAISE EXCEPTION 'Un asiento necesita al menos dos líneas' USING ERRCODE = 'check_violation';
                END IF;
                IF difference <> 0 THEN
                    RAISE EXCEPTION 'El asiento no cuadra: el debe debe ser igual al haber' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NULL;
            END;
            $fn$ LANGUAGE plpgsql;
            DROP TRIGGER IF EXISTS trg_acc_journal_entries_balanced ON acc_journal_entries;
            CREATE CONSTRAINT TRIGGER trg_acc_journal_entries_balanced AFTER INSERT ON acc_journal_entries
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_acc_entry_balanced();
            DROP TRIGGER IF EXISTS trg_acc_journal_lines_balanced ON acc_journal_lines;
            CREATE CONSTRAINT TRIGGER trg_acc_journal_lines_balanced AFTER INSERT ON acc_journal_lines
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_acc_entry_balanced();

            DROP TRIGGER IF EXISTS trg_acc_accounts_protect ON acc_accounts;
            CREATE TRIGGER trg_acc_accounts_protect BEFORE UPDATE ON acc_accounts FOR EACH ROW EXECUTE FUNCTION protect_acc_account();

            -- 6. Presupuestos: uno por año, por centro de costo, cuenta y mes (las tablas ya existían).
            ALTER TABLE budgets ADD COLUMN IF NOT EXISTS approved_by uuid REFERENCES users(id) ON DELETE SET NULL;
            ALTER TABLE budgets ADD COLUMN IF NOT EXISTS approved_at timestamptz;
            ALTER TABLE budgets ADD COLUMN IF NOT EXISTS reason text;
            ALTER TABLE budgets ADD COLUMN IF NOT EXISTS created_by uuid REFERENCES users(id) ON DELETE SET NULL;
            ALTER TABLE budgets ADD COLUMN IF NOT EXISTS lock_version integer NOT NULL DEFAULT 0;
            ALTER TABLE budgets DROP CONSTRAINT IF EXISTS budgets_status_check;
            ALTER TABLE budgets ADD CONSTRAINT budgets_status_check CHECK (status IN ('DRAFT', 'APPROVED', 'CLOSED'));
            ALTER TABLE budgets DROP CONSTRAINT IF EXISTS budgets_approved_check;
            ALTER TABLE budgets ADD CONSTRAINT budgets_approved_check CHECK (status = 'DRAFT' OR approved_at IS NOT NULL);
            CREATE UNIQUE INDEX IF NOT EXISTS ux_budgets_year ON budgets (tenant_id, fiscal_year);
            REVOKE DELETE ON budgets FROM erp_app;

            ALTER TABLE budget_lines ADD COLUMN IF NOT EXISTS cost_center_id uuid REFERENCES cost_centers(id) ON DELETE RESTRICT;
            ALTER TABLE budget_lines ADD COLUMN IF NOT EXISTS account_code varchar(20);
            ALTER TABLE budget_lines ALTER COLUMN category DROP NOT NULL;
            ALTER TABLE budget_lines DROP CONSTRAINT IF EXISTS budget_lines_amount_check;
            ALTER TABLE budget_lines ADD CONSTRAINT budget_lines_amount_check CHECK (amount >= 0);
            CREATE UNIQUE INDEX IF NOT EXISTS ux_budget_lines_center_account ON budget_lines (budget_id, cost_center_id, account_code, period_month)
                WHERE cost_center_id IS NOT NULL;
            REVOKE DELETE ON budget_lines FROM erp_app;

            -- Un presupuesto aprobado o cerrado no cambia sus líneas: se reabre con motivo.
            CREATE OR REPLACE FUNCTION protect_budget_lines() RETURNS trigger AS $fn$
            DECLARE
                parent_status text;
            BEGIN
                SELECT status INTO parent_status FROM budgets WHERE id = NEW.budget_id;
                IF FOUND AND parent_status <> 'DRAFT' THEN
                    RAISE EXCEPTION 'Las líneas de un presupuesto solo se modifican en borrador' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $fn$ LANGUAGE plpgsql;
            DROP TRIGGER IF EXISTS trg_budget_lines_protect ON budget_lines;
            CREATE TRIGGER trg_budget_lines_protect BEFORE INSERT OR UPDATE ON budget_lines
                FOR EACH ROW EXECUTE FUNCTION protect_budget_lines();

            -- 7. Permisos por recurso.
            INSERT INTO permissions (code, name, description) VALUES
                ('accounting.view', 'Ver contabilidad', 'Libro diario, mayor y balance de comprobación, estados financieros, plan de cuentas y periodos.'),
                ('accounting.create', 'Registrar asientos manuales', 'Contabilizar un asiento manual cuadrado en un periodo abierto.'),
                ('accounting.manage', 'Gestionar el plan de cuentas', 'Crear cuentas contables, cambiar su nombre y activarlas o desactivarlas.'),
                ('accounting.approve', 'Extornar asientos y cerrar periodos', 'Extornar un asiento con motivo, cerrar y reabrir periodos contables.'),
                ('accounting.export', 'Exportar contabilidad', 'Descargar el libro diario, el mayor, el balance de comprobación y el plan de cuentas.'),
                ('budgets.view', 'Ver centros de costo y presupuestos', 'Presupuesto por centro de costo frente a lo ejecutado y lo comprometido.'),
                ('budgets.approve', 'Aprobar presupuestos', 'Aprobar el presupuesto del año.'),
                ('budgets.export', 'Exportar presupuestos', 'Descargar la ejecución por centro de costo y su detalle.')
            ON CONFLICT (code) DO NOTHING;
            UPDATE permissions SET name = 'Gestionar centros de costo y presupuestos',
                description = 'Crear y editar centros de costo, preparar el presupuesto y reabrirlo para ajustarlo.'
            WHERE code = 'budgets.manage';

            UPDATE module_catalog SET permission_prefixes = permission_prefixes || '["accounting"]'::jsonb
            WHERE upper(code) = 'FINANZAS' AND NOT permission_prefixes ? 'accounting';

            -- 8. Alcance por rol: los presupuestos se ven por centro de costo (su responsable o su sede).
            ALTER TABLE role_scopes DROP CONSTRAINT IF EXISTS role_scopes_resource_check;
            ALTER TABLE role_scopes ADD CONSTRAINT role_scopes_resource_check
                CHECK (resource IN ('quotations', 'contracts', 'orders', 'portal', 'leads', 'opportunities', 'activities',
                                    'contracted_services', 'work_orders', 'projects', 'time_entries', 'cost_entries', 'agenda',
                                    'billing', 'receivables', 'payments', 'recurring', 'purchases', 'payables', 'budgets'));

            -- 9. Reparto (matriz del prototipo). El Administrador de cada empresa: todo.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r
            JOIN permissions p ON p.code LIKE 'accounting.%' OR p.code LIKE 'budgets.%'
            WHERE r.tenant_id IS NOT NULL AND (r.is_system OR upper(r.code) = 'ADMIN')
            ON CONFLICT DO NOTHING;

            -- Gerencia: ve y exporta la contabilidad; ve, aprueba y exporta presupuestos.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r
            JOIN permissions p ON p.code IN ('accounting.view', 'accounting.export', 'budgets.view', 'budgets.approve', 'budgets.export')
            WHERE upper(r.code) = 'GERENCIA' AND r.tenant_id IS NOT NULL
            ON CONFLICT DO NOTHING;

            -- Operaciones: ve los presupuestos de los centros de costo de su sede.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r
            JOIN permissions p ON p.code = 'budgets.view'
            WHERE upper(r.code) = 'OPERACIONES' AND r.tenant_id IS NOT NULL
            ON CONFLICT DO NOTHING;
            INSERT INTO role_scopes (role_id, tenant_id, resource, scope)
            SELECT r.id, r.tenant_id, 'budgets', 'BRANCH' FROM roles r
            WHERE upper(r.code) = 'OPERACIONES' AND r.tenant_id IS NOT NULL
            ON CONFLICT DO NOTHING;

            -- Finanzas: toda la contabilidad; ve y prepara presupuestos (no los aprueba ni exporta).
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r
            JOIN permissions p ON p.code LIKE 'accounting.%' OR p.code IN ('budgets.view', 'budgets.manage')
            WHERE upper(r.code) = 'FINANZAS' AND r.tenant_id IS NOT NULL
            ON CONFLICT DO NOTHING;

            INSERT INTO role_scopes (role_id, tenant_id, resource, scope)
            SELECT r.id, r.tenant_id, 'budgets', 'TENANT' FROM roles r
            WHERE upper(r.code) IN ('GERENCIA', 'FINANZAS') AND r.tenant_id IS NOT NULL
            ON CONFLICT DO NOTHING;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM role_scopes WHERE resource = 'budgets';
            ALTER TABLE role_scopes DROP CONSTRAINT IF EXISTS role_scopes_resource_check;
            ALTER TABLE role_scopes ADD CONSTRAINT role_scopes_resource_check
                CHECK (resource IN ('quotations', 'contracts', 'orders', 'portal', 'leads', 'opportunities', 'activities',
                                    'contracted_services', 'work_orders', 'projects', 'time_entries', 'cost_entries', 'agenda',
                                    'billing', 'receivables', 'payments', 'recurring', 'purchases', 'payables'));
            UPDATE module_catalog SET permission_prefixes = permission_prefixes - 'accounting' WHERE upper(code) = 'FINANZAS';
            DELETE FROM role_permissions WHERE permission_id IN (
                SELECT id FROM permissions WHERE code LIKE 'accounting.%' OR code IN ('budgets.view', 'budgets.approve', 'budgets.export'));
            DELETE FROM permissions WHERE code LIKE 'accounting.%' OR code IN ('budgets.view', 'budgets.approve', 'budgets.export');
            UPDATE permissions SET name = 'Gestionar centros de costo', description = 'Crear centros de costo.' WHERE code = 'budgets.manage';

            DROP TRIGGER IF EXISTS trg_budget_lines_protect ON budget_lines;
            DROP FUNCTION IF EXISTS protect_budget_lines();
            DROP INDEX IF EXISTS ux_budget_lines_center_account;
            ALTER TABLE budget_lines DROP CONSTRAINT IF EXISTS budget_lines_amount_check;
            ALTER TABLE budget_lines DROP COLUMN IF EXISTS account_code;
            ALTER TABLE budget_lines DROP COLUMN IF EXISTS cost_center_id;
            DROP INDEX IF EXISTS ux_budgets_year;
            ALTER TABLE budgets DROP CONSTRAINT IF EXISTS budgets_approved_check;
            ALTER TABLE budgets DROP CONSTRAINT IF EXISTS budgets_status_check;
            ALTER TABLE budgets DROP COLUMN IF EXISTS lock_version;
            ALTER TABLE budgets DROP COLUMN IF EXISTS created_by;
            ALTER TABLE budgets DROP COLUMN IF EXISTS reason;
            ALTER TABLE budgets DROP COLUMN IF EXISTS approved_at;
            ALTER TABLE budgets DROP COLUMN IF EXISTS approved_by;
            GRANT DELETE ON budgets, budget_lines TO erp_app;

            DROP TABLE IF EXISTS acc_journal_lines;
            DROP FUNCTION IF EXISTS check_acc_journal_line();
            DROP TABLE IF EXISTS acc_journal_entries;
            DROP FUNCTION IF EXISTS check_acc_entry_balanced();
            DROP FUNCTION IF EXISTS check_acc_entry_period();
            DROP FUNCTION IF EXISTS protect_acc_journal_entry();
            DROP TABLE IF EXISTS acc_periods;
            DROP TABLE IF EXISTS acc_accounts;
            DROP FUNCTION IF EXISTS protect_acc_account();

            DROP TRIGGER IF EXISTS trg_cost_centers_protect ON cost_centers;
            DROP FUNCTION IF EXISTS protect_cost_center();
            DROP TRIGGER IF EXISTS trg_cost_centers_updated_at ON cost_centers;
            ALTER TABLE cost_centers DROP CONSTRAINT IF EXISTS cost_centers_kind_check;
            ALTER TABLE cost_centers DROP COLUMN IF EXISTS updated_at;
            ALTER TABLE cost_centers DROP COLUMN IF EXISTS created_at;
            ALTER TABLE cost_centers DROP COLUMN IF EXISTS lock_version;
            ALTER TABLE cost_centers DROP COLUMN IF EXISTS created_by;
            ALTER TABLE cost_centers DROP COLUMN IF EXISTS manager_user_id;
            ALTER TABLE cost_centers DROP COLUMN IF EXISTS branch_id;
            ALTER TABLE cost_centers DROP COLUMN IF EXISTS kind;
        SQL);
    }
};
