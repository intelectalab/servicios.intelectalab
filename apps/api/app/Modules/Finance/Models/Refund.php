<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Reembolso de un cobro. Queda vinculado al cobro original; ni se edita ni se borra. */
class Refund extends Model
{
    protected $table = 'refunds';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['tenant_id', 'payment_id', 'amount', 'reason', 'status', 'provider_reference', 'created_by'];

    protected $casts = ['amount' => 'decimal:4', 'created_at' => 'datetime'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function creator()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}
