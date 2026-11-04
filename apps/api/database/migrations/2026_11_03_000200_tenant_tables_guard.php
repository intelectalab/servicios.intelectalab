<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Empresas, módulos y permisos de los roles, protegidos como ya lo están planes y suscripciones
 * (2026_11_01_000600): la app de las empresas (erp_app) no puede saltarse la consola.
 *
 * - tenants: nadie la borra. Cambiar el código o el estado (activa, suspendida, cerrada…) exige
 *   una sesión de superadmin (app.platform_token, ver PlatformWrites). Sin ella, cada empresa
 *   solo cambia su propia fila (nombre, configuración, enlace de ingreso) y una empresa nueva
 *   (registro público) nace activa o en alta.
 * - tenant_modules: nadie la borra. Sin sesión de superadmin solo se enciende (o se agrega) un
 *   módulo que el plan vigente incluye o que es obligatorio; apagar y cambiar la configuración sí
 *   se puede. Una empresa sin plan solo vuelve a encender lo que ella misma apagó.
 * - role_permissions: queda aislada por empresa a través de su rol (antes no tenía regla).
 *
 * Quien corre las migraciones (el dueño de las tablas, erp_user) no pasa por el control.
 * Se ejecuta con erp_user.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- ¿El plan vigente de la empresa incluye el módulo (o es obligatorio)?
            CREATE OR REPLACE FUNCTION public.plan_includes_module(p_tenant uuid, p_module text)
            RETURNS boolean
            LANGUAGE sql STABLE SECURITY DEFINER
            SET search_path TO 'public'
            AS $fn$
                SELECT EXISTS (SELECT 1 FROM module_catalog c WHERE upper(c.code) = upper(p_module) AND c.is_core)
                    OR EXISTS (
                        SELECT 1
                        FROM tenant_subscriptions s
                        JOIN saas_plans p ON p.id = s.plan_id
                        CROSS JOIN LATERAL jsonb_each_text(CASE WHEN jsonb_typeof(p.modules) = 'object' THEN p.modules ELSE '{}'::jsonb END) m
                        WHERE s.tenant_id = p_tenant AND s.status = 'ACTIVE'
                          AND upper(m.key) = upper(p_module) AND lower(m.value) IN ('true', '1')
                          AND s.starts_at = (SELECT max(s2.starts_at) FROM tenant_subscriptions s2 WHERE s2.tenant_id = p_tenant AND s2.status = 'ACTIVE')
                    )
            $fn$;
            REVOKE ALL ON FUNCTION public.plan_includes_module(uuid, text) FROM PUBLIC;

            CREATE OR REPLACE FUNCTION public.guard_tenant_tables()
            RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER
            SET search_path TO 'public'
            AS $fn$
            DECLARE
                v_owner name;
                v_has_plan boolean;
            BEGIN
                SELECT r.rolname INTO v_owner FROM pg_class c JOIN pg_roles r ON r.oid = c.relowner WHERE c.oid = TG_RELID;
                IF session_user = v_owner OR platform_session_user() IS NOT NULL THEN
                    RETURN COALESCE(NEW, OLD);
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Solo la consola de plataforma puede quitar empresas o módulos.' USING ERRCODE = '42501';
                END IF;

                IF TG_TABLE_NAME = 'tenants' THEN
                    IF TG_OP = 'INSERT' THEN
                        IF NEW.status NOT IN ('ACTIVE', 'PROVISIONING') THEN
                            RAISE EXCEPTION 'Una empresa nueva nace activa o en alta.' USING ERRCODE = '42501';
                        END IF;
                        RETURN NEW;
                    END IF;
                    IF OLD.id IS DISTINCT FROM app_tenant_id() THEN
                        RAISE EXCEPTION 'Una empresa solo cambia sus propios datos.' USING ERRCODE = '42501';
                    END IF;
                    IF NEW.id <> OLD.id OR NEW.code <> OLD.code OR NEW.status <> OLD.status THEN
                        RAISE EXCEPTION 'El código y el estado de la empresa solo los cambia la consola de plataforma.' USING ERRCODE = '42501';
                    END IF;
                    RETURN NEW;
                END IF;

                -- tenant_modules
                IF TG_OP = 'UPDATE' THEN
                    IF NEW.tenant_id <> OLD.tenant_id OR upper(NEW.module_code) <> upper(OLD.module_code) THEN
                        RAISE EXCEPTION 'Un módulo no cambia de empresa ni de código.' USING ERRCODE = '42501';
                    END IF;
                    IF NOT NEW.enabled OR OLD.enabled THEN
                        RETURN NEW;
                    END IF;
                ELSIF NOT NEW.enabled THEN
                    RETURN NEW;
                END IF;

                -- Encender o agregar un módulo encendido.
                IF plan_includes_module(NEW.tenant_id, NEW.module_code) THEN
                    RETURN NEW;
                END IF;
                SELECT EXISTS (SELECT 1 FROM tenant_subscriptions s WHERE s.tenant_id = NEW.tenant_id AND s.status = 'ACTIVE') INTO v_has_plan;
                IF TG_OP = 'UPDATE' AND NOT v_has_plan AND OLD.configuration->>'disabled_by' = 'tenant' THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'Ese módulo no está en el plan de la empresa: lo enciende la consola de plataforma.' USING ERRCODE = '42501';
            END
            $fn$;
            REVOKE ALL ON FUNCTION public.guard_tenant_tables() FROM PUBLIC;

            DROP TRIGGER IF EXISTS trg_guard_tenant_tables ON public.tenants;
            CREATE TRIGGER trg_guard_tenant_tables BEFORE INSERT OR UPDATE OR DELETE ON public.tenants
                FOR EACH ROW EXECUTE FUNCTION public.guard_tenant_tables();
            DROP TRIGGER IF EXISTS trg_guard_tenant_tables ON public.tenant_modules;
            CREATE TRIGGER trg_guard_tenant_tables BEFORE INSERT OR UPDATE OR DELETE ON public.tenant_modules
                FOR EACH ROW EXECUTE FUNCTION public.guard_tenant_tables();

            -- Sin TRUNCATE para la app.
            REVOKE TRUNCATE ON public.tenants, public.tenant_modules, public.role_permissions FROM erp_app;

            -- role_permissions: aislada por la empresa de su rol (roles ya tiene RLS).
            ALTER TABLE public.role_permissions ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON public.role_permissions;
            CREATE POLICY tenant_isolation ON public.role_permissions
                USING (EXISTS (SELECT 1 FROM roles r WHERE r.id = role_permissions.role_id AND r.tenant_id = app_tenant_id()))
                WITH CHECK (EXISTS (SELECT 1 FROM roles r WHERE r.id = role_permissions.role_id AND r.tenant_id = app_tenant_id()));
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP POLICY IF EXISTS tenant_isolation ON public.role_permissions;
            ALTER TABLE public.role_permissions DISABLE ROW LEVEL SECURITY;
            DROP TRIGGER IF EXISTS trg_guard_tenant_tables ON public.tenants;
            DROP TRIGGER IF EXISTS trg_guard_tenant_tables ON public.tenant_modules;
            DROP FUNCTION IF EXISTS public.guard_tenant_tables();
            DROP FUNCTION IF EXISTS public.plan_includes_module(uuid, text);
        SQL);
    }
};
