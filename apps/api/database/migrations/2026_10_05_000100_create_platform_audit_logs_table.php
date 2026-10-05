<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Auditoría de la consola de plataforma (super admin). audit_logs es por
    // tenant y está bajo RLS; lo que pasa por encima de los tenants va aquí.
    public function up(): void
    {
        Schema::create('platform_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->string('action', 100)->index();
            $table->string('entity_type', 150)->nullable();
            $table->uuid('entity_id')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->jsonb('before_data')->nullable();
            $table->jsonb('after_data')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('created_at')->useCurrent()->index();
        });

        // Solo lectura y alta: la aplicación no puede editar ni borrar la auditoría.
        // El REVOKE hace falta porque los privilegios por defecto de erp_user le
        // dan a erp_app todos los permisos sobre cada tabla nueva.
        DB::statement('REVOKE ALL ON TABLE public.platform_audit_logs FROM erp_app');
        DB::statement('GRANT SELECT, INSERT ON TABLE public.platform_audit_logs TO erp_app');
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_audit_logs');
    }
};
