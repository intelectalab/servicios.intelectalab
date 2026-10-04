<?php

namespace App\Modules\Subscription\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class TenantSubscription extends Model
{
    use HasUuids;

    protected $table = 'tenant_subscriptions';

    protected $fillable = [
        'tenant_id',
        'plan_id',
        'status',
        'starts_at',
        'ends_at',
        'configuration',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'configuration' => 'array',
    ];

    public function plan()
    {
        return $this->belongsTo(SaasPlan::class, 'plan_id');
    }
}