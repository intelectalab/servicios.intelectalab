<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Tenant extends Model
{
    use HasUuids;

    protected $table = 'tenants';

    protected $fillable = [
        'code',
        'name',
        'legal_name',
        'status',
        'timezone',
        'locale',
        'currency',
        'configuration',
        'login_slug',
    ];

    protected $casts = [
        'configuration' => 'array',
    ];

    public function subscriptions()
    {
        return $this->hasMany(\App\Modules\Subscription\Models\TenantSubscription::class, 'tenant_id');
    }

    public function companies()
    {
        return $this->hasMany(Company::class, 'tenant_id');
    }
}