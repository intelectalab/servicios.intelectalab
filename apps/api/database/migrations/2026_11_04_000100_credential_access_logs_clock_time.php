<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El historial de accesos del Credential Vault se ordena por la hora de cada acceso: se graba la
 * hora real del registro (clock_timestamp) y no la del inicio de la transacción (now()), que es la
 * misma para todo lo hecho en una misma operación y dejaba el orden al azar.
 * Se ejecuta con erp_user.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('ALTER TABLE public.credential_access_logs ALTER COLUMN created_at SET DEFAULT clock_timestamp();');
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE public.credential_access_logs ALTER COLUMN created_at SET DEFAULT now();');
    }
};
