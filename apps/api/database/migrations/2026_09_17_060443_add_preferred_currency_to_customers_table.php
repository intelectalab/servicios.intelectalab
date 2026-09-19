<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('preferred_currency', 3)->default('PEN')->after('payment_terms_days');
        });

        DB::statement("ALTER TABLE customers ADD CONSTRAINT chk_customers_preferred_currency CHECK (preferred_currency IN ('PEN', 'USD'))");
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            DB::statement('ALTER TABLE customers DROP CONSTRAINT IF EXISTS chk_customers_preferred_currency');
            $table->dropColumn('preferred_currency');
        });
    }
};