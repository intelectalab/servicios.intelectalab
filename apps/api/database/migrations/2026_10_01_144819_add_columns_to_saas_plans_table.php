<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saas_plans', function (Blueprint $table) {
            if (!Schema::hasColumn('saas_plans', 'description')) {
                $table->text('description')->nullable();
            }
            if (!Schema::hasColumn('saas_plans', 'monthly_price')) {
                $table->decimal('monthly_price', 12, 2)->default(0);
            }
            if (!Schema::hasColumn('saas_plans', 'annual_price')) {
                $table->decimal('annual_price', 12, 2)->default(0);
            }
            if (!Schema::hasColumn('saas_plans', 'currency')) {
                $table->string('currency', 10)->default('PEN');
            }
            if (!Schema::hasColumn('saas_plans', 'has_trial')) {
                $table->boolean('has_trial')->default(true);
            }
            if (!Schema::hasColumn('saas_plans', 'trial_days')) {
                $table->integer('trial_days')->default(30);
            }
            if (!Schema::hasColumn('saas_plans', 'requires_payment_upon_trial')) {
                $table->boolean('requires_payment_upon_trial')->default(false);
            }
            if (!Schema::hasColumn('saas_plans', 'auto_renew_default')) {
                $table->boolean('auto_renew_default')->default(true);
            }
            if (!Schema::hasColumn('saas_plans', 'default_billing_period')) {
                $table->string('default_billing_period', 20)->default('MONTHLY');
            }
            if (!Schema::hasColumn('saas_plans', 'active')) {
                $table->boolean('active')->default(true);
            }
            if (!Schema::hasColumn('saas_plans', 'is_public')) {
                $table->boolean('is_public')->default(true);
            }
            if (!Schema::hasColumn('saas_plans', 'display_order')) {
                $table->integer('display_order')->default(1);
            }
            if (!Schema::hasColumn('saas_plans', 'limits')) {
                $table->jsonb('limits')->default('{}');
            }
            if (!Schema::hasColumn('saas_plans', 'modules')) {
                $table->jsonb('modules')->default('{}');
            }
            if (!Schema::hasColumn('saas_plans', 'consumption')) {
                $table->jsonb('consumption')->default('{}');
            }
            if (!Schema::hasColumn('saas_plans', 'support_level')) {
                $table->string('support_level', 30)->default('STANDARD');
            }
            if (!Schema::hasColumn('saas_plans', 'support_tickets_month')) {
                $table->integer('support_tickets_month')->nullable();
            }
            if (!Schema::hasColumn('saas_plans', 'support_sla_hours')) {
                $table->integer('support_sla_hours')->nullable();
            }
            if (!Schema::hasColumn('saas_plans', 'support_channels')) {
                $table->jsonb('support_channels')->default('{"portal": true, "email": true, "whatsapp": false, "priority_attention": false}');
            }
            if (!Schema::hasColumn('saas_plans', 'features')) {
                $table->jsonb('features')->default('{}');
            }
        });
    }

    public function down(): void
    {
        Schema::table('saas_plans', function (Blueprint $table) {
            $table->dropColumn([
                'description',
                'monthly_price',
                'annual_price',
                'currency',
                'has_trial',
                'trial_days',
                'requires_payment_upon_trial',
                'auto_renew_default',
                'default_billing_period',
                'active',
                'is_public',
                'display_order',
                'limits',
                'modules',
                'consumption',
                'support_level',
                'support_tickets_month',
                'support_sla_hours',
                'support_channels',
                'features',
            ]);
        });
    }
};