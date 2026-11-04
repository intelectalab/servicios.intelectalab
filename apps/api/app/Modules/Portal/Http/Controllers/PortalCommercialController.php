<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Support\CreditLimit;
use App\Modules\Portal\Models\PortalRequest;
use App\Modules\Portal\Support\PortalAccess;
use App\Modules\Portal\Support\PortalContracts;
use App\Modules\Portal\Support\PortalCustomer;
use App\Modules\Portal\Support\PortalRequests;
use App\Modules\Services\Models\Quotation;
use App\Modules\Services\Models\QuotationApproval;
use App\Modules\Services\Support\ContractSignatures;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Services\LocalTime;

/** Portal: cotizaciones (y su decisión), mis servicios, solicitudes y renovaciones. */
class PortalCommercialController extends Controller
{
    /** Cotizaciones del cliente, sin borradores (RN-POR-04). */
    public function quotations(Request $request)
    {
        return response()->json(
            Quotation::query()->where('customer_id', $request->attributes->get('portal_customer_id'))
                ->where('status', '!=', 'DRAFT')
                ->orderByDesc('created_at')
                ->get(['id', 'quotation_number', 'version', 'status', 'currency', 'total', 'valid_until', 'created_at'])
                ->map(fn (Quotation $quotation) => [
                    'id' => $quotation->id,
                    'quotation_number' => $quotation->quotation_number,
                    'version' => (int) $quotation->version,
                    'status' => $quotation->status,
                    'currency' => $quotation->currency,
                    'total' => (float) $quotation->total,
                    'valid_until' => $quotation->valid_until ? substr((string) $quotation->getRawOriginal('valid_until'), 0, 10) : null,
                    'created_at' => $quotation->created_at?->toIso8601String(),
                ]),
        );
    }

    /** Ficha de la cotización con la forma que usa su PDF; 404 si no es del cliente. */
    public function quotation(string $quotation, Request $request)
    {
        return response()->json($this->detail($this->find($quotation, $request), $request));
    }

    /** El cliente aprueba o rechaza (con motivo) una cotización enviada y vigente. */
    public function decide(string $quotation, Request $request)
    {
        $quotation = $this->find($quotation, $request);
        $data = $request->validate([
            'decision' => ['required', 'in:APPROVED,REJECTED'],
            'comment' => ['nullable', 'string', 'max:1000', 'required_if:decision,REJECTED'],
        ], ['comment.required_if' => 'Cuéntanos el motivo del rechazo.']);
        $comment = trim((string) ($data['comment'] ?? '')) ?: null;

        if ($quotation->status !== 'SUBMITTED') {
            throw ValidationException::withMessages(['status' => ['Esta cotización ya no espera tu decisión.']]);
        }
        if ($this->expired($quotation)) {
            throw ValidationException::withMessages(['status' => ['La cotización venció y ya no se puede aprobar. Solicita una nueva versión.']]);
        }
        if ($data['decision'] === 'REJECTED' && ! $comment) {
            throw ValidationException::withMessages(['comment' => ['Cuéntanos el motivo del rechazo.']]);
        }
        // Límite de crédito: con la regla "Bloquear" el cliente no aprueba (sin mostrarle su deuda);
        // con "Solo avisar" aprueba y la empresa lo ve en el historial.
        $credit = $data['decision'] === 'APPROVED' ? $this->credit($quotation) : null;
        if ($credit && $credit['blocked']) {
            throw ValidationException::withMessages(['status' => ['Para aprobar esta cotización comunícate con tu ejecutivo de cuenta.']]);
        }
        $creditNote = $credit ? 'Sobre el límite de crédito ('.\App\Services\Notifier::money($credit['excess'], $credit['currency']).' de exceso)' : null;

        DB::transaction(function () use ($request, $quotation, $data, $comment, $creditNote) {
            QuotationApproval::create([
                'tenant_id' => $quotation->tenant_id, 'quotation_id' => $quotation->id, 'approver_user_id' => $request->user()->id,
                'decision' => $data['decision'], 'comments' => $comment, 'decided_at' => now(), 'channel' => 'PORTAL',
            ]);
            $quotation->update($data['decision'] === 'APPROVED' ? ['status' => 'APPROVED', 'approved_at' => now()] : ['status' => 'REJECTED']);

            AuditLogger::log(
                tenantId: $quotation->tenant_id, userId: $request->user()->id,
                action: $data['decision'] === 'APPROVED' ? 'quotation.approve' : 'quotation.reject',
                entityType: 'Quotation', entityId: $quotation->id,
                metadata: array_filter(['from' => 'SUBMITTED', 'to' => $data['decision'], 'channel' => 'PORTAL',
                    'note' => implode(' · ', array_filter([$comment ? "Desde el portal: {$comment}" : 'Desde el portal', $creditNote]))]),
            );
            \App\Modules\Services\Http\Controllers\QuotationController::notifyDecision($quotation, $data['decision'], $comment, portal: true);
        });

        return response()->json($this->detail($quotation->refresh(), $request));
    }

