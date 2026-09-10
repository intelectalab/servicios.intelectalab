<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;

class Person extends Model
{
    protected $table = 'persons';

    protected $primaryKey = 'party_id';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'party_id',
        'given_names',
        'family_name',
        'document_type',
        'document_number',
        'birth_date',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'birth_date' => 'date',
    ];

    public function party()
    {
        return $this->belongsTo(Party::class, 'party_id');
    }
}