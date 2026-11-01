<?php

namespace App\Modules\Credentials\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CredentialAccessLog extends Model
{
    protected $table = 'credential_access_logs';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['tenant_id', 'credential_id', 'user_id', 'action', 'success', 'reason', 'correlation_id'];

    protected $casts = ['success' => 'boolean'];

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