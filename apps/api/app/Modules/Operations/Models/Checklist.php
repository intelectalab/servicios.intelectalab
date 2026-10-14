<?php

namespace App\Modules\Operations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Plantilla de checklist. La orden de trabajo copia sus ítems al crearse: cambiar la plantilla
 * no toca las órdenes que ya la usan. No se borra: se desactiva.
 */
class Checklist extends Model
{
    protected $table = 'checklists';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['tenant_id', 'name', 'description', 'active', 'service_id', 'created_by'];

    protected $casts = ['active' => 'boolean'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    /** Los ítems vigentes, en su orden. */
    public function items()
    {
        return $this->hasMany(ChecklistItem::class, 'checklist_id')->whereNull('removed_at')->orderBy('position');
    }

    public function service()
    {
        return $this->belongsTo(\App\Modules\Services\Models\Service::class, 'service_id');
    }

    public function creator()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    /** Por su modelo: solo las órdenes de trabajo que la persona alcanza. */
    public function workOrders()
    {
        return $this->hasMany(WorkOrder::class, 'checklist_id');
    }
}
