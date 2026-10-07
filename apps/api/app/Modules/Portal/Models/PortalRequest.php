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
        'tenant_id', 'customer_id', 'request_number', 'request_type', 'subject', 'description',
        'service_id', 'status', 'resolution_note', 'crm_opportunity_id', 'quotation_id', 'created_by',
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

    public function customer()
    {
        return $this->belongsTo(\App\Modules\Core\Models\Customer::class, 'customer_id');
    }

    public function service()
    {
        return $this->belongsTo(\App\Modules\Services\Models\Service::class, 'service_id');
    }

    public function quotation()
    {
        return $this->belongsTo(\App\Modules\Services\Models\Quotation::class, 'quotation_id');
    }
}
