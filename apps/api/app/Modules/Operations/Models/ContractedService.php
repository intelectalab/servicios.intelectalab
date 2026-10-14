<?php

namespace App\Modules\Operations\Models;

use App\Models\Concerns\ScopedByAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Servicio contratado: la instancia operativa de una línea de contrato. Nace al activar el
 * contrato y de él salen las órdenes de trabajo.
 */
class ContractedService extends Model
{
    use ScopedByAccess;

    /** Recurso cuyo alcance (lo propio, equipo, sede, empresa) limita qué registros se ven. */
    public const ACCESS_RESOURCE = 'contracted_services';

    /** Estado => texto visible. */
    public const STATES = [
        'PENDING' => 'Pendiente', 'ACTIVE' => 'Activo', 'SUSPENDED' => 'Suspendido', 'COMPLETED' => 'Completado', 'CANCELLED' => 'Cancelado',
    ];

    protected $table = 'contracted_services';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id', 'customer_id', 'contract_id', 'contract_item_id', 'service_id', 'status', 'status_reason',
        'start_date', 'end_date', 'quantity', 'price', 'recurring', 'owner_user_id', 'branch_id', 'suspended_by_contract',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'quantity' => 'decimal:4',
        'price' => 'decimal:4',
        'recurring' => 'boolean',
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

    public function customer()
    {
        return $this->belongsTo(\App\Modules\Core\Models\Customer::class, 'customer_id');
    }

    /** Por su modelo: un contrato fuera del alcance de la persona no se entrega. */
    public function contract()
    {
        return $this->belongsTo(\App\Modules\Services\Models\Contract::class, 'contract_id');
    }

    public function service()
    {
        return $this->belongsTo(\App\Modules\Services\Models\Service::class, 'service_id');
    }

    public function owner()
    {
        return $this->belongsTo(\App\Models\User::class, 'owner_user_id');
    }

    public function workOrders()
    {
        return $this->hasMany(WorkOrder::class, 'contracted_service_id');
    }
}
