<?php

namespace App\Modules\Services\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Quotation extends Model
{
    protected $table = 'quotations';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'quotation_number',
        'status',
        'version',
        'currency',
        'exchange_rate',
        'valid_until',
        'subtotal',
        'tax_total',
        'discount_total',
        'total',
        'notes',
        'source_type',
        'source_id',
        'previous_id',
        'created_by',
        'approved_at',
    ];

    protected $casts = [
        'valid_until' => 'date',
        'approved_at' => 'datetime',
        'exchange_rate' => 'decimal:4',
        'subtotal' => 'decimal:4',
        'tax_total' => 'decimal:4',
        'discount_total' => 'decimal:4',
        'total' => 'decimal:4',
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

    public function items()
    {
        return $this->hasMany(QuotationItem::class, 'quotation_id');
    }

    public function customer()
    {
        return $this->belongsTo(\App\Modules\Core\Models\Customer::class, 'customer_id');
    }

    public function creator()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function approvals()
    {
        return $this->hasMany(QuotationApproval::class, 'quotation_id')->orderByDesc('decided_at');
    }

    // Versión de la que nace esta cotización, y la que la reemplazó.
    public function previous()
    {
        return $this->belongsTo(self::class, 'previous_id');
    }

    public function supersededBy()
    {
        return $this->hasOne(self::class, 'previous_id');
    }

    public function contract()
    {
        return $this->hasOne(Contract::class, 'quotation_id');
    }

    // Orden directa generada desde esta cotización, si existe.
    public function order()
    {
        return $this->hasOne(Order::class, 'quotation_id');
    }
}