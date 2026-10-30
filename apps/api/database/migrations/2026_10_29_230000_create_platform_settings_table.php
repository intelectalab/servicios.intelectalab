<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_settings')) {
            Schema::create('platform_settings', function (Blueprint $table) {
                $table->string('key', 100)->primary();
                $table->jsonb('value');
                $table->timestamps();
            });

            foreach (['erp_app', 'erp_platform', 'erp_console'] as $role) {
                try {
                    DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.platform_settings TO {$role}");
                } catch (\Throwable $e) {
                    // Ignora si el rol no existe en el entorno actual
                }
            }

            DB::table('platform_settings')->insert([
                'key' => 'support',
                'value' => json_encode([
                    'whatsapp' => '+51999999999',
                    'email' => 'soporte@erp-saas.com',
                    'schedule' => 'Lunes a Viernes de 8:00 a 18:00',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
