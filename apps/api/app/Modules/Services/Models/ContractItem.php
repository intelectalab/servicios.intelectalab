<?php

namespace App\Modules\Services\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ContractItem extends Model
{
    protected $table = 'contract_items';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'contract_id',
        'service_id',
        'description',
        'quantity',
        'unit_price',
        'line_total',
        'service_snapshot',
    ];

    protected $casts = [
        'service_snapshot' => 'array',
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'line_total' => 'decimal:4',
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