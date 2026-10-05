<?php

namespace App\Modules\Services\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class QuotationApproval extends Model
{
    protected $table = 'quotation_approvals';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'quotation_id',
        'approver_user_id',
        'decision',
        'comments',
        'decided_at',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
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

    public function approver()
    {
        return $this->belongsTo(\App\Models\User::class, 'approver_user_id');
    }
}
