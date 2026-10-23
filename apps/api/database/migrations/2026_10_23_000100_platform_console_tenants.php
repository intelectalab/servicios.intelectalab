<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Consola de plataforma: Tenants (lista y ficha), Suscripciones y Auditoría.
    // Las lecturas las hace el usuario de la consola (erp_platform) con funciones
    // que devuelven solo datos de plataforma: identidad de la empresa, plan,
    // módulos, conteos de uso y su historial. Los cambios (aprovisionar, estado,
    // plan, módulos) los hace la app dentro del contexto del tenant y quedan en
    // platform_audit_logs. Correr como erp_user.
    public function up(): void
    {
        // Historial de estado de cada empresa: solo se agrega, nunca se edita.
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS public.tenant_status_log (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES public.tenants(id) ON DELETE CASCADE,
                from_status varchar(30),
                to_status varchar(30) NOT NULL,
                reason text,
                user_id uuid REFERENCES public.users(id) ON DELETE SET NULL,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp()
            )
        SQL);
        DB::statement('CREATE INDEX IF NOT EXISTS idx_tenant_status_log_tenant ON public.tenant_status_log (tenant_id, created_at)');
        DB::statement('REVOKE ALL ON TABLE public.tenant_status_log FROM erp_app');
        DB::statement('GRANT SELECT, INSERT ON TABLE public.tenant_status_log TO erp_app');
        DB::statement('GRANT SELECT ON TABLE public.tenant_status_log TO erp_platform');

        // Tablas globales de plataforma que la consola lee directamente.
        DB::statement('GRANT SELECT ON TABLE public.module_catalog, public.saas_plans TO erp_platform');

        // Auditoría de plataforma con el nombre y correo de quien actuó (sin abrir users).
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW public.platform_audit_view AS
            SELECT l.id, l.created_at, l.action, l.entity_type, l.entity_id, l.ip_address, l.user_agent,
                   l.before_data, l.after_data, l.metadata, l.user_id, u.name AS user_name, u.email::text AS user_email
            FROM public.platform_audit_logs l
            LEFT JOIN public.users u ON u.id = l.user_id
        SQL);
        DB::statement('REVOKE ALL ON public.platform_audit_view FROM PUBLIC');
        DB::statement('GRANT SELECT ON public.platform_audit_view TO erp_platform');

        // Suscripción vigente de cada empresa (la más reciente en ACTIVE) y su uso, en conteos.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.platform_tenant_rows(p_tenant uuid DEFAULT NULL)
            RETURNS SETOF jsonb
            LANGUAGE sql STABLE SECURITY DEFINER
            SET search_path TO 'public'
            AS $fn$
            SELECT jsonb_build_object(
                'id', t.id, 'code', t.code, 'name', t.name, 'legal_name', COALESCE(c.legal_name, t.legal_name),
                'trade_name', c.trade_name, 'ruc', c.tax_identifier, 'status', t.status, 'created_at', t.created_at,
                'subscription', CASE WHEN s.id IS NULL THEN NULL ELSE jsonb_build_object(
                    'id', s.id, 'plan_id', s.plan_id, 'plan', p.name, 'plan_code', p.code, 'currency', p.currency,
                    'monthly_price', p.monthly_price, 'annual_price', p.annual_price,
                    'starts_at', s.starts_at, 'ends_at', s.ends_at,
                    'trial', COALESCE((s.configuration->>'is_trial')::boolean, false),
                    'billing_period', COALESCE(s.configuration->>'billing_period', p.default_billing_period, 'MONTHLY')
                ) END,
                -- Encendidos más los obligatorios del catálogo, que toda empresa tiene.
                'modules', COALESCE((SELECT jsonb_agg(code ORDER BY code) FROM (
                                        SELECT upper(m.module_code) AS code FROM tenant_modules m WHERE m.tenant_id = t.id AND m.enabled
                                        UNION SELECT upper(mc.code) FROM module_catalog mc WHERE mc.is_core AND mc.active) x), '[]'::jsonb),
                'users', (SELECT count(*) FROM tenant_users tu JOIN users u ON u.id = tu.user_id
                           WHERE tu.tenant_id = t.id AND tu.status = 'ACTIVE' AND u.status = 'ACTIVE'),
                'users_limit', NULLIF(COALESCE(p.limits->>'users', p.limits->>'max_users'), '')::int,
                'portal_users', (SELECT count(*) FROM portal_users pu WHERE pu.tenant_id = t.id AND pu.status = 'ACTIVE'),
                'portal_users_limit', NULLIF(p.limits->>'portal_users', '')::int,
                'customers', (SELECT count(*) FROM customers cu WHERE cu.tenant_id = t.id),
                'customers_limit', NULLIF(p.limits->>'clients', '')::int,
                'storage_mb', round(COALESCE((SELECT sum(f.size_bytes) FROM file_objects f WHERE f.tenant_id = t.id), 0) / 1048576.0, 2),
                'storage_limit_gb', NULLIF(p.consumption->>'storage_gb', '')::numeric
            )
            FROM tenants t
            LEFT JOIN LATERAL (
                SELECT * FROM companies c0 WHERE c0.tenant_id = t.id ORDER BY c0.created_at LIMIT 1
            ) c ON true
            LEFT JOIN LATERAL (
                SELECT * FROM tenant_subscriptions s0 WHERE s0.tenant_id = t.id AND s0.status = 'ACTIVE'
                ORDER BY s0.starts_at DESC LIMIT 1
            ) s ON true
            LEFT JOIN saas_plans p ON p.id = s.plan_id
            WHERE p_tenant IS NULL OR t.id = p_tenant
            ORDER BY t.created_at DESC
            $fn$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.platform_tenants()
            RETURNS jsonb
            LANGUAGE sql STABLE SECURITY DEFINER
            SET search_path TO 'public'
            AS $fn$
            SELECT COALESCE(jsonb_agg(r), '[]'::jsonb) FROM platform_tenant_rows(NULL) r
            $fn$
        SQL);

        // Ficha: lo de la lista más el historial de suscripciones y de estado, los
        // administradores (nombre, correo y último ingreso) y los módulos encendidos.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.platform_tenant(p_tenant uuid)
            RETURNS jsonb
            LANGUAGE sql STABLE SECURITY DEFINER
            SET search_path TO 'public'
            AS $fn$
            SELECT r || jsonb_build_object(
                'subscriptions', COALESCE((SELECT jsonb_agg(jsonb_build_object(
                        'id', s.id, 'plan', p.name, 'status', s.status, 'starts_at', s.starts_at, 'ends_at', s.ends_at,
                        'trial', COALESCE((s.configuration->>'is_trial')::boolean, false),
                        'billing_period', COALESCE(s.configuration->>'billing_period', p.default_billing_period, 'MONTHLY')
                    ) ORDER BY s.starts_at DESC)
                    FROM tenant_subscriptions s JOIN saas_plans p ON p.id = s.plan_id WHERE s.tenant_id = p_tenant), '[]'::jsonb),
                'status_log', COALESCE((SELECT jsonb_agg(jsonb_build_object(
                        'from', l.from_status, 'to', l.to_status, 'reason', l.reason, 'at', l.created_at, 'user_name', u.name
                    ) ORDER BY l.created_at DESC)
                    FROM tenant_status_log l LEFT JOIN users u ON u.id = l.user_id WHERE l.tenant_id = p_tenant), '[]'::jsonb),
                'admins', COALESCE((SELECT jsonb_agg(jsonb_build_object(
                        'id', u.id, 'name', u.name, 'email', u.email::text, 'status', tu.status, 'last_login_at', u.last_login_at
                    ) ORDER BY u.name)
                    FROM user_roles ur JOIN roles ro ON ro.id = ur.role_id AND upper(ro.code) = 'ADMIN'
                    JOIN users u ON u.id = ur.user_id
                    JOIN tenant_users tu ON tu.tenant_id = ur.tenant_id AND tu.user_id = u.id
                    WHERE ur.tenant_id = p_tenant), '[]'::jsonb)
            )
            FROM platform_tenant_rows(p_tenant) r
            $fn$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.platform_subscriptions()
            RETURNS jsonb
            LANGUAGE sql STABLE SECURITY DEFINER
            SET search_path TO 'public'
            AS $fn$
            SELECT COALESCE(jsonb_agg(jsonb_build_object(
                    'id', s.id, 'tenant_id', t.id, 'tenant_code', t.code, 'tenant_name', t.name, 'tenant_status', t.status,
                    'plan', p.name, 'currency', p.currency, 'status', s.status, 'starts_at', s.starts_at, 'ends_at', s.ends_at,
                    'trial', COALESCE((s.configuration->>'is_trial')::boolean, false),
                    'billing_period', COALESCE(s.configuration->>'billing_period', p.default_billing_period, 'MONTHLY'),
                    'price', CASE WHEN COALESCE(s.configuration->>'billing_period', p.default_billing_period) = 'ANNUAL'
                                  THEN p.annual_price ELSE p.monthly_price END
                ) ORDER BY s.starts_at DESC), '[]'::jsonb)
            FROM tenant_subscriptions s
            JOIN tenants t ON t.id = s.tenant_id
            JOIN saas_plans p ON p.id = s.plan_id
            $fn$
        SQL);

        foreach (self::FUNCTIONS as $function) {
            DB::statement("REVOKE ALL ON FUNCTION public.{$function} FROM PUBLIC");
            DB::statement("GRANT EXECUTE ON FUNCTION public.{$function} TO erp_platform");
        }
    }

    private const FUNCTIONS = ['platform_tenant_rows(uuid)', 'platform_tenants()', 'platform_tenant(uuid)', 'platform_subscriptions()'];

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS public.platform_subscriptions()');
        DB::statement('DROP FUNCTION IF EXISTS public.platform_tenant(uuid)');
        DB::statement('DROP FUNCTION IF EXISTS public.platform_tenants()');
        DB::statement('DROP FUNCTION IF EXISTS public.platform_tenant_rows(uuid)');
        DB::statement('DROP VIEW IF EXISTS public.platform_audit_view');
        DB::statement('REVOKE SELECT ON TABLE public.module_catalog, public.saas_plans FROM erp_platform');
        DB::statement('DROP TABLE IF EXISTS public.tenant_status_log');
    }
};
