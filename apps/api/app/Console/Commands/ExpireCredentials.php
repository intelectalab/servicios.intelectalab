<?php

namespace App\Console\Commands;

use App\Modules\Credentials\Models\CredentialRecord;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireCredentials extends Command
{
    protected $signature = 'credentials:expire';

    protected $description = 'Pasa a Expirada las credenciales del Vault cuya fecha de vencimiento ya pasó, en cada empresa';

    public function handle(): int
    {
        $tenants = DB::table('tenants')->where('status', 'ACTIVE')->orderBy('created_at')->get(['id', 'code']);

        foreach ($tenants as $tenant) {
            // Las credenciales están bajo RLS: hay que entrar al contexto de la empresa.
            DB::statement("SELECT set_config('app.tenant_id', ?, false)", [(string) $tenant->id]);

            $due = CredentialRecord::query()->where('tenant_id', $tenant->id)->where('status', 'ACTIVE')
                ->whereNotNull('expires_at')->where('expires_at', '<=', now())->orderBy('expires_at')->get();
            if ($due->isEmpty()) {
                continue;
            }

            foreach ($due as $credential) {
                DB::transaction(function () use ($credential) {
                    $credential->update(['status' => 'EXPIRED']);
                    AuditLogger::log(
                        tenantId: $credential->tenant_id,
                        userId: null,
                        action: 'credential.expire',
                        entityType: 'CredentialRecord',
                        entityId: $credential->id,
                        metadata: ['from' => 'ACTIVE', 'to' => 'EXPIRED', 'note' => 'Expirada automáticamente'],
                    );
                });
            }

            $this->line("{$tenant->code}: {$due->count()} expiradas");
        }

        DB::statement("SELECT set_config('app.tenant_id', '', false)");

        return self::SUCCESS;
    }
}
