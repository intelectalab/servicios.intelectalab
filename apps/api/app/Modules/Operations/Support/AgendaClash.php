<?php

namespace App\Modules\Operations\Support;

use App\Modules\Operations\Models\AgendaEvent;
use App\Modules\Operations\Models\WorkOrder;
use Illuminate\Support\Carbon;

/**
 * Qué ocupa ya el horario de una persona: sus eventos programados y sus órdenes de trabajo
 * asignadas o en ejecución. Se mira toda la empresa, no solo lo que alcanza quien pregunta;
 * por eso el nombre de lo que choca solo se dice si quien pregunta también lo puede ver.
 */
class AgendaClash
{
    /** Duración que ocupa una orden de trabajo sin hora de fin. */
    public const WORK_ORDER_HOURS = 2;

    /** Texto de lo primero que se cruza con ese horario (para un mensaje), o null si está libre. */
    public static function find(string $userId, Carbon $start, Carbon $end, ?string $exceptEvent = null, ?string $exceptWorkOrder = null): ?string
    {
        return self::event($userId, $start, $end, $exceptEvent) ?? self::workOrder($userId, $start, $end, $exceptWorkOrder);
    }

    /** Evento programado de la persona que se cruza con el horario. */
    public static function event(string $userId, Carbon $start, Carbon $end, ?string $except = null): ?string
    {
        $clash = AgendaEvent::ignoringAccessScope()
            ->when($except, fn ($q) => $q->whereKeyNot($except))
            ->where('assigned_user_id', $userId)
            ->where('status', 'SCHEDULED')
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)
            ->orderBy('starts_at')
            ->first(['id', 'title']);

        if (! $clash) {
            return null;
        }

        return AgendaEvent::whereKey($clash->id)->exists() ? "\"{$clash->title}\"" : 'otro evento de esa persona';
    }

    /** Orden de trabajo asignada o en ejecución de la persona que se cruza con el horario. */
    public static function workOrder(string $userId, Carbon $start, Carbon $end, ?string $except = null): ?string
    {
        $clash = WorkOrder::ignoringAccessScope()
            ->when($except, fn ($q) => $q->whereKeyNot($except))
            ->where('assigned_user_id', $userId)
            ->whereIn('status', WorkOrder::BUSY)
            ->whereNotNull('scheduled_start')
            ->where('scheduled_start', '<', $end)
            ->whereRaw('COALESCE(scheduled_end, scheduled_start + interval \''.self::WORK_ORDER_HOURS.' hours\') > ?', [$start])
            ->orderBy('scheduled_start')
            ->first(['id', 'code']);

        if (! $clash) {
            return null;
        }

        return WorkOrder::whereKey($clash->id)->exists() ? "la orden de trabajo {$clash->code}" : 'una orden de trabajo de esa persona';
    }
}
