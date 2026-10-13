<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Parte de un cobro aplicada a una cuenta por cobrar. */
class PaymentAllocation extends Model
{
    protected $table = 'payment_allocations';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['tenant_id', 'payment_id', 'receivable_id', 'amount', 'fx_difference_pen'];

    protected $casts = ['amount' => 'decimal:4', 'fx_difference_pen' => 'decimal:4', 'created_at' => 'datetime'];

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
