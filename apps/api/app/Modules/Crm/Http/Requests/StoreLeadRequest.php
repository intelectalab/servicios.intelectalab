<?php

namespace App\Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'person_name' => ['nullable', 'string', 'max:255'],
            'organization_name' => ['nullable', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'interest' => ['nullable', 'string', 'max:255'],
            'source_id' => ['required', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.email' => 'Correo inválido.',
            'source_id.required' => 'Indica la fuente.',
        ];
    }
}
