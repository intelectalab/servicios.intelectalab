<?php

namespace App\Modules\Finance\Models;

use App\Models\Concerns\ScopedByAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Cobro de un cliente. Se aplica a una o varias cuentas por cobrar de su misma moneda; lo que
 * no se aplica queda como anticipo. Un reembolso queda vinculado al cobro y nunca lo borra.
 */
class Payment extends Model
{
    use ScopedByAccess;

    /** Recurso cuyo alcance (lo propio, equipo, sede, empresa) limita qué registros se ven. */
    public const ACCESS_RESOURCE = 'payments';

    /** Estado => texto visible. */
    public const STATES = [
        'PENDING' => 'Pendiente', 'CONFIRMED' => 'Sin aplicar', 'PARTIALLY_ALLOCATED' => 'Aplicado parcial', 'ALLOCATED' => 'Aplicado',
        'REFUNDED' => 'Reembolsado', 'FAILED' => 'Fallido', 'CANCELLED' => 'Cancelado',
    ];

    /** Estados en los que el cobro tiene dinero vivo: se puede aplicar o reembolsar. */
    public const LIVE = ['CONFIRMED', 'PARTIALLY_ALLOCATED', 'ALLOCATED'];

    public const METHODS = ['TRANSFERENCIA', 'DEPOSITO', 'EFECTIVO', 'CHEQUE', 'PASARELA'];

    protected $table = 'payments';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id', 'customer_id', 'payment_number', 'amount', 'currency', 'exchange_rate',
        'payment_method', 'reference', 'source', 'financial_account_id', 'status', 'provider_reference', 'paid_at', 'metadata',
        'refunded_amount', 'reconciled', 'reconciled_at', 'reconciled_by', 'created_by', 'branch_id',
    ];

    protected $casts = [
        'amount' => 'decimal:4', 'refunded_amount' => 'decimal:4', 'exchange_rate' => 'decimal:6',
        'paid_at' => 'datetime', 'reconciled' => 'boolean', 'reconciled_at' => 'datetime', 'metadata' => 'array',
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

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class, 'payment_id')->orderBy('created_at')->orderBy('id');
    }

    public function refunds()
    {
        return $this->hasMany(Refund::class, 'payment_id')->orderByDesc('created_at');
    }

    public function creator()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}
