<?php

namespace App\Modules\Subscription\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Solo los planes que ofrece la página: activos y públicos (la base también lo exige).
            'plan_id' => ['required', 'uuid', Rule::exists('saas_plans', 'id')->where('active', true)->where('is_public', true)],
            'billing_period' => ['required', 'in:MONTHLY,ANNUAL'],
            'ruc' => ['required', 'string', 'max:50'],
            'razon_social' => ['required', 'string', 'max:255'],
            'nombre_comercial' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:100'],
            'direccion' => ['nullable', 'string'],
            'departamento' => ['nullable', 'string', 'max:100'],
            'distrito' => ['nullable', 'string', 'max:100'],
            'nombres' => ['required', 'string', 'max:255'],
            'apellidos' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'celular' => ['nullable', 'string', 'max:100'],
            'password' => ['required', 'string', 'min:8'],
        ];
    }

    public function messages(): array
    {
        return ['plan_id.exists' => 'Ese plan no está disponible. Elige uno de los planes de la página.'];
    }
}