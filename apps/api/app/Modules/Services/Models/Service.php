<?php

namespace App\Modules\Services\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Service extends Model
{
    protected $table = 'services';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'category_id',
        'code',
        'name',
        'description',
        'status',
        'unit',
        'billing_mode',
        'warranty_months',
        'warranty_conditions',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'warranty_months' => 'integer',
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

    public function category()
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    // Recurrencia vigente; solo aplica cuando billing_mode es RECURRING.
    public function recurrence()
    {
        return $this->hasOne(ServiceRecurringConfig::class, 'service_id')->where('active', true)->orderByDesc('created_at');
    }

    // Historial de precios: no se sobrescribe, cada cambio es una fila nueva.
    public function prices()
    {
        return $this->hasMany(ServicePrice::class, 'service_id')->orderByDesc('created_at');
    }

    // Precio vigente: la fila activa más reciente.
    public function currentPrice()
    {
        // Sin ofMany(): desempata con max(id) y PostgreSQL no tiene max(uuid).
        return $this->hasOne(ServicePrice::class, 'service_id')->where('active', true)->orderByDesc('created_at');
    }
}