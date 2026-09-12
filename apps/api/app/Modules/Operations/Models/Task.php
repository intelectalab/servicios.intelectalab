<?php

namespace App\Modules\Operations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Task extends Model
{
    protected $table = 'tasks';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'project_id',
        'work_order_id',
        'parent_task_id',
        'code',
        'title',
        'description',
        'status',
        'priority',
        'assigned_user_id',
        'due_at',
    ];

    protected $casts = [
        'due_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function taskChecklists()
    {
        return $this->hasMany(TaskChecklist::class, 'task_id');
    }
}