<?php

namespace App\Modules\Portal\Support;

use App\Modules\Crm\Models\CrmOpportunity;
use App\Modules\Crm\Support\CrmCatalog;
use App\Modules\Portal\Models\PortalRequest;
use App\Services\AuditLogger;
use App\Services\SequentialNumberGenerator;
use Illuminate\Support\Facades\DB;

/**
 * Solicitud de un servicio desde el portal (también "Solicitar renovación").
 *
 * Siempre queda en la bandeja "Solicitudes del portal". Si la empresa tiene el CRM, además
 * nace una oportunidad en la primera etapa del embudo, con fuente "Portal cliente" y a nombre
 * de quien le vende al cliente (quien hizo su contrato o su cotización más reciente); sin
 * vendedor, queda sin responsable. Ninguna solicitud crea contratos sin la aprobación del
 * cliente: lo que sigue es una cotización.
 */
class PortalRequests
{
    public static function create(string $tenantId, string $customerId, string $userId, string $type, object $service, string $description): PortalRequest
    {
        $crm = in_array('CRM', PortalAccess::modules($tenantId), true);

        return DB::transaction(function () use ($tenantId, $customerId, $userId, $type, $service, $description, $crm) {
            $seller = self::seller($tenantId, $customerId);
            $branchId = $seller ? DB::table('tenant_users')->where('tenant_id', $tenantId)->where('user_id', $seller)->value('default_branch_id') : null;
            $number = SequentialNumberGenerator::next($tenantId, 'portal_requests', 'request_number', 'SOL');
            $subject = ($type === 'RENEWAL' ? 'Renovación: ' : '').$service->name;

            $opportunity = $crm ? self::opportunity($tenantId, $customerId, $userId, $service, $seller, $branchId, $number, $type) : null;

            $portalRequest = PortalRequest::create([
                'tenant_id' => $tenantId,
                'customer_id' => $customerId,
                'request_number' => $number,
                'request_type' => $type,
                'subject' => $subject,
                'description' => $description,
                'service_id' => $service->id,
                'status' => 'SUBMITTED',
                'route' => $opportunity ? 'CRM' : 'ERP',
                'crm_opportunity_id' => $opportunity?->id,
                'created_by' => $userId,
                'branch_id' => $branchId,
            ]);

            AuditLogger::log(
                tenantId: $tenantId, userId: $userId, action: 'portal_request.create', entityType: 'PortalRequest', entityId: $portalRequest->id,
                metadata: array_filter(['request_number' => $number, 'to' => 'SUBMITTED', 'route' => $portalRequest->route, 'opportunity' => $opportunity?->opportunity_number]),
            );

            return $portalRequest;
        });
    }

    private static function opportunity(string $tenantId, string $customerId, string $userId, object $service, ?string $seller, ?string $branchId, string $requestNumber, string $type): CrmOpportunity
    {
        $stage = CrmCatalog::stages($tenantId)->first();
        $source = CrmCatalog::sources($tenantId)->firstWhere('code', 'PORTAL_CLIENTE');
        $price = DB::table('service_prices')->where('service_id', $service->id)->where('active', true)
            ->orderByDesc('valid_from')->value('amount');
        $number = SequentialNumberGenerator::next($tenantId, 'crm_opportunities', 'opportunity_number', 'OP');

        $opportunity = CrmOpportunity::create([
            'tenant_id' => $tenantId,
            'opportunity_number' => $number,
            'customer_id' => $customerId,
            'name' => ($type === 'RENEWAL' ? 'Renovación ' : '').$service->name.' — '.PortalCustomer::name($customerId).' (portal)',
            'pipeline_id' => $stage->pipeline_id,
            'stage_id' => $stage->id,
            'source_id' => $source?->id,
            'status' => 'OPEN',
            'expected_value' => $price ?? 0,
            'currency' => 'PEN',
            'probability' => $stage->probability,
            'expected_close_date' => now()->addDays(30)->toDateString(),
            'owner_user_id' => $seller,
            'branch_id' => $branchId,
        ]);

        AuditLogger::log(
            tenantId: $tenantId, userId: $userId, action: 'crm_opportunity.create', entityType: 'CrmOpportunity', entityId: $opportunity->id,
            metadata: ['to' => 'OPEN', 'opportunity_number' => $number, 'amount' => (float) ($price ?? 0), 'note' => "Solicitud del portal {$requestNumber}"],
        );

        return $opportunity;
    }

    /** Quien le vende al cliente: el autor de su contrato o, si no, de su cotización más reciente, si sigue en la empresa. */
    private static function seller(string $tenantId, string $customerId): ?string
    {
        $active = fn ($query) => $query->whereIn('created_by', DB::table('tenant_users')->where('tenant_id', $tenantId)->where('status', 'ACTIVE')->select('user_id'));

        return DB::table('contracts')->where('customer_id', $customerId)->whereNotNull('created_by')->where($active)->orderByDesc('created_at')->value('created_by')
            ?? DB::table('quotations')->where('customer_id', $customerId)->whereNotNull('created_by')->where($active)->orderByDesc('created_at')->value('created_by');
    }
}
