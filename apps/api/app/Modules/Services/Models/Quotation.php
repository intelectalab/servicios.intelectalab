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
        'valid_until',
        'subtotal',
        'tax_total',
        'discount_total',
        'total',
        'notes',
        'source_type',
        'source_id',
        'created_by',
        'approved_at',
    ];

    protected $casts = [
        'valid_until' => 'date',
        'approved_at' => 'datetime',
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
}