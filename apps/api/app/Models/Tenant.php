<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Tenant extends Model
{
    use HasUuids;

    protected $table = 'tenants';

    protected $fillable = [
        'code',
        'name',
        'legal_name',
        'status',
        'timezone',
        'locale',
        'currency',
        'configuration',
    ];

    protected $casts = [
        'configuration' => 'array',
    ];
}