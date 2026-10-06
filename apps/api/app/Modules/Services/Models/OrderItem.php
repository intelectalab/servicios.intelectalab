<?php

namespace App\Modules\Services\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class OrderItem extends Model
{
    protected $table = 'order_items';

    protected $keyType = 'string';
    public $incrementing = false;

    // La tabla solo tiene created_at.
    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'order_id',
        'service_id',
        'description',
        'quantity',
        'unit_price',
        'line_total',
        'service_snapshot',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'line_total' => 'decimal:4',
        'service_snapshot' => 'array',
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
