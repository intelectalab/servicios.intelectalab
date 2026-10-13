<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class InvoiceItem extends Model
{
    protected $table = 'invoice_items';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'invoice_id', 'service_id', 'position', 'description', 'quantity', 'unit_price',
        'tax_rate', 'tax_amount', 'line_total', 'billable_event_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:4', 'unit_price' => 'decimal:4',
        'tax_rate' => 'decimal:4', 'tax_amount' => 'decimal:4', 'line_total' => 'decimal:4',
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
