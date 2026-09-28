<?php

namespace App\Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->attributes->get('tenant_id') ?? $this->header('X-Tenant-ID');

        return [
            'employee_code' => [
                'nullable',
                'string',
                'max:100',
                // Validación de unicidad compuesta por tenant para que el código sea único por empresa
                Rule::unique('employees', 'employee_code')->where(function ($query) use ($tenantId) {
                    return $query->where('tenant_id', $tenantId);
                }),
            ],
            'position' => ['nullable', 'string', 'max:255'],
            'user_id' => ['nullable', 'uuid', 'exists:users,id'],

            // Datos de la persona natural asociada (Party / Person)
            'given_names' => ['required', 'string', 'max:150'],
            'family_name' => ['required', 'string', 'max:150'],
            'document_type' => ['required', 'string', 'max:20'],
            'document_number' => ['required', 'string', 'max:20'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}