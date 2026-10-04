<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Columna duplicada de requires_payment_upfront, creada por una versión
    // anterior de 2026_10_01_144819 en los ambientes donde ya se había corrido.
    public function up(): void
    {
        if (Schema::hasColumn('saas_plans', 'requires_payment_upon_trial')) {
            Schema::table('saas_plans', function (Blueprint $table) {
                $table->dropColumn('requires_payment_upon_trial');
            });
        }
    }

    public function down(): void
    {
        //
    }
};
