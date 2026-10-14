<?php

namespace App\Modules\Purchasing\Models;

use App\Models\Concerns\ScopedByAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Comprobante que entrega un proveedor (factura o recibo por honorarios). Registrado, no se
 * modifica: se aprueba para pago (y nace su cuenta por pagar), se rechaza o se anula.
 */
class SupplierInvoice extends Model
{
    use ScopedByAccess;

    /** Recurso cuyo alcance (lo propio, equipo, sede, empresa) limita qué registros se ven. */
    public const ACCESS_RESOURCE = 'payables';

    /** Estado => texto visible. */
    public const STATES = ['REGISTERED' => 'Registrada', 'APPROVED' => 'Aprobada para pago', 'REJECTED' => 'Rechazada', 'VOIDED' => 'Anulada'];

    /** Tipo => texto visible. */
    public const TYPES = ['FACTURA' => 'Factura', 'RECIBO_HONORARIOS' => 'Recibo por honorarios'];

    protected $table = 'supplier_invoices';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id', 'supplier_id', 'supplier_name', 'supplier_tax_id', 'doc_type', 'doc_number', 'issue_date', 'due_date',
        'purchase_order_id', 'cost_center_id', 'expense_account', 'description', 'currency', 'exchange_rate', 'subtotal', 'tax', 'total',
        'detraction_code', 'detraction_pct', 'detraction_amount', 'retention_4ta', 'sunat_validation', 'status', 'reason',
        'approved_by', 'approved_at', 'owner_user_id', 'created_by', 'branch_id',
    ];

    protected $casts = [
        'issue_date' => 'date', 'due_date' => 'date', 'approved_at' => 'datetime', 'exchange_rate' => 'decimal:6',
        'subtotal' => 'decimal:4', 'tax' => 'decimal:4', 'total' => 'decimal:4',
        'detraction_pct' => 'decimal:2', 'detraction_amount' => 'decimal:4', 'retention_4ta' => 'decimal:4',
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

    public function creator()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(\App\Models\User::class, 'approved_by');
    }
}
