<?php

namespace App\Modules\Finance\Models;

use App\Models\Concerns\ScopedByAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Cuenta por cobrar de un comprobante emitido. Su saldo es la suma de sus movimientos (lo lleva
 * la base). "Vencida" no es un estado: se calcula por la fecha de vencimiento y el saldo.
 */
class Receivable extends Model
{
    use ScopedByAccess;

    /** Recurso cuyo alcance (lo propio, equipo, sede, empresa) limita qué registros se ven. */
    public const ACCESS_RESOURCE = 'receivables';

    /** Estado => texto visible. */
    public const STATES = ['OPEN' => 'Pendiente', 'PARTIAL' => 'Parcial', 'PAID' => 'Pagada', 'CANCELLED' => 'Anulada'];

    /** Con saldo por cobrar. */
    public const OPEN = ['OPEN', 'PARTIAL'];

    /** Tramo de antigüedad => [texto visible, días de atraso desde, hasta]. */
    public const BUCKETS = [
        'CURRENT' => ['Por vencer', null, 0],
        'D30' => ['1–30 días', 1, 30],
        'D60' => ['31–60 días', 31, 60],
        'D90' => ['61–90 días', 61, 90],
        'OVER' => ['> 90 días', 91, null],
    ];

    /** Días de atraso de la fila (negativo o cero si aún no vence). */
    public const DAYS_OVERDUE = "((now() AT TIME ZONE 'America/Lima')::date - receivables.due_date)";

    protected $table = 'receivables';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id', 'customer_id', 'invoice_id', 'original_amount', 'balance_amount', 'currency', 'due_date', 'status',
        'owner_user_id', 'branch_id',
    ];

    protected $casts = [
        'original_amount' => 'decimal:4', 'balance_amount' => 'decimal:4', 'due_date' => 'date',
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

    public function movements()
    {
        return $this->hasMany(ReceivableMovement::class, 'receivable_id')->orderBy('created_at')->orderBy('id');
    }

    /** Tramo de antigüedad de una cuenta con saldo. */
    public static function bucketOf(?int $daysOverdue): string
    {
        return match (true) {
            $daysOverdue === null || $daysOverdue <= 0 => 'CURRENT',
            $daysOverdue <= 30 => 'D30',
            $daysOverdue <= 60 => 'D60',
            $daysOverdue <= 90 => 'D90',
            default => 'OVER',
        };
    }
}
