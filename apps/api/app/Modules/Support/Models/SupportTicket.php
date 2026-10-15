<?php

namespace App\Modules\Support\Models;

use App\Models\Concerns\ScopedByAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SupportTicket extends Model
{
    use ScopedByAccess;

    /** Recurso cuyo alcance (lo propio, equipo, sede, empresa) limita qué tickets se ven. */
    public const ACCESS_RESOURCE = 'tickets';

    /** Estado => texto visible (los del prototipo). */
    public const STATES = [
        'OPEN' => 'Abierto', 'TRIAGED' => 'Clasificado', 'ASSIGNED' => 'Asignado', 'IN_PROGRESS' => 'En atención',
        'WAITING_CUSTOMER' => 'Esperando cliente', 'RESOLVED' => 'Resuelto', 'CLOSED' => 'Cerrado', 'REOPENED' => 'Reabierto',
    ];

    /** Sin resolver: cuentan como abiertos y corre (o está en pausa) su reloj. */
    public const UNRESOLVED = ['OPEN', 'TRIAGED', 'ASSIGNED', 'IN_PROGRESS', 'WAITING_CUSTOMER', 'REOPENED'];

    public const CHANNELS = ['PHONE' => 'Teléfono', 'EMAIL' => 'Correo', 'BACKOFFICE' => 'Backoffice', 'PORTAL' => 'Portal'];

    public const CATEGORIES = ['INCIDENT' => 'Incidencia', 'REQUEST' => 'Requerimiento', 'QUESTION' => 'Consulta'];

    /**
     * Estado del SLA de resolución, calculado en la base para filtrar, ordenar y contar sobre todo
     * el listado: sin SLA (sin triage), cumplido, incumplido/vencido, en pausa, en riesgo (75 % del
     * plazo consumido) o a tiempo.
     */
    public const SLA_SQL = "CASE
        WHEN support_tickets.resolution_due_at IS NULL THEN 'none'
        WHEN support_tickets.resolved_at IS NOT NULL THEN CASE WHEN support_tickets.resolved_at <= support_tickets.resolution_due_at THEN 'met' ELSE 'breached' END
        WHEN support_tickets.paused_at IS NOT NULL THEN 'paused'
        WHEN now() > support_tickets.resolution_due_at THEN 'breached'
        WHEN now() >= COALESCE(support_tickets.sla_started_at, support_tickets.opened_at)
            + (support_tickets.resolution_due_at - COALESCE(support_tickets.sla_started_at, support_tickets.opened_at)) * 0.75 THEN 'risk'
        ELSE 'ok' END";

    public const SLA_STATES = ['ok' => 'A tiempo', 'risk' => 'En riesgo', 'breached' => 'Vencido', 'paused' => 'En pausa', 'met' => 'Cumplido', 'none' => 'Sin SLA'];

    protected $table = 'support_tickets';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id', 'customer_id', 'contract_id', 'service_id', 'ticket_number', 'subject', 'description',
        'channel', 'category', 'priority', 'status', 'sla_id', 'assigned_user_id', 'team_id', 'branch_id', 'created_by',
        'opened_at', 'triaged_at', 'resolved_at', 'closed_at',
        'sla_started_at', 'sla_calendar_mode', 'sla_factor', 'sla_response_minutes', 'sla_resolution_minutes',
        'response_due_at', 'resolution_due_at', 'first_response_at', 'paused_at', 'paused_seconds',
        'resolution', 'reopen_reason', 'work_order_id',
    ];

    protected $casts = [
        'opened_at' => 'datetime', 'triaged_at' => 'datetime', 'resolved_at' => 'datetime', 'closed_at' => 'datetime',
        'sla_started_at' => 'datetime', 'response_due_at' => 'datetime', 'resolution_due_at' => 'datetime',
        'first_response_at' => 'datetime', 'paused_at' => 'datetime', 'paused_seconds' => 'integer',
        'sla_factor' => 'float', 'sla_response_minutes' => 'integer', 'sla_resolution_minutes' => 'integer',
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

    public function comments()
    {
        return $this->hasMany(SupportTicketComment::class, 'ticket_id')->orderBy('created_at')->orderBy('id');
    }

    public function customer()
    {
        return $this->belongsTo(\App\Modules\Core\Models\Customer::class, 'customer_id');
    }

    /** Por su modelo: un contrato fuera del alcance de la persona no se entrega. */
    public function contract()
    {
        return $this->belongsTo(\App\Modules\Services\Models\Contract::class, 'contract_id');
    }

    public function service()
    {
        return $this->belongsTo(\App\Modules\Services\Models\Service::class, 'service_id');
    }

    public function assignee()
    {
        return $this->belongsTo(\App\Models\User::class, 'assigned_user_id');
    }

    public function creator()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    /** Por su modelo: una orden de trabajo fuera del alcance de la persona no se entrega. */
    public function workOrder()
    {
        return $this->belongsTo(\App\Modules\Operations\Models\WorkOrder::class, 'work_order_id');
    }

    public function claim()
    {
        return $this->hasOne(WarrantyClaim::class, 'ticket_id');
    }
}
