<?php

namespace App\Modules\Crm\Models;

use App\Models\Concerns\ScopedByAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CrmLead extends Model
{
    use ScopedByAccess;

    /** Recurso cuyo alcance (lo propio, equipo, sede, empresa) limita qué registros se ven. */
    public const ACCESS_RESOURCE = 'leads';

    /** Estados en los que el lead sigue abierto: se edita, se califica o se descarta. */
    public const OPEN = ['NEW', 'CONTACTED', 'QUALIFIED'];

    protected $table = 'crm_leads';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id', 'lead_number', 'source_id', 'party_id', 'person_name', 'organization_name', 'position',
        'email', 'phone', 'interest', 'status', 'score', 'discard_reason', 'owner_user_id', 'branch_id',
        'converted_at', 'converted_customer_id',
    ];

    protected $casts = ['converted_at' => 'datetime', 'score' => 'decimal:4'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function source()
    {
        return $this->belongsTo(CrmSource::class, 'source_id');
    }

    public function owner()
    {
        return $this->belongsTo(\App\Models\User::class, 'owner_user_id');
    }

    public function opportunity()
    {
        return $this->hasOne(CrmOpportunity::class, 'lead_id');
    }
}
