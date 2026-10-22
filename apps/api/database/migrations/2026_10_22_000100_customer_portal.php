<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Portal de clientes: el cliente entra con su propia cuenta y ve solo lo de su empresa.
 *
 * - `portal_users` (ya existía, sin uso) recibe rol del portal (Portal Admin, Portal Finanzas,
 *   Solo lectura), invitación por código, quién invitó, fechas y `lock_version`.
 * - La base garantiza: una cuenta es del sistema o del portal, nunca de los dos (ni de la
 *   plataforma); un usuario del portal no cambia de cliente ni de empresa; el cliente es de la
 *   misma empresa; como mucho 10 usuarios vigentes por cliente; nada se borra.
 * - `resolve_portal_tenant_for_user` y `portal_membership_for_user`: con qué empresa entra un
 *   usuario del portal (las rutas internas siguen usando `resolve_active_tenant_for_user`, así
 *   que una sesión del portal no abre ninguna pantalla interna).
 * - Avisos del portal (`portal_notices`): a un cliente o a todos; se retiran, no se borran.
 * - Solicitudes: por dónde se atendieron (bandeja o CRM). Decisiones de cotización: si la tomó
 *   la empresa o el cliente desde el portal.
 * - Módulo del plan "Portal de clientes" (PORTAL) en Base, Profesional y Enterprise, y en las
 *   empresas con suscripción vigente a esos planes. Permisos `portal_users.*` (Ver, Crear =
 *   invitar, Gestionar = rol, estado y avisos, Exportar): Administrador todo, Gerencia ve.
 *
 * Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- 1. Usuarios del portal.
            ALTER TABLE portal_users
                ADD COLUMN IF NOT EXISTS role varchar(20) NOT NULL DEFAULT 'PORTAL_VIEWER',
                ADD COLUMN IF NOT EXISTS invite_code_hash text,
                ADD COLUMN IF NOT EXISTS invite_expires_at timestamptz,
                ADD COLUMN IF NOT EXISTS invited_by uuid REFERENCES users(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS invited_by_type varchar(10),
                ADD COLUMN IF NOT EXISTS invited_at timestamptz,
                ADD COLUMN IF NOT EXISTS activated_at timestamptz,
                ADD COLUMN IF NOT EXISTS deactivated_at timestamptz,
                ADD COLUMN IF NOT EXISTS updated_at timestamptz NOT NULL DEFAULT now(),
                ADD COLUMN IF NOT EXISTS lock_version integer NOT NULL DEFAULT 0;
            ALTER TABLE portal_users ALTER COLUMN status SET DEFAULT 'INVITED';

            ALTER TABLE portal_users DROP CONSTRAINT IF EXISTS portal_users_role_check;
            ALTER TABLE portal_users ADD CONSTRAINT portal_users_role_check
                CHECK (role IN ('PORTAL_ADMIN', 'PORTAL_FINANCE', 'PORTAL_VIEWER'));
            ALTER TABLE portal_users DROP CONSTRAINT IF EXISTS portal_users_status_check;
            ALTER TABLE portal_users ADD CONSTRAINT portal_users_status_check
                CHECK (status IN ('INVITED', 'ACTIVE', 'INACTIVE'));
            ALTER TABLE portal_users DROP CONSTRAINT IF EXISTS portal_users_invited_by_type_check;
            ALTER TABLE portal_users ADD CONSTRAINT portal_users_invited_by_type_check
                CHECK (invited_by_type IS NULL OR invited_by_type IN ('STAFF', 'PORTAL'));
            -- Una invitación pendiente tiene su código; una cuenta activa ya no.
            ALTER TABLE portal_users DROP CONSTRAINT IF EXISTS portal_users_invite_check;
            ALTER TABLE portal_users ADD CONSTRAINT portal_users_invite_check
                CHECK (status = 'INVITED' OR invite_code_hash IS NULL);

            -- El correo de la cuenta es único en la plataforma: una cuenta, un cliente.
            CREATE UNIQUE INDEX IF NOT EXISTS ux_portal_users_user ON portal_users (user_id);
            CREATE INDEX IF NOT EXISTS idx_portal_users_customer ON portal_users (tenant_id, customer_id, status);

            DROP TRIGGER IF EXISTS trg_portal_users_updated_at ON portal_users;
            CREATE TRIGGER trg_portal_users_updated_at BEFORE UPDATE ON portal_users
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();

            REVOKE DELETE ON portal_users FROM erp_app;

            CREATE OR REPLACE FUNCTION check_portal_user() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
            BEGIN
                IF TG_OP = 'UPDATE' AND (NEW.customer_id <> OLD.customer_id OR NEW.user_id <> OLD.user_id OR NEW.tenant_id <> OLD.tenant_id) THEN
                    RAISE EXCEPTION 'Un usuario del portal no cambia de cliente ni de empresa.';
                END IF;
                IF TG_OP = 'INSERT' THEN
                    IF EXISTS (SELECT 1 FROM users WHERE id = NEW.user_id AND is_super_admin) THEN
                        RAISE EXCEPTION 'La cuenta de plataforma no puede ser usuario del portal.';
                    END IF;
                    IF EXISTS (SELECT 1 FROM tenant_users WHERE user_id = NEW.user_id) THEN
                        RAISE EXCEPTION 'Una cuenta del sistema no puede ser usuario del portal.';
                    END IF;
                    IF NOT EXISTS (SELECT 1 FROM customers WHERE id = NEW.customer_id AND tenant_id = NEW.tenant_id) THEN
                        RAISE EXCEPTION 'El cliente no es de esta empresa.';
                    END IF;
                END IF;
                IF NEW.status <> 'INACTIVE' AND (TG_OP = 'INSERT' OR OLD.status = 'INACTIVE') AND (
                    SELECT count(*) FROM portal_users
                    WHERE customer_id = NEW.customer_id AND status <> 'INACTIVE' AND id <> NEW.id
                ) >= 10 THEN
                    RAISE EXCEPTION 'El cliente ya tiene 10 usuarios del portal.';
                END IF;
                RETURN NEW;
            END $$;

            DROP TRIGGER IF EXISTS trg_portal_users_check ON portal_users;
            CREATE TRIGGER trg_portal_users_check BEFORE INSERT OR UPDATE ON portal_users
                FOR EACH ROW EXECUTE FUNCTION check_portal_user();

            -- Y al revés: una cuenta del portal no recibe acceso al sistema.
            CREATE OR REPLACE FUNCTION check_tenant_user_not_portal() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM portal_users WHERE user_id = NEW.user_id) THEN
                    RAISE EXCEPTION 'Una cuenta del portal de clientes no puede ser usuario del sistema.';
                END IF;
                RETURN NEW;
            END $$;

            DROP TRIGGER IF EXISTS trg_tenant_users_not_portal ON tenant_users;
            CREATE TRIGGER trg_tenant_users_not_portal BEFORE INSERT OR UPDATE OF user_id ON tenant_users
                FOR EACH ROW EXECUTE FUNCTION check_tenant_user_not_portal();

            -- Empresa con la que entra un usuario del portal (solo cuentas activas).
            CREATE OR REPLACE FUNCTION resolve_portal_tenant_for_user(p_user_id uuid) RETURNS uuid
            LANGUAGE sql SECURITY DEFINER SET search_path = public AS $$
                SELECT tenant_id FROM portal_users WHERE user_id = p_user_id AND status = 'ACTIVE' LIMIT 1;
            $$;

            -- Empresa y estado de una cuenta del portal, también invitada o desactivada (activar la invitación).
            CREATE OR REPLACE FUNCTION portal_membership_for_user(p_user_id uuid)
            RETURNS TABLE (tenant_id uuid, status varchar)
            LANGUAGE sql SECURITY DEFINER SET search_path = public AS $$
                SELECT tenant_id, status FROM portal_users WHERE user_id = p_user_id LIMIT 1;
            $$;

            -- 2. Avisos del portal: a un cliente o, sin cliente, a todos. Se retiran, no se borran.
            CREATE TABLE IF NOT EXISTS portal_notices (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                customer_id uuid REFERENCES customers(id) ON DELETE RESTRICT,
                body text NOT NULL,
                link text,
                expires_at timestamptz,
                created_by uuid REFERENCES users(id) ON DELETE SET NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                withdrawn_at timestamptz,
                withdrawn_by uuid REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT portal_notices_body_check CHECK (btrim(body) <> '' AND length(body) <= 500),
                CONSTRAINT portal_notices_link_check CHECK (link IS NULL OR link ~ '^(https?://|/)'),
                CONSTRAINT portal_notices_withdrawn_check CHECK ((withdrawn_at IS NULL) = (withdrawn_by IS NULL))
            );
            CREATE INDEX IF NOT EXISTS idx_portal_notices_tenant ON portal_notices (tenant_id, created_at DESC);
            ALTER TABLE portal_notices ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON portal_notices;
            CREATE POLICY tenant_isolation ON portal_notices USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
            REVOKE ALL ON portal_notices FROM erp_app;
            GRANT SELECT, INSERT, UPDATE ON portal_notices TO erp_app;

            -- 3. Solicitudes: bandeja de la empresa (ERP) o pipeline del CRM.
            ALTER TABLE portal_requests ADD COLUMN IF NOT EXISTS route varchar(10) NOT NULL DEFAULT 'ERP';
            ALTER TABLE portal_requests DROP CONSTRAINT IF EXISTS portal_requests_route_check;
            ALTER TABLE portal_requests ADD CONSTRAINT portal_requests_route_check
                CHECK (route IN ('ERP', 'CRM') AND (route = 'ERP' OR crm_opportunity_id IS NOT NULL));
            DO $$ BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'portal_requests_crm_opportunity_id_fkey') THEN
                    ALTER TABLE portal_requests ADD CONSTRAINT portal_requests_crm_opportunity_id_fkey
                        FOREIGN KEY (crm_opportunity_id) REFERENCES crm_opportunities(id) ON DELETE RESTRICT;
                END IF;
            END $$;

            -- 4. Decisión de una cotización: la empresa o el cliente desde el portal.
            ALTER TABLE quotation_approvals ADD COLUMN IF NOT EXISTS channel varchar(10) NOT NULL DEFAULT 'STAFF';
            ALTER TABLE quotation_approvals DROP CONSTRAINT IF EXISTS quotation_approvals_channel_check;
            ALTER TABLE quotation_approvals ADD CONSTRAINT quotation_approvals_channel_check CHECK (channel IN ('STAFF', 'PORTAL'));

            -- 5. Módulo del plan y permisos.
            INSERT INTO module_catalog (code, name, description, category, is_core, active, display_order, permission_prefixes, created_at, updated_at)
            VALUES ('PORTAL', 'Portal de clientes',
                'Acceso externo para los clientes: cotizaciones, servicios, documentos, facturas, solicitudes y soporte.',
                'SOPORTE_ATENCION_CLIENTE', false, true, 23, '["portal_users"]'::jsonb, now(), now())
            ON CONFLICT (code) DO NOTHING;

            UPDATE saas_plans SET modules = modules || '{"PORTAL": true}'::jsonb
            WHERE code IN ('BASE', 'PRO', 'ENTERPRISE');

            INSERT INTO tenant_modules (tenant_id, module_code, enabled, activated_at)
            SELECT DISTINCT s.tenant_id, 'PORTAL', true, now()
            FROM tenant_subscriptions s JOIN saas_plans p ON p.id = s.plan_id
            WHERE s.status IN ('ACTIVE', 'TRIAL') AND p.modules ? 'PORTAL'
            ON CONFLICT (tenant_id, module_code) DO NOTHING;

            INSERT INTO permissions (code, name, description) VALUES
                ('portal_users.view', 'Ver usuarios del portal', 'Usuarios del portal de clientes y avisos publicados.'),
                ('portal_users.create', 'Invitar usuarios del portal', 'Invitar a una persona de un cliente al portal.'),
                ('portal_users.manage', 'Gestionar usuarios del portal', 'Cambiar el rol, desactivar o reactivar usuarios del portal, reenviar invitaciones y publicar o retirar avisos.'),
                ('portal_users.export', 'Exportar usuarios del portal', 'Descargar el listado de usuarios del portal en Excel o CSV.')
            ON CONFLICT (code) DO NOTHING;

            -- Reparto del prototipo: el Administrador todo; Gerencia ve.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code LIKE 'portal\_users.%'
            WHERE r.tenant_id IS NOT NULL AND (r.is_system OR upper(r.code) = 'ADMIN')
            ON CONFLICT DO NOTHING;
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code = 'portal_users.view'
            WHERE r.tenant_id IS NOT NULL AND upper(r.code) = 'GERENCIA'
            ON CONFLICT DO NOTHING;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM role_permissions WHERE permission_id IN (SELECT id FROM permissions WHERE code LIKE 'portal\_users.%');
            DELETE FROM permissions WHERE code LIKE 'portal\_users.%';
            DELETE FROM tenant_modules WHERE module_code = 'PORTAL';
            UPDATE saas_plans SET modules = modules - 'PORTAL';
            DELETE FROM module_catalog WHERE code = 'PORTAL';

            ALTER TABLE quotation_approvals DROP CONSTRAINT IF EXISTS quotation_approvals_channel_check;
            ALTER TABLE quotation_approvals DROP COLUMN IF EXISTS channel;
            ALTER TABLE portal_requests DROP CONSTRAINT IF EXISTS portal_requests_crm_opportunity_id_fkey;
            ALTER TABLE portal_requests DROP CONSTRAINT IF EXISTS portal_requests_route_check;
            ALTER TABLE portal_requests DROP COLUMN IF EXISTS route;
            DROP TABLE IF EXISTS portal_notices;

            DROP FUNCTION IF EXISTS portal_membership_for_user(uuid);
            DROP FUNCTION IF EXISTS resolve_portal_tenant_for_user(uuid);
            DROP TRIGGER IF EXISTS trg_tenant_users_not_portal ON tenant_users;
            DROP FUNCTION IF EXISTS check_tenant_user_not_portal();
            DROP TRIGGER IF EXISTS trg_portal_users_check ON portal_users;
            DROP FUNCTION IF EXISTS check_portal_user();
            DROP TRIGGER IF EXISTS trg_portal_users_updated_at ON portal_users;
            DROP INDEX IF EXISTS idx_portal_users_customer;
            DROP INDEX IF EXISTS ux_portal_users_user;
            ALTER TABLE portal_users DROP CONSTRAINT IF EXISTS portal_users_invite_check;
            ALTER TABLE portal_users DROP CONSTRAINT IF EXISTS portal_users_invited_by_type_check;
            ALTER TABLE portal_users DROP CONSTRAINT IF EXISTS portal_users_status_check;
            ALTER TABLE portal_users DROP CONSTRAINT IF EXISTS portal_users_role_check;
            ALTER TABLE portal_users ALTER COLUMN status SET DEFAULT 'ACTIVE';
            ALTER TABLE portal_users
                DROP COLUMN IF EXISTS lock_version, DROP COLUMN IF EXISTS updated_at, DROP COLUMN IF EXISTS deactivated_at,
                DROP COLUMN IF EXISTS activated_at, DROP COLUMN IF EXISTS invited_at, DROP COLUMN IF EXISTS invited_by_type,
                DROP COLUMN IF EXISTS invited_by, DROP COLUMN IF EXISTS invite_expires_at, DROP COLUMN IF EXISTS invite_code_hash,
                DROP COLUMN IF EXISTS role;
            GRANT DELETE ON portal_users TO erp_app;
        SQL);
    }
};
