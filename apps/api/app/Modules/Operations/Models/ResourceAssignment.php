<?php

namespace App\Modules\Operations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ResourceAssignment extends Model
{
    protected $table = 'resource_assignments';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['tenant_id', 'resource_id', 'project_id', 'work_order_id', 'start_at', 'end_at', 'quantity'];

    protected $casts = ['start_at' => 'datetime', 'end_at' => 'datetime', 'quantity' => 'decimal:4'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function resource()
    {
        return $this->belongsTo(Resource::class, 'resource_id');
    }
}