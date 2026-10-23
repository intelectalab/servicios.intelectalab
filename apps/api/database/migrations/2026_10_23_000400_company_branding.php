<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Marca de cada empresa (Configuración → Marca de la empresa): su logo, los colores que salen
 * de él y los datos de contacto que van en el pie de los documentos.
 *
 * - El logo se guarda ya limpio: el servidor lo vuelve a dibujar como PNG (sin metadatos ni nada
 *   oculto) y en un tamaño acotado, así que la base solo recibe una imagen pequeña y sana.
 * - Los colores son hexadecimales en mayúsculas (#1B8FC9); sin marca, los documentos usan el
 *   azul de siempre.
 * - Una fila por empresa, aislada por la regla de siempre. La aplicación no la borra: quitar el
 *   logo es dejarlo vacío.
 *
 * Solo añade. Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS company_branding (
                tenant_id uuid PRIMARY KEY REFERENCES tenants(id) ON DELETE CASCADE,
                logo bytea,
                logo_width integer,
                logo_height integer,
                logo_sha256 char(64),
                primary_color char(7) NOT NULL DEFAULT '#1B8FC9',
                secondary_color char(7),
                palette jsonb NOT NULL DEFAULT '[]'::jsonb,
                address varchar(300),
                phone varchar(40),
                email varchar(160),
                website varchar(160),
                updated_by uuid REFERENCES users(id) ON DELETE SET NULL,
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT company_branding_logo_size CHECK (logo IS NULL OR octet_length(logo) <= 524288),
                CONSTRAINT company_branding_logo_data CHECK ((logo IS NULL) = (logo_sha256 IS NULL)),
                CONSTRAINT company_branding_primary CHECK (primary_color ~ '^#[0-9A-F]{6}$'),
                CONSTRAINT company_branding_secondary CHECK (secondary_color IS NULL OR secondary_color ~ '^#[0-9A-F]{6}$'),
                CONSTRAINT company_branding_palette CHECK (jsonb_typeof(palette) = 'array' AND jsonb_array_length(palette) <= 8)
            );
            ALTER TABLE company_branding ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON company_branding;
            CREATE POLICY tenant_isolation ON company_branding
                USING (tenant_id = app_tenant_id())
                WITH CHECK (tenant_id = app_tenant_id());
            REVOKE DELETE, TRUNCATE ON company_branding FROM erp_app;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS company_branding;');
    }
};
