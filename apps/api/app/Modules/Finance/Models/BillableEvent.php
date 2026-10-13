<?php

namespace App\Modules\Finance\Models;

use App\Models\Concerns\ScopedByAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Evento facturable: algo que ya se puede cobrar (una orden de trabajo completada, un ciclo
 * recurrente, horas aprobadas, un hito…) y que Finanzas decide cuándo facturar. Un evento no
 * se factura dos veces.
 */
class BillableEvent extends Model
{
    use ScopedByAccess;

    /** Recurso cuyo alcance (lo propio, equipo, sede, empresa) limita qué registros se ven. */
    public const ACCESS_RESOURCE = 'billing';

    /** Estado => texto visible. */
    public const STATES = ['PENDING' => 'Por facturar', 'INVOICED' => 'Facturado', 'CANCELLED' => 'Anulado'];

    /** Origen => texto visible. */
    public const SOURCES = [
        'WORK_ORDER' => 'OT completada', 'RECURRING_CYCLE' => 'Ciclo recurrente', 'CONTRACT_MILESTONE' => 'Hito de contrato',
        'TIME_ENTRY' => 'Horas aprobadas', 'MANUAL' => 'Manual',
    ];

    protected $table = 'billable_events';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id', 'source_type', 'source_id', 'customer_id', 'contract_id', 'description', 'base', 'amount', 'currency',
        'status', 'invoice_id', 'idempotency_key', 'cancel_reason', 'owner_user_id', 'created_by', 'branch_id',
    ];

    protected $casts = ['base' => 'decimal:4', 'amount' => 'decimal:4'];

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

    /** Por su modelo: un comprobante fuera del alcance de la persona no se entrega. */
    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    /** Por su modelo: un contrato fuera del alcance de la persona no se entrega. */
    public function contract()
    {
        return $this->belongsTo(\App\Modules\Services\Models\Contract::class, 'contract_id');
    }
}
