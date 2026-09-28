<?php

namespace App\Modules\Subscription\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class SaasPlan extends Model
{
    use HasUuids;

    protected $table = 'saas_plans';

    protected $fillable = [
        'code',
        'name',
        'description',
        'active',
        'display_order',
        'is_public',
        'monthly_price',
        'annual_price',
        'currency',
        'has_trial',
        'trial_days',
        'requires_payment_upfront',
        'auto_renew_default',
        'default_billing_period',
        'limits',
        'modules',
        'features',
        'support_level',
        'support_tickets_month',
        'support_sla_hours',
        'support_channels',
    ];

    protected $casts = [
        'active' => 'boolean',
        'is_public' => 'boolean',
        'has_trial' => 'boolean',
        'requires_payment_upfront' => 'boolean',
        'auto_renew_default' => 'boolean',
        'monthly_price' => 'decimal:4',
        'annual_price' => 'decimal:4',
        'display_order' => 'integer',
        'trial_days' => 'integer',
        'support_tickets_month' => 'integer',
        'support_sla_hours' => 'integer',
        'limits' => 'array',
        'modules' => 'array',
        'features' => 'array',
        'support_channels' => 'array',
    ];
}