<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Ciclo de una instancia recurrente: un periodo que, al procesarse, deja su evento facturable. */
class RecurringCycle extends Model
{
    /** Estado => texto visible. */
    public const STATES = [
        'PENDING' => 'Pendiente', 'PROCESSING' => 'Procesando', 'COMPLETED' => 'Completado', 'FAILED' => 'Fallido', 'SKIPPED' => 'Omitido',
    ];

    protected $table = 'recurring_service_cycles';

    protected $keyType = 'string';
    public $incrementing = false;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'recurring_instance_id', 'cycle_start', 'cycle_end', 'due_date', 'period', 'amount', 'status',
        'billable_event_id', 'processed_at', 'attempts', 'error', 'idempotency_key',
    ];

    protected $casts = [
        'cycle_start' => 'date', 'cycle_end' => 'date', 'due_date' => 'date', 'processed_at' => 'datetime',
        'amount' => 'decimal:4', 'attempts' => 'integer',
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
}
