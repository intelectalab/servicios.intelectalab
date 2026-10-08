<?php

namespace App\Modules\Services\Models;

use App\Models\Concerns\ScopedByAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Contract extends Model
{
    use ScopedByAccess;

    /** Recurso cuyo alcance (lo propio, equipo, sede, empresa) limita qué registros se ven. */
    public const ACCESS_RESOURCE = 'contracts';

    protected $table = 'contracts';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'quotation_id',
        'contract_number',
        'status',
        'currency',
        'start_date',
        'end_date',
        'total_value',
        'terms',
        'sla_level',
        'created_by',
        'branch_id',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'total_value' => 'decimal:4',
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

    public function quotation()
    {
        return $this->belongsTo(Quotation::class, 'quotation_id');
    }

    public function items()
    {
        return $this->hasMany(ContractItem::class, 'contract_id')->orderBy('created_at');
    }

    public function customer()
    {
        return $this->belongsTo(\App\Modules\Core\Models\Customer::class, 'customer_id');
    }

    public function creator()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'contract_id')->orderBy('created_at');
    }
}