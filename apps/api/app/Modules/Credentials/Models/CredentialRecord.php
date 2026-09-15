<?php

namespace App\Modules\Credentials\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CredentialRecord extends Model
{
    protected $table = 'credential_records';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id', 'name', 'credential_type', 'status',
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
}