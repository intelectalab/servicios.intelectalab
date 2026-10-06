<?php

namespace App\Modules\Services\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Customer;
use App\Modules\Finance\Models\ExchangeRate;
use App\Modules\Services\Http\Requests\StoreQuotationRequest;
use App\Modules\Services\Models\Quotation;
use App\Modules\Services\Models\QuotationApproval;
use App\Modules\Services\Models\QuotationItem;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServicePrice;
use App\Services\AuditHistory;
use App\Services\AuditLogger;
use App\Services\SequentialNumberGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuotationController extends Controller
{
    // Días de validez con los que nace una nueva versión.
    private const VALIDITY_DAYS = 15;

    private const DETAIL_RELATIONS = [
        'items.service.currentPrice',
        'customer.party.person',
        'customer.party.organization',
        'creator:id,name',
        'contract:id,quotation_id,contract_number,status',
        'approvals.approver:id,name',
        'previous:id,quotation_number',
        'supersededBy:id,previous_id,quotation_number',
    ];

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $query = Quotation::with('items', 'customer.party.person', 'customer.party.organization', 'creator:id,name', 'supersededBy:id,previous_id')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->query('customer_id')))
            ->when($request->filled('source'), function ($q) use ($request) {
                // Sin origen registrado, la cotización es directa.
                $source = strtoupper((string) $request->query('source'));
                $source === 'DIRECT'
                    ? $q->where(fn ($w) => $w->whereNull('source_type')->orWhere('source_type', 'DIRECT'))
                    : $q->where('source_type', $source);
            })
            ->when($search !== '', function ($q) use ($search) {
                // Por número de cotización o por nombre del cliente.
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(function ($w) use ($like) {
                    $w->where('quotation_number', 'ILIKE', $like)
                        ->orWhereHas('customer.party.organization', fn ($o) => $o
                            ->where('legal_name', 'ILIKE', $like)
                            ->orWhere('trade_name', 'ILIKE', $like))
                        ->orWhereHas('customer.party.person', fn ($p) => $p
                            ->whereRaw("concat_ws(' ', given_names, family_name) ILIKE ?", [$like]));
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return response()->json($query->paginate($request->integer('per_page', 20)));
    }

    /** Conteo y monto por estado, para los indicadores y pestañas del listado. */
    public function summary()
    {
        $byStatus = [];

        $rows = Quotation::query()
            ->selectRaw('status, currency, count(*) as count, coalesce(sum(total), 0) as amount')
            ->groupBy('status', 'currency')
            ->get();

        foreach ($rows as $row) {
            $byStatus[$row->status] ??= ['count' => 0, 'amounts' => []];
            $byStatus[$row->status]['count'] += (int) $row->count;
            $byStatus[$row->status]['amounts'][$row->currency] = (float) $row->amount;
        }

        return response()->json([
            'total' => array_sum(array_column($byStatus, 'count')),
            'by_status' => (object) $byStatus,
        ]);
    }

    public function show(string $quotation)
    {
        $quotation = Quotation::findOrFail($quotation);

        return response()->json($this->detail($quotation));
    }

    public function store(StoreQuotationRequest $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $this->assertSellable($request);
        $currency = $request->currency ?? 'PEN';
        $exchangeRate = $this->exchangeRateFor($currency);

        $quotation = DB::transaction(function () use ($request, $tenantId, $currency, $exchangeRate) {
            $quotationNumber = SequentialNumberGenerator::next(
                $tenantId,
                'quotations',
                'quotation_number',
                'COT'
            );

            [$itemsData, $totals] = $this->buildItems($request->items, $tenantId);

            $quotation = Quotation::create([
                'tenant_id' => $tenantId,
                'customer_id' => $request->customer_id,
                'quotation_number' => $quotationNumber,
                'status' => 'DRAFT',
                'currency' => $currency,
                'exchange_rate' => $exchangeRate,
                'valid_until' => $request->valid_until,
                ...$totals,
                'notes' => $request->notes,
                'created_by' => $request->user()->id,
            ]);

            foreach ($itemsData as $itemData) {
                $itemData['quotation_id'] = $quotation->id;
                QuotationItem::create($itemData);
            }

            AuditLogger::log(
                tenantId: $tenantId,
                userId: $request->user()->id,
                action: 'quotation.create',
                entityType: 'Quotation',
                entityId: $quotation->id,
                metadata: ['quotation_number' => $quotationNumber, 'total' => $quotation->total],
            );

            return $quotation;
        });

        return response()->json($quotation->load('items.service'), 201);
    }

    /** Solo las cotizaciones en borrador se editan; las demás necesitan una nueva versión. */
    public function update(string $quotation, StoreQuotationRequest $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $quotation = Quotation::findOrFail($quotation);

        if ($quotation->status !== 'DRAFT') {
            throw ValidationException::withMessages([
                'status' => ['Solo las cotizaciones en BORRADOR se pueden editar. Crea una nueva versión.'],
            ]);
        }
        $this->assertSellable($request);

        // Sin cambio de moneda se conserva el tipo de cambio con el que se convirtieron las líneas.
        $currency = $request->currency ?? $quotation->currency;
        $exchangeRate = $currency === $quotation->currency && ($currency === 'PEN' || $quotation->exchange_rate !== null)
            ? $quotation->exchange_rate
            : $this->exchangeRateFor($currency);

        DB::transaction(function () use ($request, $tenantId, $quotation, $currency, $exchangeRate) {
            $before = ['total' => $quotation->total, 'items' => $quotation->items()->count()];
            [$itemsData, $totals] = $this->buildItems($request->items, $tenantId);

            $quotation->update([
                'customer_id' => $request->customer_id,
                'currency' => $currency,
                'exchange_rate' => $exchangeRate,
                'valid_until' => $request->valid_until,
                'notes' => $request->notes,
                ...$totals,
            ]);

            QuotationItem::where('quotation_id', $quotation->id)->delete();
            foreach ($itemsData as $itemData) {
                $itemData['quotation_id'] = $quotation->id;
                QuotationItem::create($itemData);
            }

            AuditLogger::log(
                tenantId: $tenantId,
                userId: $request->user()->id,
                action: 'quotation.update',
                entityType: 'Quotation',
                entityId: $quotation->id,
                beforeData: $before,
                afterData: ['total' => $quotation->total, 'items' => count($itemsData)],
            );
        });

        return response()->json($quotation->load('items.service'));
    }

    public function submit(string $quotation, Request $request)
    {
        $quotation = Quotation::findOrFail($quotation);
        $this->assertStatus($quotation, ['DRAFT'], 'enviar');

        if (!$quotation->items()->exists()) {
            throw ValidationException::withMessages([
                'items' => ['La cotización necesita al menos una línea para enviarse.'],
            ]);
        }

        return $this->transition($request, $quotation, 'SUBMITTED', 'quotation.submit');
    }

    public function approve(string $quotation, Request $request)
    {
        $quotation = Quotation::findOrFail($quotation);
        $this->assertStatus($quotation, ['SUBMITTED'], 'aprobar');

        if ($quotation->valid_until && $quotation->valid_until->copy()->endOfDay()->isPast()) {
            throw ValidationException::withMessages([
                'status' => ['No se puede aprobar una cotización vencida.'],
            ]);
        }

        if ($this->ownApprovalBlocked($quotation, $request)) {
            throw ValidationException::withMessages([
                'status' => ['Segregación de funciones: no puedes aprobar tu propia cotización.'],
            ]);
        }

        $data = $request->validate(['comment' => ['nullable', 'string', 'max:1000']]);

        // Outbox pendiente aquí también: este es el punto exacto donde,
        // según la arquitectura, debería dispararse un evento
        // "quotation.approved". No lo invento sin definición confirmada.

        return $this->decide($request, $quotation, 'APPROVED', 'quotation.approve', $data['comment'] ?? null);
    }

    public function reject(string $quotation, Request $request)
    {
        $quotation = Quotation::findOrFail($quotation);
        $this->assertStatus($quotation, ['SUBMITTED'], 'rechazar');

        $data = $request->validate(
            ['reason' => ['required', 'string', 'max:1000']],
            ['reason.required' => 'Indica el motivo del rechazo.'],
        );

        return $this->decide($request, $quotation, 'REJECTED', 'quotation.reject', $data['reason']);
    }

    public function expire(string $quotation, Request $request)
    {
        $quotation = Quotation::findOrFail($quotation);
        $this->assertStatus($quotation, ['SUBMITTED'], 'marcar vencida');

        return $this->transition($request, $quotation, 'EXPIRED', 'quotation.expire');
    }

    public function cancel(string $quotation, Request $request)
    {
        $quotation = Quotation::findOrFail($quotation);
        $this->assertStatus($quotation, ['DRAFT', 'SUBMITTED'], 'cancelar');

        return $this->transition($request, $quotation, 'CANCELLED', 'quotation.cancel');
    }

    /**
     * Nueva versión en borrador: copia las líneas con el precio vigente del
     * catálogo. La anterior se conserva sin cambios (si estaba enviada, se cancela).
     */
    public function newVersion(string $quotation, Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $quotation = Quotation::findOrFail($quotation);

        if ($quotation->status === 'DRAFT') {
            throw ValidationException::withMessages(['status' => ['La cotización en borrador se edita directamente.']]);
        }
        if ($quotation->status === 'CANCELLED') {
            throw ValidationException::withMessages(['status' => ['No se puede versionar una cotización cancelada.']]);
        }
        if ($quotation->supersededBy()->exists()) {
            throw ValidationException::withMessages(['status' => ['Ya existe una versión más reciente.']]);
        }
        if ($quotation->contract()->exists()) {
            throw ValidationException::withMessages(['status' => ['La cotización ya se convirtió en contrato.']]);
        }

        // En dólares, el precio vigente del catálogo (soles) se convierte al tipo de cambio de hoy;
        // si hoy no hay ninguno registrado, se usa el de la cotización original.
        $exchangeRate = null;
        if ($quotation->currency !== 'PEN') {
            $rate = ExchangeRate::current($quotation->currency)?->sell ?? $quotation->exchange_rate;
            $exchangeRate = $rate !== null ? (float) $rate : null;
        }

        $version = DB::transaction(function () use ($request, $tenantId, $quotation, $exchangeRate) {
            $number = SequentialNumberGenerator::next($tenantId, 'quotations', 'quotation_number', 'COT');

            $items = $quotation->items()->with('service.currentPrice')->get()->map(function (QuotationItem $item) use ($quotation, $exchangeRate) {
                $quantity = (float) $item->quantity;
                $oldGross = $quantity * (float) $item->unit_price;
                $catalog = $item->service?->currentPrice?->amount;
                $unitPrice = match (true) {
                    $catalog === null => (float) $item->unit_price,
                    $quotation->currency === 'PEN' => (float) $catalog,
                    $exchangeRate !== null => round((float) $catalog / $exchangeRate, 2),
                    default => (float) $item->unit_price,
                };
                // Se conserva el mismo porcentaje de descuento sobre el precio nuevo.
                $ratio = $oldGross > 0 ? (float) $item->discount / $oldGross : 0;

                return [
                    'service_id' => $item->service_id,
                    'description' => $item->description,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount' => round($quantity * $unitPrice * $ratio, 2),
                    'tax_rate' => (float) $item->tax_rate,
                ];
            })->all();

            [$itemsData, $totals] = $this->buildItems($items, $tenantId);

            $version = Quotation::create([
                'tenant_id' => $tenantId,
                'customer_id' => $quotation->customer_id,
                'quotation_number' => $number,
                'status' => 'DRAFT',
                'version' => $quotation->version + 1,
                'previous_id' => $quotation->id,
                'currency' => $quotation->currency,
                'exchange_rate' => $exchangeRate,
                'valid_until' => now()->addDays(self::VALIDITY_DAYS)->toDateString(),
                ...$totals,
                'notes' => $quotation->notes,
                'created_by' => $request->user()->id,
            ]);

            foreach ($itemsData as $itemData) {
                $itemData['quotation_id'] = $version->id;
                QuotationItem::create($itemData);
            }

            if ($quotation->status === 'SUBMITTED') {
                $quotation->update(['status' => 'CANCELLED']);
                $this->audit($request, $quotation, 'quotation.cancel', [
                    'from' => 'SUBMITTED', 'to' => 'CANCELLED', 'note' => "Reemplazada por {$number}",
                ]);
            }

            $this->audit($request, $version, 'quotation.version', [
                'quotation_number' => $number,
                'note' => "Nueva versión de {$quotation->quotation_number}",
            ]);

            return $version;
        });

        return response()->json($version->load('items.service'), 201);
    }

    /**
     * Segregación de funciones: quien creó la cotización no la aprueba. Es un ajuste
     * de la empresa (tenants.configuration.segregation), activo si no se indica.
     */
    private function ownApprovalBlocked(Quotation $quotation, Request $request): bool
    {
        if ($quotation->created_by !== $request->user()?->id) {
            return false;
        }

        $configuration = DB::table('tenants')->where('id', $quotation->tenant_id)->value('configuration');

        return (bool) (json_decode($configuration ?: '{}', true)['segregation'] ?? true);
    }

    /** Tipo de cambio (venta) con el que se emite una cotización; en soles no aplica. */
    private function exchangeRateFor(string $currency): ?float
    {
        if ($currency === 'PEN') {
            return null;
        }

        $rate = ExchangeRate::current($currency);
        if (! $rate) {
            throw ValidationException::withMessages([
                'currency' => ['Registra el tipo de cambio del dólar en Finanzas → Monedas y tipo de cambio para cotizar en dólares.'],
            ]);
        }

        return (float) $rate->sell;
    }

    /** Cliente activo y servicios activos: no se cotiza lo que no se puede vender. */
    private function assertSellable(StoreQuotationRequest $request): void
    {
        $customer = Customer::find($request->customer_id);
        if (!$customer) {
            throw ValidationException::withMessages(['customer_id' => ['Selecciona un cliente.']]);
        }
        if ($customer->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['customer_id' => ['El cliente está inactivo.']]);
        }

        $serviceIds = collect($request->items)->pluck('service_id')->filter()->unique();
        $services = Service::whereIn('id', $serviceIds)->get()->keyBy('id');

        foreach ($serviceIds as $serviceId) {
            $service = $services->get($serviceId);
            if (!$service || $service->status !== 'ACTIVE') {
                $name = $service?->name ?? '?';
                throw ValidationException::withMessages([
                    'items' => ["El servicio \"{$name}\" no está activo."],
                ]);
            }
        }
    }

    /**
     * Calcula cada línea y los totales de la cotización.
     *
     * @return array{0: \Illuminate\Support\Collection, 1: array<string, float>}
     */
    private function buildItems(array $items, string $tenantId): array
    {
        $subtotal = 0;
        $taxTotal = 0;
        $discountTotal = 0;

        $itemsData = collect($items)->map(function ($item) use (&$subtotal, &$taxTotal, &$discountTotal, $tenantId) {
            $quantity = (float) $item['quantity'];
            $unitPrice = (float) $item['unit_price'];
            $discount = (float) ($item['discount'] ?? 0);
            $taxRate = (float) ($item['tax_rate'] ?? 0);

            $lineBase = ($quantity * $unitPrice) - $discount;
            $taxAmount = round($lineBase * ($taxRate / 100), 4);
            $lineTotal = round($lineBase + $taxAmount, 4);

            $subtotal += $lineBase;
            $taxTotal += $taxAmount;
            $discountTotal += $discount;

            $serviceSnapshot = null;
            if (!empty($item['service_id'])) {
                $service = Service::with('recurrence')->find($item['service_id']);
                if ($service) {
                    $serviceSnapshot = [
                        'code' => $service->code,
                        'name' => $service->name,
                        'unit' => $service->unit,
                        'billing_mode' => $service->billing_mode,
                        'frequency' => $service->recurrence?->frequency,
                    ];
                }
            }

            return [
                'tenant_id' => $tenantId,
                'service_id' => $item['service_id'] ?? null,
                'description' => $item['description'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount' => $discount,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'line_total' => $lineTotal,
                'service_snapshot' => $serviceSnapshot,
            ];
        });

        return [$itemsData, [
            'subtotal' => round($subtotal, 4),
            'tax_total' => round($taxTotal, 4),
            'discount_total' => round($discountTotal, 4),
            'total' => round($subtotal + $taxTotal, 4),
        ]];
    }

    private function detail(Quotation $quotation): array
    {
        $data = $quotation->load(self::DETAIL_RELATIONS)->toArray();

        // Versión del precio del catálogo que estaba vigente cuando se guardó cada línea
        // (la más antigua es la 1), para mostrar "precio v2" como el prototipo.
        foreach ($quotation->items as $index => $item) {
            $data['items'][$index]['price_version'] = $item->service_id
                ? max(1, ServicePrice::where('service_id', $item->service_id)->where('created_at', '<=', $item->created_at)->count())
                : null;
        }

        // Dirección y correo del cliente (sus contactos), para el PDF de la cotización.
        $contacts = $quotation->customer
            ? DB::table('contacts')
                ->where('party_id', $quotation->customer->party_id)
                ->whereIn('contact_type', ['ADDRESS', 'EMAIL'])
                ->orderByDesc('is_primary')
                ->orderBy('created_at')
                ->get(['contact_type', 'value'])
            : collect();

        return [
            ...$data,
            'customer_contact' => [
                'address' => $contacts->firstWhere('contact_type', 'ADDRESS')?->value,
                'email' => $contacts->firstWhere('contact_type', 'EMAIL')?->value,
            ],
            // Para que la ficha avise antes de intentar aprobar.
            'own_approval_blocked' => $this->ownApprovalBlocked($quotation, request()),
            'history' => AuditHistory::for('Quotation', $quotation->id),
        ];
    }

    /** Aprobación o rechazo: además del cambio de estado queda la decisión con su comentario. */
    private function decide(Request $request, Quotation $quotation, string $decision, string $action, ?string $comment)
    {
        DB::transaction(function () use ($request, $quotation, $decision, $comment) {
            QuotationApproval::create([
                'tenant_id' => $quotation->tenant_id,
                'quotation_id' => $quotation->id,
                'approver_user_id' => $request->user()->id,
                'decision' => $decision,
                'comments' => $comment,
                'decided_at' => now(),
            ]);

            $quotation->update($decision === 'APPROVED'
                ? ['status' => 'APPROVED', 'approved_at' => now()]
                : ['status' => 'REJECTED']);
        });

        $this->audit($request, $quotation, $action, array_filter([
            'from' => 'SUBMITTED', 'to' => $decision, 'note' => $comment,
        ]));

        return response()->json($quotation);
    }

    private function transition(Request $request, Quotation $quotation, string $to, string $action)
    {
        $from = $quotation->status;
        $quotation->update(['status' => $to]);
        $this->audit($request, $quotation, $action, ['from' => $from, 'to' => $to]);

        return response()->json($quotation);
    }

    private function audit(Request $request, Quotation $quotation, string $action, array $metadata = []): void
    {
        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: $action,
            entityType: 'Quotation',
            entityId: $quotation->id,
            metadata: $metadata,
        );
    }

    private function assertStatus(Quotation $quotation, array $expected, string $action): void
    {
        if (!in_array($quotation->status, $expected, true)) {
            throw ValidationException::withMessages([
                'status' => ["No se puede {$action} una cotización en estado {$quotation->status}."],
            ]);
        }
    }
}
