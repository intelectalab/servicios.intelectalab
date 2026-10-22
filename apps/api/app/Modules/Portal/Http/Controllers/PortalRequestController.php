<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\PortalRequest;
use App\Services\AuditLogger;
use App\Services\ColumnFilters;
use App\Services\ListSort;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Bandeja de solicitudes del portal, del lado de la empresa: Ventas toma la
 * solicitud y la cotiza, o la rechaza con un motivo.
 */
class PortalRequestController extends Controller
{
    private const RELATIONS = [
        'customer.party.person',
        'customer.party.organization',
        'service:id,code,name',
        'quotation:id,quotation_number,status',
        'opportunity:id,opportunity_number,status',
    ];

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $query = PortalRequest::with(self::RELATIONS)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($search !== '', function ($q) use ($search) {
                // Por número, por lo que pide o por nombre del cliente.
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(function ($w) use ($like) {
                    $w->where('request_number', 'ILIKE', $like)
                        ->orWhere('subject', 'ILIKE', $like)
                        ->orWhere('description', 'ILIKE', $like)
                        ->orWhereHas('customer.party.organization', fn ($o) => $o
                            ->where('legal_name', 'ILIKE', $like)
                            ->orWhere('trade_name', 'ILIKE', $like))
                        ->orWhereHas('customer.party.person', fn ($p) => $p
                            ->whereRaw("concat_ws(' ', given_names, family_name) ILIKE ?", [$like]));
                });
            });

        ColumnFilters::apply($query, $request, [
            'number' => ColumnFilters::text("concat_ws(' ', portal_requests.request_number, portal_requests.subject)"),
            'customer' => ColumnFilters::text(ListSort::customerName('portal_requests')),
            'service' => ColumnFilters::text('(SELECT s.name FROM services s WHERE s.id = portal_requests.service_id)'),
        ]);

        ListSort::apply($query, $request, [
            'number' => 'portal_requests.request_number',
            'customer' => ListSort::customerName('portal_requests'),
            'service' => '(SELECT s.name FROM services s WHERE s.id = portal_requests.service_id)',
            'status' => ListSort::labels('portal_requests.status', ['SUBMITTED' => 'Recibida', 'IN_REVIEW' => 'En revisión', 'QUOTED' => 'Cotizada', 'REJECTED' => 'Rechazada']),
        ], fn ($q) => $q->orderByDesc('created_at')->orderByDesc('id'));

        return response()->json($query->paginate($request->integer('per_page', 20)));
    }

    /** Recibida → En revisión: alguien de Ventas la toma para cotizarla. */
    public function take(string $portalRequest, Request $request)
    {
        return $this->transition($request, $this->find($portalRequest), ['SUBMITTED'], 'IN_REVIEW', 'tomar', 'portal_request.take');
    }

    public function reject(string $portalRequest, Request $request)
    {
        return $this->transition($request, $this->find($portalRequest), ['SUBMITTED', 'IN_REVIEW'], 'REJECTED', 'rechazar', 'portal_request.reject', true);
    }

    private function find(string $portalRequest): PortalRequest
    {
        abort_unless(Str::isUuid($portalRequest), 404);

        return PortalRequest::findOrFail($portalRequest);
    }

    private function transition(Request $request, PortalRequest $portalRequest, array $from, string $to, string $verb, string $action, bool $needsReason = false)
    {
        if (! in_array($portalRequest->status, $from, true)) {
            throw ValidationException::withMessages([
                'status' => ["No se puede {$verb} una solicitud en estado {$portalRequest->status}."],
            ]);
        }
        // La que llegó al CRM se atiende desde su oportunidad: cotizarla o perderla la cierra aquí.
        if ($portalRequest->route === 'CRM') {
            throw ValidationException::withMessages([
                'status' => ['Esta solicitud se atiende en el CRM, desde su oportunidad.'],
            ]);
        }

        $reason = null;
        if ($needsReason) {
            $reason = $request->validate(
                ['reason' => ['required', 'string', 'max:1000']],
                ['reason.required' => 'Debes indicar un motivo.'],
            )['reason'];
        }

        $previous = $portalRequest->status;
        $portalRequest->update(array_filter(['status' => $to, 'resolution_note' => $reason]) + $portalRequest->claimFor($request->user()->id));

        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: $action,
            entityType: 'PortalRequest',
            entityId: $portalRequest->id,
            metadata: array_filter(['from' => $previous, 'to' => $to, 'note' => $reason]),
        );

        return response()->json($portalRequest->load(self::RELATIONS));
    }
}
