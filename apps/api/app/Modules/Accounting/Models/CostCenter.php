<?php

namespace App\Modules\Accounting\Models;

use App\Models\Concerns\ScopedByAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Centro de costo (de gastos o de ingresos). Su presupuesto y su ejecución se ven según el alcance
 * del rol: lo propio es lo que la persona tiene como responsable o creó; con "Sede", los de su sede.
 */
class CostCenter extends Model
{
    use ScopedByAccess;

    public const ACCESS_RESOURCE = 'budgets';

    public const KINDS = ['COST' => 'Gastos', 'REVENUE' => 'Ingresos'];

    protected $table = 'cost_centers';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['tenant_id', 'code', 'name', 'kind', 'active', 'branch_id', 'manager_user_id', 'created_by'];

    protected $casts = ['active' => 'boolean'];

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
