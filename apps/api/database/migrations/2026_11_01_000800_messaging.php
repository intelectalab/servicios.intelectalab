<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Notificaciones (Administración): plantillas por evento, bitácora de envíos y permisos.
 *
 * - message_templates: el asunto y el texto de cada correo por evento, y si se envía. Sin fila,
 *   rige el texto del sistema. Se edita con control de ediciones simultáneas (lock_version).
 * - outgoing_messages: cada correo que sale (a una persona de la empresa o al cliente), con su
 *   estado (En cola, Enviado, Fallido, Omitido), su error, sus intentos y cuándo se abrió. Lo
 *   escrito (destinatario, asunto, texto) no cambia después: solo su estado. No se borra.
 * - message_opened(token): marca la apertura desde la imagen del correo, sin sesión (la usa la
 *   ruta pública /m/{token}.gif); solo toca opened_at.
 * - Permisos messaging.view / manage / export. Matriz del prototipo: Administrador todo;
 *   Gerencia y Finanzas ven (y exportan la bitácora).
 *
 * Solo añade. Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS message_templates (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                event varchar(60) NOT NULL,
                channel varchar(20) NOT NULL DEFAULT 'EMAIL' CHECK (channel IN ('EMAIL', 'WHATSAPP')),
                subject varchar(200) NOT NULL CHECK (btrim(subject) <> ''),
                body text NOT NULL CHECK (btrim(body) <> ''),
                active boolean NOT NULL DEFAULT true,
                lock_version integer NOT NULL DEFAULT 1,
                updated_by uuid REFERENCES users(id) ON DELETE SET NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                UNIQUE (tenant_id, event, channel)
            );
            ALTER TABLE message_templates ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON message_templates;
            CREATE POLICY tenant_isolation ON message_templates
                USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
            REVOKE ALL ON message_templates FROM erp_app;
            GRANT SELECT, INSERT, UPDATE ON message_templates TO erp_app;

            CREATE TABLE IF NOT EXISTS outgoing_messages (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                channel varchar(20) NOT NULL DEFAULT 'EMAIL' CHECK (channel IN ('EMAIL', 'WHATSAPP')),
                event varchar(60) NOT NULL,
                audience varchar(20) NOT NULL CHECK (audience IN ('INTERNAL', 'CUSTOMER')),
                to_address varchar(255),
                to_name varchar(255),
                subject varchar(255) NOT NULL,
                body text NOT NULL,
                link varchar(500),
                status varchar(20) NOT NULL CHECK (status IN ('QUEUED', 'SENT', 'FAILED', 'SKIPPED')),
                error text,
                attempts integer NOT NULL DEFAULT 0 CHECK (attempts >= 0),
                open_token varchar(64) UNIQUE,
                opened_at timestamptz,
                sent_at timestamptz,
                customer_id uuid,
                created_by uuid REFERENCES users(id) ON DELETE SET NULL,
                created_at timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX IF NOT EXISTS outgoing_messages_tenant ON outgoing_messages (tenant_id, created_at DESC);
            ALTER TABLE outgoing_messages ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON outgoing_messages;
            CREATE POLICY tenant_isolation ON outgoing_messages
                USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
            REVOKE ALL ON outgoing_messages FROM erp_app;
            GRANT SELECT, INSERT, UPDATE ON outgoing_messages TO erp_app;

            -- Lo que se envió no se reescribe: solo cambian el estado, el error, los intentos y las fechas.
            CREATE OR REPLACE FUNCTION outgoing_messages_immutable() RETURNS trigger LANGUAGE plpgsql AS $fn$
            BEGIN
                IF NEW.id <> OLD.id OR NEW.tenant_id <> OLD.tenant_id OR NEW.channel <> OLD.channel OR NEW.event <> OLD.event
                   OR NEW.audience <> OLD.audience OR NEW.to_address IS DISTINCT FROM OLD.to_address
                   OR NEW.subject <> OLD.subject OR NEW.body <> OLD.body OR NEW.link IS DISTINCT FROM OLD.link
                   OR NEW.open_token IS DISTINCT FROM OLD.open_token OR NEW.created_at <> OLD.created_at
                   OR NEW.created_by IS DISTINCT FROM OLD.created_by THEN
                    RAISE EXCEPTION 'Un mensaje enviado no se modifica; solo cambia su estado.' USING ERRCODE = '42501';
                END IF;
                IF OLD.opened_at IS NOT NULL AND NEW.opened_at IS DISTINCT FROM OLD.opened_at THEN
                    RAISE EXCEPTION 'La apertura de un mensaje no cambia.' USING ERRCODE = '42501';
                END IF;
                RETURN NEW;
            END
            $fn$;
            DROP TRIGGER IF EXISTS trg_outgoing_messages_immutable ON outgoing_messages;
            CREATE TRIGGER trg_outgoing_messages_immutable BEFORE UPDATE ON outgoing_messages
                FOR EACH ROW EXECUTE FUNCTION outgoing_messages_immutable();

            CREATE OR REPLACE FUNCTION public.message_opened(p_token text) RETURNS void
            LANGUAGE sql SECURITY DEFINER SET search_path TO 'public' AS $fn$
                UPDATE outgoing_messages SET opened_at = now()
                WHERE open_token = p_token AND opened_at IS NULL AND status = 'SENT';
            $fn$;
            REVOKE ALL ON FUNCTION public.message_opened(text) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION public.message_opened(text) TO erp_app;

            INSERT INTO permissions (code, name, description) VALUES
                ('messaging.view', 'Ver notificaciones', 'Ver las plantillas, la bitácora de envíos y los canales de la empresa.'),
                ('messaging.manage', 'Gestionar notificaciones', 'Editar plantillas y canales, y reintentar envíos fallidos.'),
                ('messaging.export', 'Exportar notificaciones', 'Exportar la bitácora de envíos.')
            ON CONFLICT (code) DO NOTHING;

            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN ('messaging.view', 'messaging.manage', 'messaging.export')
            WHERE r.tenant_id IS NOT NULL AND (r.is_system OR upper(r.code) = 'ADMIN')
            ON CONFLICT DO NOTHING;

            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN ('messaging.view', 'messaging.export')
            WHERE r.tenant_id IS NOT NULL AND upper(r.code) IN ('GERENCIA', 'FINANZAS')
            ON CONFLICT DO NOTHING;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM role_permissions WHERE permission_id IN (SELECT id FROM permissions WHERE code LIKE 'messaging.%');
            DELETE FROM permissions WHERE code LIKE 'messaging.%';
            DROP FUNCTION IF EXISTS public.message_opened(text);
            DROP TABLE IF EXISTS outgoing_messages;
            DROP FUNCTION IF EXISTS outgoing_messages_immutable();
            DROP TABLE IF EXISTS message_templates;
        SQL);
    }
};
