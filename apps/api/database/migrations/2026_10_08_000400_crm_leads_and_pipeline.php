<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CRM, flujo principal: leads → oportunidad → ganada / perdida → conversión a cliente.
 * Se completan las tablas `crm_…` que ya existían; no se renombra ni se borra nada.
 *
 * 1. Leads: número, cargo, interés, motivo de descarte, sede y versión del registro. Nuevo
 *    estado CONTACTED. Descartar exige motivo, también en la base.
 * 2. Oportunidades: número, evidencia de cierre, sede y versión del registro. Una sola
 *    oportunidad por lead. Ganar exige evidencia y perder exige motivo, también en la base.
 * 3. Conversiones: una por oportunidad; guardan la identidad y si se creó o se reutilizó.
 *    La aplicación solo puede añadirlas y leerlas.
 * 4. Permisos por recurso, como en Comercial: `leads.*` (antes `crm.*`, se renombran y nadie
 *    pierde lo que tenía) y `opportunities.*`. Vienen con el módulo CRM.
 * 5. Alcance por rol en leads y oportunidades. Quien ya veía el CRM sigue viendo toda la
 *    empresa; Ventas y Gerencia reciben el reparto del prototipo (Ventas, lo propio).
 *
 * Solo añade. Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- 1. Leads
            ALTER TABLE crm_leads
                ADD COLUMN IF NOT EXISTS lead_number varchar(50),
                ADD COLUMN IF NOT EXISTS position varchar(255),
                ADD COLUMN IF NOT EXISTS interest varchar(255),
                ADD COLUMN IF NOT EXISTS discard_reason text,
                ADD COLUMN IF NOT EXISTS branch_id uuid REFERENCES branches(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS lock_version integer NOT NULL DEFAULT 0;

            UPDATE crm_leads l
            SET lead_number = n.number
            FROM (
                SELECT id, 'LD-' || to_char(created_at, 'YYYY') || '-' || lpad(row_number() OVER (
                    PARTITION BY tenant_id, to_char(created_at, 'YYYY') ORDER BY created_at, id
                )::text, 4, '0') AS number
                FROM crm_leads
            ) n
            WHERE n.id = l.id AND l.lead_number IS NULL;

            ALTER TABLE crm_leads ALTER COLUMN lead_number SET NOT NULL;
            CREATE UNIQUE INDEX IF NOT EXISTS ux_crm_leads_tenant_number ON crm_leads (tenant_id, lead_number);
            CREATE INDEX IF NOT EXISTS idx_crm_leads_tenant_owner ON crm_leads (tenant_id, owner_user_id);
            CREATE INDEX IF NOT EXISTS idx_crm_leads_tenant_branch ON crm_leads (tenant_id, branch_id);

            ALTER TABLE crm_leads DROP CONSTRAINT IF EXISTS crm_leads_status_check;
            ALTER TABLE crm_leads ADD CONSTRAINT crm_leads_status_check
                CHECK (status IN ('NEW', 'CONTACTED', 'QUALIFIED', 'DISQUALIFIED', 'NURTURING', 'CONVERTED'));
            ALTER TABLE crm_leads DROP CONSTRAINT IF EXISTS crm_leads_discard_reason_check;
            ALTER TABLE crm_leads ADD CONSTRAINT crm_leads_discard_reason_check
                CHECK (status <> 'DISQUALIFIED' OR length(trim(coalesce(discard_reason, ''))) > 0) NOT VALID;

            -- 2. Oportunidades
            ALTER TABLE crm_opportunities
                ADD COLUMN IF NOT EXISTS opportunity_number varchar(50),
                ADD COLUMN IF NOT EXISTS won_evidence text,
                ADD COLUMN IF NOT EXISTS won_by uuid REFERENCES users(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS branch_id uuid REFERENCES branches(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS lock_version integer NOT NULL DEFAULT 0;

            UPDATE crm_opportunities o
            SET opportunity_number = n.number
            FROM (
                SELECT id, 'OP-' || to_char(created_at, 'YYYY') || '-' || lpad(row_number() OVER (
                    PARTITION BY tenant_id, to_char(created_at, 'YYYY') ORDER BY created_at, id
                )::text, 4, '0') AS number
                FROM crm_opportunities
            ) n
            WHERE n.id = o.id AND o.opportunity_number IS NULL;

            ALTER TABLE crm_opportunities ALTER COLUMN opportunity_number SET NOT NULL;
            CREATE UNIQUE INDEX IF NOT EXISTS ux_crm_opportunities_tenant_number ON crm_opportunities (tenant_id, opportunity_number);
            -- Calificar un lead crea una sola oportunidad, aunque lleguen dos clics a la vez.
            CREATE UNIQUE INDEX IF NOT EXISTS ux_crm_opportunities_lead ON crm_opportunities (lead_id) WHERE lead_id IS NOT NULL;
            CREATE INDEX IF NOT EXISTS idx_crm_opportunities_tenant_owner ON crm_opportunities (tenant_id, owner_user_id);
            CREATE INDEX IF NOT EXISTS idx_crm_opportunities_tenant_branch ON crm_opportunities (tenant_id, branch_id);

            ALTER TABLE crm_opportunities DROP CONSTRAINT IF EXISTS crm_opportunities_closing_check;
            ALTER TABLE crm_opportunities ADD CONSTRAINT crm_opportunities_closing_check CHECK (
                (status <> 'WON' OR length(trim(coalesce(won_evidence, ''))) > 0)
                AND (status <> 'LOST' OR length(trim(coalesce(loss_reason, ''))) > 0)
            ) NOT VALID;

            -- 3. Conversiones: una por oportunidad, también para las que no nacen de un lead.
            ALTER TABLE crm_conversions
                ALTER COLUMN lead_id DROP NOT NULL,
                ADD COLUMN IF NOT EXISTS party_id uuid REFERENCES parties(id) ON DELETE RESTRICT,
                ADD COLUMN IF NOT EXISTS mode varchar(10);
            ALTER TABLE crm_conversions DROP CONSTRAINT IF EXISTS crm_conversions_mode_check;
            ALTER TABLE crm_conversions ADD CONSTRAINT crm_conversions_mode_check CHECK (mode IS NULL OR mode IN ('CREATE', 'REUSE'));
            ALTER TABLE crm_conversions DROP CONSTRAINT IF EXISTS crm_conversions_origin_check;
            ALTER TABLE crm_conversions ADD CONSTRAINT crm_conversions_origin_check CHECK (lead_id IS NOT NULL OR opportunity_id IS NOT NULL);
            CREATE UNIQUE INDEX IF NOT EXISTS ux_crm_conversions_opportunity ON crm_conversions (opportunity_id) WHERE opportunity_id IS NOT NULL;
            -- El registro de una conversión no se corrige ni se borra desde la aplicación.
            REVOKE UPDATE, DELETE ON crm_conversions FROM erp_app;

            -- 4. Permisos por recurso.
            UPDATE permissions SET code = 'leads.view', name = 'Ver leads', description = 'Ver los leads y sus fichas.'
            WHERE code = 'crm.view' AND NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'leads.view');
            UPDATE permissions SET code = 'leads.manage', name = 'Gestionar leads', description = 'Crear, editar, contactar, calificar y descartar leads.'
            WHERE code = 'crm.manage' AND NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'leads.manage');

            INSERT INTO permissions (code, name, description) VALUES
                ('leads.view', 'Ver leads', 'Ver los leads y sus fichas.'),
                ('leads.manage', 'Gestionar leads', 'Crear, editar, contactar, calificar y descartar leads.'),
                ('opportunities.view', 'Ver oportunidades', 'Ver el pipeline de oportunidades y sus fichas.'),
                ('opportunities.manage', 'Gestionar oportunidades', 'Crear oportunidades, moverlas de etapa, ganarlas, perderlas y convertirlas en cliente.')
            ON CONFLICT (code) DO NOTHING;

            UPDATE module_catalog SET permission_prefixes = '["leads", "opportunities"]'::jsonb WHERE code = 'CRM';

            -- Quien ya veía o gestionaba el CRM, ve o gestiona también las oportunidades.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT rp.role_id, target.id
            FROM role_permissions rp
            JOIN permissions held ON held.id = rp.permission_id
            JOIN permissions target ON target.code = replace(held.code, 'leads.', 'opportunities.')
            WHERE held.code IN ('leads.view', 'leads.manage')
            ON CONFLICT DO NOTHING;

            -- 5. Alcance por rol.
            ALTER TABLE role_scopes DROP CONSTRAINT IF EXISTS role_scopes_resource_check;
            ALTER TABLE role_scopes ADD CONSTRAINT role_scopes_resource_check
                CHECK (resource IN ('quotations', 'contracts', 'orders', 'portal', 'leads', 'opportunities'));

            -- Los roles que ya veían el CRM siguen viendo toda la empresa.
            INSERT INTO role_scopes (role_id, tenant_id, resource, scope)
            SELECT DISTINCT r.id, r.tenant_id, split_part(p.code, '.', 1), 'TENANT'
            FROM roles r
            JOIN role_permissions rp ON rp.role_id = r.id
            JOIN permissions p ON p.id = rp.permission_id AND p.code IN ('leads.view', 'opportunities.view')
            WHERE r.tenant_id IS NOT NULL
            ON CONFLICT DO NOTHING;

            -- Reparto del prototipo. Ventas: gestiona sus leads y oportunidades, y solo ve lo propio.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r
            JOIN permissions p ON p.code IN ('leads.view', 'leads.manage', 'opportunities.view', 'opportunities.manage')
            WHERE upper(r.code) = 'VENTAS'
              AND EXISTS (
                  SELECT 1 FROM role_permissions rp JOIN permissions q ON q.id = rp.permission_id
                  WHERE rp.role_id = r.id AND q.code = 'quotations.manage'
              )
            ON CONFLICT DO NOTHING;

            INSERT INTO role_scopes (role_id, tenant_id, resource, scope)
            SELECT r.id, r.tenant_id, x.resource, 'OWN'
            FROM roles r
            CROSS JOIN (VALUES ('leads'), ('opportunities')) AS x(resource)
            WHERE upper(r.code) = 'VENTAS' AND r.tenant_id IS NOT NULL
            ON CONFLICT DO NOTHING;

            -- Gerencia: ve los leads y gestiona las oportunidades de toda la empresa.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r
            JOIN permissions p ON p.code IN ('leads.view', 'opportunities.view', 'opportunities.manage')
            WHERE upper(r.code) = 'GERENCIA'
              AND EXISTS (
                  SELECT 1 FROM role_permissions rp JOIN permissions q ON q.id = rp.permission_id
                  WHERE rp.role_id = r.id AND q.code = 'quotations.approve'
              )
            ON CONFLICT DO NOTHING;

            INSERT INTO role_scopes (role_id, tenant_id, resource, scope)
            SELECT r.id, r.tenant_id, x.resource, 'TENANT'
            FROM roles r
            CROSS JOIN (VALUES ('leads'), ('opportunities')) AS x(resource)
            WHERE upper(r.code) = 'GERENCIA' AND r.tenant_id IS NOT NULL
            ON CONFLICT DO NOTHING;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM role_scopes WHERE resource IN ('leads', 'opportunities');
            ALTER TABLE role_scopes DROP CONSTRAINT IF EXISTS role_scopes_resource_check;
            ALTER TABLE role_scopes ADD CONSTRAINT role_scopes_resource_check
                CHECK (resource IN ('quotations', 'contracts', 'orders', 'portal'));

            DELETE FROM permissions WHERE code IN ('opportunities.view', 'opportunities.manage');
            UPDATE permissions SET code = 'crm.view', name = 'Ver CRM', description = NULL WHERE code = 'leads.view';
            UPDATE permissions SET code = 'crm.manage', name = 'Gestionar CRM', description = NULL WHERE code = 'leads.manage';
            UPDATE module_catalog SET permission_prefixes = '["crm"]'::jsonb WHERE code = 'CRM';

            GRANT UPDATE, DELETE ON crm_conversions TO erp_app;
            DROP INDEX IF EXISTS ux_crm_conversions_opportunity;
            ALTER TABLE crm_conversions DROP CONSTRAINT IF EXISTS crm_conversions_origin_check;
            ALTER TABLE crm_conversions DROP CONSTRAINT IF EXISTS crm_conversions_mode_check;
            ALTER TABLE crm_conversions DROP COLUMN IF EXISTS mode;
            ALTER TABLE crm_conversions DROP COLUMN IF EXISTS party_id;

            ALTER TABLE crm_opportunities DROP CONSTRAINT IF EXISTS crm_opportunities_closing_check;
            DROP INDEX IF EXISTS ux_crm_opportunities_lead;
            ALTER TABLE crm_opportunities
                DROP COLUMN IF EXISTS opportunity_number, DROP COLUMN IF EXISTS won_evidence, DROP COLUMN IF EXISTS won_by,
                DROP COLUMN IF EXISTS branch_id, DROP COLUMN IF EXISTS lock_version;

            ALTER TABLE crm_leads DROP CONSTRAINT IF EXISTS crm_leads_discard_reason_check;
            ALTER TABLE crm_leads
                DROP COLUMN IF EXISTS lead_number, DROP COLUMN IF EXISTS position, DROP COLUMN IF EXISTS interest,
                DROP COLUMN IF EXISTS discard_reason, DROP COLUMN IF EXISTS branch_id, DROP COLUMN IF EXISTS lock_version;
        SQL);
    }
};
