<?php

namespace App\Modules\Support\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SupportSla extends Model
{
    protected $table = 'support_slas';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['tenant_id', 'name', 'priority', 'response_minutes', 'resolution_minutes', 'active'];

    protected $casts = ['active' => 'boolean'];

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