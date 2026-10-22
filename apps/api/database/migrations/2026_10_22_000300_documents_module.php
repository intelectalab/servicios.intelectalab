<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Documentos: la pantalla del prototipo (Control → Documentos) sobre las tablas que ya existían
 * (`documents`, `document_versions`, `document_links`, `file_objects`).
 *
 * - El documento nace en la sede de quien lo sube (alcance Sede), lleva `lock_version` para que dos
 *   personas no se pisen la edición y queda archivado con fecha y autor.
 * - Estados: ACTIVE (disponible), QUARANTINED (el antivirus encontró una amenaza: no se descarga) y
 *   ARCHIVED (deja de verse en el portal; no vuelve ni se edita).
 * - Del análisis antivirus queda el motor que lo hizo (`SIMULATED` mientras no haya uno real) y cuándo.
 * - La base garantiza que nada de esto se borra: ni documentos, ni versiones, ni vínculos, ni archivos;
 *   una versión, un vínculo y un archivo tampoco se cambian.
 * - Permisos `documents.create` (subir y nueva versión) y `documents.export`; `documents.manage`
 *   queda para editar, cambiar la visibilidad en el portal y archivar. Reparto de la matriz del
 *   prototipo y alcance por rol.
 *
 * Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- 1. Documento: sede, versión del registro, archivo.
            ALTER TABLE documents ADD COLUMN IF NOT EXISTS branch_id uuid REFERENCES branches(id) ON DELETE SET NULL;
            ALTER TABLE documents ADD COLUMN IF NOT EXISTS lock_version integer NOT NULL DEFAULT 0;
            ALTER TABLE documents ADD COLUMN IF NOT EXISTS archived_at timestamptz;
            ALTER TABLE documents ADD COLUMN IF NOT EXISTS archived_by uuid REFERENCES users(id) ON DELETE SET NULL;
            ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_status_check;
            ALTER TABLE documents ADD CONSTRAINT documents_status_check CHECK (status IN ('ACTIVE', 'QUARANTINED', 'ARCHIVED'));
            ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_archived_check;
            ALTER TABLE documents ADD CONSTRAINT documents_archived_check CHECK ((status = 'ARCHIVED') = (archived_at IS NOT NULL));
            ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_title_check;
            ALTER TABLE documents ADD CONSTRAINT documents_title_check CHECK (btrim(title) <> '') NOT VALID;
            CREATE INDEX IF NOT EXISTS idx_documents_tenant_status ON documents (tenant_id, status);
            CREATE INDEX IF NOT EXISTS idx_document_links_entity ON document_links (tenant_id, entity_type, entity_id);

            -- Un documento archivado no vuelve ni se edita; ni la empresa ni el autor cambian.
            CREATE OR REPLACE FUNCTION check_document_update() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.tenant_id IS DISTINCT FROM OLD.tenant_id OR NEW.created_by IS DISTINCT FROM OLD.created_by THEN
                    RAISE EXCEPTION 'La empresa y el autor de un documento no cambian.' USING ERRCODE = 'check_violation';
                END IF;
                IF OLD.status = 'ARCHIVED' AND (NEW.status IS DISTINCT FROM OLD.status OR NEW.title IS DISTINCT FROM OLD.title
                    OR NEW.document_type IS DISTINCT FROM OLD.document_type OR NEW.visibility IS DISTINCT FROM OLD.visibility) THEN
                    RAISE EXCEPTION 'Un documento archivado no se modifica.' USING ERRCODE = 'check_violation';
                END IF;
                -- Uno en cuarentena no se publica en el portal.
                IF NEW.status = 'QUARANTINED' AND NEW.visibility = 'PORTAL' AND OLD.visibility = 'INTERNAL' THEN
                    RAISE EXCEPTION 'Un documento en cuarentena no se publica en el portal.' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$;
            DROP TRIGGER IF EXISTS trg_documents_check_update ON documents;
            CREATE TRIGGER trg_documents_check_update BEFORE UPDATE ON documents FOR EACH ROW EXECUTE FUNCTION check_document_update();

            -- 2. Antivirus: qué motor analizó el archivo y cuándo.
            ALTER TABLE file_objects ADD COLUMN IF NOT EXISTS antivirus_engine varchar(30);
            ALTER TABLE file_objects ADD COLUMN IF NOT EXISTS scanned_at timestamptz;

            -- 3. Nada se borra; versiones, vínculos y archivos no se cambian.
            REVOKE DELETE ON documents FROM erp_app;
            REVOKE UPDATE, DELETE ON document_versions FROM erp_app;
            REVOKE UPDATE, DELETE ON document_links FROM erp_app;
            REVOKE UPDATE, DELETE ON file_objects FROM erp_app;

            -- 4. Permisos.
            UPDATE permissions SET name = 'Ver documentos', description = 'Documentos de la empresa, sus versiones y su registro de accesos; descargar por enlace temporal.'
            WHERE code = 'documents.view';
            UPDATE permissions SET name = 'Gestionar documentos', description = 'Editar título y categoría, mostrar u ocultar un documento en el portal del cliente y archivarlo.'
            WHERE code = 'documents.manage';
            INSERT INTO permissions (code, name, description) VALUES
                ('documents.view', 'Ver documentos', 'Documentos de la empresa, sus versiones y su registro de accesos; descargar por enlace temporal.'),
                ('documents.create', 'Subir documentos', 'Subir un documento vinculado a un cliente, contrato, proyecto u orden de trabajo, y subir versiones nuevas.'),
                ('documents.manage', 'Gestionar documentos', 'Editar título y categoría, mostrar u ocultar un documento en el portal del cliente y archivarlo.'),
                ('documents.export', 'Exportar documentos', 'Descargar el listado de documentos en Excel o CSV.')
            ON CONFLICT (code) DO NOTHING;

            -- Quien ya gestionaba documentos conserva subirlos.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT rp.role_id, p.id FROM role_permissions rp
            JOIN permissions old ON old.id = rp.permission_id AND old.code = 'documents.manage'
            JOIN permissions p ON p.code = 'documents.create'
            ON CONFLICT DO NOTHING;

            -- 5. Reparto de la matriz del prototipo. El Administrador de cada empresa: todo.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code LIKE 'documents.%'
            WHERE r.tenant_id IS NOT NULL AND (r.is_system OR upper(r.code) = 'ADMIN')
            ON CONFLICT DO NOTHING;
            -- Gerencia: ve y exporta.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN ('documents.view', 'documents.export')
            WHERE r.tenant_id IS NOT NULL AND upper(r.code) = 'GERENCIA'
            ON CONFLICT DO NOTHING;
            -- Operaciones: ve, sube y gestiona los de su sede.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN ('documents.view', 'documents.create', 'documents.manage')
            WHERE r.tenant_id IS NOT NULL AND upper(r.code) = 'OPERACIONES'
            ON CONFLICT DO NOTHING;
            -- Ventas, Técnico, Finanzas y Soporte: ven y suben.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN ('documents.view', 'documents.create')
            WHERE r.tenant_id IS NOT NULL AND upper(r.code) IN ('VENTAS', 'TECNICO', 'FINANZAS', 'SOPORTE')
            ON CONFLICT DO NOTHING;

            -- 6. Alcance por rol.
            ALTER TABLE role_scopes DROP CONSTRAINT IF EXISTS role_scopes_resource_check;
            ALTER TABLE role_scopes ADD CONSTRAINT role_scopes_resource_check
                CHECK (resource IN ('quotations', 'contracts', 'orders', 'portal', 'leads', 'opportunities', 'activities',
                                    'contracted_services', 'work_orders', 'projects', 'time_entries', 'cost_entries', 'agenda',
                                    'billing', 'receivables', 'payments', 'recurring', 'purchases', 'payables', 'budgets',
                                    'tickets', 'warranty', 'documents'));
            INSERT INTO role_scopes (role_id, tenant_id, resource, scope)
            SELECT r.id, r.tenant_id, 'documents', 'TENANT' FROM roles r
            WHERE upper(r.code) IN ('GERENCIA', 'FINANZAS', 'SOPORTE') AND r.tenant_id IS NOT NULL
            ON CONFLICT DO NOTHING;
            INSERT INTO role_scopes (role_id, tenant_id, resource, scope)
            SELECT r.id, r.tenant_id, 'documents', 'BRANCH' FROM roles r
            WHERE upper(r.code) = 'OPERACIONES' AND r.tenant_id IS NOT NULL
            ON CONFLICT DO NOTHING;
            INSERT INTO role_scopes (role_id, tenant_id, resource, scope)
            SELECT r.id, r.tenant_id, 'documents', 'OWN' FROM roles r
            WHERE upper(r.code) IN ('VENTAS', 'TECNICO') AND r.tenant_id IS NOT NULL
            ON CONFLICT DO NOTHING;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM role_scopes WHERE resource = 'documents';
            ALTER TABLE role_scopes DROP CONSTRAINT IF EXISTS role_scopes_resource_check;
            ALTER TABLE role_scopes ADD CONSTRAINT role_scopes_resource_check
                CHECK (resource IN ('quotations', 'contracts', 'orders', 'portal', 'leads', 'opportunities', 'activities',
                                    'contracted_services', 'work_orders', 'projects', 'time_entries', 'cost_entries', 'agenda',
                                    'billing', 'receivables', 'payments', 'recurring', 'purchases', 'payables', 'budgets',
                                    'tickets', 'warranty'));

            DELETE FROM role_permissions WHERE permission_id IN (SELECT id FROM permissions WHERE code IN ('documents.create', 'documents.export'));
            DELETE FROM permissions WHERE code IN ('documents.create', 'documents.export');
            DELETE FROM role_permissions WHERE permission_id IN (SELECT id FROM permissions WHERE code IN ('documents.view', 'documents.manage'))
                AND role_id NOT IN (SELECT id FROM roles WHERE is_system OR upper(code) = 'ADMIN');

            GRANT UPDATE, DELETE ON document_versions, document_links, file_objects TO erp_app;
            GRANT DELETE ON documents TO erp_app;

            DROP TRIGGER IF EXISTS trg_documents_check_update ON documents;
            DROP FUNCTION IF EXISTS check_document_update();
            UPDATE documents SET status = 'ACTIVE', archived_at = NULL WHERE status <> 'ACTIVE';
            ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_status_check;
            ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_archived_check;
            ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_title_check;
            DROP INDEX IF EXISTS idx_documents_tenant_status;
            DROP INDEX IF EXISTS idx_document_links_entity;
            ALTER TABLE documents DROP COLUMN IF EXISTS archived_by;
            ALTER TABLE documents DROP COLUMN IF EXISTS archived_at;
            ALTER TABLE documents DROP COLUMN IF EXISTS lock_version;
            ALTER TABLE documents DROP COLUMN IF EXISTS branch_id;
            ALTER TABLE file_objects DROP COLUMN IF EXISTS scanned_at;
            ALTER TABLE file_objects DROP COLUMN IF EXISTS antivirus_engine;
        SQL);
    }
};
