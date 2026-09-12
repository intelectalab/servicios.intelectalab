<?php

namespace App\Modules\Operations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTimeEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'work_order_id' => ['nullable', 'uuid'],
            'project_id' => ['nullable', 'uuid'],
            'task_id' => ['nullable', 'uuid'],
            'started_at' => ['required', 'date'],
            'ended_at' => ['required', 'date', 'after:started_at'],
            'billable' => ['boolean'],
            'hourly_cost' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}