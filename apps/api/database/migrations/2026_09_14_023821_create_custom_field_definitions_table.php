<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('custom_field_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('entity_type', 50); // 'customer', 'supplier', etc.
            $table->string('field_key', 100);
            $table->string('label', 150);
            $table->enum('field_type', ['text', 'number', 'date', 'boolean'])->default('text');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->unique(['tenant_id', 'entity_type', 'field_key']);
        });

        // Aplicamos el mismo patrón de RLS que usa el resto del proyecto.
        DB::statement('ALTER TABLE custom_field_definitions ENABLE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON custom_field_definitions USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_definitions');
    }
};