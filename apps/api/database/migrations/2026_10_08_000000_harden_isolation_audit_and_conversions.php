<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Protecciones en la base de datos (no cambian datos):
 *
 * 1. `persons` y `organizations` no tenían regla de aislamiento: se leían a través de
 *    `parties`, pero la base no impedía consultarlas directamente. Ahora solo se ven
 *    (y solo se escriben) las de una identidad de la empresa en curso.
 * 2. `audit_logs` pasa a ser de solo alta y lectura para la aplicación: no se puede
 *    modificar ni borrar un registro de auditoría.
 * 3. El catálogo `permissions` pasa a solo lectura para la aplicación: los permisos
 *    se crean por migración.
 * 4. Una cotización da un solo contrato y una sola orden, y un contrato una sola orden:
 *    lo garantiza la base aunque lleguen dos peticiones a la vez.
 *
 * Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['persons', 'organizations'] as $table) {
            DB::unprepared(<<<SQL
                ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY;
                DROP POLICY IF EXISTS tenant_isolation ON {$table};
                CREATE POLICY tenant_isolation ON {$table}
                    USING (EXISTS (
                        SELECT 1 FROM parties p WHERE p.id = {$table}.party_id AND p.tenant_id = app_tenant_id()
                    ))
                    WITH CHECK (EXISTS (
                        SELECT 1 FROM parties p WHERE p.id = {$table}.party_id AND p.tenant_id = app_tenant_id()
                    ));
            SQL);
        }

        DB::unprepared(<<<'SQL'
            REVOKE UPDATE, DELETE, TRUNCATE ON audit_logs FROM erp_app;
            REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON permissions FROM erp_app;

            CREATE UNIQUE INDEX IF NOT EXISTS ux_contracts_one_per_quotation
                ON contracts (tenant_id, quotation_id) WHERE quotation_id IS NOT NULL;
            CREATE UNIQUE INDEX IF NOT EXISTS ux_orders_one_per_quotation
                ON orders (tenant_id, quotation_id) WHERE quotation_id IS NOT NULL;
            CREATE UNIQUE INDEX IF NOT EXISTS ux_orders_one_per_contract
                ON orders (tenant_id, contract_id) WHERE contract_id IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS ux_orders_one_per_contract;
            DROP INDEX IF EXISTS ux_orders_one_per_quotation;
            DROP INDEX IF EXISTS ux_contracts_one_per_quotation;

            GRANT INSERT, UPDATE, DELETE ON permissions TO erp_app;
            GRANT UPDATE, DELETE ON audit_logs TO erp_app;

            DROP POLICY IF EXISTS tenant_isolation ON organizations;
            ALTER TABLE organizations DISABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON persons;
            ALTER TABLE persons DISABLE ROW LEVEL SECURITY;
        SQL);
    }
};
