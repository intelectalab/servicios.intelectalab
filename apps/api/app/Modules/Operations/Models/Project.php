<?php

namespace App\Modules\Operations\Models;

use App\Models\Concerns\ScopedByAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Proyecto: trabajo de varias tareas con plan, horas, costos y entregables. Nace de un
 * servicio contratado activo.
 */
class Project extends Model
{
    use ScopedByAccess;

    /** Recurso cuyo alcance (lo propio, equipo, sede, empresa) limita qué registros se ven. */
    public const ACCESS_RESOURCE = 'projects';

    /** Estado => texto visible. */
    public const STATES = [
        'DRAFT' => 'Borrador', 'PLANNED' => 'Planificado', 'IN_PROGRESS' => 'En ejecución',
        'ON_HOLD' => 'En pausa', 'COMPLETED' => 'Completado', 'CANCELLED' => 'Cancelado',
    ];

    /** Estados en los que el proyecto sigue vivo: se edita y cambian sus tareas y entregables. */
    public const OPEN = ['DRAFT', 'PLANNED', 'IN_PROGRESS', 'ON_HOLD'];

    protected $table = 'projects';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id', 'customer_id', 'contract_id', 'contracted_service_id', 'code', 'name', 'status', 'start_date', 'end_date',
        'budget_revenue', 'budget_cost', 'budget_hours', 'hold_reason', 'cancel_reason', 'manager_user_id', 'created_by', 'branch_id',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'budget_revenue' => 'decimal:4',
        'budget_cost' => 'decimal:4',
        'budget_hours' => 'decimal:2',
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

    /** Por su modelo: un contrato fuera del alcance de la persona no se entrega. */
    public function contract()
    {
        return $this->belongsTo(\App\Modules\Services\Models\Contract::class, 'contract_id');
    }

    public function manager()
    {
        return $this->belongsTo(\App\Models\User::class, 'manager_user_id');
    }

    public function tasks()
    {
        return $this->hasMany(Task::class, 'project_id');
    }

    public function deliverables()
    {
        return $this->hasMany(Deliverable::class, 'project_id');
    }
}
