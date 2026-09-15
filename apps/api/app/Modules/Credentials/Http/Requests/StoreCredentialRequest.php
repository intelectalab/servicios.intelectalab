<?php

namespace App\Modules\Credentials\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'credential_type' => ['required', 'in:password,api_key,ssh_key,certificate,token'],
            'secret' => ['required', 'string'],
            'owner_entity_type' => ['nullable', 'string', 'max:150'],
            'owner_entity_id' => ['nullable', 'uuid'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}