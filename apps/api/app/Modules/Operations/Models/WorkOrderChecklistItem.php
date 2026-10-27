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

    protected $fillable = ['tenant_id', 'work_order_id', 'position', 'label', 'required', 'evidence_required', 'done', 'done_by', 'done_at', 'evidence_note', 'evidence_document_id'];

    protected $casts = ['required' => 'boolean', 'evidence_required' => 'boolean', 'done' => 'boolean', 'done_at' => 'datetime'];

    /** Foto de evidencia del ítem (último documento subido). */
    public function evidence()
    {
        return $this->belongsTo(\App\Modules\Documents\Models\Document::class, 'evidence_document_id');
    }

    /** Todos los documentos de evidencia vinculados a este ítem del checklist. */
    public function evidenceDocuments()
    {
        return $this->belongsToMany(
            \App\Modules\Documents\Models\Document::class,
            'document_links',
            'entity_id',
            'document_id'
        )->wherePivot('entity_type', 'WorkOrderChecklistItem')
            ->orderBy('documents.created_at')
            ->orderBy('documents.id');
    }

    /** Lo que las pantallas muestran de la evidencia; null si el ítem no tiene foto. */
    public function evidenceSummary(): ?array
    {
        $file = $this->evidence?->latestVersion?->fileObject;

        return $file ? [
            'document_id' => $this->evidence->id,
            'file_name' => $file->original_name,
            'size_bytes' => (int) $file->size_bytes,
            'version' => (int) $this->evidence->latestVersion->version,
        ] : null;
    }

    /** Todas las fotos de evidencia subidas al ítem (cada una como documento independiente). */
    public function evidencesSummary(): array
    {
        $docs = $this->relationLoaded('evidenceDocuments')
            ? $this->evidenceDocuments
            : $this->evidenceDocuments()->with('latestVersion.fileObject')->get();

        if ($docs->isEmpty() && $this->evidence) {
            $docs = collect([$this->evidence]);
        }

        $items = [];
        foreach ($docs->where('status', 'ACTIVE') as $doc) {
            $ver = $doc->latestVersion;
            $file = $ver?->fileObject;
            if ($file) {
                $items[] = [
                    'document_id' => $doc->id,
                    'file_name' => $file->original_name,
                    'size_bytes' => (int) $file->size_bytes,
                    'version' => (int) $ver->version,
                ];
            }
        }

        return $items;
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
