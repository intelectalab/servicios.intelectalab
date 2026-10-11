<?php

use App\Modules\Crm\Support\LeadScoring;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los leads que ya existían reciben su puntaje automático con las reglas por defecto.
 * No cambia ningún otro dato del lead.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('crm_leads')->distinct()->pluck('tenant_id') as $tenantId) {
            LeadScoring::rescoreAll($tenantId);
        }
    }

    public function down(): void
    {
        DB::table('crm_leads')->update(['score' => null, 'score_grade' => null, 'score_hits' => '[]']);
    }
};
