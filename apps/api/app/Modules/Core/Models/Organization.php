<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;

class Organization extends Model
{
    protected $table = 'organizations';

    protected $primaryKey = 'party_id';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'party_id',
        'legal_name',
        'trade_name',
        'tax_identifier',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function party()
    {
        return $this->belongsTo(Party::class, 'party_id');
    }
}