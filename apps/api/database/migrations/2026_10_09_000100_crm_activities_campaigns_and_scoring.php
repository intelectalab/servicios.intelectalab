<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Resto del CRM: Actividades, Campañas y Puntuación de leads.
 *
 * Las tablas `crm_activities`, `crm_campaigns`, `crm_campaign_members` y `crm_settings` ya
 * existían (vacías); se conservan y solo se les añade lo que faltaba. Solo añade: no borra datos.
 *
 * Lo que la base garantiza por sí sola:
 *   - una actividad es de un tipo conocido y cuelga de un lead o de una oportunidad, no de los dos;
 *   - una campaña cancelada lleva su motivo;
 *   - un lead figura una sola vez en una campaña, y excluirlo no lo borra (la aplicación no
 *     puede borrar miembros).
 *
 * Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- 1. Actividades: sede (para el alcance), control de ediciones simultáneas y tipos.
            ALTER TABLE crm_activities
                ADD COLUMN IF NOT EXISTS branch_id uuid REFERENCES branches(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS completed_by uuid REFERENCES users(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS updated_at timestamptz NOT NULL DEFAULT now(),
                ADD COLUMN IF NOT EXISTS lock_version integer NOT NULL DEFAULT 0;
            ALTER TABLE crm_activities DROP CONSTRAINT IF EXISTS crm_activities_type_check;
            ALTER TABLE crm_activities ADD CONSTRAINT crm_activities_type_check
                CHECK (activity_type IN ('CALL', 'MEETING', 'EMAIL', 'DEMO', 'VISIT'));
            ALTER TABLE crm_activities DROP CONSTRAINT IF EXISTS crm_activities_single_relation_check;
            ALTER TABLE crm_activities ADD CONSTRAINT crm_activities_single_relation_check
                CHECK (lead_id IS NULL OR opportunity_id IS NULL);
            CREATE INDEX IF NOT EXISTS idx_crm_activities_lead ON crm_activities (lead_id) WHERE lead_id IS NOT NULL;
            CREATE INDEX IF NOT EXISTS idx_crm_activities_opportunity ON crm_activities (opportunity_id) WHERE opportunity_id IS NOT NULL;
            CREATE INDEX IF NOT EXISTS idx_crm_activities_owner ON crm_activities (tenant_id, owner_user_id);

            -- 2. Campañas: canal, consentimiento, responsable, motivo de cancelación y estados.
            ALTER TABLE crm_campaigns
                ADD COLUMN IF NOT EXISTS channel varchar(100),
                ADD COLUMN IF NOT EXISTS consent_required boolean NOT NULL DEFAULT true,
                ADD COLUMN IF NOT EXISTS owner_user_id uuid REFERENCES users(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS cancel_reason text,
                ADD COLUMN IF NOT EXISTS lock_version integer NOT NULL DEFAULT 0;
            ALTER TABLE crm_campaigns DROP CONSTRAINT IF EXISTS crm_campaigns_status_check;
            ALTER TABLE crm_campaigns ADD CONSTRAINT crm_campaigns_status_check
                CHECK (status IN ('DRAFT', 'ACTIVE', 'COMPLETED', 'CANCELLED'));
            ALTER TABLE crm_campaigns DROP CONSTRAINT IF EXISTS crm_campaigns_cancel_reason_check;
            ALTER TABLE crm_campaigns ADD CONSTRAINT crm_campaigns_cancel_reason_check
                CHECK (status <> 'CANCELLED' OR btrim(coalesce(cancel_reason, '')) <> '');
            ALTER TABLE crm_campaigns DROP CONSTRAINT IF EXISTS crm_campaigns_dates_check;
            ALTER TABLE crm_campaigns ADD CONSTRAINT crm_campaigns_dates_check
                CHECK (starts_at IS NULL OR ends_at IS NULL OR ends_at >= starts_at);

            -- 3. Miembros: consentimiento, exclusión sin borrado y un lead una sola vez por campaña.
            ALTER TABLE crm_campaign_members
                ADD COLUMN IF NOT EXISTS consent boolean NOT NULL DEFAULT false,
                ADD COLUMN IF NOT EXISTS added_by uuid REFERENCES users(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS excluded_at timestamptz,
                ADD COLUMN IF NOT EXISTS excluded_by uuid REFERENCES users(id) ON DELETE SET NULL;
            ALTER TABLE crm_campaign_members DROP CONSTRAINT IF EXISTS crm_campaign_members_status_check;
            ALTER TABLE crm_campaign_members ADD CONSTRAINT crm_campaign_members_status_check
                CHECK (status IN ('ADDED', 'EXCLUDED'));
            CREATE UNIQUE INDEX IF NOT EXISTS uq_crm_campaign_members_lead ON crm_campaign_members (campaign_id, lead_id) WHERE lead_id IS NOT NULL;
            CREATE INDEX IF NOT EXISTS idx_crm_campaign_members_lead ON crm_campaign_members (lead_id) WHERE lead_id IS NOT NULL;
            REVOKE DELETE ON crm_campaign_members FROM erp_app;

            -- 4. Leads: campaña de origen y puntaje automático con su grado y sus criterios.
            ALTER TABLE crm_leads
                ADD COLUMN IF NOT EXISTS campaign_id uuid REFERENCES crm_campaigns(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS score_grade char(1),
                ADD COLUMN IF NOT EXISTS score_hits jsonb NOT NULL DEFAULT '[]'::jsonb;
            ALTER TABLE crm_leads DROP CONSTRAINT IF EXISTS crm_leads_score_grade_check;
            ALTER TABLE crm_leads ADD CONSTRAINT crm_leads_score_grade_check
                CHECK (score_grade IS NULL OR score_grade IN ('A', 'B', 'C', 'D'));

            -- 5. Permisos por recurso (Ver, Gestionar, Exportar), como el resto del sistema.
            INSERT INTO permissions (code, name, description) VALUES
                ('activities.view', 'Ver actividades', 'Llamadas, reuniones, correos, demos y visitas del CRM.'),
                ('activities.manage', 'Gestionar actividades', 'Registrar, editar y marcar como realizadas las actividades.'),
                ('activities.export', 'Exportar actividades', 'Descargar el listado en Excel o CSV.'),
                ('campaigns.view', 'Ver campañas', 'Campañas, sus miembros y sus resultados.'),
                ('campaigns.manage', 'Gestionar campañas', 'Crear, editar, lanzar, finalizar y cancelar campañas, y sus miembros.'),
                ('campaigns.export', 'Exportar campañas', 'Descargar el listado en Excel o CSV.'),
                ('scoring.manage', 'Configurar la puntuación de leads', 'Cambiar los puntos de cada criterio y recalcular el puntaje de todos los leads.')
            ON CONFLICT (code) DO NOTHING;

            UPDATE module_catalog
            SET permission_prefixes = '["leads", "opportunities", "activities", "campaigns", "scoring"]'::jsonb
            WHERE upper(code) = 'CRM';

            -- 6. Alcance por rol: las actividades también lo llevan.
            ALTER TABLE role_scopes DROP CONSTRAINT IF EXISTS role_scopes_resource_check;
            ALTER TABLE role_scopes ADD CONSTRAINT role_scopes_resource_check
                CHECK (resource IN ('quotations', 'contracts', 'orders', 'portal', 'leads', 'opportunities', 'activities'));

            -- 7. Reparto de la matriz del prototipo.
            -- Administrador: todo.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r
            JOIN permissions p ON p.code IN ('activities.view', 'activities.manage', 'activities.export',
                                             'campaigns.view', 'campaigns.manage', 'campaigns.export', 'scoring.manage')
            WHERE r.tenant_id IS NOT NULL AND (r.is_system OR upper(r.code) = 'ADMIN')
              AND EXISTS (SELECT 1 FROM role_permissions rp JOIN permissions q ON q.id = rp.permission_id
                          WHERE rp.role_id = r.id AND q.code = 'leads.view')
            ON CONFLICT DO NOTHING;

            -- Gerencia: ve las actividades de toda la empresa; ve y exporta las campañas.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r
            JOIN permissions p ON p.code IN ('activities.view', 'campaigns.view', 'campaigns.export')
            WHERE upper(r.code) = 'GERENCIA' AND r.tenant_id IS NOT NULL
              AND EXISTS (SELECT 1 FROM role_permissions rp JOIN permissions q ON q.id = rp.permission_id
                          WHERE rp.role_id = r.id AND q.code = 'opportunities.view')
            ON CONFLICT DO NOTHING;

            -- Ventas: registra y completa sus actividades; ve las campañas. No exporta.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r
            JOIN permissions p ON p.code IN ('activities.view', 'activities.manage', 'campaigns.view')
            WHERE upper(r.code) = 'VENTAS' AND r.tenant_id IS NOT NULL
              AND EXISTS (SELECT 1 FROM role_permissions rp JOIN permissions q ON q.id = rp.permission_id
                          WHERE rp.role_id = r.id AND q.code = 'leads.manage')
            ON CONFLICT DO NOTHING;

            -- Alcance en actividades: el mismo que el rol ya tiene en oportunidades.
            INSERT INTO role_scopes (role_id, tenant_id, resource, scope)
            SELECT rs.role_id, rs.tenant_id, 'activities', rs.scope
            FROM role_scopes rs
            WHERE rs.resource = 'opportunities'
            ON CONFLICT DO NOTHING;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM role_scopes WHERE resource = 'activities';
            ALTER TABLE role_scopes DROP CONSTRAINT IF EXISTS role_scopes_resource_check;
            ALTER TABLE role_scopes ADD CONSTRAINT role_scopes_resource_check
                CHECK (resource IN ('quotations', 'contracts', 'orders', 'portal', 'leads', 'opportunities'));

            DELETE FROM permissions WHERE code IN ('activities.view', 'activities.manage', 'activities.export',
                'campaigns.view', 'campaigns.manage', 'campaigns.export', 'scoring.manage');
            UPDATE module_catalog SET permission_prefixes = '["leads", "opportunities"]'::jsonb WHERE upper(code) = 'CRM';

            ALTER TABLE crm_leads DROP COLUMN IF EXISTS campaign_id, DROP COLUMN IF EXISTS score_grade, DROP COLUMN IF EXISTS score_hits;

            GRANT DELETE ON crm_campaign_members TO erp_app;
            DROP INDEX IF EXISTS uq_crm_campaign_members_lead;
            DROP INDEX IF EXISTS idx_crm_campaign_members_lead;
            ALTER TABLE crm_campaign_members DROP CONSTRAINT IF EXISTS crm_campaign_members_status_check;
            ALTER TABLE crm_campaign_members
                DROP COLUMN IF EXISTS consent, DROP COLUMN IF EXISTS added_by, DROP COLUMN IF EXISTS excluded_at, DROP COLUMN IF EXISTS excluded_by;

            ALTER TABLE crm_campaigns DROP CONSTRAINT IF EXISTS crm_campaigns_status_check;
            ALTER TABLE crm_campaigns DROP CONSTRAINT IF EXISTS crm_campaigns_cancel_reason_check;
            ALTER TABLE crm_campaigns DROP CONSTRAINT IF EXISTS crm_campaigns_dates_check;
            ALTER TABLE crm_campaigns
                DROP COLUMN IF EXISTS channel, DROP COLUMN IF EXISTS consent_required, DROP COLUMN IF EXISTS owner_user_id,
                DROP COLUMN IF EXISTS cancel_reason, DROP COLUMN IF EXISTS lock_version;

            DROP INDEX IF EXISTS idx_crm_activities_lead;
            DROP INDEX IF EXISTS idx_crm_activities_opportunity;
            DROP INDEX IF EXISTS idx_crm_activities_owner;
            ALTER TABLE crm_activities DROP CONSTRAINT IF EXISTS crm_activities_type_check;
            ALTER TABLE crm_activities DROP CONSTRAINT IF EXISTS crm_activities_single_relation_check;
            ALTER TABLE crm_activities
                DROP COLUMN IF EXISTS branch_id, DROP COLUMN IF EXISTS completed_by, DROP COLUMN IF EXISTS updated_at, DROP COLUMN IF EXISTS lock_version;
        SQL);
    }
};
