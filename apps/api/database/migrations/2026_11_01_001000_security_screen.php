<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seguridad y aislamiento (Administración).
 *
 * - personal_access_tokens.last_activity_at: última actividad de la sesión, para cerrar por
 *   inactividad según la política de la empresa (Sanctum ya pisa last_used_at en cada petición).
 * - isolation_probe_targets(): para la prueba de aislamiento real, un registro de OTRA empresa
 *   por cada tabla de la lista (solo su identificador; nunca sale de la API). La app intenta
 *   leerlo con su usuario y su empresa: la base debe negarlo.
 * - tenant_status_log tenía empresa sin regla de aislamiento (la única de 151): ahora la tiene.
 *   La consola escribe en ella dentro del contexto de la empresa y la lee por sus funciones.
 *
 * Solo añade. Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE personal_access_tokens ADD COLUMN IF NOT EXISTS last_activity_at timestamp(0) without time zone;

            CREATE OR REPLACE FUNCTION public.isolation_probe_targets()
            RETURNS TABLE (resource text, record_id uuid)
            LANGUAGE plpgsql STABLE SECURITY DEFINER
            SET search_path TO 'public'
            AS $fn$
            DECLARE
                v_tenant uuid := app_tenant_id();
                v_table text;
            BEGIN
                IF v_tenant IS NULL THEN
                    RETURN;
                END IF;
                FOREACH v_table IN ARRAY ARRAY['quotations', 'contracts', 'invoices', 'documents', 'credential_records', 'support_tickets', 'parties'] LOOP
                    RETURN QUERY EXECUTE format('SELECT %L::text, id FROM %I WHERE tenant_id <> $1 ORDER BY random() LIMIT 1', v_table, v_table) USING v_tenant;
                END LOOP;
            END
            $fn$;
            REVOKE ALL ON FUNCTION public.isolation_probe_targets() FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION public.isolation_probe_targets() TO erp_app;

            ALTER TABLE tenant_status_log ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON tenant_status_log;
            CREATE POLICY tenant_isolation ON tenant_status_log
                USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP POLICY IF EXISTS tenant_isolation ON tenant_status_log;
            ALTER TABLE tenant_status_log DISABLE ROW LEVEL SECURITY;
            DROP FUNCTION IF EXISTS public.isolation_probe_targets();
            ALTER TABLE personal_access_tokens DROP COLUMN IF EXISTS last_activity_at;
        SQL);
    }
};
