<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Workflows (Control → Workflows): reglas "cuando pasa X → si se cumple Y → entonces Z" que arma
 * cada empresa. Usa las tablas que ya existían (workflow_definitions, workflow_runs).
 *
 * - La regla: nombre, disparador (entity_type), configuración (condición y acciones), activa,
 *   quién la creó. No se borran: se desactivan.
 * - Cada ejecución queda una sola vez por evento (event_key = el registro de auditoría que la
 *   disparó) y no se cambia ni se borra: disparador, registro, si cumplió la condición, resultado.
 * - Permisos workflows.view, workflows.manage y workflows.export (plan base). Según la matriz del
 *   prototipo: Administrador todo; Gerencia ve.
 *
 * Solo añade. Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE workflow_definitions ADD COLUMN IF NOT EXISTS created_by uuid REFERENCES users(id) ON DELETE SET NULL;
            ALTER TABLE workflow_definitions ADD COLUMN IF NOT EXISTS updated_by uuid REFERENCES users(id) ON DELETE SET NULL;

            ALTER TABLE workflow_runs ADD COLUMN IF NOT EXISTS event_key varchar(100);
            ALTER TABLE workflow_runs ADD COLUMN IF NOT EXISTS trigger varchar(60);
            ALTER TABLE workflow_runs ADD COLUMN IF NOT EXISTS entity_label varchar(255);
            ALTER TABLE workflow_runs ADD COLUMN IF NOT EXISTS matched boolean;
            ALTER TABLE workflow_runs ADD COLUMN IF NOT EXISTS result text;
            ALTER TABLE workflow_runs ADD CONSTRAINT workflow_runs_event_unique UNIQUE (workflow_definition_id, event_key);
            ALTER TABLE workflow_runs ALTER COLUMN status SET DEFAULT 'DONE';
            ALTER TABLE workflow_runs ADD CONSTRAINT workflow_runs_status_check CHECK (status IN ('DONE', 'SKIPPED', 'FAILED'));
            CREATE INDEX IF NOT EXISTS workflow_runs_recent_idx ON workflow_runs (tenant_id, started_at DESC);
            CREATE INDEX IF NOT EXISTS workflow_definitions_trigger_idx ON workflow_definitions (tenant_id, entity_type) WHERE active;

            REVOKE UPDATE, DELETE ON workflow_runs FROM erp_app;
            REVOKE DELETE ON workflow_definitions FROM erp_app;

            INSERT INTO permissions (code, name, description) VALUES
                ('workflows.view', 'Ver workflows', 'Ver las reglas automáticas de la empresa y sus ejecuciones.'),
                ('workflows.manage', 'Gestionar workflows', 'Crear, editar, activar y desactivar reglas automáticas.'),
                ('workflows.export', 'Exportar workflows', 'Exportar el historial de ejecuciones.')
            ON CONFLICT (code) DO NOTHING;

            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code LIKE 'workflows.%'
            WHERE r.tenant_id IS NOT NULL AND (r.is_system OR upper(r.code) = 'ADMIN')
            ON CONFLICT DO NOTHING;

            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code = 'workflows.view'
            WHERE r.tenant_id IS NOT NULL AND upper(r.code) = 'GERENCIA'
            ON CONFLICT DO NOTHING;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM role_permissions WHERE permission_id IN (SELECT id FROM permissions WHERE code LIKE 'workflows.%');
            DELETE FROM permissions WHERE code LIKE 'workflows.%';
            GRANT UPDATE, DELETE ON workflow_runs TO erp_app;
            GRANT DELETE ON workflow_definitions TO erp_app;
            DROP INDEX IF EXISTS workflow_definitions_trigger_idx;
            DROP INDEX IF EXISTS workflow_runs_recent_idx;
            ALTER TABLE workflow_runs DROP CONSTRAINT IF EXISTS workflow_runs_status_check;
            ALTER TABLE workflow_runs DROP CONSTRAINT IF EXISTS workflow_runs_event_unique;
            ALTER TABLE workflow_runs DROP COLUMN IF EXISTS result, DROP COLUMN IF EXISTS matched, DROP COLUMN IF EXISTS entity_label,
                DROP COLUMN IF EXISTS trigger, DROP COLUMN IF EXISTS event_key;
            ALTER TABLE workflow_definitions DROP COLUMN IF EXISTS updated_by, DROP COLUMN IF EXISTS created_by;
        SQL);
    }
};
