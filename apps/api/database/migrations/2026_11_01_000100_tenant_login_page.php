<?php

use App\Services\LoginSlug;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Página de ingreso con la marca de cada empresa (/e/{nombre-corto}).
 *
 * - `tenants.login_slug`: nombre corto del enlace, único, en minúsculas. A las empresas que ya
 *   existen se les genera uno a partir de su nombre (lo pueden cambiar en Marca).
 * - `company_branding.login_welcome`: texto de bienvenida opcional de esa pantalla.
 *
 * Solo añade. Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE tenants ADD COLUMN IF NOT EXISTS login_slug citext;
            ALTER TABLE tenants DROP CONSTRAINT IF EXISTS tenants_login_slug_format;
            ALTER TABLE tenants ADD CONSTRAINT tenants_login_slug_format
                CHECK (login_slug IS NULL OR (login_slug::text ~ '^[a-z0-9]([a-z0-9-]{1,38}[a-z0-9])$' AND position('--' in login_slug::text) = 0));
            CREATE UNIQUE INDEX IF NOT EXISTS tenants_login_slug_unique ON tenants (login_slug);

            ALTER TABLE company_branding ADD COLUMN IF NOT EXISTS login_welcome varchar(240);
        SQL);

        foreach (DB::table('tenants')->whereNull('login_slug')->orderBy('created_at')->get(['id', 'name']) as $tenant) {
            DB::table('tenants')->where('id', $tenant->id)->update(['login_slug' => LoginSlug::forName((string) $tenant->name, $tenant->id)]);
        }
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE company_branding DROP COLUMN IF EXISTS login_welcome;
            DROP INDEX IF EXISTS tenants_login_slug_unique;
            ALTER TABLE tenants DROP CONSTRAINT IF EXISTS tenants_login_slug_format;
            ALTER TABLE tenants DROP COLUMN IF EXISTS login_slug;
        SQL);
    }
};
