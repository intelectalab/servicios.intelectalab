<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Party extends Model
{
    protected $table = 'parties';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'party_type',
        'status',
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

    public function person()
    {
        return $this->hasOne(Person::class, 'party_id');
    }

    public function organization()
    {
        return $this->hasOne(Organization::class, 'party_id');
    }

    public function customer()
    {
        return $this->hasOne(Customer::class, 'party_id');
    }

    public function supplier()
    {
        return $this->hasOne(Supplier::class, 'party_id');
    }

    public function employee()
    {
        return $this->hasOne(Employee::class, 'person_party_id');
    }

    // Nombre "de display", sin importar si es persona u organización.
    public function getDisplayNameAttribute(): string
    {
        if ($this->party_type === 'PERSON' && $this->person) {
            return trim("{$this->person->given_names} {$this->person->family_name}");
        }

        if ($this->party_type === 'ORGANIZATION' && $this->organization) {
            return $this->organization->trade_name ?? $this->organization->legal_name;
        }

        return 'Sin nombre';
    }
}