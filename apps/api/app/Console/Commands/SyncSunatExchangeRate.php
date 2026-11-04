<?php

namespace App\Console\Commands;

use App\Modules\Finance\Models\ExchangeRate;
use App\Modules\Finance\Support\SunatExchangeRate;
use App\Modules\Subscription\Support\TenantModules;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Tipo de cambio diario (INT-FX): lee una vez lo que publica SUNAT y lo guarda en cada empresa
 * activa con Finanzas. No pisa lo que la empresa ya tenga para ese día (por ejemplo, uno
 * registrado a mano); para corregirlo está "Actualizar desde SUNAT" en Monedas y tipo de cambio.
 * Si SUNAT no responde, no se guarda nada y se intenta en la siguiente corrida.
 */
class SyncSunatExchangeRate extends Command
{
    protected $signature = 'exchange-rates:sunat';

    protected $description = 'Guarda el tipo de cambio del dólar publicado por SUNAT en cada empresa con Finanzas';

    public function handle(): int
    {
        try {
            $published = SunatExchangeRate::fetch();
        } catch (RuntimeException $e) {
            $this->warn($e->getMessage());

            return self::FAILURE;
        }

        $saved = 0;
        foreach (DB::table('tenants')->where('status', 'ACTIVE')->orderBy('created_at')->pluck('id') as $tenantId) {
            $tenantId = (string) $tenantId;
            // Las tablas de la empresa están bajo RLS: se entra a su contexto.
            DB::statement("SELECT set_config('app.tenant_id', ?, false)", [$tenantId]);
            if (! in_array('FINANZAS', TenantModules::enabledCodes($tenantId), true)) {
                continue;
            }
            if (ExchangeRate::where('tenant_id', $tenantId)->where('currency', 'USD')->where('rate_date', $published['date'])->exists()) {
                continue;
            }

            DB::transaction(function () use ($tenantId, $published) {
                $rate = ExchangeRate::create([
                    'tenant_id' => $tenantId, 'currency' => 'USD', 'rate_date' => $published['date'],
                    'buy' => $published['buy'], 'sell' => $published['sell'], 'source' => 'SUNAT', 'created_by' => null,
                ]);
                // Mismo registro que "Actualizar desde SUNAT", sin usuario: lo hizo el sistema.
                AuditLogger::log(
                    tenantId: $tenantId,
                    userId: null,
                    action: 'exchange_rate.sync',
                    entityType: 'ExchangeRate',
                    entityId: $rate->id,
                    afterData: ['buy' => $published['buy'], 'sell' => $published['sell'], 'source' => 'SUNAT'],
                    metadata: ['date' => $published['date'], 'buy' => $published['buy'], 'sell' => $published['sell'], 'note' => 'Automático'],
                );
            });
            $saved++;
        }

        $this->info("Tipo de cambio {$published['date']} (venta {$published['sell']}) guardado en {$saved} empresa(s).");

        return self::SUCCESS;
    }
}
