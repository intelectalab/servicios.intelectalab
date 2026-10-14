<?php

namespace App\Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Línea de una orden de compra. Solo cambia mientras la orden está en borrador (lo garantiza la base). */
class PurchaseOrderItem extends Model
{
    protected $table = 'purchase_order_items';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['tenant_id', 'purchase_order_id', 'position', 'description', 'quantity', 'unit_cost', 'account_code'];

    protected $casts = ['quantity' => 'decimal:3', 'unit_cost' => 'decimal:4'];

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
