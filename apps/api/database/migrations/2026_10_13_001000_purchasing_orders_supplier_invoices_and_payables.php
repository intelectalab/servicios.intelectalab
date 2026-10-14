<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Compras y cuentas por pagar: órdenes de compra, su recepción, comprobantes de proveedor,
 * cuentas por pagar y pagos a proveedores.
 *
 * Usa las tablas que ya existían (`suppliers`, `cost_centers`, `financial_accounts`,
 * `bank_movements`) y crea las suyas.
 *
 * Lo que la base garantiza por sí sola:
 *   - las líneas de una orden solo cambian mientras está en borrador;
 *   - rechazar y anular llevan su motivo; una orden aprobada tiene quién la aprobó;
 *   - lo recibido de una orden nunca pasa del 100 % y solo se recibe una orden aprobada;
 *   - un comprobante de proveedor no se registra dos veces y sus importes no se modifican;
 *   - el saldo de una cuenta por pagar es la suma de sus movimientos: nadie lo escribe a mano,
 *     no baja de cero ni supera el importe original, y los movimientos no se editan ni se borran;
 *   - órdenes, comprobantes, cuentas por pagar y pagos no se borran.
 *
 * Solo añade: no borra datos. Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- 1. Órdenes de compra.
            CREATE TABLE IF NOT EXISTS purchase_orders (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                order_number varchar(50) NOT NULL,
                supplier_id uuid NOT NULL REFERENCES suppliers(id) ON DELETE RESTRICT,
                cost_center_id uuid NOT NULL REFERENCES cost_centers(id) ON DELETE RESTRICT,
                currency varchar(10) NOT NULL DEFAULT 'PEN',
                needed_by date,
                notes text,
                subtotal numeric(18,4) NOT NULL DEFAULT 0,
                tax numeric(18,4) NOT NULL DEFAULT 0,
                total numeric(18,4) NOT NULL DEFAULT 0,
                status varchar(30) NOT NULL DEFAULT 'DRAFT',
                reason text,
                budget_warning text,
                approved_by uuid REFERENCES users(id) ON DELETE SET NULL,
                approved_at timestamptz,
                owner_user_id uuid REFERENCES users(id) ON DELETE SET NULL,
                created_by uuid REFERENCES users(id) ON DELETE SET NULL,
                branch_id uuid REFERENCES branches(id) ON DELETE SET NULL,
                lock_version integer NOT NULL DEFAULT 0,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT purchase_orders_number_key UNIQUE (tenant_id, order_number),
                CONSTRAINT purchase_orders_currency_check CHECK (currency IN ('PEN', 'USD')),
                CONSTRAINT purchase_orders_status_check CHECK (status IN ('DRAFT', 'SUBMITTED', 'APPROVED', 'PARTIALLY_RECEIVED', 'RECEIVED', 'CLOSED', 'REJECTED', 'CANCELLED')),
                CONSTRAINT purchase_orders_amounts_check CHECK (subtotal >= 0 AND tax >= 0 AND total >= subtotal),
                CONSTRAINT purchase_orders_reason_check CHECK (status NOT IN ('REJECTED', 'CANCELLED') OR btrim(coalesce(reason, '')) <> ''),
                CONSTRAINT purchase_orders_approved_check CHECK (status NOT IN ('APPROVED', 'PARTIALLY_RECEIVED', 'RECEIVED') OR approved_at IS NOT NULL)
            );
            CREATE INDEX IF NOT EXISTS idx_purchase_orders_tenant_status ON purchase_orders (tenant_id, status);
            CREATE INDEX IF NOT EXISTS idx_purchase_orders_supplier ON purchase_orders (tenant_id, supplier_id);
            CREATE INDEX IF NOT EXISTS idx_purchase_orders_cost_center ON purchase_orders (tenant_id, cost_center_id);
            CREATE INDEX IF NOT EXISTS idx_purchase_orders_owner ON purchase_orders (tenant_id, owner_user_id);
            CREATE INDEX IF NOT EXISTS idx_purchase_orders_branch ON purchase_orders (tenant_id, branch_id);
            DROP TRIGGER IF EXISTS trg_purchase_orders_updated_at ON purchase_orders;
            CREATE TRIGGER trg_purchase_orders_updated_at BEFORE UPDATE ON purchase_orders FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            ALTER TABLE purchase_orders ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON purchase_orders;
            CREATE POLICY tenant_isolation ON purchase_orders USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
            -- Las órdenes no se borran: se anulan.
            REVOKE ALL ON purchase_orders FROM erp_app;
            GRANT SELECT, INSERT, UPDATE ON purchase_orders TO erp_app;

            -- 2. Líneas de la orden.
            CREATE TABLE IF NOT EXISTS purchase_order_items (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                purchase_order_id uuid NOT NULL REFERENCES purchase_orders(id) ON DELETE CASCADE,
                position integer NOT NULL DEFAULT 1,
                description text NOT NULL,
                quantity numeric(14,3) NOT NULL,
                unit_cost numeric(18,4) NOT NULL,
                account_code varchar(30) NOT NULL,
                CONSTRAINT purchase_order_items_values_check CHECK (quantity > 0 AND unit_cost > 0 AND btrim(description) <> '')
            );
            CREATE INDEX IF NOT EXISTS idx_purchase_order_items_order ON purchase_order_items (purchase_order_id, position);
            ALTER TABLE purchase_order_items ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON purchase_order_items;
            CREATE POLICY tenant_isolation ON purchase_order_items USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());

            -- Las líneas solo cambian mientras la orden está en borrador.
            CREATE OR REPLACE FUNCTION protect_purchase_order_items() RETURNS trigger AS $fn$
            DECLARE
                parent_id uuid;
                parent_status text;
            BEGIN
                parent_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.purchase_order_id ELSE NEW.purchase_order_id END;
                SELECT status INTO parent_status FROM purchase_orders WHERE id = parent_id;
                -- Sin orden (se está borrando la empresa entera) no hay nada que proteger.
                IF FOUND AND parent_status <> 'DRAFT' THEN
                    RAISE EXCEPTION 'Las líneas de una orden de compra solo se modifican en borrador' USING ERRCODE = 'check_violation';
                END IF;
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $fn$ LANGUAGE plpgsql;
            DROP TRIGGER IF EXISTS trg_purchase_order_items_protect ON purchase_order_items;
            CREATE TRIGGER trg_purchase_order_items_protect BEFORE INSERT OR UPDATE OR DELETE ON purchase_order_items
                FOR EACH ROW EXECUTE FUNCTION protect_purchase_order_items();

            -- Enviada a aprobación, la orden ya no cambia de proveedor, centro de costo, moneda ni importes.
            CREATE OR REPLACE FUNCTION protect_purchase_order() RETURNS trigger AS $fn$
            BEGIN
                IF OLD.status <> 'DRAFT' AND (
                       NEW.supplier_id IS DISTINCT FROM OLD.supplier_id OR NEW.cost_center_id IS DISTINCT FROM OLD.cost_center_id
                    OR NEW.currency IS DISTINCT FROM OLD.currency OR NEW.subtotal IS DISTINCT FROM OLD.subtotal
                    OR NEW.tax IS DISTINCT FROM OLD.tax OR NEW.total IS DISTINCT FROM OLD.total
                    OR NEW.order_number IS DISTINCT FROM OLD.order_number
                ) THEN
                    RAISE EXCEPTION 'Una orden de compra solo se edita en borrador' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $fn$ LANGUAGE plpgsql;
            DROP TRIGGER IF EXISTS trg_purchase_orders_protect ON purchase_orders;
            CREATE TRIGGER trg_purchase_orders_protect BEFORE UPDATE ON purchase_orders
                FOR EACH ROW EXECUTE FUNCTION protect_purchase_order();

            -- 3. Recepción conforme, por porcentaje.
            CREATE TABLE IF NOT EXISTS purchase_receipts (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                purchase_order_id uuid NOT NULL REFERENCES purchase_orders(id) ON DELETE CASCADE,
                received_pct numeric(6,2) NOT NULL,
                note text,
                received_by uuid REFERENCES users(id) ON DELETE SET NULL,
                received_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT purchase_receipts_pct_check CHECK (received_pct > 0 AND received_pct <= 100)
            );
            CREATE INDEX IF NOT EXISTS idx_purchase_receipts_order ON purchase_receipts (purchase_order_id, received_at);
            ALTER TABLE purchase_receipts ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON purchase_receipts;
            CREATE POLICY tenant_isolation ON purchase_receipts USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
            -- Una conformidad registrada ni se edita ni se borra.
            REVOKE ALL ON purchase_receipts FROM erp_app;
            GRANT SELECT, INSERT ON purchase_receipts TO erp_app;

            -- Solo se recibe una orden aprobada y lo recibido nunca pasa del 100 %.
            CREATE OR REPLACE FUNCTION check_purchase_receipt() RETURNS trigger AS $fn$
            DECLARE
                parent_status text;
                received numeric(8,2);
            BEGIN
                SELECT status INTO parent_status FROM purchase_orders WHERE id = NEW.purchase_order_id FOR UPDATE;
                IF NOT FOUND OR parent_status NOT IN ('APPROVED', 'PARTIALLY_RECEIVED') THEN
                    RAISE EXCEPTION 'Solo se recibe una orden de compra aprobada' USING ERRCODE = 'check_violation';
                END IF;
                SELECT coalesce(sum(received_pct), 0) INTO received FROM purchase_receipts WHERE purchase_order_id = NEW.purchase_order_id;
                IF received + NEW.received_pct > 100 THEN
                    RAISE EXCEPTION 'Lo recibido de una orden de compra no puede pasar del 100 %%' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $fn$ LANGUAGE plpgsql;
            DROP TRIGGER IF EXISTS trg_purchase_receipts_check ON purchase_receipts;
            CREATE TRIGGER trg_purchase_receipts_check BEFORE INSERT ON purchase_receipts
                FOR EACH ROW EXECUTE FUNCTION check_purchase_receipt();

            -- 4. Comprobantes de proveedor.
            CREATE TABLE IF NOT EXISTS supplier_invoices (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                supplier_id uuid NOT NULL REFERENCES suppliers(id) ON DELETE RESTRICT,
                supplier_name varchar(255) NOT NULL,
                supplier_tax_id varchar(50),
                doc_type varchar(30) NOT NULL DEFAULT 'FACTURA',
                doc_number varchar(30) NOT NULL,
                issue_date date NOT NULL,
                due_date date NOT NULL,
                purchase_order_id uuid REFERENCES purchase_orders(id) ON DELETE RESTRICT,
                cost_center_id uuid NOT NULL REFERENCES cost_centers(id) ON DELETE RESTRICT,
                expense_account varchar(30) NOT NULL,
                description text,
                currency varchar(10) NOT NULL DEFAULT 'PEN',
                exchange_rate numeric(12,6),
                subtotal numeric(18,4) NOT NULL,
                tax numeric(18,4) NOT NULL DEFAULT 0,
                total numeric(18,4) NOT NULL,
                detraction_code varchar(10),
                detraction_pct numeric(6,2),
                -- En soles, como se deposita.
                detraction_amount numeric(18,4) NOT NULL DEFAULT 0,
                retention_4ta numeric(18,4) NOT NULL DEFAULT 0,
                sunat_validation varchar(30) NOT NULL DEFAULT 'NOT_CHECKED',
                status varchar(30) NOT NULL DEFAULT 'REGISTERED',
                reason text,
                approved_by uuid REFERENCES users(id) ON DELETE SET NULL,
                approved_at timestamptz,
                owner_user_id uuid REFERENCES users(id) ON DELETE SET NULL,
                created_by uuid REFERENCES users(id) ON DELETE SET NULL,
                branch_id uuid REFERENCES branches(id) ON DELETE SET NULL,
                lock_version integer NOT NULL DEFAULT 0,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT supplier_invoices_doc_type_check CHECK (doc_type IN ('FACTURA', 'RECIBO_HONORARIOS')),
                CONSTRAINT supplier_invoices_doc_number_check CHECK (doc_number ~ '^[A-Z0-9]{4}-[0-9]{1,8}$'),
                CONSTRAINT supplier_invoices_currency_check CHECK (currency IN ('PEN', 'USD')),
                CONSTRAINT supplier_invoices_rate_check CHECK (currency = 'PEN' OR exchange_rate > 0),
                CONSTRAINT supplier_invoices_dates_check CHECK (due_date >= issue_date),
                CONSTRAINT supplier_invoices_amounts_check CHECK (subtotal > 0 AND tax >= 0 AND total >= subtotal AND detraction_amount >= 0 AND retention_4ta >= 0),
                CONSTRAINT supplier_invoices_sunat_check CHECK (sunat_validation IN ('NOT_CHECKED', 'VALID', 'INVALID')),
                CONSTRAINT supplier_invoices_status_check CHECK (status IN ('REGISTERED', 'APPROVED', 'REJECTED', 'VOIDED')),
                CONSTRAINT supplier_invoices_reason_check CHECK (status NOT IN ('REJECTED', 'VOIDED') OR btrim(coalesce(reason, '')) <> '')
            );
            -- Un comprobante de un proveedor no se registra dos veces (uno anulado sí se puede volver a registrar).
            CREATE UNIQUE INDEX IF NOT EXISTS ux_supplier_invoices_document
                ON supplier_invoices (tenant_id, supplier_id, doc_type, doc_number) WHERE status <> 'VOIDED';
            CREATE INDEX IF NOT EXISTS idx_supplier_invoices_tenant_status ON supplier_invoices (tenant_id, status);
            CREATE INDEX IF NOT EXISTS idx_supplier_invoices_order ON supplier_invoices (purchase_order_id) WHERE purchase_order_id IS NOT NULL;
            CREATE INDEX IF NOT EXISTS idx_supplier_invoices_owner ON supplier_invoices (tenant_id, owner_user_id);
            DROP TRIGGER IF EXISTS trg_supplier_invoices_updated_at ON supplier_invoices;
            CREATE TRIGGER trg_supplier_invoices_updated_at BEFORE UPDATE ON supplier_invoices FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            ALTER TABLE supplier_invoices ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON supplier_invoices;
            CREATE POLICY tenant_isolation ON supplier_invoices USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
            REVOKE ALL ON supplier_invoices FROM erp_app;
            GRANT SELECT, INSERT, UPDATE ON supplier_invoices TO erp_app;

            -- Registrado, el comprobante es lo que emitió el proveedor: solo cambia su estado.
            CREATE OR REPLACE FUNCTION protect_supplier_invoice() RETURNS trigger AS $fn$
            BEGIN
                IF NEW.supplier_id IS DISTINCT FROM OLD.supplier_id OR NEW.doc_type IS DISTINCT FROM OLD.doc_type OR NEW.doc_number IS DISTINCT FROM OLD.doc_number
                   OR NEW.issue_date IS DISTINCT FROM OLD.issue_date OR NEW.due_date IS DISTINCT FROM OLD.due_date
                   OR NEW.purchase_order_id IS DISTINCT FROM OLD.purchase_order_id OR NEW.currency IS DISTINCT FROM OLD.currency
                   OR NEW.exchange_rate IS DISTINCT FROM OLD.exchange_rate OR NEW.subtotal IS DISTINCT FROM OLD.subtotal
                   OR NEW.tax IS DISTINCT FROM OLD.tax OR NEW.total IS DISTINCT FROM OLD.total
                   OR NEW.detraction_amount IS DISTINCT FROM OLD.detraction_amount OR NEW.retention_4ta IS DISTINCT FROM OLD.retention_4ta THEN
                    RAISE EXCEPTION 'Un comprobante de proveedor registrado no se modifica: se anula y se registra de nuevo' USING ERRCODE = 'check_violation';
                END IF;
                IF OLD.status IN ('APPROVED', 'VOIDED') AND NEW.status IS DISTINCT FROM OLD.status THEN
                    RAISE EXCEPTION 'Un comprobante de proveedor aprobado o anulado no cambia de estado' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $fn$ LANGUAGE plpgsql;
            DROP TRIGGER IF EXISTS trg_supplier_invoices_protect ON supplier_invoices;
            CREATE TRIGGER trg_supplier_invoices_protect BEFORE UPDATE ON supplier_invoices
                FOR EACH ROW EXECUTE FUNCTION protect_supplier_invoice();

            -- 5. Cuentas por pagar: una por comprobante aprobado.
            CREATE TABLE IF NOT EXISTS payables (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                supplier_invoice_id uuid NOT NULL REFERENCES supplier_invoices(id) ON DELETE RESTRICT,
                supplier_id uuid NOT NULL REFERENCES suppliers(id) ON DELETE RESTRICT,
                currency varchar(10) NOT NULL DEFAULT 'PEN',
                due_date date NOT NULL,
                original_amount numeric(18,4) NOT NULL,
                balance_amount numeric(18,4) NOT NULL DEFAULT 0,
                -- En soles; el tipo de cambio para llevarla a la moneda de la cuenta es el del comprobante.
                detraction_amount numeric(18,4) NOT NULL DEFAULT 0,
                detraction_deposited boolean NOT NULL DEFAULT false,
                status varchar(30) NOT NULL DEFAULT 'OPEN',
                owner_user_id uuid REFERENCES users(id) ON DELETE SET NULL,
                branch_id uuid REFERENCES branches(id) ON DELETE SET NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT payables_invoice_key UNIQUE (supplier_invoice_id),
                CONSTRAINT payables_currency_check CHECK (currency IN ('PEN', 'USD')),
                CONSTRAINT payables_status_check CHECK (status IN ('OPEN', 'PARTIAL', 'PAID', 'CANCELLED')),
                CONSTRAINT payables_balance_check CHECK (original_amount > 0 AND balance_amount >= 0 AND balance_amount <= original_amount)
            );
            CREATE INDEX IF NOT EXISTS idx_payables_tenant_status ON payables (tenant_id, status);
            CREATE INDEX IF NOT EXISTS idx_payables_supplier ON payables (tenant_id, supplier_id);
            CREATE INDEX IF NOT EXISTS idx_payables_due ON payables (tenant_id, due_date);
            CREATE INDEX IF NOT EXISTS idx_payables_owner ON payables (tenant_id, owner_user_id);
            DROP TRIGGER IF EXISTS trg_payables_updated_at ON payables;
            CREATE TRIGGER trg_payables_updated_at BEFORE UPDATE ON payables FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            ALTER TABLE payables ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON payables;
            CREATE POLICY tenant_isolation ON payables USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
            REVOKE ALL ON payables FROM erp_app;
            GRANT SELECT, INSERT, UPDATE ON payables TO erp_app;

            -- 6. Movimientos de la cuenta por pagar: de ahí sale su saldo.
            CREATE TABLE IF NOT EXISTS payable_movements (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                payable_id uuid NOT NULL REFERENCES payables(id) ON DELETE CASCADE,
                movement_type varchar(30) NOT NULL,
                amount numeric(18,4) NOT NULL,
                reference varchar(255),
                supplier_payment_id uuid,
                created_by uuid REFERENCES users(id) ON DELETE SET NULL,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT payable_movements_type_check CHECK (movement_type IN ('CHARGE', 'PAYMENT', 'DETRACTION', 'RETENTION_4TA')),
                -- El cargo suma al saldo; lo demás lo baja.
                CONSTRAINT payable_movements_sign_check CHECK (amount <> 0 AND (amount > 0) = (movement_type = 'CHARGE'))
            );
            CREATE INDEX IF NOT EXISTS idx_payable_movements_payable ON payable_movements (payable_id, created_at);
            ALTER TABLE payable_movements ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON payable_movements;
            CREATE POLICY tenant_isolation ON payable_movements USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
            REVOKE ALL ON payable_movements FROM erp_app;
            GRANT SELECT, INSERT ON payable_movements TO erp_app;

            CREATE OR REPLACE FUNCTION apply_payable_movement() RETURNS trigger AS $fn$
            DECLARE
                target payables%ROWTYPE;
                new_balance numeric(18,4);
            BEGIN
                SELECT * INTO target FROM payables WHERE id = NEW.payable_id FOR UPDATE;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'La cuenta por pagar del movimiento no existe' USING ERRCODE = 'foreign_key_violation';
                END IF;
                new_balance := target.balance_amount + NEW.amount;
                IF new_balance < 0 OR new_balance > target.original_amount THEN
                    RAISE EXCEPTION 'El movimiento deja el saldo de la cuenta por pagar fuera de su importe' USING ERRCODE = 'check_violation';
                END IF;
                UPDATE payables SET balance_amount = new_balance,
                    status = CASE WHEN new_balance = 0 THEN 'PAID' WHEN new_balance < target.original_amount THEN 'PARTIAL' ELSE 'OPEN' END
                WHERE id = target.id;
                RETURN NEW;
            END;
            $fn$ LANGUAGE plpgsql;
            DROP TRIGGER IF EXISTS trg_payable_movements_apply ON payable_movements;
            CREATE TRIGGER trg_payable_movements_apply AFTER INSERT ON payable_movements
                FOR EACH ROW EXECUTE FUNCTION apply_payable_movement();

            CREATE OR REPLACE FUNCTION protect_payable_balance() RETURNS trigger AS $fn$
            BEGIN
                IF NEW.balance_amount IS DISTINCT FROM OLD.balance_amount AND pg_trigger_depth() < 2 THEN
                    RAISE EXCEPTION 'El saldo de una cuenta por pagar solo cambia con un movimiento' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.original_amount IS DISTINCT FROM OLD.original_amount OR NEW.supplier_invoice_id IS DISTINCT FROM OLD.supplier_invoice_id
                   OR NEW.supplier_id IS DISTINCT FROM OLD.supplier_id OR NEW.currency IS DISTINCT FROM OLD.currency
                   OR NEW.detraction_amount IS DISTINCT FROM OLD.detraction_amount THEN
                    RAISE EXCEPTION 'El importe, el proveedor y el comprobante de una cuenta por pagar no se modifican' USING ERRCODE = 'check_violation';
                END IF;
                -- Una detracción depositada no vuelve a quedar pendiente.
                IF OLD.detraction_deposited AND NOT NEW.detraction_deposited THEN
                    RAISE EXCEPTION 'La detracción de esta cuenta por pagar ya fue depositada' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $fn$ LANGUAGE plpgsql;
            DROP TRIGGER IF EXISTS trg_payables_protect ON payables;
            CREATE TRIGGER trg_payables_protect BEFORE UPDATE ON payables
                FOR EACH ROW EXECUTE FUNCTION protect_payable_balance();

            -- 7. Pagos a proveedores y a qué cuentas se aplicaron.
            CREATE TABLE IF NOT EXISTS supplier_payments (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                payment_number varchar(50) NOT NULL,
                supplier_id uuid NOT NULL REFERENCES suppliers(id) ON DELETE RESTRICT,
                financial_account_id uuid NOT NULL REFERENCES financial_accounts(id) ON DELETE RESTRICT,
                -- Neto transferido al proveedor, en la moneda de la deuda.
                amount numeric(18,4) NOT NULL,
                currency varchar(10) NOT NULL DEFAULT 'PEN',
                -- Depósito de la detracción en el Banco de la Nación, en soles.
                detraction_amount numeric(18,4) NOT NULL DEFAULT 0,
                detraction_number varchar(100),
                detraction_account_id uuid REFERENCES financial_accounts(id) ON DELETE RESTRICT,
                payment_method varchar(30) NOT NULL,
                reference varchar(255),
                paid_at date NOT NULL,
                status varchar(30) NOT NULL DEFAULT 'PAID',
                owner_user_id uuid REFERENCES users(id) ON DELETE SET NULL,
                created_by uuid REFERENCES users(id) ON DELETE SET NULL,
                branch_id uuid REFERENCES branches(id) ON DELETE SET NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT supplier_payments_number_key UNIQUE (tenant_id, payment_number),
                CONSTRAINT supplier_payments_currency_check CHECK (currency IN ('PEN', 'USD')),
                CONSTRAINT supplier_payments_method_check CHECK (payment_method IN ('TRANSFERENCIA', 'CHEQUE', 'EFECTIVO')),
                CONSTRAINT supplier_payments_status_check CHECK (status IN ('PAID', 'REVERSED')),
                CONSTRAINT supplier_payments_amounts_check CHECK (amount >= 0 AND detraction_amount >= 0 AND amount + detraction_amount > 0),
                -- Un depósito de detracción lleva su constancia y la cuenta de la que salió.
                CONSTRAINT supplier_payments_detraction_check CHECK (detraction_amount = 0 OR (btrim(coalesce(detraction_number, '')) <> '' AND detraction_account_id IS NOT NULL))
            );
            CREATE INDEX IF NOT EXISTS idx_supplier_payments_supplier ON supplier_payments (tenant_id, supplier_id);
            CREATE INDEX IF NOT EXISTS idx_supplier_payments_paid_at ON supplier_payments (tenant_id, paid_at);
            ALTER TABLE supplier_payments ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON supplier_payments;
            CREATE POLICY tenant_isolation ON supplier_payments USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
            -- Un pago registrado ni se edita ni se borra.
            REVOKE ALL ON supplier_payments FROM erp_app;
            GRANT SELECT, INSERT ON supplier_payments TO erp_app;

            CREATE TABLE IF NOT EXISTS supplier_payment_allocations (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                supplier_payment_id uuid NOT NULL REFERENCES supplier_payments(id) ON DELETE CASCADE,
                payable_id uuid NOT NULL REFERENCES payables(id) ON DELETE RESTRICT,
                amount numeric(18,4) NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT supplier_payment_allocations_key UNIQUE (supplier_payment_id, payable_id),
                CONSTRAINT supplier_payment_allocations_amount_check CHECK (amount > 0)
            );
            CREATE INDEX IF NOT EXISTS idx_supplier_payment_allocations_payable ON supplier_payment_allocations (payable_id);
            ALTER TABLE supplier_payment_allocations ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON supplier_payment_allocations;
            CREATE POLICY tenant_isolation ON supplier_payment_allocations USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
            REVOKE ALL ON supplier_payment_allocations FROM erp_app;
            GRANT SELECT, INSERT ON supplier_payment_allocations TO erp_app;

            ALTER TABLE payable_movements DROP CONSTRAINT IF EXISTS payable_movements_payment_fkey;
            ALTER TABLE payable_movements ADD CONSTRAINT payable_movements_payment_fkey
                FOREIGN KEY (supplier_payment_id) REFERENCES supplier_payments(id) ON DELETE SET NULL;

            -- 8. Proveedor: su cuenta de detracciones en el Banco de la Nación, adonde se deposita lo detraído.
            ALTER TABLE suppliers ADD COLUMN IF NOT EXISTS detraction_account varchar(30);

            -- Un centro de costo usado en una orden o un comprobante no se borra: se desactiva.
            REVOKE DELETE ON cost_centers FROM erp_app;

            -- 9. Permisos por recurso.
            INSERT INTO permissions (code, name, description) VALUES
                ('purchases.view', 'Ver órdenes de compra', 'Órdenes de compra, su recepción y lo facturado de cada una.'),
                ('purchases.create', 'Crear órdenes de compra', 'Registrar una orden de compra en borrador.'),
                ('purchases.manage', 'Gestionar órdenes de compra', 'Editar el borrador, enviarlo a aprobación, registrar la recepción, cerrar y anular.'),
                ('purchases.approve', 'Aprobar órdenes de compra', 'Aprobar o rechazar una orden de compra enviada a aprobación.'),
                ('purchases.export', 'Exportar órdenes de compra', 'Descargar el listado en Excel o CSV.'),
                ('payables.view', 'Ver comprobantes de proveedor y cuentas por pagar', 'Comprobantes de proveedor, cuentas por pagar y pagos a proveedores.'),
                ('payables.create', 'Registrar comprobantes de proveedor', 'Registrar el comprobante que entrega un proveedor.'),
                ('payables.manage', 'Anular comprobantes de proveedor', 'Anular un comprobante de proveedor registrado o rechazado.'),
                ('payables.approve', 'Aprobar comprobantes y pagar a proveedores', 'Aprobar o rechazar un comprobante de proveedor y registrar pagos a proveedores.'),
                ('payables.export', 'Exportar cuentas por pagar', 'Descargar comprobantes de proveedor, cuentas por pagar y pagos en Excel o CSV.'),
                ('budgets.manage', 'Gestionar centros de costo', 'Crear centros de costo.')
            ON CONFLICT (code) DO NOTHING;

            UPDATE module_catalog SET permission_prefixes = permission_prefixes || '["purchases", "payables", "budgets"]'::jsonb
            WHERE upper(code) = 'FINANZAS' AND NOT permission_prefixes ? 'purchases';

            -- 10. Alcance por rol: también lo llevan las órdenes de compra y las cuentas por pagar.
            ALTER TABLE role_scopes DROP CONSTRAINT IF EXISTS role_scopes_resource_check;
            ALTER TABLE role_scopes ADD CONSTRAINT role_scopes_resource_check
                CHECK (resource IN ('quotations', 'contracts', 'orders', 'portal', 'leads', 'opportunities', 'activities',
                                    'contracted_services', 'work_orders', 'projects', 'time_entries', 'cost_entries', 'agenda',
                                    'billing', 'receivables', 'payments', 'recurring', 'purchases', 'payables'));

            -- 11. Reparto (matriz del prototipo). El Administrador de cada empresa: todo.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r
            JOIN permissions p ON p.code LIKE 'purchases.%' OR p.code LIKE 'payables.%' OR p.code = 'budgets.manage'
            WHERE r.tenant_id IS NOT NULL AND (r.is_system OR upper(r.code) = 'ADMIN')
            ON CONFLICT DO NOTHING;

            -- Gerencia: ve, aprueba y exporta; no crea ni registra.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r
            JOIN permissions p ON p.code IN ('purchases.view', 'purchases.approve', 'purchases.export', 'payables.view', 'payables.approve', 'payables.export')
            WHERE upper(r.code) = 'GERENCIA' AND r.tenant_id IS NOT NULL
            ON CONFLICT DO NOTHING;
            INSERT INTO role_scopes (role_id, tenant_id, resource, scope)
            SELECT r.id, r.tenant_id, s.resource, 'TENANT'
            FROM roles r CROSS JOIN (VALUES ('purchases'), ('payables')) AS s(resource)
            WHERE upper(r.code) IN ('GERENCIA', 'FINANZAS') AND r.tenant_id IS NOT NULL
            ON CONFLICT DO NOTHING;

            -- Operaciones: pide y recibe las compras de su sede; no las aprueba ni ve las cuentas por pagar.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r
            JOIN permissions p ON p.code IN ('purchases.view', 'purchases.create', 'purchases.manage')
            WHERE upper(r.code) = 'OPERACIONES' AND r.tenant_id IS NOT NULL
            ON CONFLICT DO NOTHING;
            INSERT INTO role_scopes (role_id, tenant_id, resource, scope)
            SELECT r.id, r.tenant_id, 'purchases', 'BRANCH' FROM roles r
            WHERE upper(r.code) = 'OPERACIONES' AND r.tenant_id IS NOT NULL
            ON CONFLICT DO NOTHING;

            -- Finanzas: pide y recibe compras (no las aprueba ni exporta) y lleva todo lo de cuentas por pagar.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r
            JOIN permissions p ON p.code IN ('purchases.view', 'purchases.create', 'purchases.manage', 'budgets.manage') OR p.code LIKE 'payables.%'
            WHERE upper(r.code) = 'FINANZAS' AND r.tenant_id IS NOT NULL
            ON CONFLICT DO NOTHING;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM role_scopes WHERE resource IN ('purchases', 'payables');
            ALTER TABLE role_scopes DROP CONSTRAINT IF EXISTS role_scopes_resource_check;
            ALTER TABLE role_scopes ADD CONSTRAINT role_scopes_resource_check
                CHECK (resource IN ('quotations', 'contracts', 'orders', 'portal', 'leads', 'opportunities', 'activities',
                                    'contracted_services', 'work_orders', 'projects', 'time_entries', 'cost_entries', 'agenda',
                                    'billing', 'receivables', 'payments', 'recurring'));
            UPDATE module_catalog SET permission_prefixes = permission_prefixes - 'purchases' - 'payables' - 'budgets' WHERE upper(code) = 'FINANZAS';
            DELETE FROM role_permissions WHERE permission_id IN (
                SELECT id FROM permissions WHERE code LIKE 'purchases.%' OR code LIKE 'payables.%' OR code = 'budgets.manage');
            DELETE FROM permissions WHERE code LIKE 'purchases.%' OR code LIKE 'payables.%' OR code = 'budgets.manage';

            GRANT DELETE ON cost_centers TO erp_app;
            ALTER TABLE suppliers DROP COLUMN IF EXISTS detraction_account;

            DROP TABLE IF EXISTS supplier_payment_allocations;
            DROP TABLE IF EXISTS payable_movements;
            DROP TABLE IF EXISTS supplier_payments;
            DROP TABLE IF EXISTS payables;
            DROP FUNCTION IF EXISTS apply_payable_movement();
            DROP FUNCTION IF EXISTS protect_payable_balance();
            DROP TABLE IF EXISTS supplier_invoices;
            DROP FUNCTION IF EXISTS protect_supplier_invoice();
            DROP TABLE IF EXISTS purchase_receipts;
            DROP FUNCTION IF EXISTS check_purchase_receipt();
            DROP TABLE IF EXISTS purchase_order_items;
            DROP FUNCTION IF EXISTS protect_purchase_order_items();
            DROP TABLE IF EXISTS purchase_orders;
            DROP FUNCTION IF EXISTS protect_purchase_order();
        SQL);
    }
};
