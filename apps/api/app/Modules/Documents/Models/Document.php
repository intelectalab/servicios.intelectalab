<?php

namespace App\Modules\Documents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Document extends Model
{
    protected $table = 'documents';

    protected $keyType = 'string';
    public $incrementing = false;

    /** Estado => texto de la pantalla. ACTIVE es "Disponible". */
    public const STATES = ['ACTIVE' => 'Disponible', 'QUARANTINED' => 'En cuarentena', 'ARCHIVED' => 'Archivado'];

    protected $fillable = [
        'tenant_id', 'document_type', 'title', 'status', 'classification', 'visibility', 'branch_id', 'created_by',
        'archived_at', 'archived_by',
    ];

    protected $casts = ['archived_at' => 'datetime', 'lock_version' => 'integer'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::orderedUuid();
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
