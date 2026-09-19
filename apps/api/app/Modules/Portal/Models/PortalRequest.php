<?php

namespace App\Modules\Portal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PortalRequest extends Model
{
    protected $table = 'portal_requests';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id', 'customer_id', 'request_type', 'subject', 'description',
        'status', 'crm_opportunity_id', 'quotation_id', 'created_by',
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
}