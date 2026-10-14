<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plantillas de checklist con pantalla propia (Operaciones).
 *
 * - La plantilla puede ir asociada a un servicio del catálogo (se propone al crear la OT) y lleva
 *   `lock_version` para que dos personas no se pisen al editarla. Nombre único por empresa.
 * - Un ítem puede pedir evidencia obligatoria: la orden de trabajo copia la marca y la base no deja
 *   dar por hecho ese ítem sin su archivo.
 * - Ni las plantillas ni sus ítems se borran: la plantilla se desactiva y, al editarla, los ítems
 *   anteriores quedan retirados (`removed_at`), con su historia.
 * - Permisos propios `checklists.view/manage/export` en el módulo Órdenes de trabajo, con el
 *   reparto de "Entregables y checklists" del prototipo: Operaciones ve y gestiona, Gerencia ve,
 *   el Administrador todo. Quien ya gestionaba plantillas por la API (`operations.manage`) conserva
 *   ver y gestionar.
 *
 * Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- 1. Plantilla: servicio asociado, versión de edición, quién la creó y nombre único.
            ALTER TABLE checklists ADD COLUMN IF NOT EXISTS service_id uuid REFERENCES services(id) ON DELETE SET NULL;
            ALTER TABLE checklists ADD COLUMN IF NOT EXISTS lock_version integer NOT NULL DEFAULT 0;
            ALTER TABLE checklists ADD COLUMN IF NOT EXISTS created_by uuid REFERENCES users(id) ON DELETE SET NULL;
            CREATE UNIQUE INDEX IF NOT EXISTS checklists_tenant_name_key ON checklists (tenant_id, lower(btrim(name)));
            CREATE INDEX IF NOT EXISTS idx_checklists_service ON checklists (tenant_id, service_id);
            ALTER TABLE checklists DROP CONSTRAINT IF EXISTS checklists_name_check;
            ALTER TABLE checklists ADD CONSTRAINT checklists_name_check CHECK (btrim(name) <> '');

            -- 2. Ítems: evidencia obligatoria y retiro en vez de borrado. El orden es único entre los vigentes.
            ALTER TABLE checklist_items ADD COLUMN IF NOT EXISTS evidence_required boolean NOT NULL DEFAULT false;
            ALTER TABLE checklist_items ADD COLUMN IF NOT EXISTS removed_at timestamptz;
            ALTER TABLE checklist_items DROP CONSTRAINT IF EXISTS checklist_items_checklist_id_position_key;
            CREATE UNIQUE INDEX IF NOT EXISTS checklist_items_current_position_key ON checklist_items (checklist_id, position) WHERE removed_at IS NULL;
            ALTER TABLE checklist_items DROP CONSTRAINT IF EXISTS checklist_items_label_check;
            ALTER TABLE checklist_items ADD CONSTRAINT checklist_items_label_check CHECK (btrim(label) <> '');

            -- Un ítem retirado no vuelve ni cambia; uno vigente solo se retira.
            CREATE OR REPLACE FUNCTION check_checklist_item_update() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.removed_at IS NOT NULL THEN
                    RAISE EXCEPTION 'Un ítem retirado de la plantilla no se modifica.' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.label IS DISTINCT FROM OLD.label OR NEW.required IS DISTINCT FROM OLD.required
                   OR NEW.evidence_required IS DISTINCT FROM OLD.evidence_required OR NEW.checklist_id IS DISTINCT FROM OLD.checklist_id THEN
                    RAISE EXCEPTION 'Un ítem de plantilla no se edita: se retira y se agrega otro.' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$;
            DROP TRIGGER IF EXISTS trg_checklist_items_update ON checklist_items;
            CREATE TRIGGER trg_checklist_items_update BEFORE UPDATE ON checklist_items FOR EACH ROW EXECUTE FUNCTION check_checklist_item_update();

            REVOKE DELETE ON checklists FROM erp_app;
            REVOKE DELETE ON checklist_items FROM erp_app;

            -- 3. La orden de trabajo copia la marca; sin su archivo, el ítem no queda hecho.
            ALTER TABLE work_order_checklist_items ADD COLUMN IF NOT EXISTS evidence_required boolean NOT NULL DEFAULT false;
            ALTER TABLE work_order_checklist_items DROP CONSTRAINT IF EXISTS work_order_checklist_items_evidence_check;
            ALTER TABLE work_order_checklist_items ADD CONSTRAINT work_order_checklist_items_evidence_check
                CHECK (NOT (done AND evidence_required AND evidence_document_id IS NULL));

            -- 4. Permisos propios.
            INSERT INTO permissions (code, name, description) VALUES
                ('checklists.view', 'Ver plantillas de checklist', 'Plantillas de checklist que se copian a las órdenes de trabajo.'),
                ('checklists.manage', 'Gestionar plantillas de checklist', 'Crear, editar, duplicar, importar, desactivar y reactivar plantillas de checklist.'),
                ('checklists.export', 'Exportar plantillas de checklist', 'Descargar las plantillas y sus ítems en Excel o CSV.')
            ON CONFLICT (code) DO NOTHING;

            UPDATE module_catalog SET permission_prefixes = permission_prefixes || '["checklists"]'::jsonb
            WHERE upper(code) = 'ORDENES_TRABAJO' AND NOT permission_prefixes ? 'checklists';

            -- 5. Reparto. El Administrador de cada empresa: todo.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code LIKE 'checklists.%'
            WHERE r.tenant_id IS NOT NULL AND (r.is_system OR upper(r.code) = 'ADMIN')
            ON CONFLICT DO NOTHING;
            -- Operaciones: ve y gestiona (no exporta, como en el prototipo).
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN ('checklists.view', 'checklists.manage')
            WHERE r.tenant_id IS NOT NULL AND upper(r.code) = 'OPERACIONES'
            ON CONFLICT DO NOTHING;
            -- Gerencia: solo ve.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code = 'checklists.view'
            WHERE r.tenant_id IS NOT NULL AND upper(r.code) = 'GERENCIA'
            ON CONFLICT DO NOTHING;
            -- Quien ya gestionaba plantillas por la API las conserva.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT rp.role_id, p.id FROM role_permissions rp
            JOIN permissions old ON old.id = rp.permission_id AND old.code = 'operations.manage'
            JOIN permissions p ON p.code IN ('checklists.view', 'checklists.manage')
            ON CONFLICT DO NOTHING;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM role_permissions WHERE permission_id IN (SELECT id FROM permissions WHERE code LIKE 'checklists.%');
            DELETE FROM permissions WHERE code LIKE 'checklists.%';
            UPDATE module_catalog SET permission_prefixes = permission_prefixes - 'checklists' WHERE upper(code) = 'ORDENES_TRABAJO';

            ALTER TABLE work_order_checklist_items DROP CONSTRAINT IF EXISTS work_order_checklist_items_evidence_check;
            ALTER TABLE work_order_checklist_items DROP COLUMN IF EXISTS evidence_required;

            GRANT DELETE ON checklists TO erp_app;
            GRANT DELETE ON checklist_items TO erp_app;
            DROP TRIGGER IF EXISTS trg_checklist_items_update ON checklist_items;
            DROP FUNCTION IF EXISTS check_checklist_item_update();
            ALTER TABLE checklist_items DROP CONSTRAINT IF EXISTS checklist_items_label_check;
            DROP INDEX IF EXISTS checklist_items_current_position_key;
            DELETE FROM checklist_items WHERE removed_at IS NOT NULL;
            ALTER TABLE checklist_items ADD CONSTRAINT checklist_items_checklist_id_position_key UNIQUE (checklist_id, position);
            ALTER TABLE checklist_items DROP COLUMN IF EXISTS removed_at;
            ALTER TABLE checklist_items DROP COLUMN IF EXISTS evidence_required;

            ALTER TABLE checklists DROP CONSTRAINT IF EXISTS checklists_name_check;
            DROP INDEX IF EXISTS idx_checklists_service;
            DROP INDEX IF EXISTS checklists_tenant_name_key;
            ALTER TABLE checklists DROP COLUMN IF EXISTS created_by;
            ALTER TABLE checklists DROP COLUMN IF EXISTS lock_version;
            ALTER TABLE checklists DROP COLUMN IF EXISTS service_id;
        SQL);
    }
};
