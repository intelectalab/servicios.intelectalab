<?php

namespace App\Services;

use App\Http\Middleware\CheckPermission;
use App\Modules\Crm\Models\CrmLead;
use App\Modules\Crm\Models\CrmOpportunity;
use App\Modules\Finance\Models\ExchangeRate;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\RecurringInstance;
use App\Modules\Operations\Models\TimeEntry;
use App\Modules\Operations\Models\WorkOrder;
use App\Modules\Services\Models\Contract;
use App\Modules\Services\Models\Quotation;
use App\Modules\Support\Models\SupportTicket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Motor de workflows (Control → Workflows): reglas "cuando pasa X → si se cumple Y → entonces Z"
 * que arma cada empresa.
 *
 * - Se dispara desde la auditoría: cuando se registra una de las acciones de TRIGGERS (OT
 *   completada, cotización aprobada…), corre DESPUÉS de que la operación se guardó; si la regla
 *   falla, la operación no se pierde.
 * - Una sola vez por evento (la ejecución guarda el registro de auditoría que la disparó).
 * - Sin bucles: lo que hace una regla no dispara otras reglas.
 * - Los avisos llegan solo a quien puede ver ese registro (permiso del módulo y alcance).
 */
final class Workflows
{
    /** Disparador => [texto, acción de auditoría, modelo, permiso para ver el registro]. */
    public const TRIGGERS = [
        'work_order.completed' => ['Orden de trabajo completada', 'work_order.complete', WorkOrder::class, 'work_orders.view'],
        'ticket.created' => ['Ticket creado', 'ticket.create', SupportTicket::class, 'tickets.view'],
        'ticket.triaged' => ['Ticket clasificado', 'ticket.triage', SupportTicket::class, 'tickets.view'],
        'quotation.approved' => ['Cotización aprobada', 'quotation.approve', Quotation::class, 'quotations.view'],
        'quotation.rejected' => ['Cotización rechazada', 'quotation.reject', Quotation::class, 'quotations.view'],
        'payment.allocated' => ['Pago aplicado', 'payment.allocate', Payment::class, 'payments.view'],
        'time_entry.approved' => ['Horas aprobadas', 'time_entry.approve', TimeEntry::class, 'time_entries.view'],
        'opportunity.won' => ['Oportunidad ganada', 'crm_opportunity.win', CrmOpportunity::class, 'opportunities.view'],
        'contract.active' => ['Contrato activado', 'contract.activate', Contract::class, 'contracts.view'],
        'invoice.issued' => ['Comprobante emitido', 'invoice.issue', Invoice::class, 'billing.view'],
        'recurring.cycle_completed' => ['Ciclo recurrente procesado', 'recurring.cycle_completed', RecurringInstance::class, 'recurring.view'],
        'lead.created' => ['Lead creado', 'crm_lead.create', CrmLead::class, 'leads.view'],
    ];

    /** Campos que una condición puede mirar, por disparador: [texto, tipo, opciones]. */
    public const FIELDS = [
        'work_order.completed' => ['priority' => ['Prioridad', 'choice', ['HIGH' => 'Alta', 'NORMAL' => 'Media', 'LOW' => 'Baja']]],
        'ticket.created' => self::TICKET_FIELDS,
        'ticket.triaged' => self::TICKET_FIELDS,
        'quotation.approved' => ['amount' => ['Monto (S/)', 'number', []]],
        'quotation.rejected' => ['amount' => ['Monto (S/)', 'number', []]],
        'payment.allocated' => ['amount' => ['Monto (S/)', 'number', []]],
        'time_entry.approved' => ['hours' => ['Horas', 'number', []], 'billable' => ['Facturable', 'boolean', []]],
        'opportunity.won' => ['amount' => ['Monto (S/)', 'number', []]],
        'contract.active' => ['amount' => ['Valor del contrato (S/)', 'number', []]],
        'invoice.issued' => ['amount' => ['Total (S/)', 'number', []], 'type' => ['Tipo', 'choice', ['INVOICE' => 'Factura', 'RECEIPT' => 'Boleta', 'CREDIT_NOTE' => 'Nota de crédito']]],
        'recurring.cycle_completed' => ['amount' => ['Monto del ciclo (S/)', 'number', []]],
        'lead.created' => ['score' => ['Puntuación', 'number', []]],
    ];

