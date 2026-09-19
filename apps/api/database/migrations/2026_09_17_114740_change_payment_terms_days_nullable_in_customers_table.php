<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->integer('payment_terms_days')->nullable()->change();
            $table->decimal('credit_limit', 12, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->integer('payment_terms_days')->nullable(false)->change();
            $table->decimal('credit_limit', 12, 2)->nullable(false)->change();
        });
    }
};