<?php

namespace App\Modules\Crm\Models;

use Illuminate\Database\Eloquent\Model;

class CrmStage extends Model
{
    protected $table = 'crm_pipeline_stages';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $casts = ['probability' => 'float', 'active' => 'boolean'];
}
