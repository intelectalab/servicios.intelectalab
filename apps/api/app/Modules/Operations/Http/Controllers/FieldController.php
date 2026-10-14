<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Models\WorkOrder;
use App\Modules\Operations\Models\WorkOrderChecklistItem;
use App\Modules\Operations\Support\Technicians;
use App\Services\AccessScope;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * App del técnico: las visitas pendientes de una persona (sus órdenes de trabajo asignadas, en
 * ejecución o bloqueadas) en el orden sugerido de recorrido, con su checklist. Las acciones
 * (iniciar, marcar, completar) van por las rutas normales de la orden de trabajo.
 */
class FieldController extends Controller
{
    /** Estados en los que la visita sigue pendiente para el técnico. */
    private const PENDING = ['ASSIGNED', 'IN_PROGRESS', 'BLOCKED'];

    public function route(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $me = $request->user();
        $userId = $request->validate(['user' => ['nullable', 'uuid']])['user'] ?? $me->id;

        // Ver la app de otra persona: solo de quien ejecuta órdenes, y siempre dentro del alcance propio.
        if ($userId !== $me->id && ! Technicians::includes($tenantId, $userId)) {
            throw ValidationException::withMessages(['user' => ['Esa persona no ejecuta órdenes de trabajo.']]);
        }

        // Por su modelo: solo las órdenes que quien mira alcanza.
        $orders = WorkOrder::query()
            ->with('customer.party.person', 'customer.party.organization', 'checklistItems.evidence.latestVersion.fileObject')
            ->where('assigned_user_id', $userId)
            ->whereIn('status', self::PENDING)
            ->orderByRaw('scheduled_start IS NULL')->orderBy('scheduled_start')->orderBy('created_at')
            ->limit(100)->get();

        $technicians = Technicians::list($tenantId);

        return response()->json([
            'viewer_id' => $me->id,
            'technician' => [
                'id' => $userId,
                'name' => $userId === $me->id ? $me->name : (collect($technicians)->firstWhere('id', $userId)['name'] ?? null),
            ],
            // Quien solo alcanza lo propio no elige a nadie más.
            'technicians' => AccessScope::current('work_orders') === 'OWN' ? [] : $technicians,
            'orders' => $this->suggested($orders)->values()->map(fn (WorkOrder $order) => [
                'id' => $order->id,
                'code' => $order->code,
                'title' => $order->title,
                'status' => $order->status,
                'priority' => $order->priority,
                'scheduled_start' => $order->scheduled_start?->toIso8601String(),
                'location' => $order->location,
                'latitude' => $order->latitude !== null ? (float) $order->latitude : null,
                'longitude' => $order->longitude !== null ? (float) $order->longitude : null,
                'customer' => $this->customerName($order->customer),
                'blocked_reason' => $order->blocked_reason,
                'checklist_status' => $order->checklist_status,
                'checklist_items' => $order->checklistItems->map(fn (WorkOrderChecklistItem $item) => [
                    'id' => $item->id,
                    'label' => $item->label,
                    'required' => (bool) $item->required,
                    'evidence_required' => (bool) $item->evidence_required,
                    'done' => (bool) $item->done,
                    'evidence' => $item->evidenceSummary(),
                ])->values(),
                'lock_version' => (int) $order->lock_version,
            ]),
        ]);
    }

    /**
     * Cambios hechos sin conexión que la API rechazó al sincronizar: quedan en el historial de
     * su orden para que quien supervisa sepa que ese trabajo no se registró.
     */
    public function syncLog(Request $request)
    {
        $data = $request->validate([
            'rejected' => ['required', 'array', 'max:50'],
            'rejected.*.work_order_id' => ['required', 'uuid'],
            'rejected.*.label' => ['required', 'string', 'max:200'],
            'rejected.*.message' => ['required', 'string', 'max:500'],
        ]);

        $recorded = 0;
        foreach ($data['rejected'] as $entry) {
            // Solo sobre órdenes que la persona alcanza.
            if (! WorkOrder::whereKey($entry['work_order_id'])->exists()) {
                continue;
            }
            AuditLogger::log(
                tenantId: $request->attributes->get('tenant_id'),
                userId: $request->user()->id,
                action: 'work_order.sync_rejected',
                entityType: 'WorkOrder',
                entityId: $entry['work_order_id'],
                metadata: ['note' => Str::limit($entry['label'].': '.$entry['message'], 600)],
            );
            $recorded++;
        }

        return response()->json(['recorded' => $recorded]);
    }

    /**
     * Orden sugerido de recorrido: desde la primera visita programada, siempre la más cercana
     * (línea recta). Con menos de tres visitas ubicadas no hay nada que ordenar; las que no
     * tienen ubicación van al final, por su hora.
     */
    private function suggested(Collection $orders): Collection
    {
        $located = $orders->filter(fn (WorkOrder $order) => $order->latitude !== null && $order->longitude !== null)->values();
        $rest = $orders->reject(fn (WorkOrder $order) => $order->latitude !== null && $order->longitude !== null)->values();
        if ($located->count() < 3) {
            return $located->concat($rest);
        }

        $route = collect([$located->shift()]);
        while ($located->isNotEmpty()) {
            $from = $route->last();
            $next = $located->sortBy(fn (WorkOrder $order) => hypot(
                (float) $order->latitude - (float) $from->latitude,
                (float) $order->longitude - (float) $from->longitude,
            ))->keys()->first();
            $route->push($located->pull($next));
        }

        return $route->concat($rest);
    }

    private function customerName(?object $customer): ?string
    {
        $party = $customer?->party;

        return $party?->organization?->trade_name ?: $party?->organization?->legal_name
            ?: (trim(($party?->person?->given_names ?? '').' '.($party?->person?->family_name ?? '')) ?: null);
    }
}