    private const TICKET_FIELDS = [
        'priority' => ['Prioridad', 'choice', ['P1' => 'P1 Crítica', 'P2' => 'P2 Alta', 'P3' => 'P3 Media', 'P4' => 'P4 Baja']],
        'category' => ['Categoría', 'choice', ['INCIDENT' => 'Incidencia', 'REQUEST' => 'Requerimiento', 'QUESTION' => 'Consulta']],
        'channel' => ['Canal', 'choice', ['PHONE' => 'Teléfono', 'EMAIL' => 'Correo', 'BACKOFFICE' => 'Backoffice', 'PORTAL' => 'Portal']],
    ];

    /** Operadores por tipo de campo. */
    public const OPERATORS = [
        'number' => ['>' => 'mayor que', '>=' => 'mayor o igual que', '<' => 'menor que', '<=' => 'menor o igual que', '=' => 'igual a'],
        'choice' => ['=' => 'es', '!=' => 'no es'],
        'boolean' => ['=' => 'es'],
    ];

    public const ACTIONS = [
        'notify_role' => 'Avisar a un rol',
        'notify_owner' => 'Avisar al responsable',
        'portal_notice' => 'Aviso en el portal del cliente',
    ];

    /** Disparadores cuyo registro no tiene cliente: no pueden publicar en el portal. */
    public const WITHOUT_CUSTOMER = ['time_entry.approved', 'lead.created'];

    /** Mientras corre una regla, lo que ella haga no dispara otras (protección anti-bucle). */
    private static bool $running = false;

    /** Lo llama la auditoría: si la acción es un disparador, deja la regla para después de guardar. */
    public static function audited(string $tenantId, ?string $userId, string $action, ?string $entityType, ?string $entityId, string $auditId): void
    {
        if (self::$running || ! $entityId) {
            return;
        }
        $trigger = collect(self::TRIGGERS)->search(fn (array $definition) => $definition[1] === $action);
        if ($trigger === false) {
            return;
        }

        DB::afterCommit(fn () => self::fire($tenantId, $userId, $trigger, $entityId, $auditId));
    }

    public static function fire(string $tenantId, ?string $userId, string $trigger, string $entityId, string $eventKey): void
    {
        $rules = DB::table('workflow_definitions')->where('tenant_id', $tenantId)->where('entity_type', $trigger)->where('active', true)->get();
        if ($rules->isEmpty()) {
            return;
        }

        // Fuera de una petición (la tarea diaria de los ciclos), los avisos necesitan saber la empresa.
        $request = request();
        $previousTenant = $request->attributes->get('tenant_id');
        $request->attributes->set('tenant_id', $tenantId);
        self::$running = true;

        try {
            [, , $model, $permission] = self::TRIGGERS[$trigger];
            /** @var Model|null $record */
            $record = $model::ignoringAccessScope()->find($entityId);
            if (! $record) {
                return;
            }
            $payload = self::payload($trigger, $record);
            $moduleOn = CheckPermission::moduleAvailable($tenantId, $permission);

            foreach ($rules as $rule) {
                // Una sola vez por evento: si ya corrió, no repite los avisos.
                if (DB::table('workflow_runs')->where('workflow_definition_id', $rule->id)->where('event_key', $eventKey)->exists()) {
                    continue;
                }
                $config = json_decode((string) $rule->configuration, true) ?: [];
                $status = 'DONE';
                $matched = null;
                $results = [];

                try {
                    if (! $moduleOn) {
                        $status = 'SKIPPED';
                        $results[] = 'El módulo de este registro está apagado';
                    } elseif (! ($matched = self::matches($config['condition'] ?? null, $payload))) {
                        $status = 'SKIPPED';
                        $results[] = 'Condición no cumplida';
                    } else {
                        foreach ($config['actions'] ?? [] as $action) {
                            $results[] = self::run($action, $rule->name, $record, $payload, $permission, $tenantId, $userId);
                        }
                    }
                } catch (Throwable $e) {
                    $status = 'FAILED';
                    $results[] = 'Error al ejecutar la regla';
                    Log::warning('Workflow falló', ['workflow' => $rule->id, 'trigger' => $trigger, 'error' => $e->getMessage()]);
                }

                DB::table('workflow_runs')->insertOrIgnore([
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $tenantId,
                    'workflow_definition_id' => $rule->id,
                    'entity_id' => $entityId,
                    'event_key' => $eventKey,
                    'trigger' => $trigger,
                    'entity_label' => Str::limit($payload['label'], 250),
                    'matched' => $matched,
                    'status' => $status,
                    'result' => implode(' · ', $results),
                    'context' => json_encode(array_intersect_key($payload, array_flip(['amount', 'hours', 'billable', 'priority', 'category', 'channel', 'type', 'score']))),
                    'started_at' => now(),
                    'completed_at' => now(),
                ]);
            }
        } catch (Throwable $e) {
            // Nunca rompe la operación que disparó la regla.
            Log::warning('Workflows: no se pudieron evaluar las reglas', ['trigger' => $trigger, 'error' => $e->getMessage()]);
        } finally {
            self::$running = false;
            $request->attributes->set('tenant_id', $previousTenant);
        }
    }

