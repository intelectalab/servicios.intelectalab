<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Contratos del prototipo: nivel de servicio (SLA) del contrato y el permiso
    // propio para activarlo (la acción "Aprobar" de la matriz). Solo añade: los
    // contratos existentes quedan en ESTANDAR, y el permiso lo reciben los roles
    // que ya podían gestionar contratos.
    public function up(): void
    {
        if (! Schema::hasColumn('contracts', 'sla_level')) {
            Schema::table('contracts', function (Blueprint $table) {
                $table->string('sla_level', 30)->default('ESTANDAR');
            });
        }

        DB::statement('ALTER TABLE contracts DROP CONSTRAINT IF EXISTS contracts_sla_level_check');
        DB::statement("ALTER TABLE contracts ADD CONSTRAINT contracts_sla_level_check CHECK (sla_level IN ('ESTANDAR', 'PREMIUM'))");

        DB::table('permissions')->insertOrIgnore([
            'code' => 'contracts.approve',
            'name' => 'Activar contratos',
            'description' => 'Pasar un contrato de borrador a vigente.',
        ]);

        DB::statement("
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT rp.role_id, approve.id
            FROM role_permissions rp
            JOIN permissions manage ON manage.id = rp.permission_id AND manage.code = 'contracts.manage'
            CROSS JOIN permissions approve
            WHERE approve.code = 'contracts.approve'
            ON CONFLICT DO NOTHING
        ");
    }

    public function down(): void
    {
        // Borra también las filas de role_permissions (ON DELETE CASCADE).
        DB::table('permissions')->where('code', 'contracts.approve')->delete();

        DB::statement('ALTER TABLE contracts DROP CONSTRAINT IF EXISTS contracts_sla_level_check');
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('sla_level');
        });
    }
};
