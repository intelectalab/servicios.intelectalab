<?php

namespace App\Modules\Subscription\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class TenantModule extends Model
{
    use HasUuids;

    protected $table = 'tenant_modules';

    protected $fillable = [
        'tenant_id',
        'module_code',
        'enabled',
        'configuration',
        'activated_at',
        'deactivated_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'configuration' => 'array',
        'activated_at' => 'datetime',
        'deactivated_at' => 'datetime',
    ];
}