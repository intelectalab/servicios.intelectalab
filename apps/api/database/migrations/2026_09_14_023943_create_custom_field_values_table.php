<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('custom_field_values', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('field_id');
            $table->string('entity_type', 50);
            $table->uuid('entity_id');
            $table->text('value')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('field_id')->references('id')->on('custom_field_definitions')->onDelete('cascade');
            $table->unique(['field_id', 'entity_id']);
            $table->index(['tenant_id', 'entity_type', 'entity_id']);
        });

        DB::statement('ALTER TABLE custom_field_values ENABLE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON custom_field_values USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_values');
    }
};