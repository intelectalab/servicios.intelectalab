<?php

namespace App\Console\Commands;

use App\Modules\Services\Models\Quotation;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireQuotations extends Command
{
    protected $signature = 'quotations:expire';

    protected $description = 'Pasa a Vencida las cotizaciones enviadas cuya fecha de validez ya pasó, en cada empresa (RN-COM-05)';

    public function handle(): int
    {
        $tenants = DB::table('tenants')->where('status', 'ACTIVE')->orderBy('created_at')->get(['id', 'code']);

        foreach ($tenants as $tenant) {
            // Las cotizaciones están bajo RLS: hay que entrar al contexto de la empresa.
            DB::statement("SELECT set_config('app.tenant_id', ?, false)", [(string) $tenant->id]);

            // Válida hasta el día indicado inclusive: vence desde el día siguiente (igual que al aprobar).
            $due = Quotation::ignoringAccessScope()->where('tenant_id', $tenant->id)->where('status', 'SUBMITTED')
                ->whereNotNull('valid_until')->where('valid_until', '<', today()->toDateString())
                ->orderBy('valid_until')->get();
            if ($due->isEmpty()) {
                continue;
            }

            foreach ($due as $quotation) {
                DB::transaction(function () use ($quotation) {
                    $quotation->update(['status' => 'EXPIRED']);
                    // Mismo registro que "Marcar vencida", sin usuario: lo hizo el sistema.
                    AuditLogger::log(
                        tenantId: $quotation->tenant_id,
                        userId: null,
                        action: 'quotation.expire',
                        entityType: 'Quotation',
                        entityId: $quotation->id,
                        metadata: ['from' => 'SUBMITTED', 'to' => 'EXPIRED', 'note' => 'Vencida automáticamente'],
                    );
                });
            }

            $this->line("{$tenant->code}: {$due->count()} vencidas");
        }

        DB::statement("SELECT set_config('app.tenant_id', '', false)");

        return self::SUCCESS;
    }
}
