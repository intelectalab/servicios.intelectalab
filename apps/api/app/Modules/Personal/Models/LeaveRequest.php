<?php

namespace App\Modules\Personal\Models;

use Illuminate\Database\Eloquent\Model;

/** Solicitud de ausencia (para filtrar por el alcance del rol con AccessScope). */
class LeaveRequest extends Model
{
    protected $table = 'hr_leave_requests';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];
}
