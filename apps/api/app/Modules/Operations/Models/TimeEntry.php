<?php

namespace App\Modules\Operations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TimeEntry extends Model
{
    protected $table = 'time_entries';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'user_id', 'employee_id', 'project_id', 'work_order_id', 'task_id',
        'started_at', 'ended_at', 'hours', 'billable', 'hourly_cost', 'approved',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'hours' => 'decimal:4',
        'billable' => 'boolean',
        'hourly_cost' => 'decimal:4',
        'approved' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }
}