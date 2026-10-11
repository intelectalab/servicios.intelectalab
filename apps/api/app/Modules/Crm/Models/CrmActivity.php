<?php

namespace App\Modules\Crm\Models;

use App\Models\Concerns\ScopedByAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Actividad comercial (llamada, reunión, correo, demo o visita) ligada a un lead o a una
 * oportunidad. Está realizada cuando tiene `completed_at`.
 */
class CrmActivity extends Model
{
    use ScopedByAccess;

    /** Recurso cuyo alcance (lo propio, equipo, sede, empresa) limita qué registros se ven. */
    public const ACCESS_RESOURCE = 'activities';

    /** Tipo => texto visible. */
    public const TYPES = ['CALL' => 'Llamada', 'MEETING' => 'Reunión', 'EMAIL' => 'Email', 'DEMO' => 'Demo', 'VISIT' => 'Visita'];

    protected $table = 'crm_activities';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id', 'lead_id', 'opportunity_id', 'activity_type', 'subject', 'description', 'due_at',
        'completed_at', 'completed_by', 'owner_user_id', 'branch_id',
    ];

    protected $casts = ['due_at' => 'datetime', 'completed_at' => 'datetime'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function lead()
    {
        return $this->belongsTo(CrmLead::class, 'lead_id');
    }

    public function opportunity()
    {
        return $this->belongsTo(CrmOpportunity::class, 'opportunity_id');
    }

    public function owner()
    {
        return $this->belongsTo(\App\Models\User::class, 'owner_user_id');
    }
}
