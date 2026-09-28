<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saas_plans', function (Blueprint $table) {
            // --- Tab 6. Soporte ---
            $table->string('support_level', 30)->default('STANDARD')->after('features');
            $table->integer('support_tickets_month')->nullable()->after('support_level');
            $table->integer('support_sla_hours')->nullable()->after('support_tickets_month');

            // Canales incluidos (jsonb en vez de 4 columnas booleanas sueltas,
            // por si mañana agregan más canales sin otra migración)
            $table->jsonb('support_channels')->default('{"portal": false, "email": false, "whatsapp": false, "priority_attention": false}')->after('support_sla_hours');
        });

        DB::statement("
            ALTER TABLE public.saas_plans
            ADD CONSTRAINT saas_plans_support_level_check
            CHECK (support_level IN ('BASIC', 'STANDARD', 'PREMIUM', 'DEDICATED'))
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE public.saas_plans DROP CONSTRAINT IF EXISTS saas_plans_support_level_check');

        Schema::table('saas_plans', function (Blueprint $table) {
            $table->dropColumn([
                'support_level',
                'support_tickets_month',
                'support_sla_hours',
                'support_channels',
            ]);
        });
    }
};
