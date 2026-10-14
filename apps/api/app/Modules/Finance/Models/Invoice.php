<?php

namespace App\Modules\Finance\Models;

use App\Models\Concerns\ScopedByAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Comprobante (factura, boleta o nota de crédito). Nace en borrador desde eventos facturables;
 * al emitirse recibe serie y correlativo, crea su cuenta por cobrar y ya no se modifica: se
 * corrige con una nota de crédito. Su estado ante SUNAT va aparte (`fiscal_status`).
 */
class Invoice extends Model
{
    use ScopedByAccess;

    /** Recurso cuyo alcance (lo propio, equipo, sede, empresa) limita qué registros se ven. */
    public const ACCESS_RESOURCE = 'billing';

    /** Tipo => texto visible. */
    public const TYPES = ['INVOICE' => 'Factura', 'RECEIPT' => 'Boleta', 'CREDIT_NOTE' => 'Nota de crédito', 'OTHER' => 'Otro'];

    /** Estado => texto visible. */
    public const STATES = [
        'DRAFT' => 'Borrador', 'ISSUED' => 'Emitido', 'CORRECTED' => 'Corregido', 'VOID' => 'Anulado', 'CANCELLED' => 'Descartado',
        // Estados de los comprobantes anteriores a esta pantalla.
        'PAID' => 'Pagado', 'PARTIALLY_PAID' => 'Pago parcial', 'OVERDUE' => 'Vencido',
    ];

    /** Estado ante SUNAT => texto visible. */
    public const FISCAL = [
        'NOT_SENT' => 'Sin enviar', 'CONTINGENCY' => 'Contingencia', 'PENDING' => 'En envío', 'ACCEPTED' => 'Aceptado', 'REJECTED' => 'Rechazado',
    ];

    protected $table = 'invoices';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id', 'customer_id', 'contract_id', 'invoice_number', 'invoice_type', 'series', 'number',
        'status', 'currency', 'exchange_rate', 'issue_date', 'issued_at', 'due_date', 'subtotal', 'tax_total',
        'total', 'balance_due', 'fiscal_document_id', 'fiscal_status', 'fiscal_response',
        'customer_tax_id', 'customer_name', 'related_invoice_id', 'reason', 'owner_user_id', 'created_by', 'branch_id',
        'withholding_mode', 'withholding_type', 'withholding_code', 'withholding_rate', 'withholding_amount', 'withholding_status',
        'withholding_number', 'withholding_date', 'withholding_registered_by',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'issued_at' => 'datetime',
        'due_date' => 'date',
        'subtotal' => 'decimal:4',
        'tax_total' => 'decimal:4',
        'total' => 'decimal:4',
        'balance_due' => 'decimal:4',
        'exchange_rate' => 'decimal:6',
        'withholding_rate' => 'decimal:3',
        'withholding_amount' => 'decimal:4',
        'withholding_date' => 'date',
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
        return $this->hasMany(InvoiceItem::class, 'invoice_id')->orderBy('position')->orderBy('created_at');
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

    /** Comprobante al que corrige (si es una nota de crédito). */
    public function related()
    {
        return $this->belongsTo(self::class, 'related_invoice_id');
    }

    /** Nota de crédito que lo corrigió. */
    public function creditNote()
    {
        return $this->hasOne(self::class, 'related_invoice_id')->where('invoice_type', 'CREDIT_NOTE');
    }
}
