<?php

namespace App\Console\Commands;

use App\Modules\Subscription\Support\BaseRoles;
use App\Modules\Subscription\Support\TenantModules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Da los roles base del kit (Gerencia, Ventas, Operaciones, Técnico, Finanzas, Soporte) a las
 * empresas que ya existían antes de que el aprovisionamiento los creara. No toca los roles que la
 * empresa ya tiene ni repite uno con el mismo código o nombre; solo agrega los que faltan, con los
 * permisos de los módulos que la empresa tiene encendidos.
 */
class CreateBaseRoles extends Command
{
    protected $signature = 'tenants:base-roles {--tenant= : Código de una sola empresa} {--dry-run : Solo muestra lo que se crearía}';

    protected $description = 'Agrega a las empresas existentes los roles base del kit que les falten';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $tenants = DB::table('tenants')->whereIn('status', ['ACTIVE', 'PROVISIONING'])
            ->when($this->option('tenant'), fn ($query, $code) => $query->where('code', $code))
            ->orderBy('created_at')->get(['id', 'code', 'name']);

        foreach ($tenants as $tenant) {
            $tenantId = (string) $tenant->id;
            DB::beginTransaction();
            // roles y tenant_modules están bajo RLS: hay que entrar al contexto de la empresa.
            DB::statement("SELECT set_config('app.tenant_id', ?, true)", [$tenantId]);
            $created = BaseRoles::create($tenantId, TenantModules::enabledCodes($tenantId));
            $dryRun ? DB::rollBack() : DB::commit();

            $this->line(sprintf('%s (%s): %s', $tenant->code, $tenant->name,
                $created ? ($dryRun ? 'se crearían ' : 'creados ').implode(', ', $created) : 'ya tiene los roles base'));
        }

        return self::SUCCESS;
    }
}
