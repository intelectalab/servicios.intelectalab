<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * App del técnico: lo que le faltaba a la orden de trabajo para el trabajo en campo.
 *
 * - `work_orders.latitude` / `longitude`: ubicación de la visita (opcional), para el mapa y la
 *   ruta del día. Van las dos o ninguna.
 * - `work_order_checklist_items.evidence_document_id`: la foto de evidencia de un ítem, como
 *   documento con versiones.
 *
 * Solo añade. Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE work_orders
                ADD COLUMN IF NOT EXISTS latitude numeric(9,6),
                ADD COLUMN IF NOT EXISTS longitude numeric(9,6);
            ALTER TABLE work_orders DROP CONSTRAINT IF EXISTS work_orders_coordinates_check;
            ALTER TABLE work_orders ADD CONSTRAINT work_orders_coordinates_check CHECK (
                (latitude IS NULL AND longitude IS NULL)
                OR (latitude IS NOT NULL AND longitude IS NOT NULL AND latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180)
            );

            ALTER TABLE work_order_checklist_items
                ADD COLUMN IF NOT EXISTS evidence_document_id uuid REFERENCES documents(id) ON DELETE RESTRICT;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE work_order_checklist_items DROP COLUMN IF EXISTS evidence_document_id;
            ALTER TABLE work_orders DROP CONSTRAINT IF EXISTS work_orders_coordinates_check;
            ALTER TABLE work_orders DROP COLUMN IF EXISTS latitude, DROP COLUMN IF EXISTS longitude;
        SQL);
    }
};
