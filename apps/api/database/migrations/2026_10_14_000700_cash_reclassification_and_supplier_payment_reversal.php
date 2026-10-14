<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Correcciones que no borran nada:
 *
 * 1. Gasto de caja chica: su centro de costo o su cuenta de gasto se corrigen dejando un registro
 *    de la corrección (antes, después, motivo y asiento). La base solo deja cambiar esos datos si la
 *    corrección quedó registrada en la misma operación; el importe, la fecha y la caja no cambian.
 * 2. Pago a proveedor: se revierte con motivo. La cuenta por pagar recupera su saldo con un
 *    movimiento (PAYMENT_REVERSAL), el dinero vuelve a la cuenta con otro movimiento y el asiento se
 *    extorna. Un pago que incluyó el depósito de la detracción no se revierte (la constancia es de SUNAT).
 *
 * Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- 1. Correcciones de clasificación de un gasto de caja.
            CREATE TABLE IF NOT EXISTS cash_expense_reclassifications (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                cash_expense_id uuid NOT NULL REFERENCES cash_expenses(id) ON DELETE RESTRICT,
                old_cost_center_id uuid REFERENCES cost_centers(id) ON DELETE RESTRICT,
                new_cost_center_id uuid NOT NULL REFERENCES cost_centers(id) ON DELETE RESTRICT,
                old_account varchar(100),
                new_account varchar(100) NOT NULL,
                reason text NOT NULL,
                reversed_entry_id uuid REFERENCES acc_journal_entries(id) ON DELETE RESTRICT,
                new_entry_id uuid REFERENCES acc_journal_entries(id) ON DELETE RESTRICT,
                created_by uuid REFERENCES users(id) ON DELETE SET NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT cash_expense_reclassifications_reason_check CHECK (btrim(reason) <> ''),
                CONSTRAINT cash_expense_reclassifications_change_check
                    CHECK (old_cost_center_id IS DISTINCT FROM new_cost_center_id OR old_account IS DISTINCT FROM new_account)
            );
            CREATE INDEX IF NOT EXISTS idx_cash_expense_reclassifications_expense ON cash_expense_reclassifications (cash_expense_id, created_at);
            ALTER TABLE cash_expense_reclassifications ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON cash_expense_reclassifications;
            CREATE POLICY tenant_isolation ON cash_expense_reclassifications USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
            REVOKE ALL ON cash_expense_reclassifications FROM erp_app;
            GRANT SELECT, INSERT ON cash_expense_reclassifications TO erp_app;
            -- El asiento nuevo se anota después de contabilizarlo, una sola vez; lo demás no cambia.
            GRANT UPDATE (new_entry_id) ON cash_expense_reclassifications TO erp_app;
            CREATE OR REPLACE FUNCTION protect_cash_expense_reclassification() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.new_entry_id IS NOT NULL THEN
                    RAISE EXCEPTION 'Una corrección registrada no cambia' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$;
            DROP TRIGGER IF EXISTS trg_cash_expense_reclassifications_protect ON cash_expense_reclassifications;
            CREATE TRIGGER trg_cash_expense_reclassifications_protect BEFORE UPDATE ON cash_expense_reclassifications
                FOR EACH ROW EXECUTE FUNCTION protect_cash_expense_reclassification();

            -- La aplicación solo puede tocar la clasificación del gasto, y solo con su corrección registrada.
            GRANT UPDATE (cost_center_id, expense_account, cost_center) ON cash_expenses TO erp_app;
            CREATE OR REPLACE FUNCTION protect_cash_expense_classification() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.cost_center_id IS DISTINCT FROM OLD.cost_center_id OR NEW.expense_account IS DISTINCT FROM OLD.expense_account THEN
                    IF NOT EXISTS (
                        SELECT 1 FROM cash_expense_reclassifications r
                        WHERE r.cash_expense_id = NEW.id AND r.created_at = now()
                          AND r.new_cost_center_id = NEW.cost_center_id AND r.new_account = NEW.expense_account
                    ) THEN
                        RAISE EXCEPTION 'La clasificación de un gasto de caja se corrige registrando la corrección' USING ERRCODE = 'check_violation';
                    END IF;
                END IF;
                IF NEW.amount IS DISTINCT FROM OLD.amount OR NEW.expense_date IS DISTINCT FROM OLD.expense_date
                   OR NEW.account_id IS DISTINCT FROM OLD.account_id OR NEW.expense_number IS DISTINCT FROM OLD.expense_number THEN
                    RAISE EXCEPTION 'El importe, la fecha y la caja de un gasto no se modifican' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$;
            DROP TRIGGER IF EXISTS trg_cash_expenses_protect ON cash_expenses;
            CREATE TRIGGER trg_cash_expenses_protect BEFORE UPDATE ON cash_expenses FOR EACH ROW EXECUTE FUNCTION protect_cash_expense_classification();

            -- 2. Reversión de un pago a proveedor.
            ALTER TABLE supplier_payments ADD COLUMN IF NOT EXISTS reversed_at timestamptz;
            ALTER TABLE supplier_payments ADD COLUMN IF NOT EXISTS reversed_by uuid REFERENCES users(id) ON DELETE SET NULL;
            ALTER TABLE supplier_payments ADD COLUMN IF NOT EXISTS reversal_reason text;
            ALTER TABLE supplier_payments DROP CONSTRAINT IF EXISTS supplier_payments_reversed_check;
            ALTER TABLE supplier_payments ADD CONSTRAINT supplier_payments_reversed_check
                CHECK (status = 'PAID' OR (reversed_at IS NOT NULL AND btrim(coalesce(reversal_reason, '')) <> '' AND detraction_amount = 0));
            GRANT UPDATE (status, reversed_at, reversed_by, reversal_reason) ON supplier_payments TO erp_app;
            CREATE OR REPLACE FUNCTION protect_supplier_payment() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.status = 'REVERSED' THEN
                    RAISE EXCEPTION 'Un pago revertido no cambia' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$;
            DROP TRIGGER IF EXISTS trg_supplier_payments_protect ON supplier_payments;
            CREATE TRIGGER trg_supplier_payments_protect BEFORE UPDATE ON supplier_payments FOR EACH ROW EXECUTE FUNCTION protect_supplier_payment();

            ALTER TABLE payable_movements DROP CONSTRAINT IF EXISTS payable_movements_type_check;
            ALTER TABLE payable_movements ADD CONSTRAINT payable_movements_type_check
                CHECK (movement_type IN ('CHARGE', 'PAYMENT', 'DETRACTION', 'RETENTION_4TA', 'PAYMENT_REVERSAL'));
            ALTER TABLE payable_movements DROP CONSTRAINT IF EXISTS payable_movements_sign_check;
            ALTER TABLE payable_movements ADD CONSTRAINT payable_movements_sign_check
                CHECK (amount <> 0 AND (amount > 0) = (movement_type IN ('CHARGE', 'PAYMENT_REVERSAL')));
            -- Una reversión corresponde a un pago revertido.
            ALTER TABLE payable_movements DROP CONSTRAINT IF EXISTS payable_movements_reversal_check;
            ALTER TABLE payable_movements ADD CONSTRAINT payable_movements_reversal_check
                CHECK (movement_type <> 'PAYMENT_REVERSAL' OR supplier_payment_id IS NOT NULL);

            ALTER TABLE bank_movements DROP CONSTRAINT IF EXISTS bank_movements_ref_type_check;
            ALTER TABLE bank_movements ADD CONSTRAINT bank_movements_ref_type_check CHECK (ref_type IN (
                'OPENING', 'PAYMENT', 'REFUND', 'TRANSFER', 'CASH_EXPENSE', 'BANK_FEE', 'DETRACTION', 'UNIDENTIFIED',
                'SUPPLIER_PAYMENT', 'MANUAL', 'SUPPLIER_PAYMENT_REVERSAL'));
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE bank_movements DROP CONSTRAINT IF EXISTS bank_movements_ref_type_check;
            ALTER TABLE bank_movements ADD CONSTRAINT bank_movements_ref_type_check CHECK (ref_type IN (
                'OPENING', 'PAYMENT', 'REFUND', 'TRANSFER', 'CASH_EXPENSE', 'BANK_FEE', 'DETRACTION', 'UNIDENTIFIED', 'SUPPLIER_PAYMENT', 'MANUAL'));
            ALTER TABLE payable_movements DROP CONSTRAINT IF EXISTS payable_movements_reversal_check;
            ALTER TABLE payable_movements DROP CONSTRAINT IF EXISTS payable_movements_sign_check;
            ALTER TABLE payable_movements ADD CONSTRAINT payable_movements_sign_check CHECK (amount <> 0 AND (amount > 0) = (movement_type = 'CHARGE'));
            ALTER TABLE payable_movements DROP CONSTRAINT IF EXISTS payable_movements_type_check;
            ALTER TABLE payable_movements ADD CONSTRAINT payable_movements_type_check
                CHECK (movement_type IN ('CHARGE', 'PAYMENT', 'DETRACTION', 'RETENTION_4TA'));
            DROP TRIGGER IF EXISTS trg_supplier_payments_protect ON supplier_payments;
            DROP FUNCTION IF EXISTS protect_supplier_payment();
            REVOKE UPDATE ON supplier_payments FROM erp_app;
            ALTER TABLE supplier_payments DROP CONSTRAINT IF EXISTS supplier_payments_reversed_check;
            ALTER TABLE supplier_payments DROP COLUMN IF EXISTS reversal_reason;
            ALTER TABLE supplier_payments DROP COLUMN IF EXISTS reversed_by;
            ALTER TABLE supplier_payments DROP COLUMN IF EXISTS reversed_at;
            DROP TRIGGER IF EXISTS trg_cash_expenses_protect ON cash_expenses;
            DROP FUNCTION IF EXISTS protect_cash_expense_classification();
            REVOKE UPDATE ON cash_expenses FROM erp_app;
            DROP TABLE IF EXISTS cash_expense_reclassifications;
        SQL);
    }
};
