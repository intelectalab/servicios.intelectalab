<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Suspender o terminar un contrato arrastra a sus servicios contratados.
 *
 * Al reactivar el contrato vuelven solo los servicios que se suspendieron con él: un servicio
 * que Operaciones ya había suspendido por su cuenta sigue suspendido. Esta marca lo distingue.
 *
 * Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE contracted_services ADD COLUMN IF NOT EXISTS suspended_by_contract boolean NOT NULL DEFAULT false;
            -- La marca solo tiene sentido en un servicio suspendido.
            ALTER TABLE contracted_services DROP CONSTRAINT IF EXISTS contracted_services_suspended_by_contract_check;
            ALTER TABLE contracted_services ADD CONSTRAINT contracted_services_suspended_by_contract_check
                CHECK (NOT suspended_by_contract OR status = 'SUSPENDED');
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE contracted_services DROP CONSTRAINT IF EXISTS contracted_services_suspended_by_contract_check;
            ALTER TABLE contracted_services DROP COLUMN IF EXISTS suspended_by_contract;
        SQL);
    }
};
