<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ventas puede dar de alta al cliente al convertir una oportunidad ganada, como en el
 * prototipo: recibe `core.manage` (gestionar Clientes y actores).
 *
 * Solo los roles Ventas que ya gestionan oportunidades. Solo añade. Se ejecuta con el
 * usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r
            JOIN permissions p ON p.code = 'core.manage'
            WHERE upper(r.code) = 'VENTAS'
              AND EXISTS (
                  SELECT 1 FROM role_permissions rp JOIN permissions q ON q.id = rp.permission_id
                  WHERE rp.role_id = r.id AND q.code = 'opportunities.manage'
              )
            ON CONFLICT DO NOTHING
        ");
    }

    public function down(): void
    {
        DB::statement("
            DELETE FROM role_permissions
            WHERE permission_id = (SELECT id FROM permissions WHERE code = 'core.manage')
              AND role_id IN (SELECT id FROM roles WHERE upper(code) = 'VENTAS')
        ");
    }
};
