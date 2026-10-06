<?php

namespace App\Modules\Services\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Order extends Model
{
    protected $table = 'orders';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'contract_id',
        'quotation_id',
        'order_number',
        'status',
        'order_date',
        'currency',
        'total',
    ];

    protected $casts = [
        'order_date' => 'date',
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
        return $this->hasMany(OrderItem::class, 'order_id');
    }
}
