<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Firma electrónica de contratos (kit: RN-COM-09 y RN-COM-13; prototipo: Contratos y Portal → Firmas).
 *
 * - Solicitud de firma (`contract_signature_requests`): huella SHA-256 del documento al enviarlo,
 *   proveedor (SIMPLE: firma electrónica simple dentro del sistema), vence a los 15 días. Estados
 *   Pendiente → Firmada / Rechazada / Vencida / Anulada.
 * - Firmantes (`contract_signature_signers`): el Portal Admin del cliente y la empresa; cada firma guarda
 *   nombre escrito, código verificado, fecha, IP, navegador y su evidencia.
 * - `contracts.signature_status` (NONE, PENDING, SIGNED, DECLINED), como en el kit.
 *
 * La base garantiza: una sola solicitud pendiente por contrato; rechazar con motivo; una firma registrada
 * no cambia; ni solicitudes ni firmas se borran.
 *
 * Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS contract_signature_requests (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                contract_id uuid NOT NULL REFERENCES contracts(id) ON DELETE RESTRICT,
                customer_id uuid NOT NULL REFERENCES customers(id) ON DELETE RESTRICT,
                status varchar(15) NOT NULL DEFAULT 'PENDING',
                document_hash char(64) NOT NULL,
                provider varchar(30) NOT NULL DEFAULT 'SIMPLE',
                expires_at timestamptz NOT NULL,
                created_by uuid REFERENCES users(id) ON DELETE SET NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                completed_at timestamptz,
                declined_reason text,
                declined_at timestamptz,
                cancelled_at timestamptz,
                cancelled_by uuid REFERENCES users(id) ON DELETE SET NULL,
                updated_at timestamptz NOT NULL DEFAULT now(),
                lock_version integer NOT NULL DEFAULT 0,
                CONSTRAINT contract_signature_requests_status_check CHECK (status IN ('PENDING', 'SIGNED', 'DECLINED', 'EXPIRED', 'CANCELLED')),
                CONSTRAINT contract_signature_requests_hash_check CHECK (document_hash ~ '^[0-9a-f]{64}$'),
                CONSTRAINT contract_signature_requests_declined_check CHECK (status <> 'DECLINED' OR btrim(coalesce(declined_reason, '')) <> ''),
                CONSTRAINT contract_signature_requests_signed_check CHECK (status <> 'SIGNED' OR completed_at IS NOT NULL)
            );
            -- Una sola solicitud pendiente por contrato.
            CREATE UNIQUE INDEX IF NOT EXISTS ux_contract_signature_pending ON contract_signature_requests (contract_id) WHERE status = 'PENDING';
            CREATE INDEX IF NOT EXISTS idx_contract_signature_requests_customer ON contract_signature_requests (tenant_id, customer_id, status);

            CREATE TABLE IF NOT EXISTS contract_signature_signers (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                request_id uuid NOT NULL REFERENCES contract_signature_requests(id) ON DELETE RESTRICT,
                side varchar(10) NOT NULL,
                portal_user_id uuid REFERENCES portal_users(id) ON DELETE RESTRICT,
                user_id uuid REFERENCES users(id) ON DELETE SET NULL,
                name varchar(255),
                email varchar(255),
                status varchar(10) NOT NULL DEFAULT 'PENDING',
                typed_name varchar(255),
                otp_verified boolean NOT NULL DEFAULT false,
                signed_at timestamptz,
                ip_address varchar(64),
                user_agent varchar(500),
                evidence_hash char(64),
                CONSTRAINT contract_signature_signers_side_check CHECK (side IN ('CLIENTE', 'EMPRESA')),
                CONSTRAINT contract_signature_signers_status_check CHECK (status IN ('PENDING', 'SIGNED', 'DECLINED')),
                CONSTRAINT contract_signature_signers_customer_check CHECK (side <> 'CLIENTE' OR portal_user_id IS NOT NULL),
                -- Firmado: con quién, cuándo, el código verificado y su evidencia.
                CONSTRAINT contract_signature_signers_signed_check CHECK (status <> 'SIGNED' OR (
                    signed_at IS NOT NULL AND otp_verified AND evidence_hash ~ '^[0-9a-f]{64}$' AND user_id IS NOT NULL AND btrim(coalesce(typed_name, '')) <> ''
                )),
                CONSTRAINT contract_signature_signers_unique UNIQUE (request_id, side)
            );

            -- Una firma registrada no cambia; una solicitud cerrada tampoco vuelve a pendiente.
            CREATE OR REPLACE FUNCTION protect_contract_signature() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_TABLE_NAME = 'contract_signature_signers' AND OLD.status <> 'PENDING' THEN
                    RAISE EXCEPTION 'Una firma registrada no se modifica.';
                END IF;
                IF TG_TABLE_NAME = 'contract_signature_requests' THEN
                    IF OLD.status <> 'PENDING' AND NOT (OLD.status = 'DECLINED' AND NEW.status = 'CANCELLED') AND NEW.status IS DISTINCT FROM OLD.status THEN
                        RAISE EXCEPTION 'La solicitud de firma ya está cerrada.';
                    END IF;
                    IF NEW.document_hash <> OLD.document_hash OR NEW.contract_id <> OLD.contract_id THEN
                        RAISE EXCEPTION 'La huella y el contrato de una solicitud de firma no cambian.';
                    END IF;
                END IF;
                RETURN NEW;
            END $$;

            DROP TRIGGER IF EXISTS trg_contract_signature_signers_protect ON contract_signature_signers;
            CREATE TRIGGER trg_contract_signature_signers_protect BEFORE UPDATE ON contract_signature_signers
                FOR EACH ROW EXECUTE FUNCTION protect_contract_signature();
            DROP TRIGGER IF EXISTS trg_contract_signature_requests_protect ON contract_signature_requests;
            CREATE TRIGGER trg_contract_signature_requests_protect BEFORE UPDATE ON contract_signature_requests
                FOR EACH ROW EXECUTE FUNCTION protect_contract_signature();
            DROP TRIGGER IF EXISTS trg_contract_signature_requests_updated_at ON contract_signature_requests;
            CREATE TRIGGER trg_contract_signature_requests_updated_at BEFORE UPDATE ON contract_signature_requests
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();

            ALTER TABLE contract_signature_requests ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON contract_signature_requests;
            CREATE POLICY tenant_isolation ON contract_signature_requests USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
            ALTER TABLE contract_signature_signers ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON contract_signature_signers;
            CREATE POLICY tenant_isolation ON contract_signature_signers USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
            REVOKE ALL ON contract_signature_requests, contract_signature_signers FROM erp_app;
            GRANT SELECT, INSERT, UPDATE ON contract_signature_requests, contract_signature_signers TO erp_app;

            ALTER TABLE contracts ADD COLUMN IF NOT EXISTS signature_status varchar(10) NOT NULL DEFAULT 'NONE';
            ALTER TABLE contracts DROP CONSTRAINT IF EXISTS contracts_signature_status_check;
            ALTER TABLE contracts ADD CONSTRAINT contracts_signature_status_check CHECK (signature_status IN ('NONE', 'PENDING', 'SIGNED', 'DECLINED'));
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE contracts DROP CONSTRAINT IF EXISTS contracts_signature_status_check;
            ALTER TABLE contracts DROP COLUMN IF EXISTS signature_status;
            DROP TABLE IF EXISTS contract_signature_signers;
            DROP TABLE IF EXISTS contract_signature_requests;
            DROP FUNCTION IF EXISTS protect_contract_signature();
        SQL);
    }
};
