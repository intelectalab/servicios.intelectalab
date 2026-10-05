<?php

namespace App\Modules\Services\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ServiceRecurringConfig extends Model
{
    protected $table = 'service_recurring_configs';

    protected $keyType = 'string';
    public $incrementing = false;

    // La tabla solo tiene created_at.
    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'service_id',
        'frequency',
        'interval_value',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'interval_value' => 'integer',
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
