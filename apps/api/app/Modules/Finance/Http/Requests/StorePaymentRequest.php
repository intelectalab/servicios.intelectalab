<?php

namespace App\Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'uuid'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['required', 'string', 'max:50'],
            'currency' => ['nullable', 'string', 'size:3'],
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.receivable_id' => ['required', 'uuid'],
            'allocations.*.amount' => ['required', 'numeric', 'gt:0'],
        ];
    }
}