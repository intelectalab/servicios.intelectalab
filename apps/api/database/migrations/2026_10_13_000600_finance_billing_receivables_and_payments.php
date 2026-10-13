<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Finanzas y CxC, primera parte: eventos facturables, comprobantes, cuentas por cobrar y cobros.
 *
 * Las tablas `invoices`, `invoice_items`, `receivables`, `payments`, `payment_allocations` y
 * `refunds` ya existían; se conservan y solo se les añade lo que faltaba. Nacen `billable_events`
 * (lo que Operaciones deja listo para facturar) y `receivable_movements` (de dónde sale el saldo).
 *
 * Lo que la base garantiza por sí sola:
 *   - un origen (orden de trabajo, ciclo, horas…) da un solo evento facturable;
 *   - un comprobante emitido no se modifica, ni sus líneas: se corrige con otro documento;
 *   - serie y correlativo no se repiten;
 *   - el saldo de una cuenta por cobrar es la suma de sus movimientos: nadie lo escribe a mano,
 *     no baja de cero ni supera el importe original, y los movimientos no se editan ni se borran;
 *   - anular un evento y reembolsar un cobro llevan su motivo; no se reembolsa más de lo cobrado.
 *
 * Solo añade: no borra datos. Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- 1. Comprobantes: serie y correlativo, datos del cliente al emitir, estado ante SUNAT, responsable, sede y versión.
            ALTER TABLE invoices
                ADD COLUMN IF NOT EXISTS series varchar(20),
                ADD COLUMN IF NOT EXISTS number bigint,
                ADD COLUMN IF NOT EXISTS customer_tax_id varchar(50),
                ADD COLUMN IF NOT EXISTS customer_name varchar(255),
                ADD COLUMN IF NOT EXISTS related_invoice_id uuid REFERENCES invoices(id) ON DELETE RESTRICT,
                ADD COLUMN IF NOT EXISTS exchange_rate numeric(12,6),
                ADD COLUMN IF NOT EXISTS fiscal_status varchar(30) NOT NULL DEFAULT 'NOT_SENT',
                ADD COLUMN IF NOT EXISTS fiscal_response text,
                ADD COLUMN IF NOT EXISTS reason text,
                ADD COLUMN IF NOT EXISTS issued_at timestamptz,
                ADD COLUMN IF NOT EXISTS owner_user_id uuid REFERENCES users(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS created_by uuid REFERENCES users(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS branch_id uuid REFERENCES branches(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS lock_version integer NOT NULL DEFAULT 0;
            -- Un borrador todavía no tiene número ni fecha de emisión.
            ALTER TABLE invoices ALTER COLUMN invoice_number DROP NOT NULL;
            ALTER TABLE invoices ALTER COLUMN issue_date DROP NOT NULL;
            ALTER TABLE invoices ALTER COLUMN issue_date DROP DEFAULT;
            UPDATE invoices SET issued_at = created_at WHERE issued_at IS NULL AND status <> 'DRAFT';
            ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_invoice_type_check;
            ALTER TABLE invoices ADD CONSTRAINT invoices_invoice_type_check
                CHECK (invoice_type IN ('INVOICE', 'RECEIPT', 'CREDIT_NOTE', 'OTHER'));
            ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_status_check;
            ALTER TABLE invoices ADD CONSTRAINT invoices_status_check
                CHECK (status IN ('DRAFT', 'ISSUED', 'CORRECTED', 'VOID', 'PAID', 'PARTIALLY_PAID', 'OVERDUE', 'CANCELLED'));
            ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_fiscal_status_check;
            ALTER TABLE invoices ADD CONSTRAINT invoices_fiscal_status_check
                CHECK (fiscal_status IN ('NOT_SENT', 'CONTINGENCY', 'PENDING', 'ACCEPTED', 'REJECTED'));
            ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_numbered_check;
            ALTER TABLE invoices ADD CONSTRAINT invoices_numbered_check
                CHECK (status IN ('DRAFT', 'CANCELLED') OR (invoice_number IS NOT NULL AND issue_date IS NOT NULL));
            ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_credit_note_check;
            ALTER TABLE invoices ADD CONSTRAINT invoices_credit_note_check
                CHECK (invoice_type <> 'CREDIT_NOTE' OR (related_invoice_id IS NOT NULL AND btrim(coalesce(reason, '')) <> ''));
            CREATE UNIQUE INDEX IF NOT EXISTS ux_invoices_series_number
                ON invoices (tenant_id, invoice_type, series, number) WHERE number IS NOT NULL;
            -- Un comprobante se corrige una sola vez.
            CREATE UNIQUE INDEX IF NOT EXISTS ux_invoices_one_credit_note
                ON invoices (related_invoice_id) WHERE related_invoice_id IS NOT NULL AND invoice_type = 'CREDIT_NOTE';
            CREATE INDEX IF NOT EXISTS idx_invoices_customer ON invoices (tenant_id, customer_id);
            CREATE INDEX IF NOT EXISTS idx_invoices_owner ON invoices (tenant_id, owner_user_id);
            CREATE INDEX IF NOT EXISTS idx_invoices_branch ON invoices (tenant_id, branch_id);

            -- 2. Eventos facturables: lo que Operaciones (o Finanzas, a mano) deja listo para facturar.
            CREATE TABLE IF NOT EXISTS billable_events (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                source_type varchar(30) NOT NULL DEFAULT 'MANUAL',
                source_id uuid,
                customer_id uuid NOT NULL REFERENCES customers(id) ON DELETE RESTRICT,
                contract_id uuid REFERENCES contracts(id) ON DELETE SET NULL,
                description text NOT NULL,
                base numeric(18,4) NOT NULL,
                amount numeric(18,4) NOT NULL,
                currency varchar(10) NOT NULL DEFAULT 'PEN',
                status varchar(30) NOT NULL DEFAULT 'PENDING',
                invoice_id uuid REFERENCES invoices(id) ON DELETE SET NULL,
                idempotency_key varchar(150) NOT NULL,
                cancel_reason text,
                owner_user_id uuid REFERENCES users(id) ON DELETE SET NULL,
                created_by uuid REFERENCES users(id) ON DELETE SET NULL,
                branch_id uuid REFERENCES branches(id) ON DELETE SET NULL,
                lock_version integer NOT NULL DEFAULT 0,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT fk_billable_events_customer_same_tenant FOREIGN KEY (tenant_id, customer_id) REFERENCES customers(tenant_id, id) ON DELETE RESTRICT,
                CONSTRAINT billable_events_source_check CHECK (source_type IN ('WORK_ORDER', 'RECURRING_CYCLE', 'TIME_ENTRY', 'CONTRACT_MILESTONE', 'MANUAL')),
                CONSTRAINT billable_events_status_check CHECK (status IN ('PENDING', 'INVOICED', 'CANCELLED')),
                CONSTRAINT billable_events_currency_check CHECK (currency IN ('PEN', 'USD')),
                CONSTRAINT billable_events_amount_check CHECK (base > 0 AND amount >= base),
                CONSTRAINT billable_events_invoiced_check CHECK (status <> 'INVOICED' OR invoice_id IS NOT NULL),
                CONSTRAINT billable_events_cancel_reason_check CHECK (status <> 'CANCELLED' OR btrim(coalesce(cancel_reason, '')) <> ''),
                CONSTRAINT billable_events_idempotency_key UNIQUE (tenant_id, idempotency_key)
            );
            -- Un origen da un solo evento: completar dos veces la misma orden no factura dos veces.
            CREATE UNIQUE INDEX IF NOT EXISTS ux_billable_events_source ON billable_events (tenant_id, source_type, source_id) WHERE source_id IS NOT NULL;
            CREATE INDEX IF NOT EXISTS idx_billable_events_tenant_status ON billable_events (tenant_id, status);
            CREATE INDEX IF NOT EXISTS idx_billable_events_customer ON billable_events (tenant_id, customer_id);
            CREATE INDEX IF NOT EXISTS idx_billable_events_invoice ON billable_events (invoice_id) WHERE invoice_id IS NOT NULL;
            DROP TRIGGER IF EXISTS trg_billable_events_updated_at ON billable_events;
            CREATE TRIGGER trg_billable_events_updated_at BEFORE UPDATE ON billable_events FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            ALTER TABLE billable_events ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON billable_events;
            CREATE POLICY tenant_isolation ON billable_events
                USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
            -- Los eventos no se borran: se anulan.
            REVOKE ALL ON billable_events FROM erp_app;
            GRANT SELECT, INSERT, UPDATE ON billable_events TO erp_app;

            -- 3. Líneas del comprobante: orden y evento del que salen.
            ALTER TABLE invoice_items
                ADD COLUMN IF NOT EXISTS position integer NOT NULL DEFAULT 1,
                ADD COLUMN IF NOT EXISTS billable_event_id uuid REFERENCES billable_events(id) ON DELETE SET NULL;
            -- Una nota de crédito lleva sus líneas en negativo.
            ALTER TABLE invoice_items DROP CONSTRAINT IF EXISTS invoice_items_unit_price_check;

            -- 4. Cuentas por cobrar: moneda, responsable, sede y estados.
            ALTER TABLE receivables
                ADD COLUMN IF NOT EXISTS currency varchar(10) NOT NULL DEFAULT 'PEN',
                ADD COLUMN IF NOT EXISTS owner_user_id uuid REFERENCES users(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS branch_id uuid REFERENCES branches(id) ON DELETE SET NULL;
            UPDATE receivables r SET currency = i.currency FROM invoices i WHERE i.id = r.invoice_id AND r.currency <> i.currency;
            UPDATE receivables SET status = 'PARTIAL' WHERE status = 'OPEN' AND balance_amount > 0 AND balance_amount < original_amount;
            ALTER TABLE receivables DROP CONSTRAINT IF EXISTS receivables_status_check;
            ALTER TABLE receivables ADD CONSTRAINT receivables_status_check CHECK (status IN ('OPEN', 'PARTIAL', 'PAID', 'CANCELLED'));
            ALTER TABLE receivables DROP CONSTRAINT IF EXISTS receivables_balance_limit_check;
            ALTER TABLE receivables ADD CONSTRAINT receivables_balance_limit_check CHECK (balance_amount <= original_amount);
            CREATE INDEX IF NOT EXISTS idx_receivables_customer ON receivables (tenant_id, customer_id);
            CREATE INDEX IF NOT EXISTS idx_receivables_due ON receivables (tenant_id, due_date);
            CREATE INDEX IF NOT EXISTS idx_receivables_owner ON receivables (tenant_id, owner_user_id);

            -- 5. Movimientos de la cuenta por cobrar: cargo, abono, reversión, nota de crédito…
            CREATE TABLE IF NOT EXISTS receivable_movements (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                receivable_id uuid NOT NULL REFERENCES receivables(id) ON DELETE CASCADE,
                movement_type varchar(30) NOT NULL,
                amount numeric(18,4) NOT NULL,
                reference varchar(255),
                payment_id uuid REFERENCES payments(id) ON DELETE SET NULL,
                created_by uuid REFERENCES users(id) ON DELETE SET NULL,
                -- La hora real de cada movimiento, no la del inicio de la transacción: así conservan su orden.
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT receivable_movements_type_check CHECK (movement_type IN ('CHARGE', 'PAYMENT', 'REVERSAL', 'CREDIT_NOTE', 'WITHHOLDING', 'VOID')),
                -- El cargo y la reversión suman al saldo; lo demás lo baja.
                CONSTRAINT receivable_movements_sign_check CHECK (amount <> 0 AND (amount > 0) = (movement_type IN ('CHARGE', 'REVERSAL')))
            );
            CREATE INDEX IF NOT EXISTS idx_receivable_movements_receivable ON receivable_movements (receivable_id, created_at);
            ALTER TABLE receivable_movements ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON receivable_movements;
            CREATE POLICY tenant_isolation ON receivable_movements
                USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
            -- Los movimientos ni se editan ni se borran.
            REVOKE ALL ON receivable_movements FROM erp_app;
            GRANT SELECT, INSERT ON receivable_movements TO erp_app;

            -- Movimientos de las cuentas que ya existían: su cargo y los cobros que se les aplicaron.
            INSERT INTO receivable_movements (tenant_id, receivable_id, movement_type, amount, reference, created_at)
            SELECT r.tenant_id, r.id, 'CHARGE', r.original_amount, i.invoice_number, r.created_at
            FROM receivables r JOIN invoices i ON i.id = r.invoice_id
            WHERE r.original_amount > 0 AND NOT EXISTS (SELECT 1 FROM receivable_movements m WHERE m.receivable_id = r.id);
            INSERT INTO receivable_movements (tenant_id, receivable_id, movement_type, amount, reference, payment_id, created_at)
            SELECT a.tenant_id, a.receivable_id, 'PAYMENT', -a.amount, p.payment_number, p.id, a.created_at
            FROM payment_allocations a JOIN payments p ON p.id = a.payment_id
            WHERE NOT EXISTS (SELECT 1 FROM receivable_movements m WHERE m.receivable_id = a.receivable_id AND m.payment_id = a.payment_id);

            -- El saldo lo lleva la base: cada movimiento lo actualiza, junto con el estado y el saldo del comprobante.
            CREATE OR REPLACE FUNCTION apply_receivable_movement() RETURNS trigger AS $fn$
            DECLARE
                target receivables%ROWTYPE;
                new_balance numeric(18,4);
            BEGIN
                SELECT * INTO target FROM receivables WHERE id = NEW.receivable_id FOR UPDATE;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'La cuenta por cobrar del movimiento no existe' USING ERRCODE = 'foreign_key_violation';
                END IF;
                new_balance := target.balance_amount + NEW.amount;
                IF new_balance < 0 OR new_balance > target.original_amount THEN
                    RAISE EXCEPTION 'El movimiento deja el saldo de la cuenta por cobrar fuera de su importe' USING ERRCODE = 'check_violation';
                END IF;
                UPDATE receivables SET balance_amount = new_balance,
                    status = CASE
                        WHEN new_balance = 0 AND NEW.movement_type IN ('CREDIT_NOTE', 'VOID') THEN 'CANCELLED'
                        WHEN new_balance = 0 THEN 'PAID'
                        WHEN new_balance < target.original_amount THEN 'PARTIAL'
                        ELSE 'OPEN' END
                WHERE id = target.id;
                UPDATE invoices SET balance_due = new_balance WHERE id = target.invoice_id;
                RETURN NEW;
            END;
            $fn$ LANGUAGE plpgsql;
            DROP TRIGGER IF EXISTS trg_receivable_movements_apply ON receivable_movements;
            CREATE TRIGGER trg_receivable_movements_apply AFTER INSERT ON receivable_movements
                FOR EACH ROW EXECUTE FUNCTION apply_receivable_movement();

            -- Nadie escribe el saldo a mano, ni cambia de quién es la cuenta ni cuánto valía.
            CREATE OR REPLACE FUNCTION protect_receivable_balance() RETURNS trigger AS $fn$
            BEGIN
                IF NEW.balance_amount IS DISTINCT FROM OLD.balance_amount AND pg_trigger_depth() < 2 THEN
                    RAISE EXCEPTION 'El saldo de una cuenta por cobrar solo cambia con un movimiento' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.original_amount IS DISTINCT FROM OLD.original_amount OR NEW.invoice_id IS DISTINCT FROM OLD.invoice_id
                   OR NEW.customer_id IS DISTINCT FROM OLD.customer_id OR NEW.currency IS DISTINCT FROM OLD.currency THEN
                    RAISE EXCEPTION 'El importe, el cliente y el comprobante de una cuenta por cobrar no se modifican' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $fn$ LANGUAGE plpgsql;
            DROP TRIGGER IF EXISTS trg_receivables_protect ON receivables;
            CREATE TRIGGER trg_receivables_protect BEFORE UPDATE ON receivables
                FOR EACH ROW EXECUTE FUNCTION protect_receivable_balance();
            -- Las cuentas por cobrar no se borran: se saldan o se anulan con un movimiento.
            REVOKE DELETE ON receivables FROM erp_app;

            -- 6. Un comprobante emitido no se modifica: solo cambian su estado, su saldo y lo que responde SUNAT.
            CREATE OR REPLACE FUNCTION protect_issued_invoice() RETURNS trigger AS $fn$
            BEGIN
                IF OLD.status <> 'DRAFT' AND (
                       NEW.status = 'DRAFT'
                    OR NEW.customer_id IS DISTINCT FROM OLD.customer_id OR NEW.contract_id IS DISTINCT FROM OLD.contract_id
                    OR NEW.invoice_number IS DISTINCT FROM OLD.invoice_number OR NEW.invoice_type IS DISTINCT FROM OLD.invoice_type
                    OR NEW.series IS DISTINCT FROM OLD.series OR NEW.number IS DISTINCT FROM OLD.number
                    OR NEW.currency IS DISTINCT FROM OLD.currency OR NEW.exchange_rate IS DISTINCT FROM OLD.exchange_rate
                    OR NEW.issue_date IS DISTINCT FROM OLD.issue_date OR NEW.due_date IS DISTINCT FROM OLD.due_date
                    OR NEW.subtotal IS DISTINCT FROM OLD.subtotal OR NEW.tax_total IS DISTINCT FROM OLD.tax_total OR NEW.total IS DISTINCT FROM OLD.total
                    OR NEW.customer_tax_id IS DISTINCT FROM OLD.customer_tax_id OR NEW.customer_name IS DISTINCT FROM OLD.customer_name
                    OR NEW.related_invoice_id IS DISTINCT FROM OLD.related_invoice_id OR NEW.reason IS DISTINCT FROM OLD.reason
                ) THEN
                    RAISE EXCEPTION 'Un comprobante emitido no se modifica: se corrige con una nota de crédito' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $fn$ LANGUAGE plpgsql;
            DROP TRIGGER IF EXISTS trg_invoices_protect ON invoices;
            CREATE TRIGGER trg_invoices_protect BEFORE UPDATE ON invoices
                FOR EACH ROW EXECUTE FUNCTION protect_issued_invoice();

            CREATE OR REPLACE FUNCTION protect_issued_invoice_items() RETURNS trigger AS $fn$
            DECLARE
                parent_id uuid;
                parent_status text;
            BEGIN
                parent_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.invoice_id ELSE NEW.invoice_id END;
                SELECT status INTO parent_status FROM invoices WHERE id = parent_id;
                -- Sin comprobante (se está borrando la empresa entera) no hay nada que proteger.
                IF FOUND AND parent_status <> 'DRAFT' THEN
                    RAISE EXCEPTION 'Las líneas de un comprobante emitido no se modifican' USING ERRCODE = 'check_violation';
                END IF;
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $fn$ LANGUAGE plpgsql;
            DROP TRIGGER IF EXISTS trg_invoice_items_protect ON invoice_items;
            CREATE TRIGGER trg_invoice_items_protect BEFORE INSERT OR UPDATE OR DELETE ON invoice_items
                FOR EACH ROW EXECUTE FUNCTION protect_issued_invoice_items();
            -- Los comprobantes no se borran: un borrador se descarta y queda registrado.
            REVOKE DELETE ON invoices FROM erp_app;

            -- 7. Cobros: n.º de operación, origen, reembolsado, conciliación, tipo de cambio, quién lo registró, sede y versión.
            ALTER TABLE payments
                ADD COLUMN IF NOT EXISTS reference varchar(255),
                ADD COLUMN IF NOT EXISTS source varchar(30) NOT NULL DEFAULT 'BACKOFFICE',
                ADD COLUMN IF NOT EXISTS refunded_amount numeric(18,4) NOT NULL DEFAULT 0,
                ADD COLUMN IF NOT EXISTS reconciled boolean NOT NULL DEFAULT false,
                ADD COLUMN IF NOT EXISTS reconciled_at timestamptz,
                ADD COLUMN IF NOT EXISTS reconciled_by uuid REFERENCES users(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS exchange_rate numeric(12,6),
                ADD COLUMN IF NOT EXISTS created_by uuid REFERENCES users(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS branch_id uuid REFERENCES branches(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS updated_at timestamptz NOT NULL DEFAULT now(),
                ADD COLUMN IF NOT EXISTS lock_version integer NOT NULL DEFAULT 0;
            ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_status_check;
            ALTER TABLE payments ADD CONSTRAINT payments_status_check
                CHECK (status IN ('PENDING', 'CONFIRMED', 'PARTIALLY_ALLOCATED', 'ALLOCATED', 'REFUNDED', 'FAILED', 'CANCELLED'));
            -- Los cobros que ya estaban aplicados quedan con su estado real.
            UPDATE payments p SET status = CASE WHEN a.applied >= p.amount THEN 'ALLOCATED' ELSE 'PARTIALLY_ALLOCATED' END
            FROM (SELECT payment_id, sum(amount) AS applied FROM payment_allocations GROUP BY payment_id) a
            WHERE a.payment_id = p.id AND p.status = 'CONFIRMED' AND a.applied > 0;
            ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_source_check;
            ALTER TABLE payments ADD CONSTRAINT payments_source_check CHECK (source IN ('BACKOFFICE', 'GATEWAY', 'PORTAL'));
            ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_refunded_check;
            ALTER TABLE payments ADD CONSTRAINT payments_refunded_check CHECK (refunded_amount >= 0 AND refunded_amount <= amount);
            CREATE INDEX IF NOT EXISTS idx_payments_customer ON payments (tenant_id, customer_id);
            CREATE INDEX IF NOT EXISTS idx_payments_creator ON payments (tenant_id, created_by);
            -- Un cobro no se borra: se reembolsa.
            REVOKE DELETE ON payments FROM erp_app;

            ALTER TABLE payment_allocations ADD COLUMN IF NOT EXISTS fx_difference_pen numeric(18,4) NOT NULL DEFAULT 0;

            ALTER TABLE refunds ADD COLUMN IF NOT EXISTS created_by uuid REFERENCES users(id) ON DELETE SET NULL;
            ALTER TABLE refunds DROP CONSTRAINT IF EXISTS refunds_reason_check;
            ALTER TABLE refunds ADD CONSTRAINT refunds_reason_check CHECK (btrim(coalesce(reason, '')) <> '');
            -- Los reembolsos quedan: ni se editan ni se borran.
            REVOKE UPDATE, DELETE ON refunds FROM erp_app;

            -- 8. Permisos por recurso.
            INSERT INTO permissions (code, name, description) VALUES
                ('billing.create', 'Preparar comprobantes', 'Registrar eventos facturables y generar el comprobante en borrador a partir de ellos.'),
                ('billing.approve', 'Emitir comprobantes', 'Emitir un comprobante, corregirlo con nota de crédito y reintentar su envío.'),
                ('billing.export', 'Exportar facturación', 'Descargar eventos facturables y comprobantes en Excel o CSV.'),
                ('receivables.view', 'Ver cuentas por cobrar', 'Cuentas por cobrar, su saldo, sus movimientos y la antigüedad de la deuda.'),
                ('receivables.manage', 'Gestionar cuentas por cobrar', 'Enviar recordatorios de cobranza y registrar constancias sobre una cuenta.'),
                ('receivables.export', 'Exportar cuentas por cobrar', 'Descargar el listado en Excel o CSV.'),
                ('payments.view', 'Ver cobros', 'Cobros registrados, a qué cuentas se aplicaron y sus reembolsos.'),
                ('payments.create', 'Registrar cobros', 'Registrar un cobro de un cliente.'),
                ('payments.manage', 'Aplicar cobros', 'Aplicar un cobro a las cuentas por cobrar del cliente.'),
                ('payments.approve', 'Conciliar y reembolsar cobros', 'Conciliar un cobro con el banco y reembolsarlo.'),
                ('payments.export', 'Exportar cobros', 'Descargar el listado en Excel o CSV.'),
                ('fiscal.view', 'Ver tipo de cambio', 'Monedas y tipo de cambio registrado.'),
                ('fiscal.manage', 'Gestionar tipo de cambio', 'Registrar o corregir el tipo de cambio del día.')
            ON CONFLICT (code) DO NOTHING;
            UPDATE permissions SET name = 'Ver facturación', description = 'Eventos facturables y comprobantes.' WHERE code = 'billing.view';
            UPDATE permissions SET name = 'Gestionar facturación', description = 'Anular un evento facturable y descartar un comprobante en borrador.' WHERE code = 'billing.manage';

            UPDATE module_catalog SET permission_prefixes = '["billing", "finance", "receivables", "payments", "fiscal"]'::jsonb WHERE upper(code) = 'FINANZAS';

            -- 9. Alcance por rol: también lo llevan facturación, cuentas por cobrar y cobros.
            ALTER TABLE role_scopes DROP CONSTRAINT IF EXISTS role_scopes_resource_check;
            ALTER TABLE role_scopes ADD CONSTRAINT role_scopes_resource_check
                CHECK (resource IN ('quotations', 'contracts', 'orders', 'portal', 'leads', 'opportunities', 'activities',
                                    'contracted_services', 'work_orders', 'projects', 'time_entries', 'cost_entries', 'agenda',
                                    'billing', 'receivables', 'payments'));

            -- 10. Reparto. Tipo de cambio pasa a tener su permiso: lo conserva quien ya lo veía o lo registraba.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT rp.role_id, n.id
            FROM role_permissions rp
            JOIN permissions o ON o.id = rp.permission_id AND o.code IN ('billing.view', 'billing.manage')
            JOIN permissions n ON n.code = CASE o.code WHEN 'billing.view' THEN 'fiscal.view' ELSE 'fiscal.manage' END
            ON CONFLICT DO NOTHING;

            -- El Administrador de cada empresa: todo (solo vale si su plan tiene el módulo Finanzas).
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r
            JOIN permissions p ON p.code LIKE 'billing.%' OR p.code LIKE 'receivables.%' OR p.code LIKE 'payments.%' OR p.code LIKE 'fiscal.%'
            WHERE r.tenant_id IS NOT NULL AND (r.is_system OR upper(r.code) = 'ADMIN')
            ON CONFLICT DO NOTHING;

            -- Gerencia: ve y exporta todo, y emite comprobantes; no registra cobros.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r
            JOIN permissions p ON p.code IN ('billing.view', 'billing.approve', 'billing.export', 'receivables.view', 'receivables.export',
                                             'payments.view', 'payments.export', 'fiscal.view')
            WHERE upper(r.code) = 'GERENCIA' AND r.tenant_id IS NOT NULL
            ON CONFLICT DO NOTHING;
            INSERT INTO role_scopes (role_id, tenant_id, resource, scope)
            SELECT r.id, r.tenant_id, s.resource, 'TENANT'
            FROM roles r CROSS JOIN (VALUES ('billing'), ('receivables'), ('payments')) AS s(resource)
            WHERE upper(r.code) = 'GERENCIA' AND r.tenant_id IS NOT NULL
            ON CONFLICT DO NOTHING;

            -- Ventas ve las cuentas por cobrar de lo que vendió (sin fila de alcance: lo propio).
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code = 'receivables.view'
            WHERE upper(r.code) = 'VENTAS' AND r.tenant_id IS NOT NULL
            ON CONFLICT DO NOTHING;

            -- Operaciones ve los eventos y comprobantes de su sede.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code = 'billing.view'
            WHERE upper(r.code) = 'OPERACIONES' AND r.tenant_id IS NOT NULL
            ON CONFLICT DO NOTHING;
            INSERT INTO role_scopes (role_id, tenant_id, resource, scope)
            SELECT r.id, r.tenant_id, 'billing', 'BRANCH' FROM roles r
            WHERE upper(r.code) = 'OPERACIONES' AND r.tenant_id IS NOT NULL
            ON CONFLICT DO NOTHING;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM role_scopes WHERE resource IN ('billing', 'receivables', 'payments');
            ALTER TABLE role_scopes DROP CONSTRAINT IF EXISTS role_scopes_resource_check;
            ALTER TABLE role_scopes ADD CONSTRAINT role_scopes_resource_check
                CHECK (resource IN ('quotations', 'contracts', 'orders', 'portal', 'leads', 'opportunities', 'activities',
                                    'contracted_services', 'work_orders', 'projects', 'time_entries', 'cost_entries', 'agenda'));
            UPDATE module_catalog SET permission_prefixes = '["billing", "finance"]'::jsonb WHERE upper(code) = 'FINANZAS';
            DELETE FROM role_permissions WHERE permission_id IN (
                SELECT id FROM permissions WHERE code IN ('billing.create', 'billing.approve', 'billing.export')
                    OR code LIKE 'receivables.%' OR code LIKE 'payments.%' OR code LIKE 'fiscal.%');
            DELETE FROM permissions WHERE code IN ('billing.create', 'billing.approve', 'billing.export')
                OR code LIKE 'receivables.%' OR code LIKE 'payments.%' OR code LIKE 'fiscal.%';

            GRANT UPDATE, DELETE ON refunds TO erp_app;
            GRANT DELETE ON payments, invoices, receivables TO erp_app;
            ALTER TABLE refunds DROP CONSTRAINT IF EXISTS refunds_reason_check;
            ALTER TABLE refunds DROP COLUMN IF EXISTS created_by;
            ALTER TABLE payment_allocations DROP COLUMN IF EXISTS fx_difference_pen;

            UPDATE payments SET status = 'CONFIRMED' WHERE status IN ('PARTIALLY_ALLOCATED', 'ALLOCATED');
            ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_status_check;
            ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('PENDING', 'CONFIRMED', 'FAILED', 'REFUNDED', 'CANCELLED'));
            ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_source_check;
            ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_refunded_check;
            ALTER TABLE payments
                DROP COLUMN IF EXISTS reference, DROP COLUMN IF EXISTS source, DROP COLUMN IF EXISTS refunded_amount, DROP COLUMN IF EXISTS reconciled,
                DROP COLUMN IF EXISTS reconciled_at, DROP COLUMN IF EXISTS reconciled_by, DROP COLUMN IF EXISTS exchange_rate,
                DROP COLUMN IF EXISTS created_by, DROP COLUMN IF EXISTS branch_id, DROP COLUMN IF EXISTS updated_at, DROP COLUMN IF EXISTS lock_version;

            DROP TRIGGER IF EXISTS trg_invoice_items_protect ON invoice_items;
            DROP FUNCTION IF EXISTS protect_issued_invoice_items();
            DROP TRIGGER IF EXISTS trg_invoices_protect ON invoices;
            DROP FUNCTION IF EXISTS protect_issued_invoice();
            DROP TRIGGER IF EXISTS trg_receivables_protect ON receivables;
            DROP FUNCTION IF EXISTS protect_receivable_balance();
            DROP TABLE IF EXISTS receivable_movements;
            DROP FUNCTION IF EXISTS apply_receivable_movement();

            ALTER TABLE receivables DROP CONSTRAINT IF EXISTS receivables_status_check;
            ALTER TABLE receivables DROP CONSTRAINT IF EXISTS receivables_balance_limit_check;
            ALTER TABLE receivables DROP COLUMN IF EXISTS currency, DROP COLUMN IF EXISTS owner_user_id, DROP COLUMN IF EXISTS branch_id;

            ALTER TABLE invoice_items DROP COLUMN IF EXISTS position, DROP COLUMN IF EXISTS billable_event_id;
            DROP TABLE IF EXISTS billable_events;

            DROP INDEX IF EXISTS ux_invoices_series_number;
            DROP INDEX IF EXISTS ux_invoices_one_credit_note;
            ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_fiscal_status_check;
            ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_numbered_check;
            ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_credit_note_check;
            ALTER TABLE invoices
                DROP COLUMN IF EXISTS series, DROP COLUMN IF EXISTS number, DROP COLUMN IF EXISTS customer_tax_id, DROP COLUMN IF EXISTS customer_name,
                DROP COLUMN IF EXISTS related_invoice_id, DROP COLUMN IF EXISTS exchange_rate, DROP COLUMN IF EXISTS fiscal_status,
                DROP COLUMN IF EXISTS fiscal_response, DROP COLUMN IF EXISTS reason, DROP COLUMN IF EXISTS issued_at,
                DROP COLUMN IF EXISTS owner_user_id, DROP COLUMN IF EXISTS created_by, DROP COLUMN IF EXISTS branch_id, DROP COLUMN IF EXISTS lock_version;
        SQL);
    }
};
