<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            CREATE TRIGGER trg_module_catalog_updated_at
            BEFORE UPDATE ON public.module_catalog
            FOR EACH ROW EXECUTE FUNCTION public.set_updated_at()
        ');

        // Mismo patrón de permisos que el resto de tablas del schema
        DB::statement('GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.module_catalog TO erp_app');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_module_catalog_updated_at ON public.module_catalog');
    }
};
