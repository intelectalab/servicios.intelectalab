<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reglas de aprobación (Administración): autorización interna por niveles de las cotizaciones
 * que superan un monto o un descuento, antes de enviarse.
 *
 * - quotation_authorizations: una solicitud por cotización (como mucho una pendiente), con el
 *   nivel exigido, los motivos y la huella de montos y descuentos con la que se pidió. Si la
 *   cotización cambia, la autorización se anula. Solo cambia su estado.
 * - quotation_authorization_steps: la decisión de cada nivel (solo se agrega). La base garantiza
 *   que quien creó la cotización no autoriza, que una misma persona no decide dos niveles y que
 *   rechazar lleva motivo.
 * - Permisos approvals.view (ver las reglas y lo pendiente) y approvals.manage (cambiar reglas y
 *   niveles). Administrador todo; Gerencia ve.
 *
 * Las reglas y los niveles viven en tenants.configuration.approval. Solo añade. Se ejecuta con
 * el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS quotation_authorizations (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                quotation_id uuid NOT NULL REFERENCES quotations(id) ON DELETE CASCADE,
                required_level smallint NOT NULL CHECK (required_level BETWEEN 1 AND 2),
                reasons jsonb NOT NULL DEFAULT '[]'::jsonb,
                signature varchar(64) NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'PENDING' CHECK (status IN ('PENDING', 'APPROVED', 'REJECTED', 'VOID')),
                requested_by uuid REFERENCES users(id) ON DELETE SET NULL,
                requested_at timestamptz NOT NULL DEFAULT now(),
                closed_at timestamptz,
                void_reason text,
                CONSTRAINT quotation_authorizations_void_reason CHECK (status <> 'VOID' OR btrim(coalesce(void_reason, '')) <> '')
            );
            CREATE UNIQUE INDEX IF NOT EXISTS quotation_authorizations_one_pending ON quotation_authorizations (quotation_id) WHERE status = 'PENDING';
            CREATE INDEX IF NOT EXISTS quotation_authorizations_quotation ON quotation_authorizations (quotation_id, requested_at DESC);
            ALTER TABLE quotation_authorizations ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON quotation_authorizations;
            CREATE POLICY tenant_isolation ON quotation_authorizations
                USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
            REVOKE ALL ON quotation_authorizations FROM erp_app;
            GRANT SELECT, INSERT, UPDATE ON quotation_authorizations TO erp_app;

            -- Lo pedido no cambia; el estado solo avanza desde Pendiente (o se anula una ya aprobada).
            CREATE OR REPLACE FUNCTION quotation_authorizations_guard() RETURNS trigger LANGUAGE plpgsql AS $fn$
            BEGIN
                IF NEW.quotation_id <> OLD.quotation_id OR NEW.tenant_id <> OLD.tenant_id OR NEW.required_level <> OLD.required_level
                   OR NEW.reasons <> OLD.reasons OR NEW.signature <> OLD.signature OR NEW.requested_at <> OLD.requested_at
                   OR NEW.requested_by IS DISTINCT FROM OLD.requested_by THEN
                    RAISE EXCEPTION 'Una solicitud de autorización no se modifica.' USING ERRCODE = '42501';
                END IF;
                IF NEW.status <> OLD.status AND NOT (OLD.status = 'PENDING' OR (OLD.status = 'APPROVED' AND NEW.status = 'VOID')) THEN
                    RAISE EXCEPTION 'La autorización ya está cerrada.' USING ERRCODE = '42501';
                END IF;
                RETURN NEW;
            END
            $fn$;
            DROP TRIGGER IF EXISTS trg_quotation_authorizations_guard ON quotation_authorizations;
            CREATE TRIGGER trg_quotation_authorizations_guard BEFORE UPDATE ON quotation_authorizations
                FOR EACH ROW EXECUTE FUNCTION quotation_authorizations_guard();

            CREATE TABLE IF NOT EXISTS quotation_authorization_steps (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                authorization_id uuid NOT NULL REFERENCES quotation_authorizations(id) ON DELETE CASCADE,
                level smallint NOT NULL CHECK (level BETWEEN 1 AND 2),
                decision varchar(20) NOT NULL CHECK (decision IN ('APPROVED', 'REJECTED')),
                user_id uuid NOT NULL REFERENCES users(id),
                comment text,
                created_at timestamptz NOT NULL DEFAULT now(),
                UNIQUE (authorization_id, level),
                CONSTRAINT quotation_authorization_steps_reject_reason CHECK (decision <> 'REJECTED' OR btrim(coalesce(comment, '')) <> '')
            );
            ALTER TABLE quotation_authorization_steps ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON quotation_authorization_steps;
            CREATE POLICY tenant_isolation ON quotation_authorization_steps
                USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
            REVOKE ALL ON quotation_authorization_steps FROM erp_app;
            GRANT SELECT, INSERT ON quotation_authorization_steps TO erp_app;

            -- Quien creó la cotización no la autoriza, y una misma persona no decide dos niveles.
            CREATE OR REPLACE FUNCTION quotation_authorization_steps_guard() RETURNS trigger LANGUAGE plpgsql AS $fn$
            BEGIN
                IF EXISTS (SELECT 1 FROM quotation_authorizations a JOIN quotations q ON q.id = a.quotation_id
                           WHERE a.id = NEW.authorization_id AND q.created_by = NEW.user_id) THEN
                    RAISE EXCEPTION 'Quien creó la cotización no la autoriza.' USING ERRCODE = '42501';
                END IF;
                IF EXISTS (SELECT 1 FROM quotation_authorization_steps s WHERE s.authorization_id = NEW.authorization_id AND s.user_id = NEW.user_id) THEN
                    RAISE EXCEPTION 'Una misma persona no autoriza dos niveles.' USING ERRCODE = '42501';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM quotation_authorizations a WHERE a.id = NEW.authorization_id AND a.status = 'PENDING') THEN
                    RAISE EXCEPTION 'La autorización ya está cerrada.' USING ERRCODE = '42501';
                END IF;
                RETURN NEW;
            END
            $fn$;
            DROP TRIGGER IF EXISTS trg_quotation_authorization_steps_guard ON quotation_authorization_steps;
            CREATE TRIGGER trg_quotation_authorization_steps_guard BEFORE INSERT ON quotation_authorization_steps
                FOR EACH ROW EXECUTE FUNCTION quotation_authorization_steps_guard();

            INSERT INTO permissions (code, name, description) VALUES
                ('approvals.view', 'Ver reglas de aprobación', 'Ver las reglas de autorización interna y las cotizaciones que la esperan.'),
                ('approvals.manage', 'Gestionar reglas de aprobación', 'Cambiar las reglas de autorización interna y quién autoriza cada nivel.')
            ON CONFLICT (code) DO NOTHING;

            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN ('approvals.view', 'approvals.manage')
            WHERE r.tenant_id IS NOT NULL AND (r.is_system OR upper(r.code) = 'ADMIN')
            ON CONFLICT DO NOTHING;

            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code = 'approvals.view'
            WHERE r.tenant_id IS NOT NULL AND upper(r.code) = 'GERENCIA'
            ON CONFLICT DO NOTHING;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM role_permissions WHERE permission_id IN (SELECT id FROM permissions WHERE code LIKE 'approvals.%');
            DELETE FROM permissions WHERE code LIKE 'approvals.%';
            DROP TABLE IF EXISTS quotation_authorization_steps;
            DROP FUNCTION IF EXISTS quotation_authorization_steps_guard();
            DROP TABLE IF EXISTS quotation_authorizations;
            DROP FUNCTION IF EXISTS quotation_authorizations_guard();
        SQL);
    }
};
