<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saas_plans', function (Blueprint $table) {
            // --- Tab 1. Información ---
            $table->text('description')->nullable()->after('name');
            $table->integer('display_order')->default(0)->after('active');
            $table->boolean('is_public')->default(true)->after('display_order');

            // --- Tab 2. Precio y suscripción ---
            $table->decimal('monthly_price', 18, 4)->nullable()->after('is_public');
            $table->decimal('annual_price', 18, 4)->nullable()->after('monthly_price');
            $table->string('currency', 10)->default('PEN')->after('annual_price');

            $table->boolean('has_trial')->default(false)->after('currency');
            $table->integer('trial_days')->default(0)->after('has_trial');
            $table->boolean('requires_payment_upfront')->default(false)->after('trial_days');

            $table->boolean('auto_renew_default')->default(true)->after('requires_payment_upfront');
            $table->string('default_billing_period', 20)->default('MONTHLY')->after('auto_renew_default');
            // valores esperados: MONTHLY, ANNUAL

            // --- Tab 7. Características (feature flags transversales, separado de "modules") ---
            $table->jsonb('features')->default('{}')->after('modules');
        });

        // Restricciones de valores permitidos (coherente con el resto del schema, que usa CHECK)
        DB::statement("
            ALTER TABLE public.saas_plans
            ADD CONSTRAINT saas_plans_default_billing_period_check
            CHECK (default_billing_period IN ('MONTHLY', 'ANNUAL'))
        ");

        DB::statement("
            ALTER TABLE public.saas_plans
            ADD CONSTRAINT saas_plans_monthly_price_check
            CHECK (monthly_price IS NULL OR monthly_price >= 0)
        ");

        DB::statement("
            ALTER TABLE public.saas_plans
            ADD CONSTRAINT saas_plans_annual_price_check
            CHECK (annual_price IS NULL OR annual_price >= 0)
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE public.saas_plans DROP CONSTRAINT IF EXISTS saas_plans_default_billing_period_check');
        DB::statement('ALTER TABLE public.saas_plans DROP CONSTRAINT IF EXISTS saas_plans_monthly_price_check');
        DB::statement('ALTER TABLE public.saas_plans DROP CONSTRAINT IF EXISTS saas_plans_annual_price_check');

        Schema::table('saas_plans', function (Blueprint $table) {
            $table->dropColumn([
                'description',
                'display_order',
                'is_public',
                'monthly_price',
                'annual_price',
                'currency',
                'has_trial',
                'trial_days',
                'requires_payment_upfront',
                'auto_renew_default',
                'default_billing_period',
                'features',
            ]);
        });
    }
};
