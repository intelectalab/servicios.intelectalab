<?php

namespace App\Modules\Support\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'uuid'],
            'contract_id' => ['nullable', 'uuid'],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'priority' => ['nullable', 'in:LOW,NORMAL,HIGH,URGENT'],
            'sla_id' => ['nullable', 'uuid'],
        ];
    }
}