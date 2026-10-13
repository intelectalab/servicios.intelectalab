<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Movimiento de una cuenta por cobrar. Solo se agregan: al insertarse, la base actualiza el saldo. */
class ReceivableMovement extends Model
{
    /** Tipo => texto visible. */
    public const TYPES = [
        'CHARGE' => 'Cargo', 'PAYMENT' => 'Abono', 'REVERSAL' => 'Reversión', 'CREDIT_NOTE' => 'Nota de crédito',
        'WITHHOLDING' => 'Detracción / retención', 'VOID' => 'Baja',
    ];

    protected $table = 'receivable_movements';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['tenant_id', 'receivable_id', 'movement_type', 'amount', 'reference', 'payment_id', 'created_by'];

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
}
