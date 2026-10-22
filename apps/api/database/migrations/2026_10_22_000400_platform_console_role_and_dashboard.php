<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Usuario de base propio para la consola de plataforma (kit: erp_console,
    // miembro de erp_platform). No lee tablas de negocio: solo ejecuta
    // platform_dashboard(), que devuelve totales de plataforma (empresas,
    // suscripciones, uso en conteos y salud), y escribe en platform_audit_logs.
    // Debe correrse como erp_user (dueño de las tablas). Los dos roles los crea
    // la migración solo si quien la corre puede crear roles (en la PC, el
    // dueño del contenedor). En un servidor donde erp_user no puede (el VPS),
    // los crea antes el administrador de PostgreSQL; ver
    // docs/cambios/2026-10-22_panel-consola-de-plataforma.md. La contraseña del
    // usuario sale de DB_PLATFORM_PASSWORD; sin el usuario, la consola
    // responde 503.
    public function up(): void
    {
        $canManageRoles = (bool) DB::selectOne(
            'SELECT (rolsuper OR rolcreaterole) AS can FROM pg_roles WHERE rolname = current_user'
        )->can;
        $groupExists = (bool) DB::selectOne("SELECT 1 AS x FROM pg_roles WHERE rolname = 'erp_platform'");

        if (! $groupExists && ! $canManageRoles) {
            throw new RuntimeException(
                'Falta el rol erp_platform y este usuario no puede crearlo. Créalo como administrador de PostgreSQL '
                .'(CREATE ROLE erp_platform NOLOGIN; y el usuario erp_console dentro de él) y vuelve a migrar.'
            );
        }
        if (! $groupExists) {
            DB::statement('CREATE ROLE erp_platform NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS');
        }

        $user = env('DB_PLATFORM_USERNAME', 'erp_console');
        $password = env('DB_PLATFORM_PASSWORD');
        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $user)) {
            throw new RuntimeException('DB_PLATFORM_USERNAME no es un nombre de rol válido.');
        }

        if ($password && $canManageRoles) {
            $quoted = DB::getPdo()->quote($password);
            $exists = DB::selectOne('SELECT 1 AS x FROM pg_roles WHERE rolname = ?', [$user]);
            DB::statement($exists
                ? "ALTER ROLE {$user} WITH LOGIN PASSWORD {$quoted}"
                : "CREATE ROLE {$user} LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS PASSWORD {$quoted}");
            DB::statement("GRANT erp_platform TO {$user}");
            $database = DB::getDatabaseName();
            DB::statement("GRANT CONNECT ON DATABASE \"{$database}\" TO {$user}");
        }

        DB::statement('GRANT USAGE ON SCHEMA public TO erp_platform');
        DB::statement('GRANT INSERT ON TABLE public.platform_audit_logs TO erp_platform');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.platform_dashboard(p_soon_days integer DEFAULT 7)
            RETURNS jsonb
            LANGUAGE sql STABLE SECURITY DEFINER
            SET search_path TO 'public'
            AS $fn$
            WITH subs AS (
                SELECT ts.tenant_id, ts.plan_id, ts.status, ts.ends_at,
                       COALESCE((ts.configuration->>'is_trial')::boolean, false) AS is_trial,
                       COALESCE(ts.configuration->>'billing_period', p.default_billing_period, 'MONTHLY') AS period,
                       p.monthly_price, p.annual_price, p.currency
                FROM tenant_subscriptions ts
                JOIN saas_plans p ON p.id = ts.plan_id
                WHERE ts.status = 'ACTIVE'
            ),
            paid AS (
                SELECT s.*, CASE WHEN s.period = 'ANNUAL' THEN COALESCE(s.annual_price, 0) / 12
                                 ELSE COALESCE(s.monthly_price, 0) END AS mrr
                FROM subs s
                WHERE NOT s.is_trial AND (s.ends_at IS NULL OR s.ends_at >= now())
            ),
            current_sub AS (
                SELECT DISTINCT ON (s.tenant_id) s.tenant_id, s.plan_id, s.is_trial, s.ends_at
                FROM subs s
                ORDER BY s.tenant_id, s.ends_at DESC NULLS FIRST
            ),
            members AS (
                SELECT tu.tenant_id, count(*) AS users
                FROM tenant_users tu JOIN users u ON u.id = tu.user_id
                WHERE tu.status = 'ACTIVE' AND u.status = 'ACTIVE'
                GROUP BY tu.tenant_id
            ),
            portal AS (
                SELECT tenant_id, count(*) AS users FROM portal_users WHERE status = 'ACTIVE' GROUP BY tenant_id
            ),
            clients AS (
                SELECT tenant_id, count(*) AS total FROM customers GROUP BY tenant_id
            ),
            storage AS (
                SELECT tenant_id, sum(size_bytes) AS bytes FROM file_objects GROUP BY tenant_id
            ),
            months AS (
                SELECT generate_series(date_trunc('month', now()) - interval '11 months', date_trunc('month', now()), interval '1 month') AS m
            )
            SELECT jsonb_build_object(
                'generated_at', now(),
                'tenants', jsonb_build_object(
                    'total', (SELECT count(*) FROM tenants),
                    'by_status', COALESCE((SELECT jsonb_object_agg(status, n) FROM (SELECT status, count(*) AS n FROM tenants GROUP BY status) x), '{}'::jsonb),
                    'new_this_month', (SELECT count(*) FROM tenants WHERE created_at >= date_trunc('month', now())),
                    'new_last_month', (SELECT count(*) FROM tenants WHERE created_at >= date_trunc('month', now()) - interval '1 month' AND created_at < date_trunc('month', now())),
                    'without_subscription', (SELECT count(*) FROM tenants t WHERE NOT EXISTS (SELECT 1 FROM subs s WHERE s.tenant_id = t.id))
                ),
                'subscriptions', jsonb_build_object(
                    'current', (SELECT count(*) FROM subs WHERE ends_at IS NULL OR ends_at >= now()),
                    'paid', (SELECT count(*) FROM paid),
                    'trial', (SELECT count(*) FROM subs WHERE is_trial AND (ends_at IS NULL OR ends_at >= now())),
                    'trial_expiring', (SELECT count(*) FROM subs WHERE is_trial AND ends_at >= now() AND ends_at < now() + make_interval(days => p_soon_days)),
                    'trial_expired', (SELECT count(*) FROM subs WHERE is_trial AND ends_at < now()),
                    'paid_expired', (SELECT count(*) FROM subs WHERE NOT is_trial AND ends_at < now())
                ),
                'revenue', COALESCE((SELECT jsonb_agg(jsonb_build_object('currency', currency, 'mrr', round(total, 2)) ORDER BY currency)
                                     FROM (SELECT currency, sum(mrr) AS total FROM paid GROUP BY currency) r), '[]'::jsonb),
                'plans', COALESCE((SELECT jsonb_agg(jsonb_build_object(
                                        'id', p.id, 'code', p.code, 'name', p.name, 'active', p.active, 'currency', p.currency,
                                        'tenants', (SELECT count(*) FROM subs s WHERE s.plan_id = p.id AND (s.ends_at IS NULL OR s.ends_at >= now())),
                                        'paid', (SELECT count(*) FROM paid x WHERE x.plan_id = p.id),
                                        'trial', (SELECT count(*) FROM subs s WHERE s.plan_id = p.id AND s.is_trial AND (s.ends_at IS NULL OR s.ends_at >= now())),
                                        'mrr', round(COALESCE((SELECT sum(mrr) FROM paid x WHERE x.plan_id = p.id), 0), 2)
                                    ) ORDER BY p.display_order NULLS LAST, p.monthly_price NULLS LAST, p.name)
                                   FROM saas_plans p), '[]'::jsonb),
                'signups', (SELECT jsonb_agg(jsonb_build_object('month', to_char(m, 'YYYY-MM'),
                                'tenants', (SELECT count(*) FROM tenants t WHERE t.created_at >= m AND t.created_at < m + interval '1 month')) ORDER BY m)
                            FROM months),
                'users', jsonb_build_object(
                    'tenant_users', (SELECT COALESCE(sum(users), 0) FROM members),
                    'portal_users', (SELECT COALESCE(sum(users), 0) FROM portal),
                    'super_admins', (SELECT count(*) FROM users WHERE is_super_admin AND status = 'ACTIVE')
                ),
                'trials', COALESCE((SELECT jsonb_agg(jsonb_build_object(
                                        'tenant_id', t.id, 'code', t.code, 'name', t.name, 'plan', p.name, 'ends_at', s.ends_at,
                                        'days_left', floor(extract(epoch FROM (s.ends_at - now())) / 86400)::int
                                    ) ORDER BY s.ends_at)
                                    FROM subs s JOIN tenants t ON t.id = s.tenant_id JOIN saas_plans p ON p.id = s.plan_id
                                    WHERE s.is_trial AND s.ends_at < now() + make_interval(days => p_soon_days)), '[]'::jsonb),
                'usage', COALESCE((SELECT jsonb_agg(jsonb_build_object(
                                        'tenant_id', t.id, 'code', t.code, 'name', t.name, 'status', t.status,
                                        'plan', p.name, 'trial', cs.is_trial, 'created_at', t.created_at,
                                        'users', COALESCE(m.users, 0),
                                        'users_limit', NULLIF(COALESCE(p.limits->>'users', p.limits->>'max_users'), '')::int,
                                        'portal_users', COALESCE(pu.users, 0),
                                        'portal_users_limit', NULLIF(p.limits->>'portal_users', '')::int,
                                        'customers', COALESCE(c.total, 0),
                                        'customers_limit', NULLIF(p.limits->>'clients', '')::int,
                                        'storage_mb', round(COALESCE(st.bytes, 0) / 1048576.0, 2)
                                    ) ORDER BY t.created_at DESC)
                                   FROM tenants t
                                   LEFT JOIN current_sub cs ON cs.tenant_id = t.id
                                   LEFT JOIN saas_plans p ON p.id = cs.plan_id
                                   LEFT JOIN members m ON m.tenant_id = t.id
                                   LEFT JOIN portal pu ON pu.tenant_id = t.id
                                   LEFT JOIN clients c ON c.tenant_id = t.id
                                   LEFT JOIN storage st ON st.tenant_id = t.id), '[]'::jsonb),
                'health', jsonb_build_object(
                    'jobs_pending', (SELECT count(*) FROM jobs),
                    'jobs_failed', (SELECT count(*) FROM failed_jobs),
                    'failed_last_24h', (SELECT count(*) FROM failed_jobs WHERE failed_at >= now() - interval '24 hours'),
                    'outbox_pending', (SELECT count(*) FROM outbox_events WHERE status = 'PENDING'),
                    'outbox_failed', (SELECT count(*) FROM outbox_events WHERE status = 'FAILED'),
                    'dead_letters', (SELECT count(*) FROM integration_dead_letters WHERE resolved_at IS NULL),
                    'files_quarantined', (SELECT count(*) FROM file_objects WHERE antivirus_status IN ('INFECTED', 'QUARANTINED'))
                ),
                'audit', COALESCE((SELECT jsonb_agg(a ORDER BY a.created_at DESC) FROM (
                                        SELECT l.created_at, l.action, l.entity_type, u.name AS user_name
                                        FROM platform_audit_logs l LEFT JOIN users u ON u.id = l.user_id
                                        ORDER BY l.created_at DESC LIMIT 8) a), '[]'::jsonb)
            )
            $fn$
        SQL);

        DB::statement('REVOKE ALL ON FUNCTION public.platform_dashboard(integer) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION public.platform_dashboard(integer) TO erp_platform');

        // Las demás funciones SECURITY DEFINER las ejecutaba cualquier rol (PUBLIC):
        // quedan solo para la app, que es quien las usa.
        foreach (self::APP_FUNCTIONS as $function) {
            DB::statement("GRANT EXECUTE ON FUNCTION public.{$function} TO erp_app");
            DB::statement("REVOKE EXECUTE ON FUNCTION public.{$function} FROM PUBLIC");
        }
    }

    private const APP_FUNCTIONS = [
        'superadmin_tenant_subscriptions(uuid)',
        'superadmin_companies(uuid)',
        'resolve_active_tenant_for_user(uuid)',
        'resolve_portal_tenant_for_user(uuid)',
        'portal_membership_for_user(uuid)',
    ];

    public function down(): void
    {
        foreach (self::APP_FUNCTIONS as $function) {
            DB::statement("GRANT EXECUTE ON FUNCTION public.{$function} TO PUBLIC");
        }
        DB::statement('DROP FUNCTION IF EXISTS public.platform_dashboard(integer)');
        DB::statement('REVOKE INSERT ON TABLE public.platform_audit_logs FROM erp_platform');
    }
};
