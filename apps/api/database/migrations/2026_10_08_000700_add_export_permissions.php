<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Exportar" separado de "Ver", como en la matriz del prototipo: quien ve un listado no por
 * eso se lo lleva entero en un archivo.
 *
 * - Permiso `<recurso>.export` en cada listado que se exporta.
 * - Todo rol que hoy ve un listado (y por tanto lo exporta) conserva exportarlo: no pierde
 *   nada. La excepción es Ventas, que en el prototipo no exporta.
 *
 * Solo añade. Se ejecuta con el usuario dueño de las tablas (erp_user): la aplicación no
 * puede escribir en `permissions`.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'core' => 'Exportar clientes y actores',
        'services' => 'Exportar servicios',
        'quotations' => 'Exportar cotizaciones',
        'contracts' => 'Exportar contratos',
        'orders' => 'Exportar órdenes',
        'portal' => 'Exportar solicitudes del portal',
        'leads' => 'Exportar leads',
        'opportunities' => 'Exportar oportunidades',
        'users' => 'Exportar usuarios',
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $resource => $name) {
            DB::table('permissions')->insertOrIgnore([
                'code' => "{$resource}.export", 'name' => $name, 'description' => 'Descargar el listado en Excel o CSV.',
            ]);

            DB::statement("
                INSERT INTO role_permissions (role_id, permission_id)
                SELECT DISTINCT r.id, created.id
                FROM roles r
                JOIN role_permissions rp ON rp.role_id = r.id
                JOIN permissions held ON held.id = rp.permission_id AND held.code = '{$resource}.view'
                CROSS JOIN permissions created
                WHERE created.code = '{$resource}.export' AND upper(r.code) <> 'VENTAS'
                ON CONFLICT DO NOTHING
            ");
        }
    }

    public function down(): void
    {
        $codes = array_map(fn (string $resource) => "{$resource}.export", array_keys(self::PERMISSIONS));

        DB::table('role_permissions')->whereIn('permission_id', DB::table('permissions')->whereIn('code', $codes)->pluck('id'))->delete();
        DB::table('permissions')->whereIn('code', $codes)->delete();
    }
};
