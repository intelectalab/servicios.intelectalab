<?php

namespace App\Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Modules\Core\Rules\UniquePartyDocument;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->attributes->get('tenant_id');
        $partyType = $this->input('party_type');
        
        return [
            'party_type' => ['required', 'in:PERSON,ORGANIZATION'],

            'given_names' => ['required_if:party_type,PERSON', 'string', 'max:150'],
            'family_name' => ['required_if:party_type,PERSON', 'string', 'max:150'],
            'document_type' => ['required_if:party_type,PERSON', 'string', 'max:20'],
            // 'document_number' => ['required_if:party_type,PERSON', 'string', 'max:20'],
            'document_number' => [
                'required', 
                'string', 
                'max:20',
                new UniquePartyDocument($tenantId, $partyType)
            ],

            'legal_name' => ['required_if:party_type,ORGANIZATION', 'string', 'max:200'],
            'trade_name' => ['nullable', 'string', 'max:200'],

            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}