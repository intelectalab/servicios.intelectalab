<?php

namespace App\Modules\Credentials\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CredentialRecord extends Model
{
    protected $table = 'credential_records';

    protected $keyType = 'string';
    public $incrementing = false;

    /** Tipos del prototipo => texto visible. */
    public const TYPES = [
        'SERVIDOR' => 'Servidor',
        'DNS' => 'DNS',
        'HOSTING' => 'Hosting',
        'CLOUD' => 'Cloud',
        'API_KEY' => 'API key',
        'SMTP' => 'SMTP',
        'CERTIFICADO' => 'Certificado',
        'BASE_DATOS' => 'Base de datos',
    ];

    public const STATES = ['ACTIVE' => 'Activa', 'EXPIRED' => 'Expirada', 'REVOKED' => 'Revocada'];

    /**
     * Estado que se muestra: una activa cuya fecha pasó ya está expirada, aunque la tarea diaria
     * (`credentials:expire`) todavía no la haya marcado.
     */
    public const STATUS_SQL = "CASE WHEN credential_records.status = 'ACTIVE' AND credential_records.expires_at <= now() THEN 'EXPIRED' ELSE credential_records.status END";

    protected $fillable = [
        'tenant_id', 'name', 'credential_type', 'status', 'owner_label',
        'owner_entity_type', 'owner_entity_id', 'expires_at', 'created_by',
    ];

    protected $casts = ['expires_at' => 'datetime'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function effectiveStatus(): string
    {
        return $this->status === 'ACTIVE' && $this->expires_at?->isPast() ? 'EXPIRED' : $this->status;
    }
}
