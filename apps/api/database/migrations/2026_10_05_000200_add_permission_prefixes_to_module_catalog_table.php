<?php

use Database\Seeders\ModuleCatalogSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // El catálogo pasa a ser la única fuente de la relación módulo → permisos
    // (antes estaba escrita en código). Como el sidebar y el registro de tenants
    // dependen de él, la migración también carga el catálogo base.
    public function up(): void
    {
        if (! Schema::hasColumn('module_catalog', 'permission_prefixes')) {
            Schema::table('module_catalog', function (Blueprint $table) {
                $table->jsonb('permission_prefixes')->default('[]');
            });
        }

        (new ModuleCatalogSeeder())->run();
    }

    public function down(): void
    {
        Schema::table('module_catalog', function (Blueprint $table) {
            $table->dropColumn('permission_prefixes');
        });
    }
};
