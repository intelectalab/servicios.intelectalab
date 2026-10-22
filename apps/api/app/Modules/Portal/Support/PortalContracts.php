<?php

namespace App\Modules\Portal\Support;

use App\Modules\Services\Support\ContractSignatures;
use Illuminate\Support\Facades\DB;

/**
 * Contratos que el cliente ve en el portal y los datos de su PDF (la misma forma que usa la ficha del
 * contrato, sin nada interno). Los borradores no se muestran, salvo los ya firmados por las dos partes.
 */
class PortalContracts
{
    public static function visible(string $customerId)
    {
        return DB::table('contracts')->where('customer_id', $customerId)
            ->where(fn ($query) => $query->where('status', '!=', 'DRAFT')->orWhere('signature_status', 'SIGNED'))
            ->orderByDesc('created_at')
            ->get(['id', 'contract_number', 'status', 'signature_status', 'created_at', 'updated_at']);
    }

    public static function detail(string $contractId): array
    {
        $contract = DB::table('contracts')->where('id', $contractId)->first();
        $partyId = DB::table('customers')->where('id', $contract->customer_id)->value('party_id');
        $signature = DB::table('contract_signature_requests')->where('contract_id', $contractId)->orderByDesc('created_at')->first();

        return [
            'id' => $contract->id,
            'contract_number' => $contract->contract_number,
            'status' => $contract->status,
            'currency' => $contract->currency,
            'start_date' => $contract->start_date,
            'end_date' => $contract->end_date,
            'total_value' => $contract->total_value,
            'terms' => $contract->terms,
            'sla_level' => $contract->sla_level,
            'signature_status' => $contract->signature_status,
            'customer' => PortalCustomer::of($contract->customer_id),
            'customer_contact' => [
                'address' => DB::table('contacts')->where('party_id', $partyId)->where('contact_type', 'ADDRESS')
                    ->orderByDesc('is_primary')->orderBy('created_at')->value('value'),
            ],
            'quotation' => $contract->quotation_id
                ? ['id' => $contract->quotation_id, 'quotation_number' => DB::table('quotations')->where('id', $contract->quotation_id)->value('quotation_number')]
                : null,
            'creator' => ['name' => DB::table('users')->where('id', $contract->created_by)->value('name')],
            'items' => DB::table('contract_items')->where('contract_id', $contractId)->orderBy('created_at')->orderBy('id')
                ->get(['id', 'description', 'quantity', 'unit_price', 'line_total', 'service_snapshot'])
                ->map(fn ($item) => [...(array) $item, 'service_snapshot' => json_decode((string) $item->service_snapshot, true)]),
            // Para el cliente, la firma sin las IP (las del lado de la empresa no le corresponden).
            'signature' => $signature ? ContractSignatures::present($signature, true) : null,
        ];
    }
}
