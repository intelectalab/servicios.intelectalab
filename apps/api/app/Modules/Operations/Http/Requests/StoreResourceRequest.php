<?php

namespace App\Modules\Operations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'resource_type' => ['required', 'in:EMPLOYEE,FREELANCER,SUPPLIER'],
            'cost_rate' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}