    /** Sin condición se cumple siempre. */
    public static function matches(?array $condition, array $payload): bool
    {
        if (! $condition || empty($condition['field'])) {
            return true;
        }
        $value = $payload[$condition['field']] ?? null;
        $expected = $condition['value'] ?? null;

        if (is_bool($value)) {
            return $value === filter_var($expected, FILTER_VALIDATE_BOOLEAN);
        }
        if (is_int($value) || is_float($value)) {
            $expected = (float) $expected;

            return match ($condition['op'] ?? '=') {
                '>' => $value > $expected,
                '>=' => $value >= $expected,
                '<' => $value < $expected,
                '<=' => $value <= $expected,
                default => abs($value - $expected) < 0.005,
            };
        }

        return ($condition['op'] ?? '=') === '!=' ? (string) $value !== (string) $expected : (string) $value === (string) $expected;
    }

    private static function run(array $action, string $ruleName, Model $record, array $payload, string $permission, string $tenantId, ?string $userId): string
    {
        $text = trim((string) ($action['text'] ?? ''));
        $body = $text !== '' ? "{$text} · {$payload['label']}" : $payload['label'];

        switch ($action['type'] ?? null) {
            case 'notify_role':
                // Del rol, solo quienes pueden ver este registro.
                $members = DB::table('user_roles')->where('tenant_id', $tenantId)->where('role_id', $action['role_id'] ?? null)->pluck('user_id')->all();
                $recipients = array_values(array_intersect($members, Notifier::withPermission($permission, $record)));
                Notifier::send('workflow.notify', $ruleName, $body, $payload['link'], $recipients);
                $role = DB::table('roles')->where('id', $action['role_id'] ?? null)->value('name') ?? 'rol';

                return count($recipients) ? "Aviso a {$role} (".count($recipients).')' : "Nadie de {$role} puede ver este registro";
            case 'notify_owner':
                $owner = $payload['owner_id'];
                if (! $owner || ! in_array($owner, Notifier::withPermission($permission, $record), true)) {
                    return 'Sin responsable que pueda verlo';
                }
                if ($owner === $userId) {
                    return 'El responsable hizo la acción: no se le avisa';
                }
                Notifier::send('workflow.notify', $ruleName, $body, $payload['link'], [$owner]);

                return 'Aviso al responsable';
            case 'portal_notice':
                if (! $payload['customer_id']) {
                    return 'El registro no tiene cliente';
                }
                DB::table('portal_notices')->insert([
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $tenantId,
                    'customer_id' => $payload['customer_id'],
                    'body' => Str::limit($text !== '' ? $text : $ruleName, 500),
                    'created_by' => $userId,
                    'expires_at' => now()->addDays(30),
                    'created_at' => now(),
                ]);

                return 'Aviso publicado en el portal';
            default:
                return 'Acción desconocida';
        }
    }

