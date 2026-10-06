<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Multimoneda del prototipo (PEN/USD): tipo de cambio diario por tenant y el
    // tipo de cambio con el que se emitió cada cotización en dólares.
    // Solo añade: las cotizaciones existentes siguen en soles, sin tipo de cambio.
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->uuid('tenant_id');
            $table->date('rate_date');
            $table->char('currency', 3)->default('USD');
            $table->decimal('buy', 10, 4);
            $table->decimal('sell', 10, 4);
            $table->string('source', 50)->default('Manual');
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // Un tipo de cambio por día y moneda: registrar otro el mismo día lo corrige.
            $table->unique(['tenant_id', 'currency', 'rate_date']);
        });

        DB::statement('ALTER TABLE exchange_rates ADD CONSTRAINT exchange_rates_positive_check CHECK (buy > 0 AND sell > 0)');
        DB::statement('ALTER TABLE exchange_rates ENABLE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON exchange_rates USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id())');

        if (! Schema::hasColumn('quotations', 'exchange_rate')) {
            Schema::table('quotations', function (Blueprint $table) {
                $table->decimal('exchange_rate', 10, 4)->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn('exchange_rate');
        });
        Schema::dropIfExists('exchange_rates');
    }
};
