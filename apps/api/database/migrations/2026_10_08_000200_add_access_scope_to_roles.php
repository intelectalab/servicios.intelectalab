<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Alcance en los permisos: además de qué puede hacer un rol, qué registros alcanza
 * (lo propio, su equipo, su sede o toda la empresa). Aplica a cotizaciones, contratos,
 * órdenes y solicitudes del portal.
 *
 * 1. `role_scopes`: el alcance de cada rol por recurso. Un rol sin fila alcanza solo lo propio.
 * 2. `teams` y `tenant_users.team_id`: el equipo de cada usuario (la sede ya existía en
 *    `tenant_users.default_branch_id`).
 * 3. `branch_id` en los cuatro documentos: la sede a la que pertenece cada uno.
 * 4. `portal_requests.taken_by`: quién tomó la solicitud.
 *
 * Los roles que ya existen conservan lo que ven hoy (toda la empresa), salvo Ventas, que
 * pasa a ver solo lo propio en cotizaciones, contratos y órdenes y recibe el permiso de
 * ver contratos y órdenes, como en el prototipo.
 *
 * Solo añade. Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS role_scopes (
                role_id uuid NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                resource varchar(50) NOT NULL,
                scope varchar(30) NOT NULL DEFAULT 'OWN',
                updated_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (role_id, resource),
                CONSTRAINT role_scopes_resource_check CHECK (resource IN ('quotations', 'contracts', 'orders', 'portal')),
                CONSTRAINT role_scopes_scope_check CHECK (scope IN ('OWN', 'TEAM', 'BRANCH', 'TENANT'))
            );
            ALTER TABLE role_scopes ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON role_scopes;
            CREATE POLICY tenant_isolation ON role_scopes
                USING (tenant_id = app_tenant_id())
                WITH CHECK (tenant_id = app_tenant_id());

            CREATE TABLE IF NOT EXISTS teams (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                name varchar(255) NOT NULL,
                status varchar(30) NOT NULL DEFAULT 'ACTIVE',
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT teams_status_check CHECK (status IN ('ACTIVE', 'INACTIVE'))
            );
            CREATE UNIQUE INDEX IF NOT EXISTS ux_teams_tenant_name ON teams (tenant_id, lower(name));
            ALTER TABLE teams ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON teams;
            CREATE POLICY tenant_isolation ON teams
                USING (tenant_id = app_tenant_id())
                WITH CHECK (tenant_id = app_tenant_id());

            ALTER TABLE tenant_users ADD COLUMN IF NOT EXISTS team_id uuid REFERENCES teams(id) ON DELETE SET NULL;
            CREATE INDEX IF NOT EXISTS idx_tenant_users_team ON tenant_users (tenant_id, team_id);

            ALTER TABLE portal_requests ADD COLUMN IF NOT EXISTS taken_by uuid REFERENCES users(id) ON DELETE SET NULL;
        SQL);

        foreach (['quotations', 'contracts', 'orders', 'portal_requests'] as $table) {
            DB::unprepared(<<<SQL
                ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS branch_id uuid REFERENCES branches(id) ON DELETE SET NULL;
                CREATE INDEX IF NOT EXISTS idx_{$table}_tenant_branch ON {$table} (tenant_id, branch_id);
            SQL);
        }
        foreach (['quotations', 'contracts', 'orders'] as $table) {
            DB::unprepared("CREATE INDEX IF NOT EXISTS idx_{$table}_tenant_creator ON {$table} (tenant_id, created_by);");
        }

        DB::unprepared(<<<'SQL'
            -- Quién tomó cada solicitud ya atendida, según la auditoría.
            UPDATE portal_requests p
            SET taken_by = a.user_id
            FROM (
                SELECT DISTINCT ON (entity_id) entity_id, user_id
                FROM audit_logs
                WHERE action IN ('portal_request.take', 'portal_request.quote', 'portal_request.reject') AND user_id IS NOT NULL
                ORDER BY entity_id, created_at
            ) a
            WHERE a.entity_id::text = p.id::text AND p.taken_by IS NULL AND p.status <> 'SUBMITTED';

            -- Los roles que ya existen siguen viendo lo que ven hoy.
            INSERT INTO role_scopes (role_id, tenant_id, resource, scope)
            SELECT r.id, r.tenant_id, x.resource, 'TENANT'
            FROM roles r
            CROSS JOIN (VALUES ('quotations'), ('contracts'), ('orders'), ('portal')) AS x(resource)
            WHERE r.tenant_id IS NOT NULL
            ON CONFLICT DO NOTHING;

            -- Ventas, como en el prototipo: lo propio en cotizaciones, contratos y órdenes;
            -- la bandeja de solicitudes, completa.
            UPDATE role_scopes s
            SET scope = 'OWN'
            FROM roles r
            WHERE r.id = s.role_id AND upper(r.code) = 'VENTAS' AND s.resource IN ('quotations', 'contracts', 'orders');

            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r
            JOIN permissions p ON p.code IN ('contracts.view', 'orders.view')
            WHERE upper(r.code) = 'VENTAS'
              AND EXISTS (
                  SELECT 1 FROM role_permissions rp JOIN permissions q ON q.id = rp.permission_id
                  WHERE rp.role_id = r.id AND q.code = 'quotations.view'
              )
            ON CONFLICT DO NOTHING;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE portal_requests DROP COLUMN IF EXISTS taken_by;
            ALTER TABLE portal_requests DROP COLUMN IF EXISTS branch_id;
            ALTER TABLE orders DROP COLUMN IF EXISTS branch_id;
            ALTER TABLE contracts DROP COLUMN IF EXISTS branch_id;
            ALTER TABLE quotations DROP COLUMN IF EXISTS branch_id;
            DROP INDEX IF EXISTS idx_orders_tenant_creator;
            DROP INDEX IF EXISTS idx_contracts_tenant_creator;
            DROP INDEX IF EXISTS idx_quotations_tenant_creator;
            ALTER TABLE tenant_users DROP COLUMN IF EXISTS team_id;
            DROP TABLE IF EXISTS teams;
            DROP TABLE IF EXISTS role_scopes;
        SQL);
    }
};