    /**
     * Lo que la regla sabe del registro: texto y enlace para el aviso, responsable, cliente y los
     * campos que puede mirar una condición (montos en soles).
     */
    public static function payload(string $trigger, Model $r): array
    {
        $pen = function ($amount, ?string $currency, $rate = null) {
            $amount = (float) $amount;
            if ($currency !== 'USD') {
                return round($amount, 2);
            }

            return round($amount * (float) ($rate ?: (ExchangeRate::current('USD')?->sell ?? 0)), 2);
        };

        return match ($trigger) {
            'work_order.completed' => [
                'label' => "{$r->code} · {$r->title}", 'link' => "/work-orders/{$r->id}", 'owner_id' => $r->assigned_user_id ?? $r->created_by,
                'customer_id' => $r->customer_id, 'priority' => $r->priority,
            ],
            'ticket.created', 'ticket.triaged' => [
                'label' => "{$r->ticket_number} · {$r->subject}", 'link' => "/tickets/{$r->id}", 'owner_id' => $r->assigned_user_id ?? $r->created_by,
                'customer_id' => $r->customer_id, 'priority' => $r->priority, 'category' => $r->category, 'channel' => $r->channel,
            ],
            'quotation.approved', 'quotation.rejected' => [
                'label' => (string) $r->quotation_number, 'link' => "/quotations/{$r->id}", 'owner_id' => $r->created_by,
                'customer_id' => $r->customer_id, 'amount' => $pen($r->total, $r->currency, $r->exchange_rate),
            ],
            'payment.allocated' => [
                'label' => (string) $r->payment_number, 'link' => '/payments', 'owner_id' => $r->created_by,
                'customer_id' => $r->customer_id, 'amount' => $pen($r->amount, $r->currency, $r->exchange_rate),
            ],
            'time_entry.approved' => [
                'label' => rtrim(rtrim(number_format((float) $r->hours, 2, '.', ''), '0'), '.').' h'.($r->description ? " · {$r->description}" : ''),
                'link' => '/timesheets', 'owner_id' => $r->user_id, 'customer_id' => null,
                'hours' => (float) $r->hours, 'billable' => (bool) $r->billable,
            ],
            'opportunity.won' => [
                'label' => "{$r->opportunity_number} · {$r->name}", 'link' => "/crm/opportunities/{$r->id}", 'owner_id' => $r->owner_user_id,
                'customer_id' => $r->customer_id, 'amount' => $pen($r->expected_value, $r->currency),
            ],
            'contract.active' => [
                'label' => (string) $r->contract_number, 'link' => "/contracts/{$r->id}", 'owner_id' => $r->created_by,
                'customer_id' => $r->customer_id, 'amount' => $pen($r->total_value, $r->currency),
            ],
            'invoice.issued' => [
                'label' => (string) $r->invoice_number, 'link' => "/invoices/{$r->id}", 'owner_id' => $r->owner_user_id ?? $r->created_by,
                'customer_id' => $r->customer_id, 'amount' => $pen($r->total, $r->currency, $r->exchange_rate), 'type' => $r->invoice_type,
            ],
            'recurring.cycle_completed' => [
                'label' => 'Servicio recurrente · '.(DB::table('contracted_services as cs')->join('services as s', 's.id', '=', 'cs.service_id')->where('cs.id', $r->contracted_service_id)->value('s.name') ?? ''),
                'link' => "/recurring/{$r->id}", 'owner_id' => $r->owner_user_id,
                'customer_id' => $r->customer_id, 'amount' => $pen((float) $r->unit_price * (float) $r->quantity, $r->currency),
            ],
            'lead.created' => [
                'label' => trim("{$r->lead_number} · ".($r->organization_name ?: $r->person_name), ' ·'), 'link' => "/crm/leads/{$r->id}", 'owner_id' => $r->owner_user_id,
                'customer_id' => null, 'score' => (float) $r->score,
            ],
        };
    }
}
