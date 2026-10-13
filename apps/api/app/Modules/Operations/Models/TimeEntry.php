<?php

namespace App\Modules\Operations\Models;

use App\Models\Concerns\ScopedByAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Registro de horas de una persona en un proyecto o en una orden de trabajo. Solo las aprobadas cuentan para el costo. */
class TimeEntry extends Model
{
    use ScopedByAccess;

    /** Recurso cuyo alcance (lo propio, equipo, sede, empresa) limita qué registros se ven. */
    public const ACCESS_RESOURCE = 'time_entries';

    /** Estado => texto visible. */
    public const STATES = ['SUBMITTED' => 'Enviado', 'APPROVED' => 'Aprobado', 'REJECTED' => 'Rechazado'];

    /** Duración máxima de un registro, en horas. */
    public const MAX_HOURS = 12;

    protected $table = 'time_entries';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id', 'user_id', 'employee_id', 'project_id', 'work_order_id', 'task_id',
        'started_at', 'ended_at', 'hours', 'billable', 'hourly_cost', 'approved',
        'status', 'description', 'approved_by', 'approved_at', 'reject_reason', 'branch_id',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'hours' => 'decimal:4',
        'billable' => 'boolean',
        'hourly_cost' => 'decimal:4',
        'approved' => 'boolean',
        'approved_at' => 'datetime',
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

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    /** Por su modelo: un proyecto fuera del alcance de la persona no se entrega. */
    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /** Por su modelo: una orden de trabajo fuera del alcance de la persona no se entrega. */
    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }

    public function task()
    {
        return $this->belongsTo(Task::class, 'task_id');
    }
}
