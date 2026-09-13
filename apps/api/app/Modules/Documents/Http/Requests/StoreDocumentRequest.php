<?php

namespace App\Modules\Documents\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'document_type' => ['required', 'string', 'max:100'],
            'classification' => ['nullable', 'in:PRIVATE,INTERNAL,PUBLIC'],
            'file' => ['required', 'file', 'max:20480'], // 20 MB máximo
        ];
    }
}