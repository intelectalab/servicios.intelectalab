<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Credential Vault (Control → Credential Vault).
 *
 * - "Pertenece a" (texto libre: un cliente, un servidor, la facturación electrónica…) y los tipos
 *   del prototipo (Servidor, DNS, Hosting, Cloud, API key, SMTP, Certificado, Base de datos); los
 *   tipos anteriores se pasan al equivalente.
 * - Motivo de cada acceso (revelar, usar, rotar, revocar) y de cada versión. Nunca el valor.
 * - Candados: la aplicación no puede cambiar ni borrar versiones ni el registro de accesos, y no
 *   borra credenciales (se revocan).
 * - Permiso `credentials.export` y nombres de los permisos según las acciones del Vault. Vienen con
 *   el plan base (como Auditoría). Según la matriz del prototipo: Administrador todo; Gerencia y
 *   Finanzas ver; Operaciones y Técnico ver y usar.
 *
 * Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE credential_records ADD COLUMN IF NOT EXISTS owner_label varchar(150);

            UPDATE credential_records SET credential_type = CASE lower(credential_type)
                WHEN 'api_key' THEN 'API_KEY' WHEN 'token' THEN 'API_KEY'
                WHEN 'certificate' THEN 'CERTIFICADO'
                WHEN 'password' THEN 'SERVIDOR' WHEN 'ssh_key' THEN 'SERVIDOR'
                ELSE credential_type END
            WHERE credential_type NOT IN ('SERVIDOR', 'DNS', 'HOSTING', 'CLOUD', 'API_KEY', 'SMTP', 'CERTIFICADO', 'BASE_DATOS');

            ALTER TABLE credential_records ADD CONSTRAINT credential_records_type_check
                CHECK (credential_type IN ('SERVIDOR', 'DNS', 'HOSTING', 'CLOUD', 'API_KEY', 'SMTP', 'CERTIFICADO', 'BASE_DATOS'));

            ALTER TABLE credential_access_logs ADD COLUMN IF NOT EXISTS reason varchar(500);
            ALTER TABLE credential_versions ADD COLUMN IF NOT EXISTS reason varchar(500);

            CREATE INDEX IF NOT EXISTS credential_access_logs_credential_idx ON credential_access_logs (credential_id, created_at DESC);

            REVOKE UPDATE, DELETE ON credential_versions FROM erp_app;
            REVOKE UPDATE, DELETE ON credential_access_logs FROM erp_app;
            REVOKE DELETE ON credential_records FROM erp_app;

            INSERT INTO permissions (code, name, description) VALUES
                ('credentials.export', 'Exportar credenciales', 'Exportar el listado del Vault (solo datos generales, nunca los valores).')
            ON CONFLICT (code) DO NOTHING;

            UPDATE permissions SET name = 'Ver credenciales', description = 'Ver los datos generales de las credenciales y su historial, nunca el valor.' WHERE code = 'credentials.view';
            UPDATE permissions SET name = 'Crear y rotar credenciales', description = 'Guardar una credencial nueva y cambiar su valor (nueva versión).' WHERE code = 'credentials.manage';
            UPDATE permissions SET name = 'Usar credenciales', description = 'Copiar el valor al portapapeles sin verlo en pantalla. Pide motivo y queda registrado.' WHERE code = 'credentials.use';
            UPDATE permissions SET name = 'Revelar credenciales', description = 'Ver el valor durante 20 segundos. Pide motivo y queda registrado.' WHERE code = 'credentials.reveal';
            UPDATE permissions SET name = 'Revocar credenciales', description = 'Dejar una credencial sin uso. No se borra su historial.' WHERE code = 'credentials.delete';

            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code LIKE 'credentials.%'
            WHERE r.tenant_id IS NOT NULL AND (r.is_system OR upper(r.code) = 'ADMIN')
            ON CONFLICT DO NOTHING;

            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code = 'credentials.view'
            WHERE r.tenant_id IS NOT NULL AND upper(r.code) IN ('GERENCIA', 'FINANZAS', 'OPERACIONES', 'TECNICO')
            ON CONFLICT DO NOTHING;

            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code = 'credentials.use'
            WHERE r.tenant_id IS NOT NULL AND upper(r.code) IN ('OPERACIONES', 'TECNICO')
            ON CONFLICT DO NOTHING;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM role_permissions WHERE permission_id = (SELECT id FROM permissions WHERE code = 'credentials.export');
            DELETE FROM permissions WHERE code = 'credentials.export';
            GRANT UPDATE, DELETE ON credential_versions, credential_access_logs TO erp_app;
            GRANT DELETE ON credential_records TO erp_app;
            DROP INDEX IF EXISTS credential_access_logs_credential_idx;
            ALTER TABLE credential_versions DROP COLUMN IF EXISTS reason;
            ALTER TABLE credential_access_logs DROP COLUMN IF EXISTS reason;
            ALTER TABLE credential_records DROP CONSTRAINT IF EXISTS credential_records_type_check;
            ALTER TABLE credential_records DROP COLUMN IF EXISTS owner_label;
        SQL);
    }
};
