<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Receivable extends Model
{
    protected $table = 'receivables';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id', 'customer_id', 'invoice_id', 'original_amount', 'balance_amount', 'due_date', 'status',
    ];

    protected $casts = [
        'original_amount' => 'decimal:4', 'balance_amount' => 'decimal:4', 'due_date' => 'date',
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