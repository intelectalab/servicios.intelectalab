<?php

namespace App\Modules\Crm\Models;

use App\Models\Concerns\ScopedByAccess;
use App\Modules\Services\Models\Quotation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CrmOpportunity extends Model
{
    use ScopedByAccess;

    /** Recurso cuyo alcance (lo propio, equipo, sede, empresa) limita qué registros se ven. */
    public const ACCESS_RESOURCE = 'opportunities';

    protected $table = 'crm_opportunities';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id', 'opportunity_number', 'customer_id', 'lead_id', 'pipeline_id', 'stage_id', 'source_id', 'name',
        'status', 'expected_value', 'currency', 'probability', 'expected_close_date', 'owner_user_id', 'branch_id',
        'won_at', 'won_by', 'won_evidence', 'lost_at', 'loss_reason',
    ];

    protected $casts = [
        'expected_value' => 'decimal:4',
        'probability' => 'decimal:4',
        'expected_close_date' => 'date',
        'won_at' => 'datetime',
        'lost_at' => 'datetime',
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

    public function stage()
    {
        return $this->belongsTo(CrmStage::class, 'stage_id');
    }

    public function source()
    {
        return $this->belongsTo(CrmSource::class, 'source_id');
    }

    public function owner()
    {
        return $this->belongsTo(\App\Models\User::class, 'owner_user_id');
    }

    public function lead()
    {
        return $this->belongsTo(CrmLead::class, 'lead_id');
    }

    public function customer()
    {
        return $this->belongsTo(\App\Modules\Core\Models\Customer::class, 'customer_id');
    }

    /** Cotizaciones emitidas desde la oportunidad; respetan el alcance de quien consulta. */
    public function quotations()
    {
        return $this->hasMany(Quotation::class, 'source_id')->where('source_type', 'OPPORTUNITY');
    }
}
