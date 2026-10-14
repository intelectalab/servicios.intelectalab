<?php

namespace App\Modules\Purchasing\Models;

use App\Models\Concerns\ScopedByAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Pago a un proveedor: el neto que se le transfiere y, si corresponde, el depósito de la
 * detracción en su cuenta del Banco de la Nación. Registrado, no se edita ni se borra.
 */
class SupplierPayment extends Model
{
    use ScopedByAccess;

    /** Recurso cuyo alcance (lo propio, equipo, sede, empresa) limita qué registros se ven. */
    public const ACCESS_RESOURCE = 'payables';

    /** Medio => texto visible. */
    public const METHODS = ['TRANSFERENCIA' => 'Transferencia', 'CHEQUE' => 'Cheque', 'EFECTIVO' => 'Efectivo'];

    public const UPDATED_AT = null;

    protected $table = 'supplier_payments';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id', 'payment_number', 'supplier_id', 'financial_account_id', 'amount', 'currency', 'detraction_amount',
        'detraction_number', 'detraction_account_id', 'payment_method', 'reference', 'paid_at', 'status',
        'owner_user_id', 'created_by', 'branch_id',
    ];

    protected $casts = ['amount' => 'decimal:4', 'detraction_amount' => 'decimal:4', 'paid_at' => 'date'];

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
