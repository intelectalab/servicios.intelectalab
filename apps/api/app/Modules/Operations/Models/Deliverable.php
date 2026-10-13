<?php

namespace App\Modules\Operations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Entregable de un proyecto: se prepara, se envía a revisión con su documento, se aprueba y se entrega. */
class Deliverable extends Model
{
    /** Estado => texto visible. */
    public const STATES = [
        'DRAFT' => 'En preparación', 'IN_REVIEW' => 'En revisión', 'RETURNED' => 'Devuelto', 'APPROVED' => 'Aprobado', 'DELIVERED' => 'Entregado',
    ];

    protected $table = 'deliverables';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id', 'project_id', 'work_order_id', 'task_id', 'name', 'description', 'status', 'due_date', 'accepted_at',
        'document_ref', 'review_note', 'created_by', 'reviewed_by', 'reviewed_at', 'delivered_at',
    ];

    protected $casts = ['due_date' => 'date', 'accepted_at' => 'datetime', 'reviewed_at' => 'datetime', 'delivered_at' => 'datetime'];

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
