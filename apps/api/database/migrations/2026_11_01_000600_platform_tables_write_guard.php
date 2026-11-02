<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las tablas de plataforma (planes, catálogo de módulos y suscripciones) dejan de estar
 * abiertas a la app de las empresas (erp_app).
 *
 * - module_catalog: solo lectura. Solo lo cambian las migraciones (su seeder corre en una).
 * - saas_plans y tenant_subscriptions: nadie las borra ni las vacía. Crear o cambiar una fila
 *   exige una sesión de superadmin abierta y verificada en dos pasos: la app pone el número de
 *   esa sesión en app.platform_token dentro de la transacción (App\Services\PlatformWrites) y
 *   la base lo comprueba contra personal_access_tokens y users.
 * - Sin esa sesión solo entra la primera suscripción de una empresa nueva (el registro
 *   público): activa, con un plan activo y público, y con una vigencia que no pase de un año
 *   (o de la prueba del plan, si es más larga).
 *
 * Quien corre las migraciones (el dueño de las tablas, erp_user) no pasa por el control.
 * Se ejecuta con erp_user.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Superadmin activo de la sesión indicada en app.platform_token (vigente y con verificación), o NULL.
            CREATE OR REPLACE FUNCTION public.platform_session_user()
            RETURNS uuid
            LANGUAGE plpgsql STABLE SECURITY DEFINER
            SET search_path TO 'public'
            AS $fn$
            DECLARE
                v_token text := current_setting('app.platform_token', true);
                v_user uuid;
            BEGIN
                IF v_token IS NULL OR v_token !~ '^[0-9]{1,18}$' THEN
                    RETURN NULL;
                END IF;
                SELECT u.id INTO v_user
                FROM personal_access_tokens t
                JOIN users u ON u.id = t.tokenable_id
                WHERE t.id = v_token::bigint
                  AND t.mfa
                  AND (t.expires_at IS NULL OR t.expires_at > (now() AT TIME ZONE 'UTC'))
                  AND u.is_super_admin AND u.status = 'ACTIVE';
                RETURN v_user;
            END
            $fn$;
            REVOKE ALL ON FUNCTION public.platform_session_user() FROM PUBLIC;

            CREATE OR REPLACE FUNCTION public.guard_platform_tables()
            RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER
            SET search_path TO 'public'
            AS $fn$
            DECLARE
                v_owner name;
                v_trial integer;
            BEGIN
                -- Las migraciones corren con el dueño de la tabla.
                SELECT r.rolname INTO v_owner FROM pg_class c JOIN pg_roles r ON r.oid = c.relowner WHERE c.oid = TG_RELID;
                IF session_user = v_owner THEN
                    RETURN NEW;
                END IF;

                IF platform_session_user() IS NOT NULL THEN
                    -- PL/pgSQL no corta el AND: NEW.tenant_id solo se lee en suscripciones.
                    IF TG_TABLE_NAME = 'tenant_subscriptions' AND TG_OP = 'UPDATE' THEN
                        IF NEW.id <> OLD.id OR NEW.tenant_id <> OLD.tenant_id THEN
                            RAISE EXCEPTION 'Una suscripción no cambia de empresa.' USING ERRCODE = '42501';
                        END IF;
                    END IF;
                    RETURN NEW;
                END IF;

                -- Registro público: la primera suscripción de una empresa nueva, y nada más.
                IF TG_TABLE_NAME = 'tenant_subscriptions' AND TG_OP = 'INSERT' THEN
                    IF EXISTS (SELECT 1 FROM tenant_subscriptions s WHERE s.tenant_id = NEW.tenant_id) THEN
                        RAISE EXCEPTION 'La empresa ya tiene suscripción: solo la consola de plataforma la cambia.' USING ERRCODE = '42501';
                    END IF;
                    SELECT p.trial_days INTO v_trial FROM saas_plans p WHERE p.id = NEW.plan_id AND p.active AND p.is_public;
                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'El plan no está disponible para el registro.' USING ERRCODE = '42501';
                    END IF;
                    IF NEW.status <> 'ACTIVE' OR NEW.ends_at IS NULL
                       OR NEW.ends_at > now() + GREATEST(interval '367 days', make_interval(days => COALESCE(v_trial, 0) + 1)) THEN
                        RAISE EXCEPTION 'Vigencia no permitida para una suscripción nueva.' USING ERRCODE = '42501';
                    END IF;
                    RETURN NEW;
                END IF;

                RAISE EXCEPTION 'Solo la consola de plataforma, con una sesión de superadmin verificada, cambia %.', TG_TABLE_NAME
                    USING ERRCODE = '42501';
            END
            $fn$;
            REVOKE ALL ON FUNCTION public.guard_platform_tables() FROM PUBLIC;

            DROP TRIGGER IF EXISTS trg_saas_plans_guard ON public.saas_plans;
            CREATE TRIGGER trg_saas_plans_guard BEFORE INSERT OR UPDATE ON public.saas_plans
                FOR EACH ROW EXECUTE FUNCTION public.guard_platform_tables();
            DROP TRIGGER IF EXISTS trg_tenant_subscriptions_guard ON public.tenant_subscriptions;
            CREATE TRIGGER trg_tenant_subscriptions_guard BEFORE INSERT OR UPDATE ON public.tenant_subscriptions
                FOR EACH ROW EXECUTE FUNCTION public.guard_platform_tables();

            REVOKE ALL ON TABLE public.module_catalog FROM erp_app;
            GRANT SELECT ON TABLE public.module_catalog TO erp_app;
            REVOKE ALL ON TABLE public.saas_plans FROM erp_app;
            GRANT SELECT, INSERT, UPDATE ON TABLE public.saas_plans TO erp_app;
            REVOKE ALL ON TABLE public.tenant_subscriptions FROM erp_app;
            GRANT SELECT, INSERT, UPDATE ON TABLE public.tenant_subscriptions TO erp_app;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_saas_plans_guard ON public.saas_plans;
            DROP TRIGGER IF EXISTS trg_tenant_subscriptions_guard ON public.tenant_subscriptions;
            DROP FUNCTION IF EXISTS public.guard_platform_tables();
            DROP FUNCTION IF EXISTS public.platform_session_user();
            GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.module_catalog, public.saas_plans, public.tenant_subscriptions TO erp_app;
        SQL);
    }
};
