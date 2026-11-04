<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las fechas que la base pone sola (fecha de la orden, fecha del costo) son las del calendario de
 * Lima: el servidor trabaja en UTC y de noche en Perú ya sería el día siguiente.
 * Se ejecuta con erp_user.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.orders ALTER COLUMN order_date SET DEFAULT ((now() AT TIME ZONE 'America/Lima')::date);
            ALTER TABLE public.cost_entries ALTER COLUMN incurred_at SET DEFAULT ((now() AT TIME ZONE 'America/Lima')::date);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.orders ALTER COLUMN order_date SET DEFAULT CURRENT_DATE;
            ALTER TABLE public.cost_entries ALTER COLUMN incurred_at SET DEFAULT CURRENT_DATE;
        SQL);
    }
};
