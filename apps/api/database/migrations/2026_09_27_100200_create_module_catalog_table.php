<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_catalog', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->string('code', 100)->unique(); // ej. CRM, FINANZAS, PROYECTOS
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('category', 100); // ej. CORE, FINANZAS_FACTURACION, OPERACIONES_SERVICIO
            $table->boolean('is_core')->default(false); // obligatorio en todos los planes
            $table->boolean('active')->default(true);
            $table->integer('display_order')->default(0);
            $table->timestampsTz();
        });

        DB::statement('COMMENT ON TABLE public.module_catalog IS \'Catálogo maestro de módulos disponibles para armar planes SaaS; saas_plans.modules referencia estos codes.\'');
    }

    public function down(): void
    {
        Schema::dropIfExists('module_catalog');
    }
};
