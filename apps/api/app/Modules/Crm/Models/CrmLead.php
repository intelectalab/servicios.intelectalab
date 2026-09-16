<?php

namespace App\Modules\Crm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CrmLead extends Model
{
    protected $table = 'crm_leads';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id', 'source_id', 'party_id', 'person_name', 'organization_name',
        'email', 'phone', 'status', 'score', 'owner_user_id', 'converted_at', 'converted_customer_id',
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
}