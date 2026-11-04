<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los límites del plan bloquean crear más: al dar de alta un cliente, servicio, orden de trabajo,
 * proyecto, documento, usuario del portal, lead, oportunidad o sede, la base cuenta lo que ya
 * tiene la empresa y lo compara con el límite de su plan vigente. Así lo cumplen también la
 * importación masiva y cualquier otra vía, no solo las pantallas.
 *
 * - Sin suscripción, o con un plan que no limita esa clave (o la deja en 0), no se controla.
 * - Lo que ya existe no se toca: solo se impide el alta siguiente.
 * - Dos altas a la vez no pasan el límite: se toma un candado por empresa y clave.
 * - El error lleva PLAN_LIMIT_REACHED y la clave; la API lo devuelve como 422 con un mensaje
 *   claro (bootstrap/app.php → App\Modules\Subscription\Support\PlanLimits).
 *
 * Los usuarios activos siguen controlándose en la app (cuentan por estado, no por filas).
 * Quien corre las migraciones (el dueño de las tablas, erp_user) no pasa por el control.
 * Se ejecuta con erp_user.
 */
return new class extends Migration
{
    /** Tabla => clave del límite en saas_plans.limits. */
    private const TABLES = [
        'customers' => 'clients',
        'services' => 'services',
        'work_orders' => 'work_orders',
        'projects' => 'projects',
        'documents' => 'documents',
        'portal_users' => 'portal_users',
        'crm_leads' => 'crm_leads',
        'crm_opportunities' => 'crm_opportunities',
        'branches' => 'branches',
    ];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Límite de la clave en el plan vigente de la empresa; NULL si no limita.
            CREATE OR REPLACE FUNCTION public.plan_limit_for(p_tenant uuid, p_key text)
            RETURNS integer
            LANGUAGE sql STABLE SECURITY DEFINER
            SET search_path TO 'public'
            AS $fn$
                SELECT CASE WHEN (p.limits->>p_key) ~ '^[0-9]+$' AND (p.limits->>p_key)::integer > 0
                            THEN (p.limits->>p_key)::integer END
                FROM tenant_subscriptions s
                JOIN saas_plans p ON p.id = s.plan_id
                WHERE s.tenant_id = p_tenant AND s.status = 'ACTIVE'
                ORDER BY s.starts_at DESC
                LIMIT 1
            $fn$;
            REVOKE ALL ON FUNCTION public.plan_limit_for(uuid, text) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION public.plan_limit_for(uuid, text) TO erp_app;

            CREATE OR REPLACE FUNCTION public.guard_plan_limit()
            RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER
            SET search_path TO 'public'
            AS $fn$
            DECLARE
                v_owner name;
                v_key text := TG_ARGV[0];
                v_limit integer;
                v_used integer;
            BEGIN
                SELECT r.rolname INTO v_owner FROM pg_class c JOIN pg_roles r ON r.oid = c.relowner WHERE c.oid = TG_RELID;
                IF session_user = v_owner THEN
                    RETURN NEW;
                END IF;

                v_limit := plan_limit_for(NEW.tenant_id, v_key);
                IF v_limit IS NULL THEN
                    RETURN NEW;
                END IF;

                PERFORM pg_advisory_xact_lock(hashtext('plan_limit:' || NEW.tenant_id::text || ':' || v_key));
                EXECUTE format('SELECT count(*) FROM %I.%I WHERE tenant_id = $1', TG_TABLE_SCHEMA, TG_TABLE_NAME)
                    INTO v_used USING NEW.tenant_id;

                IF v_used >= v_limit THEN
                    RAISE EXCEPTION 'PLAN_LIMIT_REACHED'
                        USING ERRCODE = 'P0001', DETAIL = v_key || '|' || v_limit;
                END IF;
                RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION public.guard_plan_limit() FROM PUBLIC;
        SQL);

        foreach (self::TABLES as $table => $key) {
            DB::unprepared("DROP TRIGGER IF EXISTS trg_plan_limit ON public.{$table};
                CREATE TRIGGER trg_plan_limit BEFORE INSERT ON public.{$table}
                FOR EACH ROW EXECUTE FUNCTION public.guard_plan_limit('{$key}');");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS trg_plan_limit ON public.{$table};");
        }
        DB::unprepared('DROP FUNCTION IF EXISTS public.guard_plan_limit(); DROP FUNCTION IF EXISTS public.plan_limit_for(uuid, text);');
    }
};
