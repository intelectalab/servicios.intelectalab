<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CustomFieldValue extends Model
{
    protected $table = 'custom_field_values';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['tenant_id', 'field_id', 'entity_type', 'entity_id', 'value'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function definition()
    {
        return $this->belongsTo(CustomFieldDefinition::class, 'field_id');
    }
}