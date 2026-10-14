<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tesorería (Bancos y caja), lo que pedía el kit y faltaba:
 *   - un gasto de caja chica lleva centro de costo y cuenta de gasto del plan (los gastos ya
 *     registrados con texto libre se conservan tal cual: la regla vale para los nuevos);
 *   - el extracto importado guarda su archivo (en Documentos, con DocumentStore);
 *   - cada transferencia tiene su registro, y la base garantiza: cuentas distintas, de la misma
 *     moneda, activas, con saldo suficiente en la de origen, y que sus dos movimientos son
 *     exactamente el egreso de una y el ingreso de la otra.
 *
 * Solo añade: no borra datos. Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- 1. Caja chica: centro de costo y cuenta de gasto del plan, obligatorios en los gastos nuevos.
            ALTER TABLE cash_expenses ADD COLUMN IF NOT EXISTS cost_center_id uuid REFERENCES cost_centers(id) ON DELETE RESTRICT;
            ALTER TABLE cash_expenses DROP CONSTRAINT IF EXISTS cash_expenses_classified_check;
            -- NOT VALID: los gastos anteriores (texto libre) no se revisan; todo gasto nuevo debe cumplirla.
            ALTER TABLE cash_expenses ADD CONSTRAINT cash_expenses_classified_check
                CHECK (cost_center_id IS NOT NULL AND expense_account IS NOT NULL) NOT VALID;
            ALTER TABLE cash_expenses DROP CONSTRAINT IF EXISTS cash_expenses_account_fkey;
            ALTER TABLE cash_expenses ADD CONSTRAINT cash_expenses_account_fkey
                FOREIGN KEY (tenant_id, expense_account) REFERENCES acc_accounts(tenant_id, code) ON DELETE RESTRICT NOT VALID;

            -- 2. El archivo del extracto, guardado como documento.
            ALTER TABLE bank_statements ADD COLUMN IF NOT EXISTS document_id uuid REFERENCES documents(id) ON DELETE SET NULL;

            -- 3. Transferencias entre cuentas propias.
            CREATE TABLE IF NOT EXISTS bank_transfers (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                from_account_id uuid NOT NULL REFERENCES financial_accounts(id) ON DELETE RESTRICT,
                to_account_id uuid NOT NULL REFERENCES financial_accounts(id) ON DELETE RESTRICT,
                amount numeric(18,2) NOT NULL,
                currency varchar(10) NOT NULL,
                reference varchar(255),
                transfer_date date NOT NULL,
                kind varchar(20) NOT NULL DEFAULT 'TRANSFER',
                created_by uuid REFERENCES users(id) ON DELETE SET NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT bank_transfers_accounts_check CHECK (from_account_id <> to_account_id),
                CONSTRAINT bank_transfers_amount_check CHECK (amount > 0),
                CONSTRAINT bank_transfers_currency_check CHECK (currency IN ('PEN', 'USD')),
                CONSTRAINT bank_transfers_kind_check CHECK (kind IN ('TRANSFER', 'REFILL'))
            );
            CREATE INDEX IF NOT EXISTS idx_bank_transfers_from ON bank_transfers (tenant_id, from_account_id, transfer_date);
            CREATE INDEX IF NOT EXISTS idx_bank_transfers_to ON bank_transfers (tenant_id, to_account_id, transfer_date);
            ALTER TABLE bank_transfers ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON bank_transfers;
            CREATE POLICY tenant_isolation ON bank_transfers USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
            -- Una transferencia registrada ni se edita ni se borra.
            REVOKE ALL ON bank_transfers FROM erp_app;
            GRANT SELECT, INSERT ON bank_transfers TO erp_app;

            -- Las transferencias que ya existían (pares de movimientos con la misma referencia), antes de activar los controles.
            INSERT INTO bank_transfers (id, tenant_id, from_account_id, to_account_id, amount, currency, reference, transfer_date, kind, created_by, created_at)
            SELECT o.ref_id, o.tenant_id, o.account_id, i.account_id, abs(o.amount), a.currency, o.op_number, o.movement_date,
                   CASE WHEN o.op_number LIKE 'REPOSICION-%' THEN 'REFILL' ELSE 'TRANSFER' END, o.created_by, o.created_at
            FROM bank_movements o
            JOIN bank_movements i ON i.ref_type = 'TRANSFER' AND i.ref_id = o.ref_id AND i.amount > 0
            JOIN financial_accounts a ON a.id = o.account_id
            WHERE o.ref_type = 'TRANSFER' AND o.amount < 0 AND o.ref_id IS NOT NULL
            ON CONFLICT (id) DO NOTHING;

            -- Misma moneda, cuentas activas que no son de detracciones, y saldo suficiente en la de origen.
            CREATE OR REPLACE FUNCTION check_bank_transfer() RETURNS trigger AS $fn$
            DECLARE
                source financial_accounts%ROWTYPE;
                target financial_accounts%ROWTYPE;
                available numeric;
            BEGIN
                -- Las dos cuentas se bloquean siempre en el mismo orden.
                PERFORM 1 FROM financial_accounts WHERE id IN (NEW.from_account_id, NEW.to_account_id) ORDER BY id FOR UPDATE;
                SELECT * INTO source FROM financial_accounts WHERE id = NEW.from_account_id;
                SELECT * INTO target FROM financial_accounts WHERE id = NEW.to_account_id;
                IF source.tenant_id IS DISTINCT FROM NEW.tenant_id OR target.tenant_id IS DISTINCT FROM NEW.tenant_id THEN
                    RAISE EXCEPTION 'Las cuentas de la transferencia no son de esta empresa' USING ERRCODE = 'check_violation';
                END IF;
                IF source.currency <> target.currency OR source.currency <> NEW.currency THEN
                    RAISE EXCEPTION 'Una transferencia es entre cuentas de la misma moneda' USING ERRCODE = 'check_violation';
                END IF;
                IF source.status <> 'ACTIVE' OR target.status <> 'ACTIVE' OR source.account_type = 'DETRACTION' OR target.account_type = 'DETRACTION' THEN
                    RAISE EXCEPTION 'Una transferencia es entre cuentas activas que no son de detracciones' USING ERRCODE = 'check_violation';
                END IF;
                SELECT coalesce(sum(amount), 0) INTO available FROM bank_movements WHERE account_id = NEW.from_account_id;
                IF available < NEW.amount THEN
                    RAISE EXCEPTION 'Saldo insuficiente en la cuenta de origen' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $fn$ LANGUAGE plpgsql;
            DROP TRIGGER IF EXISTS trg_bank_transfers_check ON bank_transfers;
            CREATE TRIGGER trg_bank_transfers_check BEFORE INSERT ON bank_transfers FOR EACH ROW EXECUTE FUNCTION check_bank_transfer();

            -- Un movimiento de transferencia corresponde a una transferencia registrada: egreso en el origen, ingreso en el destino, por su importe.
            CREATE OR REPLACE FUNCTION check_transfer_movement() RETURNS trigger AS $fn$
            DECLARE
                transfer bank_transfers%ROWTYPE;
            BEGIN
                IF NEW.ref_type <> 'TRANSFER' THEN
                    RETURN NEW;
                END IF;
                SELECT * INTO transfer FROM bank_transfers WHERE id = NEW.ref_id;
                IF NOT FOUND
                   OR NOT ((NEW.account_id = transfer.from_account_id AND NEW.amount = -transfer.amount)
                        OR (NEW.account_id = transfer.to_account_id AND NEW.amount = transfer.amount)) THEN
                    RAISE EXCEPTION 'El movimiento no corresponde a una transferencia registrada' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $fn$ LANGUAGE plpgsql;
            DROP TRIGGER IF EXISTS trg_bank_movements_transfer ON bank_movements;
            CREATE TRIGGER trg_bank_movements_transfer BEFORE INSERT ON bank_movements FOR EACH ROW EXECUTE FUNCTION check_transfer_movement();
            -- Una transferencia deja un solo movimiento por cuenta.
            CREATE UNIQUE INDEX IF NOT EXISTS ux_bank_movements_transfer ON bank_movements (ref_id, account_id) WHERE ref_type = 'TRANSFER';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS ux_bank_movements_transfer;
            DROP TRIGGER IF EXISTS trg_bank_movements_transfer ON bank_movements;
            DROP FUNCTION IF EXISTS check_transfer_movement();
            DROP TABLE IF EXISTS bank_transfers;
            DROP FUNCTION IF EXISTS check_bank_transfer();
            ALTER TABLE bank_statements DROP COLUMN IF EXISTS document_id;
            ALTER TABLE cash_expenses DROP CONSTRAINT IF EXISTS cash_expenses_account_fkey;
            ALTER TABLE cash_expenses DROP CONSTRAINT IF EXISTS cash_expenses_classified_check;
            ALTER TABLE cash_expenses DROP COLUMN IF EXISTS cost_center_id;
        SQL);
    }
};
