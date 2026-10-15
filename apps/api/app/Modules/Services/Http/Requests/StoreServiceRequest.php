<?php

namespace App\Modules\Services\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function messages(): array
    {
        return [
            'category_id.required' => 'Código, nombre y categoría son obligatorios.',
            'frequency.required_if' => 'Un servicio recurrente necesita frecuencia.',
        ];
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'unit' => ['nullable', 'string', 'max:100'],
            'category_id' => ['required', 'uuid'],
            'billing_mode' => ['nullable', 'in:ONE_TIME,RECURRING,PER_EVENT'],
            'frequency' => ['nullable', 'required_if:billing_mode,RECURRING', 'in:MONTHLY,QUARTERLY,YEARLY'],
            'warranty_months' => ['nullable', 'integer', 'min:0', 'max:120'],
            // Lo que cubre la garantía: se muestra en la cobertura de cada reclamo.
            'warranty_conditions' => ['nullable', 'string', 'max:1000'],
            // Precio inicial (sin IGV): crea la primera versión del precio.
            'price' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
