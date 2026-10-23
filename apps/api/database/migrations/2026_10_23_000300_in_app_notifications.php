<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Notificaciones dentro del sistema (campana de la cabecera y centro de notificaciones).
 *
 * La tabla `notifications` ya existía sin uso. Se le añade lo que necesita un aviso: qué lo
 * originó (`event`), a qué pantalla lleva (`link`, siempre una ruta interna) y cuándo se leyó.
 *
 * - `channel`: IN_APP (se ve en la campana) o EMAIL (la persona apagó la campana y solo pidió
 *   correo: queda el registro de que se le avisó).
 * - La aplicación solo crea avisos y marca su lectura: no cambia su texto ni los borra, y un
 *   aviso leído no vuelve a "no leído".
 *
 * Solo añade. Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE notifications
                ADD COLUMN IF NOT EXISTS event varchar(60),
                ADD COLUMN IF NOT EXISTS link varchar(300),
                ADD COLUMN IF NOT EXISTS read_at timestamptz,
                ADD COLUMN IF NOT EXISTS actor_user_id uuid REFERENCES users(id) ON DELETE SET NULL;

            ALTER TABLE notifications DROP CONSTRAINT IF EXISTS notifications_channel_check;
            ALTER TABLE notifications ADD CONSTRAINT notifications_channel_check
                CHECK (channel IN ('IN_APP', 'EMAIL')) NOT VALID;
            -- El enlace lleva a una pantalla del sistema, nunca a otro sitio.
            ALTER TABLE notifications DROP CONSTRAINT IF EXISTS notifications_link_internal;
            ALTER TABLE notifications ADD CONSTRAINT notifications_link_internal
                CHECK (link IS NULL OR (link ~ '^/[A-Za-z0-9/_?=&.%-]*$' AND link NOT LIKE '//%')) NOT VALID;

            CREATE INDEX IF NOT EXISTS idx_notifications_inbox
                ON notifications (tenant_id, user_id, created_at DESC) WHERE channel = 'IN_APP';

            -- Leído es para siempre.
            CREATE OR REPLACE FUNCTION notifications_read_once() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.read_at IS NOT NULL AND NEW.read_at IS DISTINCT FROM OLD.read_at THEN
                    RAISE EXCEPTION 'Una notificación leída no cambia.' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$;
            DROP TRIGGER IF EXISTS notifications_read_once ON notifications;
            CREATE TRIGGER notifications_read_once BEFORE UPDATE ON notifications
                FOR EACH ROW EXECUTE FUNCTION notifications_read_once();

            REVOKE UPDATE, DELETE ON notifications FROM erp_app;
            GRANT UPDATE (read_at) ON notifications TO erp_app;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            GRANT UPDATE ON notifications TO erp_app;
            DROP TRIGGER IF EXISTS notifications_read_once ON notifications;
            DROP FUNCTION IF EXISTS notifications_read_once();
            DROP INDEX IF EXISTS idx_notifications_inbox;
            ALTER TABLE notifications DROP CONSTRAINT IF EXISTS notifications_link_internal;
            ALTER TABLE notifications DROP CONSTRAINT IF EXISTS notifications_channel_check;
            ALTER TABLE notifications
                DROP COLUMN IF EXISTS actor_user_id, DROP COLUMN IF EXISTS read_at,
                DROP COLUMN IF EXISTS link, DROP COLUMN IF EXISTS event;
        SQL);
    }
};
