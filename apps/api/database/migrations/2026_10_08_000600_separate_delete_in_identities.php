<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Eliminar" separado de "Gestionar" en Clientes y actores, como en la matriz del prototipo
 * (Ventas ve, crea y edita identidades, pero no elimina).
 *
 * - Permiso nuevo `core.delete`: eliminar datos de contacto y columnas personalizadas.
 * - Quien ya podía gestionar conserva el poder de eliminar: no pierde nada. La excepción es
 *   Ventas, que recibió gestionar para dar de alta al cliente al convertir una oportunidad.
 *
 * Solo añade. Se ejecuta con el usuario dueño de las tablas (erp_user): la aplicación no
 * puede escribir en `permissions`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore([
            'code' => 'core.delete',
            'name' => 'Eliminar en CORE',
            'description' => 'Eliminar datos de contacto y columnas personalizadas de Clientes y actores.',
        ]);

        DB::statement("
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT DISTINCT r.id, created.id
            FROM roles r
            JOIN role_permissions rp ON rp.role_id = r.id
            JOIN permissions held ON held.id = rp.permission_id AND held.code = 'core.manage'
            CROSS JOIN permissions created
            WHERE created.code = 'core.delete' AND upper(r.code) <> 'VENTAS'
            ON CONFLICT DO NOTHING
        ");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM role_permissions WHERE permission_id IN (SELECT id FROM permissions WHERE code = 'core.delete')");
        DB::table('permissions')->where('code', 'core.delete')->delete();
    }
};
