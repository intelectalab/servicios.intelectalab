<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mi perfil: sesiones recientes y preferencias de notificación.
 *
 * 1. Cada sesión guarda desde dónde se abrió (navegador, dirección) y si pasó la verificación
 *    en dos pasos, para que la persona reconozca las suyas y cierre las demás.
 * 2. `notification_preferences`: por qué canales quiere avisos cada persona de una empresa.
 *    Sin fila, los tres canales están activos.
 *
 * Solo añade. Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE personal_access_tokens
                ADD COLUMN IF NOT EXISTS user_agent varchar(500),
                ADD COLUMN IF NOT EXISTS ip_address varchar(45),
                ADD COLUMN IF NOT EXISTS mfa boolean NOT NULL DEFAULT false;

            CREATE TABLE IF NOT EXISTS notification_preferences (
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                user_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                in_app boolean NOT NULL DEFAULT true,
                email boolean NOT NULL DEFAULT true,
                whatsapp boolean NOT NULL DEFAULT true,
                updated_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (tenant_id, user_id)
            );
            ALTER TABLE notification_preferences ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON notification_preferences;
            CREATE POLICY tenant_isolation ON notification_preferences
                USING (tenant_id = app_tenant_id())
                WITH CHECK (tenant_id = app_tenant_id());
            -- Las preferencias se crean y se cambian; no se borran desde la aplicación.
            REVOKE DELETE ON notification_preferences FROM erp_app;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS notification_preferences;
            ALTER TABLE personal_access_tokens
                DROP COLUMN IF EXISTS user_agent, DROP COLUMN IF EXISTS ip_address, DROP COLUMN IF EXISTS mfa;
        SQL);
    }
};
