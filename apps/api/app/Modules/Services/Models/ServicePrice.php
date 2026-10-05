<?php

namespace App\Modules\Services\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ServicePrice extends Model
{
    protected $table = 'service_prices';

    protected $keyType = 'string';
    public $incrementing = false;

    // La tabla solo tiene created_at.
    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'service_id',
        'currency',
        'amount',
        'valid_from',
        'valid_to',
        'active',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'valid_from' => 'date:Y-m-d',
        'valid_to' => 'date:Y-m-d',
        'active' => 'boolean',
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
