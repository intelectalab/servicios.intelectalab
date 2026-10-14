<?php

namespace App\Modules\Purchasing\Models;

use App\Models\Concerns\ScopedByAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Orden de compra: se pide, se aprueba (otra persona), se recibe por porcentaje y se cierra.
 * Solo se edita en borrador. Lo facturado sale de los comprobantes de proveedor que la citan.
 */
class PurchaseOrder extends Model
{
    use ScopedByAccess;

    /** Recurso cuyo alcance (lo propio, equipo, sede, empresa) limita qué registros se ven. */
    public const ACCESS_RESOURCE = 'purchases';

    /** Estado => texto visible. */
    public const STATES = [
        'DRAFT' => 'Borrador', 'SUBMITTED' => 'Por aprobar', 'APPROVED' => 'Aprobada', 'PARTIALLY_RECEIVED' => 'Recepción parcial',
        'RECEIVED' => 'Recibida', 'CLOSED' => 'Cerrada', 'REJECTED' => 'Rechazada', 'CANCELLED' => 'Anulada',
    ];

    /** Se puede registrar su recepción. */
    public const RECEIVABLE = ['APPROVED', 'PARTIALLY_RECEIVED'];

    /** Aprobada y aún abierta: compromete presupuesto y admite comprobantes del proveedor. */
    public const COMMITTED = ['APPROVED', 'PARTIALLY_RECEIVED', 'RECEIVED'];

    /** Porcentaje recibido de la fila (expresión SQL sobre `purchase_orders`). */
    public const RECEIVED_PCT = '(SELECT coalesce(sum(r.received_pct), 0) FROM purchase_receipts r WHERE r.purchase_order_id = purchase_orders.id)';

    /** Total de sus comprobantes de proveedor no anulados. Es un dato de la orden, se vean o no esos comprobantes. */
    public const INVOICED = "(SELECT coalesce(sum(si.total), 0) FROM supplier_invoices si WHERE si.purchase_order_id = purchase_orders.id AND si.status <> 'VOIDED')";

    protected $table = 'purchase_orders';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id', 'order_number', 'supplier_id', 'cost_center_id', 'currency', 'needed_by', 'notes', 'subtotal', 'tax', 'total',
        'status', 'reason', 'budget_warning', 'approved_by', 'approved_at', 'owner_user_id', 'created_by', 'branch_id',
    ];

    protected $casts = [
        'subtotal' => 'decimal:4', 'tax' => 'decimal:4', 'total' => 'decimal:4', 'needed_by' => 'date', 'approved_at' => 'datetime',
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

    public function supplier()
    {
        return $this->belongsTo(\App\Modules\Core\Models\Supplier::class, 'supplier_id');
    }

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class, 'purchase_order_id')->orderBy('position')->orderBy('id');
    }

    public function creator()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(\App\Models\User::class, 'approved_by');
    }
}
