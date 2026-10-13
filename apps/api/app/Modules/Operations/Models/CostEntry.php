<?php

namespace App\Modules\Operations\Models;

use App\Models\Concerns\ScopedByAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Costo directo de un proyecto (proveedor, licencias, materiales…). Solo los aprobados cuentan para el margen. */
class CostEntry extends Model
{
    use ScopedByAccess;

    /** Recurso cuyo alcance (lo propio, equipo, sede, empresa) limita qué registros se ven. */
    public const ACCESS_RESOURCE = 'cost_entries';

    /** Estado => texto visible. */
    public const STATES = ['SUBMITTED' => 'Enviado', 'APPROVED' => 'Aprobado', 'REJECTED' => 'Rechazado'];

    public const CATEGORIES = ['Proveedor', 'Licencias', 'Infraestructura', 'Materiales', 'Personal externo'];

    protected $table = 'cost_entries';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id', 'project_id', 'work_order_id', 'task_id', 'supplier_id', 'category', 'description', 'quantity', 'unit_cost', 'amount',
        'currency', 'incurred_at', 'approved', 'status', 'created_by', 'approved_by', 'approved_at', 'reject_reason', 'branch_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:4', 'unit_cost' => 'decimal:4', 'amount' => 'decimal:4',
        'incurred_at' => 'date', 'approved' => 'boolean', 'approved_at' => 'datetime',
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

    /** Por su modelo: un proyecto fuera del alcance de la persona no se entrega. */
    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function creator()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}
