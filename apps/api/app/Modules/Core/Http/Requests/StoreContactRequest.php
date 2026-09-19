<?php

namespace App\Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'party_id' => ['required', 'uuid'],
            'contact_name' => ['nullable', 'string', 'max:150'],
            'contact_type' => ['required', 'in:EMAIL,PHONE,WHATSAPP,ADDRESS,OTHER'],
            'value' => ['required', 'string', 'max:255'],
            'country_code' => ['nullable', 'string', 'max:5'],
            'is_primary' => ['boolean'],
        ];
    }
}