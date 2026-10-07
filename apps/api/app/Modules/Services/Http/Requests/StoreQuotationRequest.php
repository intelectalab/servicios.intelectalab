<?php

namespace App\Modules\Services\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'uuid'],
            'currency' => ['nullable', 'in:PEN,USD'],
            'valid_until' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            // Solicitud del portal que se está cotizando (solo al crear).
            'portal_request_id' => ['nullable', 'uuid'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.service_id' => ['nullable', 'uuid'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}