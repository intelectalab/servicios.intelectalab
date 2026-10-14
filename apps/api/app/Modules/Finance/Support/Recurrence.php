<?php

namespace App\Modules\Finance\Support;

use App\Modules\Core\Models\Customer;
use App\Modules\Finance\Models\BillableEvent;
use App\Modules\Finance\Models\RecurringCycle;
use App\Modules\Finance\Models\RecurringInstance;
use App\Services\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ciclos de los servicios recurrentes: cada periodo vencido de una instancia activa deja su
 * evento facturable, una sola vez. Un periodo que falla (cliente sin datos fiscales, sin precio)
 * se reintenta con la misma clave; las instancias suspendidas, vencidas o canceladas se omiten.
 */
class Recurrence
{
    /** Periodos que una instancia atrasada pone al día en una sola pasada. */
    private const MAX_PER_RUN = 12;

    /** Días de anticipación con que se puede procesar a mano el ciclo de una instancia. */
    public const SINGLE_HORIZON_DAYS = 40;

    /** Datos con que nace la instancia de un servicio contratado recurrente: lo pactado, no el catálogo. */
    public static function snapshot(object $contracted): array
    {
        $contract = DB::table('contracts')->where('id', $contracted->contract_id)->first(['currency', 'created_by', 'start_date', 'end_date']);
        $start = $contracted->start_date ?? $contract?->start_date;
        $end = $contracted->end_date ?? $contract?->end_date;

        return [
            'customer_id' => $contracted->customer_id,
            'unit_price' => round((float) $contracted->price, 4),
            'quantity' => (float) $contracted->quantity > 0 ? (float) $contracted->quantity : 1,
            'currency' => in_array($contract?->currency, ['PEN', 'USD'], true) ? $contract->currency : 'PEN',
            'start_date' => $start ? Carbon::parse($start)->toDateString() : now()->toDateString(),
            'end_date' => $end ? Carbon::parse($end)->toDateString() : null,
            // Por alcance, la instancia es de quien lleva el servicio o, si nadie, de quien vendió el contrato.
            'owner_user_id' => $contracted->owner_user_id ?? $contract?->created_by,
            'branch_id' => $contracted->branch_id,
        ];
    }

    /** Importe de un ciclo, con IGV. */
    public static function cycleAmount(RecurringInstance $instance): float
    {
        return Billing::withTax(self::cycleBase($instance));
    }

    public static function cycleBase(RecurringInstance $instance): float
    {
        return round((float) $instance->unit_price * (float) $instance->quantity, 2);
    }

    /** Fecha del ciclo siguiente a uno que empieza en `$date`. */
    public static function nextDate(Carbon $date, string $frequency, int $interval = 1): Carbon
    {
        $interval = max(1, $interval);

        return match ($frequency) {
            'DAILY' => $date->copy()->addDays($interval),
            'WEEKLY' => $date->copy()->addWeeks($interval),
            'QUARTERLY' => $date->copy()->addMonthsNoOverflow(3 * $interval),
            'YEARLY' => $date->copy()->addYearsNoOverflow($interval),
            default => $date->copy()->addMonthsNoOverflow($interval),
        };
    }

    /** Nombre del periodo de un ciclo: el mes, o el día si la frecuencia es menor que un mes. */
    public static function periodOf(Carbon $start, string $frequency): string
    {
        return in_array($frequency, ['DAILY', 'WEEKLY'], true) ? $start->format('Y-m-d') : $start->format('Y-m');
    }

    /**
     * Procesa los ciclos vencidos de las instancias indicadas (ya filtradas por lo que la persona
     * alcanza). Cada instancia va en su propia transacción: una que falla no detiene a las demás.
     *
     * @param  string[]  $instanceIds
     * @return array{created: int, skipped_idem: int, skipped_status: int, failed: int, event_ids: string[]}
     */
    public static function run(array $instanceIds, Carbon $horizon, ?string $userId, bool $single = false): array
    {
        $summary = ['created' => 0, 'skipped_idem' => 0, 'skipped_status' => 0, 'failed' => 0, 'event_ids' => []];

        foreach ($instanceIds as $instanceId) {
            DB::transaction(function () use ($instanceId, $horizon, $userId, $single, &$summary) {
                // Se bloquea hasta terminar: dos ejecuciones a la vez no procesan el mismo periodo.
                $instance = RecurringInstance::ignoringAccessScope()->whereKey($instanceId)->lockForUpdate()->first();
                if (! $instance || ! $instance->next_cycle_date || $instance->next_cycle_date->gt($horizon)) {
                    return;
                }
                if ($instance->status !== 'ACTIVE') {
                    $summary['skipped_status']++;

                    return;
                }

                // A mano y por adelantado, un solo periodo; el proceso general pone al día los atrasados.
                $limit = $single ? 1 : self::MAX_PER_RUN;
                for ($done = 0; $done < $limit && $instance->status === 'ACTIVE' && $instance->next_cycle_date->lte($horizon); $done++) {
                    $outcome = self::process($instance, $userId);
                    if ($outcome instanceof BillableEvent) {
                        $summary['created']++;
                        $summary['event_ids'][] = $outcome->id;
                    } elseif ($outcome === 'skipped') {
                        $summary['skipped_idem']++;
                    } else {
                        $summary['failed']++;
                        break;
                    }
                }
            });
        }

        return $summary;
    }

