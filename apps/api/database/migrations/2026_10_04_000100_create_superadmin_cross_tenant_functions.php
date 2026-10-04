<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // El panel de superadmin necesita leer suscripciones y empresas de TODOS
    // los tenants, pero esas tablas están bajo RLS. Mismo patrón que
    // resolve_active_tenant_for_user: funciones SECURITY DEFINER (deben
    // crearse como erp_user, dueño de las tablas), que solo devuelven filas
    // si el usuario indicado es super admin activo.
    public function up(): void
    {
        DB::statement("
            CREATE OR REPLACE FUNCTION public.superadmin_tenant_subscriptions(p_user_id uuid)
            RETURNS SETOF public.tenant_subscriptions
            LANGUAGE sql STABLE SECURITY DEFINER
            SET search_path TO 'public'
            AS \$\$
                SELECT ts.*
                FROM tenant_subscriptions ts
                WHERE EXISTS (
                    SELECT 1 FROM users u
                    WHERE u.id = p_user_id AND u.is_super_admin AND u.status = 'ACTIVE'
                );
            \$\$
        ");

        DB::statement("
            CREATE OR REPLACE FUNCTION public.superadmin_companies(p_user_id uuid)
            RETURNS SETOF public.companies
            LANGUAGE sql STABLE SECURITY DEFINER
            SET search_path TO 'public'
            AS \$\$
                SELECT c.*
                FROM companies c
                WHERE EXISTS (
                    SELECT 1 FROM users u
                    WHERE u.id = p_user_id AND u.is_super_admin AND u.status = 'ACTIVE'
                );
            \$\$
        ");

        DB::statement('GRANT EXECUTE ON FUNCTION public.superadmin_tenant_subscriptions(uuid) TO erp_app');
        DB::statement('GRANT EXECUTE ON FUNCTION public.superadmin_companies(uuid) TO erp_app');
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS public.superadmin_tenant_subscriptions(uuid)');
        DB::statement('DROP FUNCTION IF EXISTS public.superadmin_companies(uuid)');
    }
};
