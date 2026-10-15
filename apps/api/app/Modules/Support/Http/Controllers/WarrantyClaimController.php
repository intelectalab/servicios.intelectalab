<?php

namespace App\Modules\Support\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Models\ContractedService;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Models\WarrantyClaim;
use App\Services\AuditHistory;
use App\Services\AuditLogger;
use App\Services\ColumnFilters;
use App\Services\ListSort;
use App\Services\SequentialNumberGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Reclamos de garantía: Recibido → En revisión (cobertura validada) → Cubierto / No cubierto →
 * Resuelto. Un reclamo no se aprueba solo por existir un ticket: la cobertura se valida con la
 * última OT completada del servicio contratado (o su inicio) más los meses de garantía del
 * servicio (S9-R-006). No factura nada.
 */
class WarrantyClaimController extends Controller
{
    private const OPTIONS_LIMIT = 300;

    /** Servicios contratados que pueden reclamarse: los que ya se prestaron o se están prestando. */
    private const CLAIMABLE = ['ACTIVE', 'SUSPENDED', 'COMPLETED'];

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', '');
        $coverage = (string) $request->query('coverage', '');

        $query = $this->listing()
            ->when(isset(WarrantyClaim::STATES[$status]), fn ($q) => $q->where('warranty_claims.status', $status))
            ->when($coverage === 'covered', fn ($q) => $q->where('warranty_claims.coverage_valid', true))
            ->when($coverage === 'not_covered', fn ($q) => $q->where('warranty_claims.coverage_valid', false))
            ->when($coverage === 'unchecked', fn ($q) => $q->whereNull('warranty_claims.coverage_checked_at'))
            ->when($search !== '', function ($q) use ($search) {
                // Por número, descripción, cliente o servicio.
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w
                    ->where('warranty_claims.claim_number', 'ILIKE', $like)
                    ->orWhere('warranty_claims.description', 'ILIKE', $like)
                    ->orWhereRaw(ListSort::customerName('warranty_claims').' ILIKE ?', [$like])
                    ->orWhereRaw('(SELECT s.name FROM services s WHERE s.id = warranty_claims.service_id) ILIKE ?', [$like]));
            });

        ColumnFilters::apply($query, $request, [
            'claim' => ColumnFilters::text("concat_ws(' ', warranty_claims.claim_number, warranty_claims.description)"),
            'customer' => ColumnFilters::text(ListSort::customerName('warranty_claims')),
            'opened' => ColumnFilters::date('warranty_claims.created_at'),
        ]);

        ListSort::apply($query, $request, [
            'claim' => 'warranty_claims.claim_number',
            'customer' => ListSort::customerName('warranty_claims'),
            'ticket' => '(SELECT t.ticket_number FROM support_tickets t WHERE t.id = warranty_claims.ticket_id)',
            'coverage' => 'warranty_claims.coverage_until',
            'status' => ListSort::labels('warranty_claims.status', WarrantyClaim::STATES),
            'opened' => 'warranty_claims.created_at',
        ], fn ($q) => $q->orderByDesc('warranty_claims.created_at'), 'warranty_claims.id');

        $page = $query->paginate(min(max($request->integer('per_page', 20), 1), 200));
        $page->getCollection()->transform(fn (WarrantyClaim $claim) => $this->row($claim));

        return response()->json($page);
    }

    /** Servicios contratados que la persona alcanza y tickets sin reclamo, para el formulario. */
    public function options()
    {
        return response()->json([
            'contracted_services' => ContractedService::query()
                ->with('customer.party.person', 'customer.party.organization', 'service:id,name,warranty_months', 'contract:id,contract_number')
                ->whereIn('status', self::CLAIMABLE)
                ->orderByDesc('created_at')->limit(self::OPTIONS_LIMIT)->get()
                ->map(fn (ContractedService $service) => [
                    'id' => $service->id,
                    'customer_id' => $service->customer_id,
                    'customer' => $this->customerName($service->customer),
                    'service' => $service->service?->name,
                    'contract' => $service->contract?->contract_number,
                    'warranty_months' => (int) ($service->service?->warranty_months ?? 0),
                    'status' => $service->status,
                ]),
            // Por su modelo: solo los tickets que la persona alcanza y que aún no tienen reclamo.
            'tickets' => SupportTicket::query()
                ->whereNotIn('id', fn ($sub) => $sub->select('ticket_id')->from('warranty_claims')->whereNotNull('ticket_id'))
                ->orderByDesc('opened_at')->limit(self::OPTIONS_LIMIT)->get(['id', 'ticket_number', 'subject', 'customer_id'])
                ->map(fn (SupportTicket $ticket) => ['id' => $ticket->id, 'number' => $ticket->ticket_number, 'subject' => $ticket->subject, 'customer_id' => $ticket->customer_id]),
        ]);
    }

    public function show(string $claim)
    {
        return response()->json($this->detail($this->find($claim)));
    }

    /** Registra el reclamo y, como en el prototipo, valida su cobertura en el acto. */
    public function store(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $data = $request->validate([
            'contracted_service_id' => ['required', 'uuid'],
            'ticket_id' => ['nullable', 'uuid'],
            'description' => ['required', 'string', 'max:5000'],
        ], [
            'contracted_service_id.required' => 'Selecciona el servicio contratado reclamado.',
            'description.required' => 'Describe la falla.',
        ]);
        if (trim($data['description']) === '') {
            throw ValidationException::withMessages(['description' => ['Describe la falla.']]);
        }

        // Por su modelo: un servicio contratado fuera del alcance de la persona "no existe".
        $service = ContractedService::whereIn('status', self::CLAIMABLE)->find($data['contracted_service_id']);
        if (! $service) {
            throw ValidationException::withMessages(['contracted_service_id' => ['El servicio contratado indicado no existe o aún no se presta.']]);
        }

        $ticket = null;
        if (! empty($data['ticket_id'])) {
            $ticket = SupportTicket::find($data['ticket_id']);
            if (! $ticket || $ticket->customer_id !== $service->customer_id) {
                throw ValidationException::withMessages(['ticket_id' => ['El ticket indicado no es de ese cliente.']]);
            }
            if (WarrantyClaim::ignoringAccessScope()->where('ticket_id', $ticket->id)->exists()) {
                throw ValidationException::withMessages(['ticket_id' => ['Ese ticket ya tiene un reclamo de garantía.']]);
            }
        }

        $claim = DB::transaction(function () use ($request, $tenantId, $data, $service, $ticket) {
            $claim = WarrantyClaim::create([
                'tenant_id' => $tenantId,
                'claim_number' => SequentialNumberGenerator::next($tenantId, 'warranty_claims', 'claim_number', 'GAR'),
                'customer_id' => $service->customer_id,
                'contract_id' => $service->contract_id,
                'contracted_service_id' => $service->id,
                'service_id' => $service->service_id,
                'ticket_id' => $ticket?->id,
                'description' => trim($data['description']),
                'status' => 'SUBMITTED',
                'opened_at' => now(),
                'created_by' => $request->user()->id,
                ...($service->branch_id ? ['branch_id' => $service->branch_id] : []),
            ]);
            $this->audit($request, $claim, 'warranty_claim.create', ['to' => 'SUBMITTED', 'number' => $claim->claim_number]);
            if ($ticket) {
                AuditLogger::log(
                    tenantId: $tenantId, userId: $request->user()->id, action: 'ticket.warranty_claim',
                    entityType: 'SupportTicket', entityId: $ticket->id, metadata: ['note' => "Reclamo de garantía {$claim->claim_number}"],
                );
            }
            $this->checkCoverage($request, $claim);

            return $claim;
        });

        return response()->json($this->detail($claim), 201);
    }

    /** Validar (o volver a validar) la cobertura. */
    public function coverage(string $claim, Request $request)
    {
        $claim = $this->find($claim);
        if (! in_array($claim->status, ['SUBMITTED', 'UNDER_REVIEW'], true)) {
            throw ValidationException::withMessages(['status' => ['La cobertura de un reclamo ya decidido no se vuelve a validar.']]);
        }

        DB::transaction(fn () => $this->checkCoverage($request, $claim));

        return response()->json($this->detail($claim));
    }

    /** Aprobar la cobertura: solo dentro de garantía (RN-SUP-06). */
    public function approve(string $claim, Request $request)
    {
        $claim = $this->find($claim);
        $this->assertStatus($claim, 'UNDER_REVIEW', 'aprobar');
        if (! $claim->coverage_valid) {
            throw ValidationException::withMessages(['coverage' => ['Fuera de cobertura: el reclamo no se puede aprobar.']]);
        }

        DB::transaction(function () use ($request, $claim) {
            $claim->update(['status' => 'APPROVED', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
            $this->audit($request, $claim, 'warranty_claim.approve', ['from' => 'UNDER_REVIEW', 'to' => 'APPROVED']);
        });

        return response()->json($this->detail($claim));
    }

    public function reject(string $claim, Request $request)
    {
        $claim = $this->find($claim);
        $this->assertStatus($claim, 'UNDER_REVIEW', 'rechazar');
        $reason = $this->reason($request, 'Debes indicar un motivo.');

        DB::transaction(function () use ($request, $claim, $reason) {
            $claim->update(['status' => 'REJECTED', 'reject_reason' => $reason, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
            $this->audit($request, $claim, 'warranty_claim.reject', ['from' => 'UNDER_REVIEW', 'to' => 'REJECTED', 'note' => $reason]);
        });

        return response()->json($this->detail($claim));
    }

    public function resolve(string $claim, Request $request)
    {
        $claim = $this->find($claim);
        $this->assertStatus($claim, 'APPROVED', 'marcar resuelto');
        $resolution = $this->reason($request, 'Escribe cómo se resolvió.');

        DB::transaction(function () use ($request, $claim, $resolution) {
            $claim->update(['status' => 'RESOLVED', 'resolution' => $resolution, 'resolved_at' => now()]);
            $this->audit($request, $claim, 'warranty_claim.resolve', ['from' => 'APPROVED', 'to' => 'RESOLVED', 'note' => $resolution]);
        });

        return response()->json($this->detail($claim));
    }

    // ------------------------------------------------------------------ piezas

    /**
     * Cobertura: desde la última OT completada del servicio contratado (o su inicio) más los meses
     * de garantía del servicio. Cubierto si el reclamo llegó dentro de ese plazo.
     */
    private function checkCoverage(Request $request, WarrantyClaim $claim): void
    {
        // Se lee aunque la persona no alcance el servicio contratado ni sus órdenes: es el dato del reclamo.
        $contracted = DB::table('contracted_services')->where('id', $claim->contracted_service_id)->first(['id', 'start_date', 'service_id', 'created_at']);
        $service = DB::table('services')->where('id', $contracted->service_id)->first(['name', 'warranty_months', 'warranty_conditions']);
        $order = DB::table('work_orders')->where('contracted_service_id', $contracted->id)->where('status', 'COMPLETED')
            ->whereNotNull('completed_at')->orderByDesc('completed_at')->first(['code', 'completed_at']);

        $months = (int) ($service->warranty_months ?? 0);
        $base = $order ? Carbon::parse($order->completed_at)->startOfDay() : Carbon::parse($contracted->start_date ?? $contracted->created_at)->startOfDay();
        $until = $months > 0 ? $base->copy()->addMonthsNoOverflow($months) : null;
        $claimedOn = ($claim->opened_at ?? now())->copy()->startOfDay();
        $covered = $until !== null && $until->greaterThanOrEqualTo($claimedOn);
        $conditions = trim((string) ($service->warranty_conditions ?? ''));

        $previous = $claim->status;
        $claim->update([
            'coverage_checked_at' => now(),
            'coverage_valid' => $covered,
            'coverage_base_date' => $base->toDateString(),
            'coverage_until' => $until?->toDateString(),
            'coverage_policy' => $months > 0
                ? $months.' '.($months === 1 ? 'mes' : 'meses').($conditions !== '' ? " · {$conditions}" : '')
                : 'El servicio no tiene política de garantía',
            'coverage_base' => $order ? "Última OT completada ({$order->code})" : 'Inicio del servicio contratado',
            'status' => $previous === 'SUBMITTED' ? 'UNDER_REVIEW' : $previous,
        ]);

        $this->audit($request, $claim, 'warranty_claim.coverage', array_filter([
            'from' => $previous === 'SUBMITTED' ? 'SUBMITTED' : null,
            'to' => $previous === 'SUBMITTED' ? 'UNDER_REVIEW' : null,
            'covered' => $covered,
            'until' => $until?->toDateString(),
            'note' => ($covered ? 'Dentro de garantía' : 'Fuera de cobertura').($until ? ' · hasta '.$until->format('d/m/Y') : ''),
        ], fn ($value) => $value !== null));
    }

    private function find(string $claim): WarrantyClaim
    {
        abort_unless(Str::isUuid($claim), 404);

        return WarrantyClaim::findOrFail($claim);
    }

    private function listing()
    {
        return WarrantyClaim::query()
            ->select('warranty_claims.*')
            ->with('customer.party.person', 'customer.party.organization', 'service:id,name');
    }

    private function row(WarrantyClaim $claim): array
    {
        $ticket = $claim->ticket_id ? DB::table('support_tickets')->where('id', $claim->ticket_id)->first(['id', 'ticket_number']) : null;

        return [
            'id' => $claim->id,
            'number' => $claim->claim_number,
            'description' => $claim->description,
            'customer' => $claim->customer,
            'service' => $claim->service ? ['id' => $claim->service->id, 'name' => $claim->service->name] : null,
            'contracted_service_id' => $claim->contracted_service_id,
            // Un ticket fuera del alcance se muestra con su número, sin enlace.
            'ticket' => $ticket ? ['id' => $ticket->id, 'number' => $ticket->ticket_number, 'visible' => SupportTicket::whereKey($ticket->id)->exists()] : null,
            'coverage' => $claim->coverage_checked_at ? [
                'checked_at' => $claim->coverage_checked_at->toIso8601String(),
                'valid' => (bool) $claim->coverage_valid,
                'base_date' => $claim->coverage_base_date?->toDateString(),
                'base' => $claim->coverage_base,
                'until' => $claim->coverage_until?->toDateString(),
                'policy' => $claim->coverage_policy,
            ] : null,
            'status' => $claim->status,
            'reject_reason' => $claim->reject_reason,
            'resolution' => $claim->resolution,
            'created_at' => ($claim->opened_at ?? $claim->created_at)?->toIso8601String(),
            'lock_version' => (int) $claim->lock_version,
        ];
    }

    private function detail(WarrantyClaim $claim): array
    {
        $claim = $this->listing()->with('creator:id,name', 'reviewer:id,name')->findOrFail($claim->id);

        return [
            ...$this->row($claim),
            'contract' => $claim->contract_id ? DB::table('contracts')->where('id', $claim->contract_id)->value('contract_number') : null,
            'creator' => $claim->creator?->name,
            'reviewer' => $claim->reviewer?->name,
            'reviewed_at' => $claim->reviewed_at?->toIso8601String(),
            'resolved_at' => $claim->resolved_at?->toIso8601String(),
            'history' => AuditHistory::for('WarrantyClaim', $claim->id),
        ];
    }

    private function assertStatus(WarrantyClaim $claim, string $expected, string $verb): void
    {
        if ($claim->status !== $expected) {
            throw ValidationException::withMessages([
                'status' => ["No se puede {$verb} un reclamo ".mb_strtolower(WarrantyClaim::STATES[$claim->status] ?? $claim->status).'.'],
            ]);
        }
    }

    private function reason(Request $request, string $message): string
    {
        $reason = trim((string) $request->validate(['reason' => ['required', 'string', 'max:2000']], ['reason.required' => $message])['reason']);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => [$message]]);
        }

        return $reason;
    }

    private function customerName(?object $customer): ?string
    {
        $party = $customer?->party;

        return $party?->organization?->trade_name ?: $party?->organization?->legal_name
            ?: (trim(($party?->person?->given_names ?? '').' '.($party?->person?->family_name ?? '')) ?: null);
    }

    private function audit(Request $request, WarrantyClaim $claim, string $action, array $metadata = []): void
    {
        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: $action,
            entityType: 'WarrantyClaim',
            entityId: $claim->id,
            metadata: $metadata,
        );
    }
}
