<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Una cuenta de plataforma (users.is_super_admin) no puede ser miembro de un
    // tenant, y un miembro de un tenant no puede volverse super admin: así la
    // consola de plataforma nunca queda con contexto de una empresa.
    // Las filas que ya existían no se tocan; solo se validan las altas y cambios.
    // Debe correrse como erp_user (dueño de las tablas).
    public function up(): void
    {
        DB::statement("
            CREATE OR REPLACE FUNCTION public.prevent_super_admin_membership() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER
            SET search_path TO 'public'
            AS \$\$
            BEGIN
                IF EXISTS (SELECT 1 FROM users WHERE id = NEW.user_id AND is_super_admin) THEN
                    RAISE EXCEPTION 'Una cuenta de plataforma (super admin) no puede pertenecer a un tenant.'
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            \$\$
        ");

        DB::statement('
            CREATE TRIGGER trg_tenant_users_no_super_admin
            BEFORE INSERT OR UPDATE OF user_id ON public.tenant_users
            FOR EACH ROW EXECUTE FUNCTION public.prevent_super_admin_membership()
        ');

        DB::statement("
            CREATE OR REPLACE FUNCTION public.prevent_member_becoming_super_admin() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER
            SET search_path TO 'public'
            AS \$\$
            BEGIN
                IF NEW.is_super_admin AND EXISTS (SELECT 1 FROM tenant_users WHERE user_id = NEW.id) THEN
                    RAISE EXCEPTION 'Un usuario que pertenece a un tenant no puede ser super admin.'
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            \$\$
        ");

        DB::statement('
            CREATE TRIGGER trg_users_super_admin_without_tenant
            BEFORE UPDATE OF is_super_admin ON public.users
            FOR EACH ROW
            WHEN (NEW.is_super_admin AND NOT OLD.is_super_admin)
            EXECUTE FUNCTION public.prevent_member_becoming_super_admin()
        ');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_tenant_users_no_super_admin ON public.tenant_users');
        DB::statement('DROP TRIGGER IF EXISTS trg_users_super_admin_without_tenant ON public.users');
        DB::statement('DROP FUNCTION IF EXISTS public.prevent_super_admin_membership()');
        DB::statement('DROP FUNCTION IF EXISTS public.prevent_member_becoming_super_admin()');
    }
};