    /**
     * Procesa el periodo que le toca a la instancia. Devuelve el evento creado, 'skipped' si ese
     * periodo ya estaba procesado o 'failed'. Debe llamarse con la instancia bloqueada.
     */
    private static function process(RecurringInstance $instance, ?string $userId): BillableEvent|string
    {
        $start = $instance->next_cycle_date->copy();
        $next = self::nextDate($start, $instance->frequency, (int) $instance->interval_value);
        $period = self::periodOf($start, $instance->frequency);
        $key = "{$instance->tenant_id}:{$instance->id}:{$period}";

        $cycle = RecurringCycle::where('tenant_id', $instance->tenant_id)->where('idempotency_key', $key)->first();
        if ($cycle?->status === 'COMPLETED') {
            // Ya se procesó: no se duplica, solo se avanza al periodo siguiente.
            self::advance($instance, $next, $userId);

            return 'skipped';
        }

        $base = self::cycleBase($instance);
        $amount = Billing::withTax($base);
        $cycle ??= new RecurringCycle([
            'tenant_id' => $instance->tenant_id, 'recurring_instance_id' => $instance->id, 'period' => $period, 'idempotency_key' => $key,
            'cycle_start' => $start->toDateString(), 'cycle_end' => $next->copy()->subDay()->toDateString(), 'due_date' => $start->toDateString(),
        ]);
        $cycle->fill(['amount' => $amount, 'attempts' => (int) $cycle->attempts + 1]);

        $customer = Customer::with('party.person', 'party.organization')->where('tenant_id', $instance->tenant_id)->find($instance->customer_id);
        $identity = Billing::identity($customer);
        $error = match (true) {
            ! $customer || $identity['name'] === '' || ! $identity['tax_id'] => 'Cliente sin datos fiscales',
            $base <= 0 => 'El servicio no tiene precio pactado',
            default => null,
        };
        if ($error) {
            $cycle->fill(['status' => 'FAILED', 'error' => $error])->save();
            self::audit($instance, $userId, 'recurring.cycle_failed', ['note' => "Periodo {$period}: {$error}"]);

            return 'failed';
        }

        $contracted = DB::table('contracted_services')
            ->join('services', 'services.id', '=', 'contracted_services.service_id')
            ->where('contracted_services.id', $instance->contracted_service_id)
            ->first(['contracted_services.contract_id', 'services.name']);

        $cycle->fill(['status' => 'PROCESSING', 'error' => null])->save();
        $event = BillableEvent::create([
            'tenant_id' => $instance->tenant_id,
            'source_type' => 'RECURRING_CYCLE',
            'source_id' => $cycle->id,
            'customer_id' => $instance->customer_id,
            'contract_id' => $contracted?->contract_id,
            'description' => Str::limit(($contracted?->name ?? 'Servicio recurrente')." · periodo {$period}", 500, ''),
            'base' => $base,
            'amount' => $amount,
            'currency' => $instance->currency,
            'status' => 'PENDING',
            'idempotency_key' => "cycle:{$key}",
            'owner_user_id' => $instance->owner_user_id,
            'created_by' => $userId,
            // El evento es de la sede de la instancia, no de quien procesa el ciclo.
            'branch_id' => $instance->branch_id,
        ]);
        $cycle->fill(['status' => 'COMPLETED', 'billable_event_id' => $event->id, 'processed_at' => now()])->save();

        AuditLogger::log(
            tenantId: $instance->tenant_id, userId: $userId, action: 'billable_event.create', entityType: 'BillableEvent', entityId: $event->id,
            metadata: ['to' => 'PENDING', 'source' => 'RECURRING_CYCLE', 'amount' => $amount, 'note' => "Periodo {$period}"],
        );
        self::audit($instance, $userId, 'recurring.cycle_completed', ['note' => "Periodo {$period}", 'amount' => $amount]);
        self::advance($instance, $next, $userId);

        return $event;
    }

    /** Pasa al periodo siguiente; si ya cae fuera de la vigencia, la instancia vence. */
    private static function advance(RecurringInstance $instance, Carbon $next, ?string $userId): void
    {
        $instance->next_cycle_date = $next;
        if ($instance->end_date && $next->gt($instance->end_date)) {
            $instance->status = 'EXPIRED';
            self::audit($instance, $userId, 'recurring.expire', ['from' => 'ACTIVE', 'to' => 'EXPIRED', 'note' => 'Fin de vigencia']);
        }
        $instance->save();
    }

    public static function audit(RecurringInstance $instance, ?string $userId, string $action, array $metadata = []): void
    {
        AuditLogger::log(
            tenantId: $instance->tenant_id, userId: $userId, action: $action, entityType: 'RecurringInstance', entityId: $instance->id, metadata: $metadata,
        );
    }
}
