<?php

namespace App\Modules\Operations\Support;

use App\Modules\Operations\Models\Checklist;
use App\Modules\Operations\Models\ContractedService;
use App\Modules\Operations\Models\WorkOrder;
use App\Modules\Operations\Models\WorkOrderChecklistItem;
use App\Services\AuditLogger;
use App\Services\SequentialNumberGenerator;
use Illuminate\Http\Request;

/**
 * Alta de una orden de trabajo: nace de un servicio contratado activo, copia el checklist de la
 * plantilla elegida y queda en auditoría. La usan la pantalla de órdenes de trabajo y los tickets
 * de soporte ("Crear OT"); se llama dentro de la transacción de quien la crea.
 */
class WorkOrders
{
    /** @param array{title: string, priority?: ?string, scheduled_start?: mixed, scheduled_end?: mixed, location?: ?string, latitude?: mixed, longitude?: mixed, description?: ?string} $data */
    public static function create(Request $request, ContractedService $service, ?Checklist $template, array $data, array $audit = []): WorkOrder
    {
        $tenantId = $request->attributes->get('tenant_id');

        $order = WorkOrder::create([
            'tenant_id' => $tenantId,
            'customer_id' => $service->customer_id,
            'contracted_service_id' => $service->id,
            'contract_id' => $service->contract_id,
            'service_id' => $service->service_id,
            'code' => SequentialNumberGenerator::next($tenantId, 'work_orders', 'code', 'OT'),
            'title' => trim($data['title']),
            'status' => 'PLANNED',
            'priority' => $data['priority'] ?? 'NORMAL',
            'scheduled_start' => $data['scheduled_start'] ?? null,
            'scheduled_end' => $data['scheduled_end'] ?? null,
            'location' => $data['location'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'description' => $data['description'] ?? null,
            'created_by' => $request->user()->id,
            'checklist_id' => $template?->id,
            'checklist_name' => $template?->name,
            'checklist_status' => $template ? 'PENDING' : null,
            // La orden nace en la sede de su servicio; si no tiene, en la de quien la crea.
            ...($service->branch_id ? ['branch_id' => $service->branch_id] : []),
        ]);

        foreach ($template?->items ?? [] as $item) {
            WorkOrderChecklistItem::create([
                'tenant_id' => $tenantId, 'work_order_id' => $order->id,
                'position' => $item->position, 'label' => $item->label, 'required' => $item->required,
                'evidence_required' => $item->evidence_required,
            ]);
        }

        AuditLogger::log(
            tenantId: $tenantId,
            userId: $request->user()->id,
            action: 'work_order.create',
            entityType: 'WorkOrder',
            entityId: $order->id,
            metadata: ['to' => 'PLANNED', 'code' => $order->code, 'title' => $order->title, ...$audit],
        );

        return $order;
    }
}
