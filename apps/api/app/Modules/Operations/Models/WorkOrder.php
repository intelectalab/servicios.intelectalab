<?php

namespace App\Modules\Operations\Models;

use App\Models\Concerns\ScopedByAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class WorkOrder extends Model
{
    use ScopedByAccess;

    /** Recurso cuyo alcance (lo propio, equipo, sede, empresa) limita qué registros se ven. */
    public const ACCESS_RESOURCE = 'work_orders';

    /** Estado => texto visible. */
    public const STATES = [
        'PLANNED' => 'Planificada', 'ASSIGNED' => 'Asignada', 'IN_PROGRESS' => 'En ejecución',
        'BLOCKED' => 'Bloqueada', 'COMPLETED' => 'Completada', 'CANCELLED' => 'Cancelada',
    ];

    /** Estados en los que la orden sigue viva: se edita, se reasigna y se marca su checklist. */
    public const OPEN = ['PLANNED', 'ASSIGNED', 'IN_PROGRESS', 'BLOCKED'];

    /** Estados en los que la orden ocupa la agenda de su técnico. */
    public const BUSY = ['ASSIGNED', 'IN_PROGRESS'];

    /** Prioridad => texto visible. */
    public const PRIORITIES = ['HIGH' => 'Alta', 'NORMAL' => 'Media', 'LOW' => 'Baja'];

    /** Estado del checklist => texto visible. */
    public const CHECKLIST_STATES = ['PENDING' => 'Pendiente', 'IN_PROGRESS' => 'En curso', 'COMPLETED' => 'Completado', 'APPROVED' => 'Aprobado'];

    protected $table = 'work_orders';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'contracted_service_id',
        'contract_id',
        'service_id',
        'order_id',
        'code',
        'title',
        'status',
        'priority',
        'scheduled_start',
        'scheduled_end',
        'started_at',
        'completed_at',
        'location',
        'description',
        'assigned_user_id',
        'blocked_reason',
        'cancel_reason',
        'created_by',
        'branch_id',
        'checklist_id',
        'checklist_name',
        'checklist_status',
        'checklist_reviewed_by',
        'checklist_reviewed_at',
        'checklist_review_note',
    ];

    protected $casts = [
        'scheduled_start' => 'datetime',
        'scheduled_end' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'checklist_reviewed_at' => 'datetime',
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

    public function tasks()
    {
        return $this->hasMany(Task::class, 'work_order_id');
    }

    public function checklistItems()
    {
        return $this->hasMany(WorkOrderChecklistItem::class, 'work_order_id')->orderBy('position');
    }

    public function customer()
    {
        return $this->belongsTo(\App\Modules\Core\Models\Customer::class, 'customer_id');
    }

    /** Por su modelo: un servicio contratado fuera del alcance de la persona no se entrega. */
    public function contractedService()
    {
        return $this->belongsTo(ContractedService::class, 'contracted_service_id');
    }

    /** Por su modelo: un contrato fuera del alcance de la persona no se entrega. */
    public function contract()
    {
        return $this->belongsTo(\App\Modules\Services\Models\Contract::class, 'contract_id');
    }

    public function service()
    {
        return $this->belongsTo(\App\Modules\Services\Models\Service::class, 'service_id');
    }

    public function assignee()
    {
        return $this->belongsTo(\App\Models\User::class, 'assigned_user_id');
    }

    public function creator()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}
