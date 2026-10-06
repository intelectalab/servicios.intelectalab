<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // "Orden directa": una cotización aprobada se convierte en orden sin pasar
    // por contrato. Los permisos orders.view / orders.manage ya existían pero
    // ningún módulo los otorgaba; pasan a venir con el módulo Contratos (las dos
    // conversiones de una cotización aprobada), y los reciben los roles que ya
    // podían ver o gestionar contratos.
    public function up(): void
    {
        DB::statement("
            UPDATE module_catalog
            SET permission_prefixes = permission_prefixes || '[\"orders\"]'::jsonb
            WHERE code = 'CONTRATOS' AND NOT permission_prefixes @> '[\"orders\"]'::jsonb
        ");

        foreach (['view', 'manage'] as $action) {
            DB::statement("
                INSERT INTO role_permissions (role_id, permission_id)
                SELECT rp.role_id, orders.id
                FROM role_permissions rp
                JOIN permissions contracts ON contracts.id = rp.permission_id AND contracts.code = 'contracts.{$action}'
                CROSS JOIN permissions orders
                WHERE orders.code = 'orders.{$action}'
                ON CONFLICT DO NOTHING
            ");
        }
    }

    public function down(): void
    {
        DB::statement("
            DELETE FROM role_permissions
            WHERE permission_id IN (SELECT id FROM permissions WHERE code IN ('orders.view', 'orders.manage'))
        ");
        DB::statement("UPDATE module_catalog SET permission_prefixes = permission_prefixes - 'orders' WHERE code = 'CONTRATOS'");
    }
};
