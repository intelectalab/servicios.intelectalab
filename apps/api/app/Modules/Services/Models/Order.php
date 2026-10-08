<?php

namespace App\Modules\Services\Models;

use App\Models\Concerns\ScopedByAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Order extends Model
{
    use ScopedByAccess;

    /** Recurso cuyo alcance (lo propio, equipo, sede, empresa) limita qué registros se ven. */
    public const ACCESS_RESOURCE = 'orders';

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
        'notes',
        'created_by',
        'branch_id',
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
        return $this->hasMany(OrderItem::class, 'order_id')->orderBy('created_at');
    }

    public function customer()
    {
        return $this->belongsTo(\App\Modules\Core\Models\Customer::class, 'customer_id');
    }

    public function quotation()
    {
        return $this->belongsTo(Quotation::class, 'quotation_id');
    }

    public function contract()
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function creator()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}
