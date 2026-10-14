<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Finanzas: bancos y caja.
 *
 * La tabla `financial_accounts` ya existía (vacía, enlazada a los cobros); se conserva y se le
 * añade lo que una cuenta de banco, una caja o la cuenta de detracciones necesitan. Nacen
 * `bank_movements` (de dónde sale el saldo), `bank_statements` y `bank_statement_lines`
 * (extractos importados y su conciliación), `cash_expenses` y `cash_counts` (caja chica).
 *
 * Lo que la base garantiza por sí sola:
 *   - el saldo de una cuenta es la suma de sus movimientos: no hay columna de saldo que editar;
 *   - un movimiento no cambia de cuenta, fecha ni importe, ni se borra: solo se concilia (una vez);
 *   - un cobro deja un solo movimiento; una línea de extracto concilia un solo movimiento;
 *   - la misma línea de un extracto no entra dos veces en una cuenta;
 *   - la moneda y el tipo de una cuenta no cambian; gastos de caja y arqueos no se editan ni se borran.
 *
 * Solo añade: no borra datos. Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- 1. Cuentas: banco, número, CCI, cuenta contable, fondo fijo y custodio de la caja, tope de gasto y versión.
            ALTER TABLE financial_accounts
                ADD COLUMN IF NOT EXISTS bank_name varchar(150),
                ADD COLUMN IF NOT EXISTS account_number varchar(60),
                ADD COLUMN IF NOT EXISTS cci varchar(40),
                ADD COLUMN IF NOT EXISTS ledger_account varchar(30),
                ADD COLUMN IF NOT EXISTS fund_amount numeric(18,4),
                ADD COLUMN IF NOT EXISTS expense_limit numeric(18,4),
                ADD COLUMN IF NOT EXISTS custodian_user_id uuid REFERENCES users(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS created_by uuid REFERENCES users(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS created_at timestamptz NOT NULL DEFAULT now(),
                ADD COLUMN IF NOT EXISTS updated_at timestamptz NOT NULL DEFAULT now(),
                ADD COLUMN IF NOT EXISTS lock_version integer NOT NULL DEFAULT 0;
            ALTER TABLE financial_accounts DROP CONSTRAINT IF EXISTS financial_accounts_account_type_check;
            ALTER TABLE financial_accounts ADD CONSTRAINT financial_accounts_account_type_check
                CHECK (account_type IN ('CASH', 'BANK', 'DETRACTION', 'GATEWAY', 'OTHER'));
            ALTER TABLE financial_accounts DROP CONSTRAINT IF EXISTS financial_accounts_currency_check;
            ALTER TABLE financial_accounts ADD CONSTRAINT financial_accounts_currency_check CHECK (currency IN ('PEN', 'USD'));
            ALTER TABLE financial_accounts DROP CONSTRAINT IF EXISTS financial_accounts_status_check;
            ALTER TABLE financial_accounts ADD CONSTRAINT financial_accounts_status_check CHECK (status IN ('ACTIVE', 'INACTIVE'));
            -- La cuenta de detracciones del Banco de la Nación es en soles; el fondo fijo es solo de una caja.
            ALTER TABLE financial_accounts DROP CONSTRAINT IF EXISTS financial_accounts_kind_rules_check;
            ALTER TABLE financial_accounts ADD CONSTRAINT financial_accounts_kind_rules_check CHECK (
                (account_type <> 'DETRACTION' OR currency = 'PEN')
                AND (fund_amount IS NULL OR (account_type = 'CASH' AND fund_amount >= 0))
                AND (expense_limit IS NULL OR (account_type = 'CASH' AND expense_limit > 0)));
            DROP TRIGGER IF EXISTS trg_financial_accounts_updated_at ON financial_accounts;
            CREATE TRIGGER trg_financial_accounts_updated_at BEFORE UPDATE ON financial_accounts FOR EACH ROW EXECUTE FUNCTION set_updated_at();

            -- La moneda y el tipo de una cuenta no cambian: sus movimientos quedarían en otra moneda.
            CREATE OR REPLACE FUNCTION protect_financial_account() RETURNS trigger AS $fn$
            BEGIN
                IF NEW.currency IS DISTINCT FROM OLD.currency OR NEW.account_type IS DISTINCT FROM OLD.account_type
                   OR NEW.tenant_id IS DISTINCT FROM OLD.tenant_id THEN
                    RAISE EXCEPTION 'La moneda y el tipo de una cuenta no se modifican' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $fn$ LANGUAGE plpgsql;
            DROP TRIGGER IF EXISTS trg_financial_accounts_protect ON financial_accounts;
            CREATE TRIGGER trg_financial_accounts_protect BEFORE UPDATE ON financial_accounts
                FOR EACH ROW EXECUTE FUNCTION protect_financial_account();
            -- Una cuenta no se borra: se desactiva.
            REVOKE DELETE ON financial_accounts FROM erp_app;

            -- 2. Movimientos en libros: + ingreso / − egreso. De ellos sale el saldo.
            CREATE TABLE IF NOT EXISTS bank_movements (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                account_id uuid NOT NULL REFERENCES financial_accounts(id) ON DELETE RESTRICT,
                movement_date date NOT NULL,
                description text NOT NULL,
                amount numeric(18,4) NOT NULL,
                ref_type varchar(30) NOT NULL,
                ref_id uuid,
                op_number varchar(255),
                reconciled boolean NOT NULL DEFAULT false,
                reconciled_at timestamptz,
                statement_line_id uuid,
                created_by uuid REFERENCES users(id) ON DELETE SET NULL,
                -- La hora real de cada movimiento, no la del inicio de la transacción: así conservan su orden.
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT bank_movements_amount_check CHECK (amount <> 0),
                CONSTRAINT bank_movements_ref_type_check CHECK (ref_type IN (
                    'OPENING', 'PAYMENT', 'REFUND', 'TRANSFER', 'CASH_EXPENSE', 'BANK_FEE', 'DETRACTION', 'UNIDENTIFIED', 'SUPPLIER_PAYMENT', 'MANUAL'))
            );
            CREATE INDEX IF NOT EXISTS idx_bank_movements_account ON bank_movements (account_id, movement_date, created_at);
            CREATE INDEX IF NOT EXISTS idx_bank_movements_pending ON bank_movements (tenant_id, account_id) WHERE NOT reconciled;
            -- Un cobro deja un solo movimiento; una línea de extracto concilia un solo movimiento.
            CREATE UNIQUE INDEX IF NOT EXISTS ux_bank_movements_payment ON bank_movements (ref_id) WHERE ref_type = 'PAYMENT';
            CREATE UNIQUE INDEX IF NOT EXISTS ux_bank_movements_statement_line ON bank_movements (statement_line_id) WHERE statement_line_id IS NOT NULL;
            ALTER TABLE bank_movements ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON bank_movements;
            CREATE POLICY tenant_isolation ON bank_movements
                USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());

            -- Un movimiento no cambia de cuenta, fecha ni importe: solo se concilia, y una sola vez.
            CREATE OR REPLACE FUNCTION protect_bank_movement() RETURNS trigger AS $fn$
            BEGIN
                IF NEW.account_id IS DISTINCT FROM OLD.account_id OR NEW.amount IS DISTINCT FROM OLD.amount
                   OR NEW.movement_date IS DISTINCT FROM OLD.movement_date OR NEW.ref_type IS DISTINCT FROM OLD.ref_type
                   OR NEW.ref_id IS DISTINCT FROM OLD.ref_id OR NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
                   OR NEW.description IS DISTINCT FROM OLD.description OR NEW.op_number IS DISTINCT FROM OLD.op_number THEN
                    RAISE EXCEPTION 'Un movimiento de banco o caja no se modifica' USING ERRCODE = 'check_violation';
                END IF;
                IF OLD.reconciled AND (NOT NEW.reconciled OR NEW.statement_line_id IS DISTINCT FROM OLD.statement_line_id) THEN
                    RAISE EXCEPTION 'Un movimiento conciliado no se vuelve a conciliar ni se desconcilia' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $fn$ LANGUAGE plpgsql;
            DROP TRIGGER IF EXISTS trg_bank_movements_protect ON bank_movements;
            CREATE TRIGGER trg_bank_movements_protect BEFORE UPDATE ON bank_movements
                FOR EACH ROW EXECUTE FUNCTION protect_bank_movement();
            REVOKE ALL ON bank_movements FROM erp_app;
            GRANT SELECT, INSERT, UPDATE ON bank_movements TO erp_app;

            -- 3. Extractos importados y sus líneas.
            CREATE TABLE IF NOT EXISTS bank_statements (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                account_id uuid NOT NULL REFERENCES financial_accounts(id) ON DELETE RESTRICT,
                file_name varchar(255) NOT NULL,
                file_sha256 char(64) NOT NULL,
                lines_count integer NOT NULL DEFAULT 0,
                duplicates_count integer NOT NULL DEFAULT 0,
                imported_by uuid REFERENCES users(id) ON DELETE SET NULL,
                created_at timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX IF NOT EXISTS idx_bank_statements_account ON bank_statements (account_id, created_at);
            ALTER TABLE bank_statements ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON bank_statements;
            CREATE POLICY tenant_isolation ON bank_statements
                USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
            REVOKE ALL ON bank_statements FROM erp_app;
            GRANT SELECT, INSERT ON bank_statements TO erp_app;

            CREATE TABLE IF NOT EXISTS bank_statement_lines (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                statement_id uuid NOT NULL REFERENCES bank_statements(id) ON DELETE CASCADE,
                account_id uuid NOT NULL REFERENCES financial_accounts(id) ON DELETE RESTRICT,
                position integer NOT NULL,
                line_date date NOT NULL,
                description text NOT NULL DEFAULT '',
                amount numeric(18,4) NOT NULL,
                op_number varchar(255),
                status varchar(30) NOT NULL DEFAULT 'UNMATCHED',
                movement_id uuid REFERENCES bank_movements(id) ON DELETE RESTRICT,
                rule varchar(100),
                fingerprint varchar(255) NOT NULL,
                resolved_by uuid REFERENCES users(id) ON DELETE SET NULL,
                resolved_at timestamptz,
                CONSTRAINT bank_statement_lines_amount_check CHECK (amount <> 0),
                CONSTRAINT bank_statement_lines_status_check CHECK (status IN ('UNMATCHED', 'MATCHED', 'CREATED', 'IGNORED')),
                CONSTRAINT bank_statement_lines_resolved_check CHECK ((status IN ('MATCHED', 'CREATED')) = (movement_id IS NOT NULL))
            );
            -- La misma línea (fecha, importe, operación y su orden entre iguales) no entra dos veces en una cuenta.
            CREATE UNIQUE INDEX IF NOT EXISTS ux_bank_statement_lines_fingerprint ON bank_statement_lines (account_id, fingerprint);
            CREATE UNIQUE INDEX IF NOT EXISTS ux_bank_statement_lines_movement ON bank_statement_lines (movement_id) WHERE movement_id IS NOT NULL;
            CREATE INDEX IF NOT EXISTS idx_bank_statement_lines_statement ON bank_statement_lines (statement_id, position);
            ALTER TABLE bank_statement_lines ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON bank_statement_lines;
            CREATE POLICY tenant_isolation ON bank_statement_lines
                USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());

            -- Lo que dijo el banco no se toca; una línea se resuelve una sola vez.
            CREATE OR REPLACE FUNCTION protect_bank_statement_line() RETURNS trigger AS $fn$
            BEGIN
                IF NEW.amount IS DISTINCT FROM OLD.amount OR NEW.line_date IS DISTINCT FROM OLD.line_date
                   OR NEW.description IS DISTINCT FROM OLD.description OR NEW.op_number IS DISTINCT FROM OLD.op_number
                   OR NEW.statement_id IS DISTINCT FROM OLD.statement_id OR NEW.account_id IS DISTINCT FROM OLD.account_id
                   OR NEW.fingerprint IS DISTINCT FROM OLD.fingerprint THEN
                    RAISE EXCEPTION 'Una línea de extracto no se modifica' USING ERRCODE = 'check_violation';
                END IF;
                IF OLD.status <> 'UNMATCHED' AND (NEW.status IS DISTINCT FROM OLD.status OR NEW.movement_id IS DISTINCT FROM OLD.movement_id) THEN
                    RAISE EXCEPTION 'Una línea de extracto ya resuelta no se vuelve a resolver' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $fn$ LANGUAGE plpgsql;
            DROP TRIGGER IF EXISTS trg_bank_statement_lines_protect ON bank_statement_lines;
            CREATE TRIGGER trg_bank_statement_lines_protect BEFORE UPDATE ON bank_statement_lines
                FOR EACH ROW EXECUTE FUNCTION protect_bank_statement_line();
            REVOKE ALL ON bank_statement_lines FROM erp_app;
            GRANT SELECT, INSERT, UPDATE ON bank_statement_lines TO erp_app;

            -- 4. Caja chica: gastos y arqueos. Quedan como se registraron.
            CREATE TABLE IF NOT EXISTS cash_expenses (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                account_id uuid NOT NULL REFERENCES financial_accounts(id) ON DELETE RESTRICT,
                expense_number varchar(50) NOT NULL,
                expense_date date NOT NULL,
                description text NOT NULL,
                amount numeric(18,4) NOT NULL,
                receipt varchar(150),
                cost_center varchar(100),
                expense_account varchar(100),
                created_by uuid REFERENCES users(id) ON DELETE SET NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT cash_expenses_amount_check CHECK (amount > 0),
                CONSTRAINT cash_expenses_number_key UNIQUE (tenant_id, expense_number)
            );
            CREATE INDEX IF NOT EXISTS idx_cash_expenses_account ON cash_expenses (account_id, expense_date);
            ALTER TABLE cash_expenses ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON cash_expenses;
            CREATE POLICY tenant_isolation ON cash_expenses
                USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
            REVOKE ALL ON cash_expenses FROM erp_app;
            GRANT SELECT, INSERT ON cash_expenses TO erp_app;

            CREATE TABLE IF NOT EXISTS cash_counts (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                account_id uuid NOT NULL REFERENCES financial_accounts(id) ON DELETE RESTRICT,
                book_balance numeric(18,4) NOT NULL,
                counted numeric(18,4) NOT NULL,
                difference numeric(18,4) NOT NULL,
                note text,
                created_by uuid REFERENCES users(id) ON DELETE SET NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT cash_counts_counted_check CHECK (counted >= 0),
                CONSTRAINT cash_counts_difference_check CHECK (difference = counted - book_balance)
            );
            CREATE INDEX IF NOT EXISTS idx_cash_counts_account ON cash_counts (account_id, created_at);
            ALTER TABLE cash_counts ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON cash_counts;
            CREATE POLICY tenant_isolation ON cash_counts
                USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
            REVOKE ALL ON cash_counts FROM erp_app;
            GRANT SELECT, INSERT ON cash_counts TO erp_app;

            -- 5. Permisos.
            INSERT INTO permissions (code, name, description) VALUES
                ('treasury.view', 'Ver bancos y caja', 'Cuentas, saldos, movimientos, extractos y caja chica.'),
                ('treasury.create', 'Operar bancos y caja', 'Transferir entre cuentas, importar extractos, registrar gastos de caja chica, reponer el fondo y hacer arqueos.'),
                ('treasury.manage', 'Gestionar cuentas y conciliar', 'Crear y editar cuentas, y conciliar a mano las líneas de un extracto.'),
                ('treasury.export', 'Exportar bancos y caja', 'Descargar movimientos y gastos de caja chica en Excel o CSV.')
            ON CONFLICT (code) DO NOTHING;

            UPDATE module_catalog
            SET permission_prefixes = permission_prefixes || '["treasury"]'::jsonb
            WHERE upper(code) = 'FINANZAS' AND NOT permission_prefixes @> '["treasury"]'::jsonb;

            -- 6. Reparto (matriz del prototipo). El Administrador de cada empresa: todo.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r JOIN permissions p ON p.code LIKE 'treasury.%'
            WHERE r.tenant_id IS NOT NULL AND (r.is_system OR upper(r.code) = 'ADMIN')
            ON CONFLICT DO NOTHING;

            -- Gerencia ve y exporta.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN ('treasury.view', 'treasury.export')
            WHERE upper(r.code) = 'GERENCIA' AND r.tenant_id IS NOT NULL
            ON CONFLICT DO NOTHING;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            UPDATE module_catalog SET permission_prefixes = permission_prefixes - 'treasury' WHERE upper(code) = 'FINANZAS';
            DELETE FROM role_permissions WHERE permission_id IN (SELECT id FROM permissions WHERE code LIKE 'treasury.%');
            DELETE FROM permissions WHERE code LIKE 'treasury.%';

            DROP TABLE IF EXISTS cash_counts;
            DROP TABLE IF EXISTS cash_expenses;
            DROP TABLE IF EXISTS bank_statement_lines;
            DROP FUNCTION IF EXISTS protect_bank_statement_line();
            DROP TABLE IF EXISTS bank_statements;
            DROP TABLE IF EXISTS bank_movements;
            DROP FUNCTION IF EXISTS protect_bank_movement();

            GRANT DELETE ON financial_accounts TO erp_app;
            DROP TRIGGER IF EXISTS trg_financial_accounts_protect ON financial_accounts;
            DROP FUNCTION IF EXISTS protect_financial_account();
            DROP TRIGGER IF EXISTS trg_financial_accounts_updated_at ON financial_accounts;
            ALTER TABLE financial_accounts DROP CONSTRAINT IF EXISTS financial_accounts_kind_rules_check;
            ALTER TABLE financial_accounts DROP CONSTRAINT IF EXISTS financial_accounts_currency_check;
            ALTER TABLE financial_accounts DROP CONSTRAINT IF EXISTS financial_accounts_status_check;
            ALTER TABLE financial_accounts DROP CONSTRAINT IF EXISTS financial_accounts_account_type_check;
            ALTER TABLE financial_accounts ADD CONSTRAINT financial_accounts_account_type_check
                CHECK (account_type IN ('CASH', 'BANK', 'GATEWAY', 'OTHER'));
            ALTER TABLE financial_accounts
                DROP COLUMN IF EXISTS bank_name, DROP COLUMN IF EXISTS account_number, DROP COLUMN IF EXISTS cci, DROP COLUMN IF EXISTS ledger_account,
                DROP COLUMN IF EXISTS fund_amount, DROP COLUMN IF EXISTS expense_limit, DROP COLUMN IF EXISTS custodian_user_id,
                DROP COLUMN IF EXISTS created_by, DROP COLUMN IF EXISTS created_at, DROP COLUMN IF EXISTS updated_at, DROP COLUMN IF EXISTS lock_version;
        SQL);
    }
};
