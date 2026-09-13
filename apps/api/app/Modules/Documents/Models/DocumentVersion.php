<?php

namespace App\Modules\Documents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DocumentVersion extends Model
{
    protected $table = 'document_versions';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['tenant_id', 'document_id', 'file_object_id', 'version', 'checksum', 'created_by'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function fileObject()
    {
        return $this->belongsTo(FileObject::class, 'file_object_id');
    }
}