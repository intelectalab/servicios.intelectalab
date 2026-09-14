<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Payment extends Model
{
    protected $table = 'payments';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'customer_id', 'payment_number', 'amount', 'currency',
        'payment_method', 'financial_account_id', 'status', 'provider_reference', 'paid_at', 'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:4', 'paid_at' => 'datetime', 'metadata' => 'array',
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