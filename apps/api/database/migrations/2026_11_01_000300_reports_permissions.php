<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reportes (Control → Reportes): vienen con el plan base. Cada reporte pide además el permiso del
 * módulo de donde salen sus datos, y respeta el alcance de quien lo mira.
 *
 * Según la matriz del prototipo: Administrador, Gerencia y Finanzas ven y exportan; Ventas,
 * Operaciones y Soporte ven (cada uno con su alcance); Técnico no.
 *
 * Solo añade. Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            UPDATE permissions SET name = 'Ver reportes', description = 'Ver los reportes de los módulos que el rol puede ver, con su mismo alcance.' WHERE code = 'reports.view';
            UPDATE permissions SET name = 'Exportar reportes', description = 'Exportar un reporte a Excel o CSV. Queda en auditoría.' WHERE code = 'reports.export';

            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN ('reports.view', 'reports.export')
            WHERE r.tenant_id IS NOT NULL AND (r.is_system OR upper(r.code) IN ('ADMIN', 'GERENCIA', 'FINANZAS'))
            ON CONFLICT DO NOTHING;

            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code = 'reports.view'
            WHERE r.tenant_id IS NOT NULL AND upper(r.code) IN ('VENTAS', 'OPERACIONES', 'SOPORTE')
            ON CONFLICT DO NOTHING;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM role_permissions WHERE permission_id IN (SELECT id FROM permissions WHERE code IN ('reports.view', 'reports.export'));
        SQL);
    }
};
