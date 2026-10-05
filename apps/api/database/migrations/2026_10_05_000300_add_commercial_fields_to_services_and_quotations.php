<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Lo que el catálogo de servicios y las cotizaciones del prototipo necesitan
    // y la base no tenía: modalidad de facturación y garantía del servicio, el
    // estado ARCHIVED, y el enlace de una cotización con su versión anterior.
    // Solo añade: los registros existentes quedan como "Único", sin garantía.
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            if (! Schema::hasColumn('services', 'billing_mode')) {
                $table->string('billing_mode', 20)->default('ONE_TIME');
            }
            if (! Schema::hasColumn('services', 'warranty_months')) {
                $table->unsignedSmallInteger('warranty_months')->default(0);
            }
        });

        DB::statement('ALTER TABLE services DROP CONSTRAINT IF EXISTS services_billing_mode_check');
        DB::statement("ALTER TABLE services ADD CONSTRAINT services_billing_mode_check CHECK (billing_mode IN ('ONE_TIME', 'RECURRING', 'PER_EVENT'))");

        DB::statement('ALTER TABLE services DROP CONSTRAINT IF EXISTS services_status_check');
        DB::statement("ALTER TABLE services ADD CONSTRAINT services_status_check CHECK (status IN ('DRAFT', 'ACTIVE', 'INACTIVE', 'ARCHIVED'))");

        if (! Schema::hasColumn('quotations', 'previous_id')) {
            Schema::table('quotations', function (Blueprint $table) {
                $table->uuid('previous_id')->nullable();
                $table->foreign('previous_id')->references('id')->on('quotations')->nullOnDelete();
                $table->index('previous_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropForeign(['previous_id']);
            $table->dropColumn('previous_id');
        });

        DB::statement("UPDATE services SET status = 'INACTIVE' WHERE status = 'ARCHIVED'");
        DB::statement('ALTER TABLE services DROP CONSTRAINT IF EXISTS services_status_check');
        DB::statement("ALTER TABLE services ADD CONSTRAINT services_status_check CHECK (status IN ('DRAFT', 'ACTIVE', 'INACTIVE'))");
        DB::statement('ALTER TABLE services DROP CONSTRAINT IF EXISTS services_billing_mode_check');

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['billing_mode', 'warranty_months']);
        });
    }
};
