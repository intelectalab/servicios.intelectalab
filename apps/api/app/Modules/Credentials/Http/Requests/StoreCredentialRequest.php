<?php

namespace App\Modules\Credentials\Http\Requests;

use App\Modules\Credentials\Models\CredentialRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'credential_type' => ['required', Rule::in(array_keys(CredentialRecord::TYPES))],
            'owner_label' => ['nullable', 'string', 'max:150'],
            'secret' => ['required', 'string', 'max:10000'],
            // Vence al terminar ese día; hoy ya no sirve.
            'expires_at' => ['nullable', 'date', 'after:today'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'nombre', 'credential_type' => 'tipo', 'owner_label' => 'pertenece a', 'secret' => 'valor secreto', 'expires_at' => 'fecha de vencimiento'];
    }
}
