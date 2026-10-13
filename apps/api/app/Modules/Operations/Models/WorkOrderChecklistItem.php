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

    protected $fillable = ['tenant_id', 'work_order_id', 'position', 'label', 'required', 'done', 'done_by', 'done_at', 'evidence_note'];

    protected $casts = ['required' => 'boolean', 'done' => 'boolean', 'done_at' => 'datetime'];

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
