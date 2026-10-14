<?php

namespace App\Modules\Finance\Models;

use App\Models\Concerns\ScopedByAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Instancia recurrente: un servicio contratado que se cobra por ciclos (mensual, trimestral,
 * anual). Guarda el precio pactado, no el del catálogo: cambiar el catálogo no altera sus ciclos.
 * Cada ciclo procesado deja un evento facturable.
 */
class RecurringInstance extends Model
{
    use ScopedByAccess;

    /** Recurso cuyo alcance (lo propio, equipo, sede, empresa) limita qué registros se ven. */
    public const ACCESS_RESOURCE = 'recurring';

    /** Estado => texto visible. */
    public const STATES = ['ACTIVE' => 'Activa', 'SUSPENDED' => 'Suspendida', 'EXPIRED' => 'Vencida', 'CANCELLED' => 'Cancelada'];

    /** Frecuencia => texto visible. */
    public const FREQUENCIES = [
        'DAILY' => 'Diaria', 'WEEKLY' => 'Semanal', 'MONTHLY' => 'Mensual', 'QUARTERLY' => 'Trimestral', 'YEARLY' => 'Anual', 'CUSTOM' => 'Personalizada',
    ];

    /** Días antes del fin de vigencia en que se avisa que toca renovar. */
    public const RENEWAL_ALERT_DAYS = 45;

    protected $table = 'recurring_service_instances';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id', 'contracted_service_id', 'customer_id', 'status', 'status_reason', 'frequency', 'interval_value', 'next_cycle_date',
        'suspended_at', 'unit_price', 'quantity', 'currency', 'start_date', 'end_date', 'owner_user_id', 'branch_id',
    ];

    protected $casts = [
        'next_cycle_date' => 'date', 'start_date' => 'date', 'end_date' => 'date', 'suspended_at' => 'datetime',
        'unit_price' => 'decimal:4', 'quantity' => 'decimal:4', 'interval_value' => 'integer',
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

    public function cycles()
    {
        return $this->hasMany(RecurringCycle::class, 'recurring_instance_id')->orderByDesc('cycle_start')->orderByDesc('created_at');
    }
}
