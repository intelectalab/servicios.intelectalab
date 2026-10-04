<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // El resto de columnas del plan ya las crean las migraciones del 2026_09_27;
    // aquí solo se agrega lo nuevo (Tab 5. Consumo).
    public function up(): void
    {
        if (! Schema::hasColumn('saas_plans', 'consumption')) {
            Schema::table('saas_plans', function (Blueprint $table) {
                $table->jsonb('consumption')->default('{}');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('saas_plans', 'consumption')) {
            Schema::table('saas_plans', function (Blueprint $table) {
                $table->dropColumn('consumption');
            });
        }
    }
};
