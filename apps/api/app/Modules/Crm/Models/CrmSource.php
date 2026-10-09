<?php

namespace App\Modules\Crm\Models;

use Illuminate\Database\Eloquent\Model;

class CrmSource extends Model
{
    protected $table = 'crm_sources';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;
}
