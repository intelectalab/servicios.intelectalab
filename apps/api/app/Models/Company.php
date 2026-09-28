<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Company extends Model
{
    use HasUuids;

    protected $table = 'companies';

    protected $fillable = [
        'tenant_id',
        'code',
        'legal_name',
        'trade_name',
        'tax_identifier',
        'tax_country',
        'status',
        'address',
        'configuration',
    ];

    protected $casts = [
        'address' => 'array',
        'configuration' => 'array',
    ];
}