<?php

namespace App\Modules\Operations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Ítem del checklist de una orden de trabajo: copia del de la plantilla elegida al crearla. */
class WorkOrderChecklistItem extends Model
{
    protected $table = 'work_order_checklist_items';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['tenant_id', 'work_order_id', 'position', 'label', 'required', 'done', 'done_by', 'done_at', 'evidence_note', 'evidence_document_id'];

    protected $casts = ['required' => 'boolean', 'done' => 'boolean', 'done_at' => 'datetime'];

    /** Foto de evidencia del ítem (documento con versiones). */
    public function evidence()
    {
        return $this->belongsTo(\App\Modules\Documents\Models\Document::class, 'evidence_document_id');
    }

    /** Lo que las pantallas muestran de la evidencia; null si el ítem no tiene foto. */
    public function evidenceSummary(): ?array
    {
        $file = $this->evidence?->latestVersion?->fileObject;

        return $file ? ['file_name' => $file->original_name, 'size_bytes' => (int) $file->size_bytes, 'version' => (int) $this->evidence->latestVersion->version] : null;
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function doneBy()
    {
        return $this->belongsTo(\App\Models\User::class, 'done_by');
    }
}
