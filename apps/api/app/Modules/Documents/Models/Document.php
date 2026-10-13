<?php

namespace App\Modules\Documents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Document extends Model
{
    protected $table = 'documents';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['tenant_id', 'document_type', 'title', 'status', 'classification', 'visibility', 'created_by'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function versions()
    {
        return $this->hasMany(DocumentVersion::class, 'document_id');
    }

    public function latestVersion()
    {
        return $this->hasOne(DocumentVersion::class, 'document_id')->orderByDesc('version');
    }
}