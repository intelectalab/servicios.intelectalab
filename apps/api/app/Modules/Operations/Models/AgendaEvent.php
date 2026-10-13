<?php

namespace App\Modules\Operations\Models;

use App\Models\Concerns\ScopedByAccess;
use App\Models\User;
use App\Modules\Core\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Evento de agenda: reunión, visita, mantenimiento o sesión de una persona, con su horario. */
class AgendaEvent extends Model
{
    use ScopedByAccess;

    /** Recurso cuyo alcance (lo propio, equipo, sede, empresa) limita qué eventos se ven. */
    public const ACCESS_RESOURCE = 'agenda';

    /** Tipo => texto visible. */
    public const TYPES = ['MEETING' => 'Reunión', 'VISIT' => 'Visita', 'MAINTENANCE' => 'Mantenimiento', 'SESSION' => 'Sesión'];

    /** Estado => texto visible. */
    public const STATES = ['SCHEDULED' => 'Programado', 'DONE' => 'Realizado', 'CANCELLED' => 'Cancelado'];

    /** Duración de un evento sin hora de fin, en minutos. */
    public const DEFAULT_MINUTES = 60;

    protected $table = 'agenda_events';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id', 'title', 'event_type', 'status', 'starts_at', 'ends_at', 'assigned_user_id', 'customer_id',
        'location', 'notes', 'cancel_reason', 'branch_id', 'created_by',
    ];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
