<?php

namespace App\Modules\Purchasing\Models;

use App\Models\Concerns\ScopedByAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Cuenta por pagar de un comprobante de proveedor aprobado. Su saldo es la suma de sus
 * movimientos (lo lleva la base). "Vencida" no es un estado: se calcula por la fecha.
 */
class Payable extends Model
{
    use ScopedByAccess;

    /** Recurso cuyo alcance (lo propio, equipo, sede, empresa) limita qué registros se ven. */
    public const ACCESS_RESOURCE = 'payables';

    /** Estado => texto visible. */
    public const STATES = ['OPEN' => 'Pendiente', 'PARTIAL' => 'Pago parcial', 'PAID' => 'Pagada', 'CANCELLED' => 'Anulada'];

    /** Con saldo por pagar. */
    public const OPEN = ['OPEN', 'PARTIAL'];

    /** Días que faltan para el vencimiento de la fila (negativo si ya venció). */
    public const DAYS_TO_DUE = '(payables.due_date - CURRENT_DATE)';

    /** Movimiento => texto visible. */
    public const MOVEMENTS = ['CHARGE' => 'Cargo', 'PAYMENT' => 'Pago', 'DETRACTION' => 'Depósito de detracción', 'RETENTION_4TA' => 'Retención 4.ª categoría'];

    protected $table = 'payables';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id', 'supplier_invoice_id', 'supplier_id', 'currency', 'due_date', 'original_amount',
        'detraction_amount', 'detraction_deposited', 'status', 'owner_user_id', 'branch_id',
    ];

    protected $casts = [
        'original_amount' => 'decimal:4', 'balance_amount' => 'decimal:4', 'detraction_amount' => 'decimal:4',
        'detraction_deposited' => 'boolean', 'due_date' => 'date',
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

    public function invoice()
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id');
    }
}
