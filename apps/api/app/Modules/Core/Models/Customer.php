<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Customer extends Model
{
    protected $table = 'customers';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'party_id',
        'status',
        'credit_limit',
        'payment_terms_days',
        'retention_agent',
        'preferred_currency',
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

    public function party()
    {
        return $this->belongsTo(Party::class, 'party_id');
    }
}