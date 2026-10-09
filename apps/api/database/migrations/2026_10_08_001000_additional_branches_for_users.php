<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sedes adicionales de una persona. La sede principal (`tenant_users.default_branch_id`)
 * sigue siendo donde nace lo que crea; las adicionales solo amplían lo que alcanza con el
 * alcance "Sede" (un supervisor de zona, un vendedor regional).
 *
 * Solo añade. Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS tenant_user_branches (
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                user_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                branch_id uuid NOT NULL REFERENCES branches(id) ON DELETE CASCADE,
                created_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (tenant_id, user_id, branch_id)
            );
            CREATE INDEX IF NOT EXISTS idx_tenant_user_branches_branch ON tenant_user_branches (tenant_id, branch_id);
            ALTER TABLE tenant_user_branches ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON tenant_user_branches;
            CREATE POLICY tenant_isolation ON tenant_user_branches
                USING (tenant_id = app_tenant_id())
                WITH CHECK (tenant_id = app_tenant_id());
            -- Se asignan y se quitan; una asignación no se modifica.
            REVOKE UPDATE ON tenant_user_branches FROM erp_app;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS tenant_user_branches;');
    }
};
