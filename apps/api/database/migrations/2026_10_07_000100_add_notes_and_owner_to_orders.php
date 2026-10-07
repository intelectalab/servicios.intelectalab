<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Órdenes del prototipo: notas ("Orden directa desde COT-…", "Orden desde
    // CTR-…") y responsable. Solo añade; las órdenes que ya existían toman ambos
    // datos del movimiento con el que se crearon en la auditoría.
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'notes')) {
                $table->text('notes')->nullable();
            }
            if (! Schema::hasColumn('orders', 'created_by')) {
                $table->uuid('created_by')->nullable();
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            }
        });

        DB::statement("
            UPDATE orders o
            SET notes = COALESCE(o.notes, a.metadata->>'note'),
                created_by = COALESCE(o.created_by, a.user_id)
            FROM audit_logs a
            WHERE a.entity_type = 'Order'
              AND a.action = 'order.generate'
              AND a.entity_id::text = o.id::text
              AND (o.notes IS NULL OR o.created_by IS NULL)
        ");
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropColumn(['notes', 'created_by']);
        });
    }
};
