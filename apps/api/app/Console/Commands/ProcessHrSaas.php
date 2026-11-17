<?php

namespace App\Console\Commands;

use App\Modules\Personal\HrSaas\HrSaasSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Cada minuto, en cada empresa con la conexión con el SaaS de RRHH: procesa los cambios recibidos
 * que quedaron pendientes y envía lo que toca (horas del periodo, vínculos con usuarios), con
 * reintentos. Tras el último intento, el mensaje queda como fallido en Sincronización.
 */
class ProcessHrSaas extends Command
{
    protected $signature = 'hr-saas:process';

    protected $description = 'Procesa los mensajes recibidos del SaaS de RRHH y envía los pendientes';

    public function handle(): int
    {
        $tenants = DB::table('tenants')->where('status', 'ACTIVE')->orderBy('created_at')->pluck('id');
        $received = 0;
        $sent = 0;

        foreach ($tenants as $tenantId) {
            try {
                DB::statement("SELECT set_config('app.tenant_id', ?, false)", [(string) $tenantId]);
                if (! DB::table('hr_connections')->where('tenant_id', $tenantId)->exists()) {
                    continue;
                }
                request()->attributes->set('tenant_id', (string) $tenantId);
                $received += DB::transaction(fn () => HrSaasSync::processPending((string) $tenantId));
                $sent += HrSaasSync::deliverDue((string) $tenantId);
            } catch (Throwable $e) {
                report($e);
            }
        }
        DB::statement("SELECT set_config('app.tenant_id', '', false)");
        request()->attributes->remove('tenant_id');

        if ($received || $sent) {
            $this->info("RRHH: {$received} cambio(s) procesado(s), {$sent} envío(s) intentado(s).");
        }

        return self::SUCCESS;
    }
}
