<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use App\Services\LocalTime;

class ExchangeRate extends Model
{
    protected $table = 'exchange_rates';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id', 'rate_date', 'currency', 'buy', 'sell', 'source', 'created_by',
    ];

    protected $casts = [
        'rate_date' => 'date:Y-m-d',
        'buy' => 'decimal:4',
        'sell' => 'decimal:4',
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

    /** Tipo de cambio vigente a una fecha: el más reciente registrado hasta ese día. */
    public static function current(string $currency = 'USD', ?string $date = null): ?self
    {
        return static::where('currency', $currency)
            ->where('rate_date', '<=', $date ?? LocalTime::today()->toDateString())
            ->orderByDesc('rate_date')
            ->first();
    }
}
