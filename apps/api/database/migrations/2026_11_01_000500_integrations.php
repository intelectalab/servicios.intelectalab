<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Integraciones (Control → Integraciones): estado real de las conexiones y webhooks salientes
 * firmados, con cola de envíos, reintentos y fallidos. Usa las tablas que ya existían
 * (webhook_endpoints, outbox_events, webhook_deliveries, integration_attempts).
 *
 * - Módulo INTEGRACIONES en el catálogo (se vende aparte), incluido en el Plan Enterprise y
 *   encendido en las empresas con Enterprise vigente.
 * - Webhook: quién lo creó, descripción y fechas; la clave de firma vive en el Credential Vault
 *   (tipo WEBHOOK). No se borran: se desactivan.
 * - Evento (outbox): el registro de auditoría que lo originó (event_key, único por empresa).
 * - Envío: estado PENDING / RETRY / SENT / DEAD, un solo envío por webhook y evento.
 * - Intento: cada llamada con su respuesta; no se cambia ni se borra.
 * - Permisos integrations.view, integrations.manage e integrations.export: Administrador.
 *
 * Solo añade. Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            INSERT INTO module_catalog (code, name, description, category, is_core, active, display_order, permission_prefixes)
            VALUES ('INTEGRACIONES', 'Integraciones', 'Webhooks salientes firmados hacia otros sistemas, con reintentos, y el estado de las conexiones (SUNAT, correo, pagos).', 'CONTROL', false, true, 16, '["integrations"]')
            ON CONFLICT (code) DO NOTHING;

            UPDATE saas_plans SET modules = modules || '{"INTEGRACIONES": true}'::jsonb, updated_at = now()
            WHERE code = 'ENTERPRISE' AND NOT (modules ? 'INTEGRACIONES');

            INSERT INTO tenant_modules (tenant_id, module_code, enabled, activated_at, configuration, created_at, updated_at)
            SELECT DISTINCT s.tenant_id, 'INTEGRACIONES', true, now(), '{}'::jsonb, now(), now()
            FROM tenant_subscriptions s JOIN saas_plans p ON p.id = s.plan_id
            WHERE s.status = 'ACTIVE' AND p.code = 'ENTERPRISE'
              AND NOT EXISTS (SELECT 1 FROM tenant_modules m WHERE m.tenant_id = s.tenant_id AND upper(m.module_code) = 'INTEGRACIONES');

            ALTER TABLE credential_records DROP CONSTRAINT IF EXISTS credential_records_type_check;
            ALTER TABLE credential_records ADD CONSTRAINT credential_records_type_check
                CHECK (credential_type IN ('SERVIDOR', 'DNS', 'HOSTING', 'CLOUD', 'API_KEY', 'SMTP', 'CERTIFICADO', 'BASE_DATOS', 'WEBHOOK'));

            ALTER TABLE webhook_endpoints ADD COLUMN IF NOT EXISTS description varchar(300);
            ALTER TABLE webhook_endpoints ADD COLUMN IF NOT EXISTS created_by uuid REFERENCES users(id) ON DELETE SET NULL;
            ALTER TABLE webhook_endpoints ADD COLUMN IF NOT EXISTS updated_at timestamptz NOT NULL DEFAULT now();
            ALTER TABLE webhook_endpoints ADD CONSTRAINT webhook_endpoints_https CHECK (url LIKE 'https://%');

            ALTER TABLE outbox_events ADD COLUMN IF NOT EXISTS event_key varchar(100);
            ALTER TABLE outbox_events ADD CONSTRAINT outbox_events_event_unique UNIQUE (tenant_id, event_key);

            ALTER TABLE webhook_deliveries ADD COLUMN IF NOT EXISTS event_type varchar(60);
            ALTER TABLE webhook_deliveries ADD COLUMN IF NOT EXISTS entity_label varchar(255);
            ALTER TABLE webhook_deliveries ADD COLUMN IF NOT EXISTS updated_at timestamptz NOT NULL DEFAULT now();
            ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_status_check CHECK (status IN ('PENDING', 'RETRY', 'SENT', 'DEAD'));
            ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_unique UNIQUE (endpoint_id, event_id);
            CREATE INDEX IF NOT EXISTS webhook_deliveries_due_idx ON webhook_deliveries (next_attempt_at) WHERE status IN ('PENDING', 'RETRY');
            CREATE INDEX IF NOT EXISTS webhook_deliveries_recent_idx ON webhook_deliveries (tenant_id, created_at DESC);

            ALTER TABLE integration_attempts ADD COLUMN IF NOT EXISTS delivery_id uuid REFERENCES webhook_deliveries(id) ON DELETE CASCADE;
            ALTER TABLE integration_attempts ADD COLUMN IF NOT EXISTS duration_ms integer;
            CREATE INDEX IF NOT EXISTS integration_attempts_delivery_idx ON integration_attempts (delivery_id, attempted_at DESC);

            REVOKE DELETE ON webhook_endpoints FROM erp_app;
            REVOKE UPDATE, DELETE ON integration_attempts FROM erp_app;
            REVOKE DELETE ON webhook_deliveries, outbox_events FROM erp_app;

            UPDATE permissions SET name = 'Ver integraciones', description = 'Ver las conexiones, los webhooks y sus envíos.' WHERE code = 'integrations.view';
            UPDATE permissions SET name = 'Gestionar integraciones', description = 'Crear, editar, activar y desactivar webhooks, rotar su clave y reprocesar envíos fallidos.' WHERE code = 'integrations.manage';
            INSERT INTO permissions (code, name, description) VALUES
                ('integrations.export', 'Exportar integraciones', 'Exportar la cola de envíos de los webhooks.')
            ON CONFLICT (code) DO NOTHING;

            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code LIKE 'integrations.%'
            WHERE r.tenant_id IS NOT NULL AND (r.is_system OR upper(r.code) = 'ADMIN')
            ON CONFLICT DO NOTHING;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM role_permissions WHERE permission_id = (SELECT id FROM permissions WHERE code = 'integrations.export');
            DELETE FROM permissions WHERE code = 'integrations.export';
            GRANT DELETE ON webhook_endpoints, webhook_deliveries, outbox_events TO erp_app;
            GRANT UPDATE, DELETE ON integration_attempts TO erp_app;
            DROP INDEX IF EXISTS integration_attempts_delivery_idx;
            ALTER TABLE integration_attempts DROP COLUMN IF EXISTS duration_ms, DROP COLUMN IF EXISTS delivery_id;
            DROP INDEX IF EXISTS webhook_deliveries_recent_idx;
            DROP INDEX IF EXISTS webhook_deliveries_due_idx;
            ALTER TABLE webhook_deliveries DROP CONSTRAINT IF EXISTS webhook_deliveries_unique, DROP CONSTRAINT IF EXISTS webhook_deliveries_status_check,
                DROP COLUMN IF EXISTS updated_at, DROP COLUMN IF EXISTS entity_label, DROP COLUMN IF EXISTS event_type;
            ALTER TABLE outbox_events DROP CONSTRAINT IF EXISTS outbox_events_event_unique, DROP COLUMN IF EXISTS event_key;
            ALTER TABLE webhook_endpoints DROP CONSTRAINT IF EXISTS webhook_endpoints_https,
                DROP COLUMN IF EXISTS updated_at, DROP COLUMN IF EXISTS created_by, DROP COLUMN IF EXISTS description;
            ALTER TABLE credential_records DROP CONSTRAINT IF EXISTS credential_records_type_check;
            ALTER TABLE credential_records ADD CONSTRAINT credential_records_type_check
                CHECK (credential_type IN ('SERVIDOR', 'DNS', 'HOSTING', 'CLOUD', 'API_KEY', 'SMTP', 'CERTIFICADO', 'BASE_DATOS'));
            DELETE FROM tenant_modules WHERE upper(module_code) = 'INTEGRACIONES';
            UPDATE saas_plans SET modules = modules - 'INTEGRACIONES' WHERE code = 'ENTERPRISE';
            DELETE FROM module_catalog WHERE code = 'INTEGRACIONES';
        SQL);
    }
};