    /** Datos del PDF de un contrato del cliente (sin borradores, salvo los ya firmados); 404 si no es suyo. */
    public function contract(string $contract, Request $request)
    {
        abort_unless(PortalAccess::can($request, 'services') || PortalAccess::can($request, 'documents'), 403, 'Tu rol del portal no incluye esta sección.');
        abort_unless(Str::isUuid($contract), 404);
        $row = PortalContracts::visible($request->attributes->get('portal_customer_id'))->firstWhere('id', $contract);
        abort_if(! $row, 404);

        return response()->json(PortalContracts::detail($row->id));
    }

    /** Contratos y servicios contratados del cliente, con su renovación. */
    public function services(Request $request)
    {
        $customerId = $request->attributes->get('portal_customer_id');
        $soon = now()->addDays(60)->toDateString();

        $contracts = DB::table('contracts')->where('customer_id', $customerId)->where('status', '!=', 'DRAFT')
            ->orderByDesc('start_date')
            ->get(['id', 'contract_number', 'start_date', 'end_date', 'sla_level', 'status', 'currency', 'total_value']);

        $services = DB::table('contracted_services')
            ->join('services', 'services.id', '=', 'contracted_services.service_id')
            ->join('contracts', 'contracts.id', '=', 'contracted_services.contract_id')
            ->leftJoin('recurring_service_instances as recurring', 'recurring.contracted_service_id', '=', 'contracted_services.id')
            ->where('contracted_services.customer_id', $customerId)
            ->orderBy('services.name')
            ->get([
                'contracted_services.id', 'contracted_services.status', 'contracted_services.start_date', 'contracted_services.end_date',
                'contracted_services.quantity', 'services.id as service_id', 'services.name as service_name', 'services.status as service_status',
                'contracts.contract_number', 'recurring.status as recurring_status', 'recurring.end_date as renewal_date', 'recurring.frequency',
            ]);

        // Una renovación ya pedida y sin atender no se pide de nuevo.
        $pending = DB::table('portal_requests')->where('customer_id', $customerId)->where('request_type', 'RENEWAL')
            ->whereIn('status', ['SUBMITTED', 'IN_REVIEW'])->pluck('service_id')->all();

        return response()->json([
            'contracts' => $contracts->map(fn ($contract) => [
                ...(array) $contract, 'total_value' => (float) $contract->total_value,
            ]),
            'services' => $services->map(fn ($service) => [
                'id' => $service->id,
                'status' => $service->status,
                'service_id' => $service->service_id,
                'service_name' => $service->service_name,
                'contract_number' => $service->contract_number,
                'start_date' => $service->start_date,
                'end_date' => $service->end_date,
                'quantity' => (float) $service->quantity,
                'recurring' => (bool) $service->recurring_status,
                'frequency' => $service->frequency,
                'renewal_date' => $service->renewal_date,
                'renewal_soon' => $service->recurring_status === 'ACTIVE' && $service->renewal_date && $service->renewal_date <= $soon,
                'renewal_requested' => in_array($service->service_id, $pending, true),
                'can_renew' => (bool) $service->recurring_status && $service->service_status === 'ACTIVE',
            ]),
        ]);
    }

