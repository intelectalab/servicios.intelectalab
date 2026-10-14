<?php

namespace App\Modules\Operations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Ítem de una plantilla de checklist. No se edita ni se borra: al cambiar la plantilla, los
 * ítems anteriores quedan retirados (`removed_at`) y se agregan los nuevos.
 */
class ChecklistItem extends Model
{
    protected $table = 'checklist_items';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['tenant_id', 'checklist_id', 'position', 'label', 'required', 'evidence_required', 'removed_at'];

    protected $casts = ['required' => 'boolean', 'evidence_required' => 'boolean', 'removed_at' => 'datetime'];

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
