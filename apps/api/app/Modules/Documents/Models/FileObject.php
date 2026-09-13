<?php

namespace App\Modules\Documents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class FileObject extends Model
{
    protected $table = 'file_objects';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'storage_provider', 'bucket', 'object_key',
        'original_name', 'mime_type', 'size_bytes', 'sha256',
        'encrypted', 'antivirus_status',
    ];

    protected $casts = ['encrypted' => 'boolean'];

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