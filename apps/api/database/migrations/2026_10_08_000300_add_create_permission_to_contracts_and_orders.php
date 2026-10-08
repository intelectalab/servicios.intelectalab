<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Crear" separado de "Gestionar" en contratos y órdenes, como en el prototipo: Ventas
 * genera el contrato o la orden de su cotización aprobada, pero no los edita, suspende
 * ni termina.
 *
 * - Permisos nuevos `contracts.create` y `orders.create` (vienen con el módulo Contratos,
 *   por su prefijo).
 * - Quien ya podía gestionar conserva el poder de crear: no pierde nada.
 * - Ventas recibe los dos.
 *
 * Solo añade. Se ejecuta con el usuario dueño de las tablas (erp_user): la aplicación no
 * puede escribir en `permissions`.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'contracts' => ['Crear contratos', 'Generar el contrato de una cotización aprobada.'],
        'orders' => ['Crear órdenes', 'Generar la orden de una cotización aprobada o de un contrato vigente.'],
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $resource => [$name, $description]) {
            DB::table('permissions')->insertOrIgnore([
                'code' => "{$resource}.create", 'name' => $name, 'description' => $description,
            ]);

            DB::statement("
                INSERT INTO role_permissions (role_id, permission_id)
                SELECT DISTINCT r.id, created.id
                FROM roles r
                JOIN role_permissions rp ON rp.role_id = r.id
                JOIN permissions held ON held.id = rp.permission_id
                CROSS JOIN permissions created
                WHERE created.code = '{$resource}.create'
                  AND (held.code = '{$resource}.manage' OR (upper(r.code) = 'VENTAS' AND held.code = '{$resource}.view'))
                ON CONFLICT DO NOTHING
            ");
        }
    }

    public function down(): void
    {
        $codes = array_map(fn (string $resource) => "{$resource}.create", array_keys(self::PERMISSIONS));

        DB::statement('DELETE FROM role_permissions WHERE permission_id IN (SELECT id FROM permissions WHERE code IN (?, ?))', $codes);
        DB::table('permissions')->whereIn('code', $codes)->delete();
    }
};