    /** "Solicitar renovación" de un servicio recurrente: una solicitud de tipo renovación. */
    public function renewal(string $serviceLine, Request $request)
    {
        $customerId = $request->attributes->get('portal_customer_id');
        $row = DB::table('contracted_services')->where('id', $serviceLine)->where('customer_id', $customerId)->first(['id', 'service_id']);
        abort_if(! $row || ! DB::table('recurring_service_instances')->where('contracted_service_id', $row->id)->exists(), 404);

        $service = DB::table('services')->where('id', $row->service_id)->where('status', 'ACTIVE')->first(['id', 'name']);
        if (! $service) {
            throw ValidationException::withMessages(['service_id' => ['Este servicio ya no se ofrece. Escríbenos desde Solicitudes.']]);
        }
        if (DB::table('portal_requests')->where('customer_id', $customerId)->where('request_type', 'RENEWAL')
            ->where('service_id', $service->id)->whereIn('status', ['SUBMITTED', 'IN_REVIEW'])->exists()) {
            throw ValidationException::withMessages(['service_id' => ['Ya pediste la renovación de este servicio: tu ejecutivo la está revisando.']]);
        }

        $portalRequest = PortalRequests::create(
            $request->attributes->get('tenant_id'), $customerId, $request->user()->id, 'RENEWAL', $service, 'Solicito la renovación de este servicio.',
        );

        return response()->json($this->requestRow($portalRequest), 201);
    }

    /** Servicios activos del catálogo que el cliente puede pedir. */
    public function catalog()
    {
        return response()->json(
            DB::table('services')->where('status', 'ACTIVE')->orderBy('name')->get(['id', 'code', 'name', 'description', 'unit']),
        );
    }

    public function requests(Request $request)
    {
        return response()->json(
            PortalRequest::query()->with('service:id,name')
                ->where('customer_id', $request->attributes->get('portal_customer_id'))
                ->orderByDesc('created_at')->get()
                ->map(fn (PortalRequest $portalRequest) => $this->requestRow($portalRequest)),
        );
    }

    public function storeRequest(Request $request)
    {
        $data = $request->validate([
            'service_id' => ['required', 'uuid'],
            'description' => ['required', 'string', 'max:2000'],
        ], [
            'service_id.required' => 'Selecciona el servicio.',
            'description.required' => 'Cuéntanos qué necesitas.',
        ]);
        $service = DB::table('services')->where('id', $data['service_id'])->where('status', 'ACTIVE')->first(['id', 'name']);
        if (! $service) {
            throw ValidationException::withMessages(['service_id' => ['Selecciona un servicio del catálogo.']]);
        }
        if (trim($data['description']) === '') {
            throw ValidationException::withMessages(['description' => ['Cuéntanos qué necesitas.']]);
        }

        $portalRequest = PortalRequests::create(
            $request->attributes->get('tenant_id'), $request->attributes->get('portal_customer_id'), $request->user()->id,
            'NEW_SERVICE', $service, trim($data['description']),
        );

        return response()->json($this->requestRow($portalRequest), 201);
    }

    /** Contratos por firmar (con el documento completo) e historial de firmas del cliente. */
    public function signatures(Request $request)
    {
        $customerId = $request->attributes->get('portal_customer_id');
        foreach (DB::table('contract_signature_requests')->where('customer_id', $customerId)->where('status', 'PENDING')->pluck('contract_id') as $contractId) {
            ContractSignatures::expireStale($contractId);
        }
        $requests = DB::table('contract_signature_requests')->where('customer_id', $customerId)->orderByDesc('created_at')->get();
        $numbers = DB::table('contracts')->whereIn('id', $requests->pluck('contract_id'))->pluck('contract_number', 'id');
        $me = $request->user()->id;

        return response()->json([
            'pending' => $requests->where('status', 'PENDING')->values()->map(function ($signature) use ($me, $request) {
                $data = ContractSignatures::present($signature, true);
                $client = collect($data['signers'])->firstWhere('side', 'CLIENTE');
                $assignee = DB::table('portal_users')->where('id', $client['portal_user_id'])->value('user_id');

                return [...$data, 'mine' => $assignee === $me, 'contract' => $this->contractDocument($signature->contract_id, $request)];
            }),
            'history' => $requests->map(fn ($signature) => [
                'id' => $signature->id,
                'contract_number' => $numbers[$signature->contract_id] ?? null,
                'status' => $signature->status,
                'created_at' => Carbon::parse($signature->created_at)->toIso8601String(),
                'signed_at' => ($signed = DB::table('contract_signature_signers')->where('request_id', $signature->id)->where('side', 'CLIENTE')->value('signed_at'))
                    ? Carbon::parse($signed)->toIso8601String() : null,
                'declined_reason' => $signature->declined_reason,
            ])->values(),
        ]);
    }

