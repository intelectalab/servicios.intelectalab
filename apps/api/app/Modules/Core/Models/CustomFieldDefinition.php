<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CustomFieldDefinition extends Model
{
    protected $table = 'custom_field_definitions';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['tenant_id', 'entity_type', 'field_key', 'label', 'field_type'];

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