<?php

namespace App\Modules\Crm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Campaña comercial. Quien puede ver campañas ve todas las de la empresa; sus miembros y sus
 * resultados, en cambio, se consultan por los modelos de leads y oportunidades, así que cada
 * persona ve ahí solo lo que su rol alcanza.
 */
class CrmCampaign extends Model
{
    /** Estado => texto visible. */
    public const STATES = ['DRAFT' => 'Borrador', 'ACTIVE' => 'Activa', 'COMPLETED' => 'Finalizada', 'CANCELLED' => 'Cancelada'];

    /** Estados en los que la campaña se edita y admite cambios en sus miembros. */
    public const OPEN = ['DRAFT', 'ACTIVE'];

    protected $table = 'crm_campaigns';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id', 'code', 'name', 'channel', 'status', 'starts_at', 'ends_at', 'budget',
        'consent_required', 'owner_user_id', 'cancel_reason',
    ];

    protected $casts = [
        'starts_at' => 'date',
        'ends_at' => 'date',
        'budget' => 'decimal:4',
        'consent_required' => 'boolean',
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

    public function owner()
    {
        return $this->belongsTo(\App\Models\User::class, 'owner_user_id');
    }
}
