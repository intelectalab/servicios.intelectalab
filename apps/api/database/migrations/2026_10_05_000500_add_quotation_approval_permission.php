<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Aprobar o rechazar una cotización pasa a ser un permiso propio
    // (quotations.approve), como la acción "Aprobar" de la matriz del prototipo:
    // Ventas crea y envía, Gerencia aprueba. Para no quitarle nada a nadie, lo
    // reciben los roles que ya podían gestionar cotizaciones (quotations.manage).
    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore([
            'code' => 'quotations.approve',
            'name' => 'Aprobar cotizaciones',
            'description' => 'Aprobar o rechazar cotizaciones enviadas a aprobación.',
        ]);

        DB::statement("
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT rp.role_id, approve.id
            FROM role_permissions rp
            JOIN permissions manage ON manage.id = rp.permission_id AND manage.code = 'quotations.manage'
            CROSS JOIN permissions approve
            WHERE approve.code = 'quotations.approve'
            ON CONFLICT DO NOTHING
        ");
    }

    public function down(): void
    {
        // Borra también las filas de role_permissions (ON DELETE CASCADE).
        DB::table('permissions')->where('code', 'quotations.approve')->delete();
    }
};
