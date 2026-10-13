<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El documento de un entregable pasa a ser un archivo subido (documento con versiones), no solo
 * un enlace escrito.
 *
 * - `deliverables.document_id`: el documento adjunto. `document_ref` se conserva: guarda el nombre
 *   del archivo (o el enlace de los entregables anteriores), así que la regla "sin documento no
 *   pasa a revisión" sigue siendo la misma en la base.
 * - `documents.visibility`: Interno o visible para el cliente en el portal.
 * - Las versiones de un documento no se destruyen: la aplicación no puede modificarlas ni borrarlas.
 *
 * Solo añade. Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE deliverables
                ADD COLUMN IF NOT EXISTS document_id uuid REFERENCES documents(id) ON DELETE RESTRICT;
            CREATE INDEX IF NOT EXISTS idx_deliverables_document ON deliverables (document_id) WHERE document_id IS NOT NULL;

            ALTER TABLE documents
                ADD COLUMN IF NOT EXISTS visibility varchar(20) NOT NULL DEFAULT 'INTERNAL';
            ALTER TABLE documents DROP CONSTRAINT IF EXISTS chk_document_visibility;
            ALTER TABLE documents ADD CONSTRAINT chk_document_visibility CHECK (visibility IN ('INTERNAL', 'PORTAL'));

            REVOKE UPDATE, DELETE ON document_versions FROM erp_app;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            GRANT UPDATE, DELETE ON document_versions TO erp_app;
            ALTER TABLE documents DROP CONSTRAINT IF EXISTS chk_document_visibility;
            ALTER TABLE documents DROP COLUMN IF EXISTS visibility;
            DROP INDEX IF EXISTS idx_deliverables_document;
            ALTER TABLE deliverables DROP COLUMN IF EXISTS document_id;
        SQL);
    }
};
