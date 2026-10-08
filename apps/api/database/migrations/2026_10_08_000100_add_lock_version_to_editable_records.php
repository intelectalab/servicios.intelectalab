<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Número de versión de cada registro que se edita desde una ficha. Sube en uno con
 * cada cambio; quien guarda indica la versión que tenía a la vista y, si ya no es la
 * vigente, el cambio se rechaza en vez de pisar el de otra persona.
 * Lo maneja App\Http\Middleware\CheckRecordVersion.
 */
return new class extends Migration
{
    private const TABLES = ['parties', 'services', 'quotations', 'contracts', 'orders', 'portal_requests'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::unprepared("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS lock_version integer NOT NULL DEFAULT 0");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            DB::unprepared("ALTER TABLE {$table} DROP COLUMN IF EXISTS lock_version");
        }
    }
};
