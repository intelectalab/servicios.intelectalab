<?php

namespace App\Modules\Portal\Support;

use Illuminate\Support\Facades\DB;

/** El cliente del portal con la misma forma que usa la web (`customer.party…`), sin datos internos. */
class PortalCustomer
{
    public static function of(string $customerId): array
    {
        $row = DB::table('customers')
            ->join('parties', 'parties.id', '=', 'customers.party_id')
            ->leftJoin('organizations', 'organizations.party_id', '=', 'parties.id')
            ->leftJoin('persons', 'persons.party_id', '=', 'parties.id')
            ->where('customers.id', $customerId)
            ->first([
                'customers.id', 'parties.party_type', 'organizations.legal_name', 'organizations.trade_name', 'organizations.tax_identifier',
                'persons.given_names', 'persons.family_name', 'persons.document_type', 'persons.document_number',
            ]);

        return [
            'id' => $customerId,
            'party' => [
                'party_type' => $row?->party_type,
                'organization' => $row?->party_type === 'ORGANIZATION'
                    ? ['legal_name' => $row->legal_name, 'trade_name' => $row->trade_name, 'tax_identifier' => $row->tax_identifier]
                    : null,
                'person' => $row && $row->party_type !== 'ORGANIZATION'
                    ? ['given_names' => $row->given_names, 'family_name' => $row->family_name, 'document_type' => $row->document_type, 'document_number' => $row->document_number]
                    : null,
            ],
        ];
    }

    public static function name(string $customerId): string
    {
        $party = self::of($customerId)['party'];

        return $party['organization']
            ? ($party['organization']['trade_name'] ?: $party['organization']['legal_name'] ?: 'Cliente')
            : (trim(($party['person']['given_names'] ?? '').' '.($party['person']['family_name'] ?? '')) ?: 'Cliente');
    }

    /** Nombre de la empresa (razón social si la tiene) para el portal y los correos. */
    public static function companyName(string $tenantId): string
    {
        $company = DB::table('companies')->where('tenant_id', $tenantId)->orderBy('created_at')->first(['legal_name', 'trade_name']);
        $tenant = DB::table('tenants')->where('id', $tenantId)->first(['name', 'legal_name']);

        return $company?->trade_name ?: $tenant?->name ?: $company?->legal_name ?: $tenant?->legal_name ?: 'La empresa';
    }
}
