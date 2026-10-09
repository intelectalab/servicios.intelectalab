<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permisos de la pantalla Auditoría (Control).
 *
 * - `audit.view` ya existía sin que ningún rol lo tuviera; se añade `audit.export`.
 * - Reparto de la matriz del prototipo: Administrador y Gerencia ven y exportan la auditoría
 *   de toda la empresa; los demás roles, nada.
 *
 * Solo añade. Se ejecuta con el usuario dueño de las tablas (erp_user): la aplicación no
 * puede escribir en `permissions`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore([
            'code' => 'audit.export', 'name' => 'Exportar auditoría', 'description' => 'Descargar el listado en Excel o CSV.',
        ]);

        DB::statement("
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r
            JOIN permissions p ON p.code IN ('audit.view', 'audit.export')
            WHERE r.tenant_id IS NOT NULL AND (r.is_system OR upper(r.code) IN ('ADMIN', 'GERENCIA'))
            ON CONFLICT DO NOTHING
        ");
    }

    public function down(): void
    {
        DB::table('role_permissions')
            ->whereIn('permission_id', DB::table('permissions')->whereIn('code', ['audit.view', 'audit.export'])->pluck('id'))
            ->delete();
        DB::table('permissions')->where('code', 'audit.export')->delete();
    }
};