    public function signatureCode(string $signatureRequest, Request $request)
    {
        ContractSignatures::sendCode($this->signature($signatureRequest, $request), $request->user(), 'CLIENTE');

        return response()->json(['message' => 'Te enviamos un código a tu correo.']);
    }

    public function sign(string $signatureRequest, Request $request)
    {
        $data = $request->validate([
            'typed_name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:10'],
            'accept' => ['accepted'],
        ], [
            'typed_name.required' => 'Escribe tu nombre completo.',
            'code.required' => 'Escribe el código de tu correo.',
            'accept.accepted' => 'Debes aceptar los términos del contrato.',
        ]);
        ContractSignatures::sign($this->signature($signatureRequest, $request), $request->user(), 'CLIENTE', $data, $request->ip(), $request->userAgent());

        return $this->signatures($request);
    }

    public function declineSignature(string $signatureRequest, Request $request)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']], ['reason.required' => 'Indica el motivo.']);
        ContractSignatures::decline($this->signature($signatureRequest, $request), $request->user(), $data['reason']);

        return $this->signatures($request);
    }

    /** Una solicitud de otro cliente "no existe". */
    private function signature(string $id, Request $request): object
    {
        abort_unless(Str::isUuid($id), 404);

        return DB::table('contract_signature_requests')->where('id', $id)
            ->where('customer_id', $request->attributes->get('portal_customer_id'))->first() ?? abort(404);
    }

    /** Lo que el cliente lee antes de firmar: las partes, la vigencia, el SLA, las líneas y las condiciones. */
    private function contractDocument(string $contractId, Request $request): array
    {
        $contract = DB::table('contracts')->where('id', $contractId)
            ->first(['contract_number', 'customer_id', 'currency', 'start_date', 'end_date', 'sla_level', 'terms', 'total_value']);
        $tenantId = $request->attributes->get('tenant_id');
        $company = DB::table('companies')->where('tenant_id', $tenantId)->orderBy('created_at')->value('legal_name');

        return [
            'contract_number' => $contract->contract_number,
            'company_name' => $company ?: PortalCustomer::companyName($tenantId),
            'customer_name' => PortalCustomer::name($contract->customer_id),
            'currency' => $contract->currency,
            'start_date' => substr((string) $contract->start_date, 0, 10),
            'end_date' => substr((string) $contract->end_date, 0, 10),
            'sla_level' => $contract->sla_level,
            'terms' => $contract->terms,
            'total_value' => (float) $contract->total_value,
            'items' => DB::table('contract_items')->where('contract_id', $contractId)->orderBy('created_at')->orderBy('id')
                ->get(['id', 'description', 'quantity', 'unit_price', 'line_total'])
                ->map(fn ($item) => ['id' => $item->id, 'description' => $item->description, 'quantity' => (float) $item->quantity,
                    'unit_price' => (float) $item->unit_price, 'line_total' => (float) $item->line_total]),
        ];
    }

    private function requestRow(PortalRequest $portalRequest): array
    {
        $quotation = $portalRequest->quotation_id
            ? DB::table('quotations')->where('id', $portalRequest->quotation_id)->first(['id', 'quotation_number', 'status'])
            : null;

        return [
            'id' => $portalRequest->id,
            'request_number' => $portalRequest->request_number,
            'request_type' => $portalRequest->request_type,
            'subject' => $portalRequest->subject,
            'description' => $portalRequest->description,
            'service_name' => $portalRequest->service?->name ?? DB::table('services')->where('id', $portalRequest->service_id)->value('name'),
            'status' => $portalRequest->status,
            'resolution_note' => $portalRequest->status === 'REJECTED' ? $portalRequest->resolution_note : null,
            'created_at' => $portalRequest->created_at?->toIso8601String(),
            // La cotización solo se enlaza cuando ya se le envió al cliente (no en borrador).
            'quotation' => $quotation && $quotation->status !== 'DRAFT' ? ['id' => $quotation->id, 'quotation_number' => $quotation->quotation_number] : null,
        ];
    }

    private function find(string $id, Request $request): Quotation
    {
        return Quotation::query()->whereKey($id)
            ->where('customer_id', $request->attributes->get('portal_customer_id'))
            ->where('status', '!=', 'DRAFT')
            ->firstOrFail();
    }

    private function expired(Quotation $quotation): bool
    {
        $validUntil = substr((string) $quotation->getRawOriginal('valid_until'), 0, 10);

        return $validUntil !== '' && $validUntil < LocalTime::today()->toDateString();
    }

    /** Exceso sobre el límite de crédito si aprobara la cotización; null si no lo supera. */
    private function credit(Quotation $quotation): ?array
    {
        $customer = \App\Modules\Core\Models\Customer::find($quotation->customer_id);
        $status = $customer ? CreditLimit::status($customer, (float) $quotation->total, $quotation->currency) : null;
        if (! $status || ! $status['exceeds']) {
            return null;
        }

        return [...$status, 'blocked' => CreditLimit::mode($quotation->tenant_id) === CreditLimit::BLOCK];
    }

    private function detail(Quotation $quotation, Request $request): array
    {
        $items = DB::table('quotation_items')->leftJoin('services', 'services.id', '=', 'quotation_items.service_id')
            ->where('quotation_items.quotation_id', $quotation->id)->orderBy('quotation_items.created_at')
            ->get(['quotation_items.id', 'quotation_items.description', 'quotation_items.quantity', 'quotation_items.unit_price',
                'quotation_items.discount', 'quotation_items.tax_amount', 'quotation_items.line_total', 'quotation_items.service_snapshot', 'services.unit']);
        $customer = PortalCustomer::of($quotation->customer_id);
        $partyId = DB::table('customers')->where('id', $quotation->customer_id)->value('party_id');
        $contacts = DB::table('contacts')->where('party_id', $partyId)->whereIn('contact_type', ['ADDRESS', 'EMAIL'])
            ->orderByDesc('is_primary')->orderBy('created_at')->get(['contact_type', 'value']);

        return [
            'id' => $quotation->id,
            'quotation_number' => $quotation->quotation_number,
            'version' => (int) $quotation->version,
            'status' => $quotation->status,
            'currency' => $quotation->currency,
            'exchange_rate' => $quotation->exchange_rate !== null ? (float) $quotation->exchange_rate : null,
            'subtotal' => (float) $quotation->subtotal,
            'discount_total' => (float) $quotation->discount_total,
            'tax_total' => (float) $quotation->tax_total,
            'total' => (float) $quotation->total,
            'valid_until' => substr((string) $quotation->getRawOriginal('valid_until'), 0, 10) ?: null,
            'created_at' => $quotation->created_at?->toIso8601String(),
            'notes' => $quotation->notes,
            'customer' => $customer,
            'customer_contact' => [
                'address' => $contacts->firstWhere('contact_type', 'ADDRESS')?->value,
                'email' => $contacts->firstWhere('contact_type', 'EMAIL')?->value,
            ],
            'creator' => ['name' => DB::table('users')->where('id', $quotation->created_by)->value('name')],
            'items' => $items->map(fn ($item) => [
                'id' => $item->id,
                'description' => $item->description,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'discount' => (float) $item->discount,
                'tax_amount' => (float) $item->tax_amount,
                'line_total' => (float) $item->line_total,
                'service_snapshot' => ['unit' => json_decode((string) $item->service_snapshot, true)['unit'] ?? null],
                'service' => ['unit' => $item->unit],
            ]),
            'expired' => $this->expired($quotation),
            'can_decide' => $quotation->status === 'SUBMITTED' && PortalAccess::can($request, 'approve'),
            // Con la regla "Bloquear" y el cliente sobre su límite, no puede aprobar (solo rechazar).
            'credit_blocked' => $quotation->status === 'SUBMITTED' && (bool) ($this->credit($quotation)['blocked'] ?? false),
            // Solo la decisión y su canal; el comentario, solo si lo escribió el cliente.
            'approvals' => DB::table('quotation_approvals')->where('quotation_id', $quotation->id)->orderBy('decided_at')
                ->get(['decision', 'channel', 'comments', 'decided_at'])
                ->map(fn ($approval) => [
                    'decision' => $approval->decision,
                    'channel' => $approval->channel,
                    'comment' => $approval->channel === 'PORTAL' ? $approval->comments : null,
                    'decided_at' => \Illuminate\Support\Carbon::parse($approval->decided_at)->toIso8601String(),
                ]),
        ];
    }
}
