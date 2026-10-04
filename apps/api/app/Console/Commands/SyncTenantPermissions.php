<?php

namespace App\Console\Commands;

use App\Modules\Subscription\Support\ModulePermissions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncTenantPermissions extends Command
{
    protected $signature = 'tenants:sync-permissions {--dry-run : Solo muestra lo que se agregaría}';

    protected $description = 'Agrega al rol ADMIN de cada tenant los permisos que corresponden a sus módulos habilitados';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $permissionIds = DB::table('permissions')->pluck('id', 'code');

        foreach (DB::table('tenants')->orderBy('created_at')->get(['id', 'code', 'name']) as $tenant) {
            // roles y tenant_modules están bajo RLS: hay que entrar al contexto del tenant.
            DB::statement("SELECT set_config('app.tenant_id', ?, false)", [(string) $tenant->id]);

            $roleId = DB::table('roles')
                ->where('tenant_id', $tenant->id)
                ->where('code', 'ADMIN')
                ->where('is_system', true)
                ->value('id');

            if (! $roleId) {
                $this->line("{$tenant->code} ({$tenant->name}): sin rol ADMIN de sistema, se omite.");
                continue;
            }

            $moduleCodes = DB::table('tenant_modules')
                ->where('tenant_id', $tenant->id)
                ->where('enabled', true)
                ->pluck('module_code');

            $current = DB::table('role_permissions')->where('role_id', $roleId)->pluck('permission_id')->all();

            $missing = collect(ModulePermissions::codesFor($moduleCodes))
                ->filter(fn (string $code) => isset($permissionIds[$code]) && ! in_array($permissionIds[$code], $current))
                ->values();

            if ($missing->isEmpty()) {
                $this->line("{$tenant->code} ({$tenant->name}): al día.");
                continue;
            }

            if (! $dryRun) {
                DB::table('role_permissions')->insert(
                    $missing->map(fn (string $code) => ['role_id' => $roleId, 'permission_id' => $permissionIds[$code]])->all()
                );
            }

            $this->info("{$tenant->code} ({$tenant->name}): ".($dryRun ? 'faltan ' : 'agregados ').$missing->implode(', '));
        }

        DB::statement("SELECT set_config('app.tenant_id', '', false)");

        return self::SUCCESS;
    }
}
