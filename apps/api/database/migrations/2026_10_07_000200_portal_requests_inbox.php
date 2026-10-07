<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Bandeja "Solicitudes del portal" del prototipo: cada solicitud lleva su
    // número (SOL-…), el servicio que pide el cliente, los estados Recibida →
    // En revisión → Cotizada / Rechazada y el motivo del rechazo. Solo añade.
    // El permiso para ver la bandeja (portal.view) y el de gestionarla
    // (portal.manage, que ya existía sin uso) vienen con el módulo Cotizaciones.
    public function up(): void
    {
        Schema::table('portal_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('portal_requests', 'request_number')) {
                $table->string('request_number', 100)->nullable();
                $table->unique(['tenant_id', 'request_number']);
            }
            if (! Schema::hasColumn('portal_requests', 'service_id')) {
                $table->uuid('service_id')->nullable();
                $table->foreign('service_id')->references('id')->on('services')->nullOnDelete();
            }
            if (! Schema::hasColumn('portal_requests', 'resolution_note')) {
                $table->text('resolution_note')->nullable();
            }
        });

        // Las solicitudes anteriores (estado OPEN, sin número) pasan a Recibida.
        DB::statement("
            UPDATE portal_requests p
            SET request_number = 'SOL-' || to_char(p.created_at, 'YYYY') || '-' || lpad(n.position::text, 4, '0')
            FROM (
                SELECT id, row_number() OVER (PARTITION BY tenant_id, to_char(created_at, 'YYYY') ORDER BY created_at, id) AS position
                FROM portal_requests
                WHERE request_number IS NULL
            ) n
            WHERE n.id = p.id
        ");
        DB::statement("UPDATE portal_requests SET status = CASE WHEN quotation_id IS NOT NULL THEN 'QUOTED' ELSE 'SUBMITTED' END WHERE status NOT IN ('SUBMITTED', 'IN_REVIEW', 'QUOTED', 'REJECTED')");
        DB::statement("ALTER TABLE portal_requests ALTER COLUMN status SET DEFAULT 'SUBMITTED'");
        DB::statement('ALTER TABLE portal_requests DROP CONSTRAINT IF EXISTS portal_requests_status_check');
        DB::statement("ALTER TABLE portal_requests ADD CONSTRAINT portal_requests_status_check CHECK (status IN ('SUBMITTED', 'IN_REVIEW', 'QUOTED', 'REJECTED'))");

        DB::table('permissions')->insertOrIgnore([
            'code' => 'portal.view',
            'name' => 'Ver solicitudes del portal',
            'description' => 'Ver la bandeja de solicitudes que los clientes envían desde el portal.',
        ]);

        DB::statement("
            UPDATE module_catalog
            SET permission_prefixes = permission_prefixes || '[\"portal\"]'::jsonb
            WHERE code = 'COTIZACIONES' AND NOT permission_prefixes @> '[\"portal\"]'::jsonb
        ");

        // Quien ya ve o gestiona cotizaciones ve o gestiona la bandeja.
        foreach (['view', 'manage'] as $action) {
            DB::statement("
                INSERT INTO role_permissions (role_id, permission_id)
                SELECT rp.role_id, portal.id
                FROM role_permissions rp
                JOIN permissions quotations ON quotations.id = rp.permission_id AND quotations.code = 'quotations.{$action}'
                CROSS JOIN permissions portal
                WHERE portal.code = 'portal.{$action}'
                ON CONFLICT DO NOTHING
            ");
        }
    }

    public function down(): void
    {
        DB::statement("
            DELETE FROM role_permissions
            WHERE permission_id IN (SELECT id FROM permissions WHERE code IN ('portal.view', 'portal.manage'))
        ");
        DB::table('permissions')->where('code', 'portal.view')->delete();
        DB::statement("UPDATE module_catalog SET permission_prefixes = permission_prefixes - 'portal' WHERE code = 'COTIZACIONES'");

        DB::statement('ALTER TABLE portal_requests DROP CONSTRAINT IF EXISTS portal_requests_status_check');
        DB::statement("ALTER TABLE portal_requests ALTER COLUMN status SET DEFAULT 'OPEN'");
        Schema::table('portal_requests', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'request_number']);
            $table->dropForeign(['service_id']);
            $table->dropColumn(['request_number', 'service_id', 'resolution_note']);
        });
    }
};
