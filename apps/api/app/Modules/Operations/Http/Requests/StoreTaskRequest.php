<?php

namespace App\Modules\Operations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'work_order_id' => ['required_without:project_id', 'nullable', 'uuid'],
            'project_id' => ['required_without:work_order_id', 'nullable', 'uuid'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'priority' => ['nullable', 'in:LOW,NORMAL,HIGH,URGENT'],
            'assigned_user_id' => ['nullable', 'uuid'],
            'due_at' => ['nullable', 'date'],
            'checklist_id' => ['nullable', 'uuid'],
        ];
    }
}