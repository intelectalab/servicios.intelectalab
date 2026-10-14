<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Finanzas: facturación electrónica — configuración fiscal, series editables, contingencia,
 * comunicaciones de baja, detracciones (SPOT) y retenciones del IGV.
 *
 * `fiscal_configurations` y `fiscal_series` ya existían: la configuración de la empresa (series,
 * modo de emisión, detracciones) va en `fiscal_configurations.configuration`. El comprobante
 * guarda su detracción o retención; el cliente, si es agente de retención.
 *
 * Lo que la base garantiza por sí sola:
 *   - la detracción o retención de un comprobante emitido no se modifica: solo se anota, una vez,
 *     la constancia o el certificado que entrega el cliente;
 *   - un comprobante tiene como mucho una comunicación de baja en curso o aceptada, siempre con
 *     motivo; las bajas no se borran;
 *   - las series no se borran (su numeración queda).
 *
 * Solo añade: no borra datos. Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- 1. Detracción o retención del comprobante. El importe va siempre en soles.
            ALTER TABLE invoices
                ADD COLUMN IF NOT EXISTS withholding_mode varchar(20) NOT NULL DEFAULT 'AUTO',
                ADD COLUMN IF NOT EXISTS withholding_type varchar(20),
                ADD COLUMN IF NOT EXISTS withholding_code varchar(10),
                ADD COLUMN IF NOT EXISTS withholding_rate numeric(6,3),
                ADD COLUMN IF NOT EXISTS withholding_amount numeric(18,4),
                ADD COLUMN IF NOT EXISTS withholding_status varchar(20),
                ADD COLUMN IF NOT EXISTS withholding_number varchar(100),
                ADD COLUMN IF NOT EXISTS withholding_date date,
                ADD COLUMN IF NOT EXISTS withholding_registered_by uuid REFERENCES users(id) ON DELETE SET NULL;
            ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_withholding_check;
            ALTER TABLE invoices ADD CONSTRAINT invoices_withholding_check CHECK (
                withholding_mode IN ('AUTO', 'NONE', 'DETRACTION', 'RETENTION')
                AND (withholding_type IS NULL OR withholding_type IN ('DETRACTION', 'RETENTION'))
                AND (withholding_type IS NULL) = (withholding_amount IS NULL)
                AND (withholding_type IS NULL) = (withholding_status IS NULL)
                AND (withholding_amount IS NULL OR withholding_amount > 0)
                AND (withholding_status IS NULL OR withholding_status IN ('PENDING', 'RECEIVED'))
                AND (withholding_status IS DISTINCT FROM 'RECEIVED' OR btrim(coalesce(withholding_number, '')) <> ''));
            CREATE INDEX IF NOT EXISTS idx_invoices_withholding_pending ON invoices (tenant_id) WHERE withholding_status = 'PENDING';

            -- Un comprobante emitido no se modifica; de su detracción o retención solo se anota la constancia, una vez.
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
                    OR NEW.withholding_mode IS DISTINCT FROM OLD.withholding_mode OR NEW.withholding_type IS DISTINCT FROM OLD.withholding_type
                    OR NEW.withholding_code IS DISTINCT FROM OLD.withholding_code OR NEW.withholding_rate IS DISTINCT FROM OLD.withholding_rate
                    OR NEW.withholding_amount IS DISTINCT FROM OLD.withholding_amount
                ) THEN
                    RAISE EXCEPTION 'Un comprobante emitido no se modifica: se corrige con una nota de crédito' USING ERRCODE = 'check_violation';
                END IF;
                IF OLD.status <> 'DRAFT' AND OLD.withholding_status = 'RECEIVED' AND (
                       NEW.withholding_status IS DISTINCT FROM OLD.withholding_status OR NEW.withholding_number IS DISTINCT FROM OLD.withholding_number
                    OR NEW.withholding_date IS DISTINCT FROM OLD.withholding_date
                ) THEN
                    RAISE EXCEPTION 'La constancia de una detracción o retención ya registrada no se modifica' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $fn$ LANGUAGE plpgsql;

            -- 2. Clientes que son agentes de retención del IGV.
            ALTER TABLE customers ADD COLUMN IF NOT EXISTS retention_agent boolean NOT NULL DEFAULT false;

            -- 3. Comunicaciones de baja.
            CREATE TABLE IF NOT EXISTS fiscal_void_requests (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                invoice_id uuid NOT NULL REFERENCES invoices(id) ON DELETE RESTRICT,
                invoice_number varchar(100) NOT NULL,
                reason text NOT NULL,
                ticket varchar(100),
                status varchar(20) NOT NULL DEFAULT 'PENDING',
                response text,
                requested_by uuid REFERENCES users(id) ON DELETE SET NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                resolved_at timestamptz,
                CONSTRAINT fiscal_void_requests_status_check CHECK (status IN ('PENDING', 'ACCEPTED', 'REJECTED')),
                CONSTRAINT fiscal_void_requests_reason_check CHECK (btrim(reason) <> '')
            );
            -- Una sola baja en curso o aceptada por comprobante; una rechazada se puede volver a pedir.
            CREATE UNIQUE INDEX IF NOT EXISTS ux_fiscal_void_requests_live ON fiscal_void_requests (invoice_id) WHERE status IN ('PENDING', 'ACCEPTED');
            CREATE INDEX IF NOT EXISTS idx_fiscal_void_requests_tenant ON fiscal_void_requests (tenant_id, created_at);
            ALTER TABLE fiscal_void_requests ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON fiscal_void_requests;
            CREATE POLICY tenant_isolation ON fiscal_void_requests
                USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
            REVOKE ALL ON fiscal_void_requests FROM erp_app;
            GRANT SELECT, INSERT, UPDATE ON fiscal_void_requests TO erp_app;

            -- 4. Las series no se borran: su numeración queda aunque se deje de usar.
            REVOKE DELETE ON fiscal_series FROM erp_app;

            -- 5. Permisos. Configurar la facturación electrónica es aparte de registrar el tipo de cambio.
            INSERT INTO permissions (code, name, description) VALUES
                ('fiscal.configure', 'Configurar facturación electrónica', 'Proveedor, RUC emisor, series, detracciones y emisión en contingencia.')
            ON CONFLICT (code) DO NOTHING;
            UPDATE permissions SET name = 'Ver facturación electrónica y tipo de cambio', description = 'Configuración fiscal, contingencia, bajas, monedas y tipo de cambio registrado.' WHERE code = 'fiscal.view';

            -- El Administrador de cada empresa: todo.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r JOIN permissions p ON p.code = 'fiscal.configure'
            WHERE r.tenant_id IS NOT NULL AND (r.is_system OR upper(r.code) = 'ADMIN')
            ON CONFLICT DO NOTHING;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM role_permissions WHERE permission_id IN (SELECT id FROM permissions WHERE code = 'fiscal.configure');
            DELETE FROM permissions WHERE code = 'fiscal.configure';
            GRANT DELETE ON fiscal_series TO erp_app;
            DROP TABLE IF EXISTS fiscal_void_requests;
            ALTER TABLE customers DROP COLUMN IF EXISTS retention_agent;

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

            DROP INDEX IF EXISTS idx_invoices_withholding_pending;
            ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_withholding_check;
            ALTER TABLE invoices
                DROP COLUMN IF EXISTS withholding_mode, DROP COLUMN IF EXISTS withholding_type, DROP COLUMN IF EXISTS withholding_code,
                DROP COLUMN IF EXISTS withholding_rate, DROP COLUMN IF EXISTS withholding_amount, DROP COLUMN IF EXISTS withholding_status,
                DROP COLUMN IF EXISTS withholding_number, DROP COLUMN IF EXISTS withholding_date, DROP COLUMN IF EXISTS withholding_registered_by;
        SQL);
    }
};
