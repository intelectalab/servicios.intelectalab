<?php

namespace App\Console\Commands;

use App\Modules\Finance\Models\RecurringInstance;
use App\Modules\Finance\Support\Recurrence;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RunRecurringCycles extends Command
{
    protected $signature = 'recurring:run-cycles';

    protected $description = 'Procesa los ciclos vencidos de los servicios recurrentes de cada empresa: cada uno deja su evento facturable';

    public function handle(): int
    {
        $tenants = DB::table('tenants')->where('status', 'ACTIVE')->orderBy('created_at')->get(['id', 'code']);

        foreach ($tenants as $tenant) {
            // Las instancias están bajo RLS: hay que entrar al contexto de la empresa.
            DB::statement("SELECT set_config('app.tenant_id', ?, false)", [(string) $tenant->id]);

            $ids = RecurringInstance::query()->where('tenant_id', $tenant->id)->where('status', 'ACTIVE')
                ->whereNotNull('next_cycle_date')->where('next_cycle_date', '<=', today()->toDateString())
                ->orderBy('next_cycle_date')->pluck('id')->all();
            if (! $ids) {
                continue;
            }

            $summary = Recurrence::run($ids, today(), null);
            $this->line("{$tenant->code}: {$summary['created']} procesados, {$summary['failed']} fallidos");
        }

        DB::statement("SELECT set_config('app.tenant_id', '', false)");

        return self::SUCCESS;
    }
}
