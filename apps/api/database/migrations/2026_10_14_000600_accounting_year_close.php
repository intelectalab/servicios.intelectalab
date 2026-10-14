<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cierre del ejercicio contable.
 *
 * - `acc_fiscal_years`: un registro por año y empresa, abierto o cerrado, con su asiento de cierre.
 * - El asiento de cierre (`YEAR_CLOSE`) lleva al 31 de diciembre las cuentas de ingresos y gastos a
 *   resultados acumulados: la utilidad a 5911 y la pérdida a 5921 (cuenta nueva del plan). Hay uno
 *   vigente por año; reabrir el ejercicio lo extorna.
 * - Importar asientos al libro diario deja asientos manuales con origen `IMPORT` (para distinguirlos).
 *
 * Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS acc_fiscal_years (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                fiscal_year integer NOT NULL,
                status varchar(10) NOT NULL DEFAULT 'OPEN',
                closing_entry_id uuid REFERENCES acc_journal_entries(id) ON DELETE RESTRICT,
                result numeric(16, 2),
                closed_at timestamptz,
                closed_by uuid REFERENCES users(id) ON DELETE SET NULL,
                reason text,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT acc_fiscal_years_unique UNIQUE (tenant_id, fiscal_year),
                CONSTRAINT acc_fiscal_years_year_check CHECK (fiscal_year BETWEEN 2000 AND 2999),
                CONSTRAINT acc_fiscal_years_status_check CHECK (status IN ('OPEN', 'CLOSED')),
                CONSTRAINT acc_fiscal_years_closed_check CHECK (status = 'OPEN' OR closed_at IS NOT NULL)
            );
            ALTER TABLE acc_fiscal_years ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON acc_fiscal_years;
            CREATE POLICY tenant_isolation ON acc_fiscal_years USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
            REVOKE ALL ON acc_fiscal_years FROM erp_app;
            GRANT SELECT, INSERT, UPDATE ON acc_fiscal_years TO erp_app;
            DROP TRIGGER IF EXISTS trg_acc_fiscal_years_updated_at ON acc_fiscal_years;
            CREATE TRIGGER trg_acc_fiscal_years_updated_at BEFORE UPDATE ON acc_fiscal_years FOR EACH ROW EXECUTE FUNCTION set_updated_at();

            -- Orígenes nuevos del libro diario.
            ALTER TABLE acc_journal_entries DROP CONSTRAINT IF EXISTS acc_journal_entries_source_check;
            ALTER TABLE acc_journal_entries ADD CONSTRAINT acc_journal_entries_source_check CHECK (source_type IN (
                'MANUAL', 'REVERSAL', 'OPENING', 'INVOICE', 'PAYMENT', 'REFUND', 'WITHHOLDING', 'FX',
                'SUPPLIER_INVOICE', 'SUPPLIER_PAYMENT', 'CASH_EXPENSE', 'BANK_FEE', 'UNIDENTIFIED', 'TRANSFER',
                'YEAR_CLOSE', 'IMPORT'));
            -- El asiento de cierre cae el 31 de diciembre.
            ALTER TABLE acc_journal_entries DROP CONSTRAINT IF EXISTS acc_journal_entries_year_close_check;
            ALTER TABLE acc_journal_entries ADD CONSTRAINT acc_journal_entries_year_close_check
                CHECK (source_type <> 'YEAR_CLOSE' OR to_char(entry_date, 'MM-DD') = '12-31');
            -- Un asiento importado se distingue como manual: varios por archivo, sin documento de origen.
            DROP INDEX IF EXISTS ux_acc_journal_entries_source;
            CREATE UNIQUE INDEX ux_acc_journal_entries_source ON acc_journal_entries (tenant_id, source_type, source_id)
                WHERE status = 'POSTED' AND source_id IS NOT NULL AND source_type NOT IN ('MANUAL', 'REVERSAL', 'IMPORT');

            -- Pérdidas acumuladas, para el cierre de un ejercicio con pérdida (en las empresas que ya tienen plan).
            INSERT INTO acc_accounts (tenant_id, code, name, account_type, nature, postable, parent_code, active, is_system)
            SELECT DISTINCT a.tenant_id, '5921', 'Pérdidas acumuladas', 'EQUITY', 'C', true, '59', true, true
            FROM acc_accounts a
            WHERE a.code = '59'
            ON CONFLICT DO NOTHING;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS ux_acc_journal_entries_source;
            CREATE UNIQUE INDEX ux_acc_journal_entries_source ON acc_journal_entries (tenant_id, source_type, source_id)
                WHERE status = 'POSTED' AND source_id IS NOT NULL AND source_type NOT IN ('MANUAL', 'REVERSAL');
            ALTER TABLE acc_journal_entries DROP CONSTRAINT IF EXISTS acc_journal_entries_year_close_check;
            ALTER TABLE acc_journal_entries DROP CONSTRAINT IF EXISTS acc_journal_entries_source_check;
            ALTER TABLE acc_journal_entries ADD CONSTRAINT acc_journal_entries_source_check CHECK (source_type IN (
                'MANUAL', 'REVERSAL', 'OPENING', 'INVOICE', 'PAYMENT', 'REFUND', 'WITHHOLDING', 'FX',
                'SUPPLIER_INVOICE', 'SUPPLIER_PAYMENT', 'CASH_EXPENSE', 'BANK_FEE', 'UNIDENTIFIED', 'TRANSFER'));
            DROP TABLE IF EXISTS acc_fiscal_years;
        SQL);
    }
};
