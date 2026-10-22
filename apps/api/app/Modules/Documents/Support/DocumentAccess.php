<?php

namespace App\Modules\Documents\Support;

use App\Http\Middleware\CheckPermission;
use App\Modules\Core\Models\Party;
use App\Modules\Documents\Models\Document;
use App\Modules\Operations\Models\Project;
use App\Modules\Operations\Models\WorkOrder;
use App\Modules\Services\Models\Contract;
use App\Services\AccessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Qué documentos alcanza la persona y a qué registro pertenece cada uno.
 *
 * - En la pantalla Documentos: los que su rol alcanza (lo que subió, su sede o toda la empresa).
 * - En la ficha de un registro que puede ver (contrato, proyecto, OT, cliente): todos los suyos,
 *   aunque los haya subido otra persona.
 * - Los extractos bancarios, solo con permiso de Tesorería, también en la pantalla Documentos.
 */
class DocumentAccess
{
    /** Registros a los que se vincula un documento desde la pantalla: tipo => texto. */
    public const LINKABLE = ['Party' => 'Cliente o actor', 'Contract' => 'Contrato', 'Project' => 'Proyecto', 'WorkOrder' => 'Orden de trabajo'];

    private const NONE = '00000000-0000-0000-0000-000000000000';

    /** Los documentos de la pantalla Documentos. */
    public static function listing(): Builder
    {
        $query = Document::query();
        AccessScope::apply($query, 'documents');

        return self::hideRestricted($query);
    }

    /** Un documento que la persona puede abrir: por su alcance o por un registro suyo que ve. */
    public static function find(string $id): Document
    {
        abort_unless(Str::isUuid($id), 404);
        $document = self::hideRestricted(Document::query())->whereKey($id)->first();
        abort_unless($document && (self::listing()->whereKey($id)->exists() || self::reachableThroughLinks($id)), 404);

        return $document;
    }

    /** Documentos de un registro que la persona puede ver (y de lo que cuelga de él). */
    public static function forRecord(string $type, string $id): Builder
    {
        abort_unless(Str::isUuid($id) && self::recordVisible($type, $id), 404);

        $targets = [[$type, [$id]]];
        if ($type === 'Project') {
            $targets[] = ['Deliverable', DB::table('deliverables')->where('project_id', $id)->pluck('id')->all()];
        }
        if ($type === 'WorkOrder') {
            $targets[] = ['WorkOrderChecklistItem', DB::table('work_order_checklist_items')->where('work_order_id', $id)->pluck('id')->all()];
        }
        if ($type === 'Party') {
            // Lo del cliente: sus contratos, proyectos y órdenes que la persona alcanza.
            $customerId = DB::table('customers')->where('party_id', $id)->value('id');
            if ($customerId) {
                $targets[] = ['Customer', [$customerId]];
                foreach (['Contract' => Contract::class, 'Project' => Project::class, 'WorkOrder' => WorkOrder::class] as $kind => $model) {
                    $targets[] = [$kind, $model::query()->where('customer_id', $customerId)->pluck('id')->all()];
                }
            }
        }

        return self::hideRestricted(Document::query())->whereIn('documents.id', fn ($sub) => $sub->select('document_id')->from('document_links')
            ->where(function ($links) use ($targets) {
                foreach ($targets as [$kind, $ids]) {
                    if ($ids) {
                        $links->orWhere(fn ($q) => $q->where('entity_type', $kind)->whereIn('entity_id', $ids));
                    }
                }
            }));
    }

    /** ¿La persona ve ese registro? Por el modelo de cada uno, que aplica el alcance de su rol. */
    public static function recordVisible(string $type, string $id): bool
    {
        if (! Str::isUuid($id)) {
            return false;
        }
        $can = fn (string $permission) => CheckPermission::granted(request(), $permission);

        return match ($type) {
            'Contract' => $can('contracts.view') && Contract::query()->whereKey($id)->exists(),
            'Project' => $can('projects.view') && Project::query()->whereKey($id)->exists(),
            'WorkOrder' => $can('work_orders.view') && WorkOrder::query()->whereKey($id)->exists(),
            'Party' => $can('core.view') && Party::query()->whereKey($id)->exists(),
            'Customer' => $can('core.view') && DB::table('customers')->where('id', $id)->exists(),
            'Deliverable' => self::recordVisible('Project', (string) DB::table('deliverables')->where('id', $id)->value('project_id')),
            'WorkOrderChecklistItem' => self::recordVisible('WorkOrder', (string) DB::table('work_order_checklist_items')->where('id', $id)->value('work_order_id')),
            'BankStatement' => $can('treasury.view'),
            default => false,
        };
    }

    /** ¿Algún registro del documento lo ve la persona? */
    private static function reachableThroughLinks(string $documentId): bool
    {
        return DB::table('document_links')->where('document_id', $documentId)->get(['entity_type', 'entity_id'])
            ->contains(fn ($link) => self::recordVisible($link->entity_type, $link->entity_id));
    }

    /** Sin permiso de Tesorería, los extractos bancarios no aparecen. */
    private static function hideRestricted(Builder $query): Builder
    {
        if (! CheckPermission::granted(request(), 'treasury.view')) {
            $query->whereNotExists(fn ($sub) => $sub->select(DB::raw(1))->from('document_links')
                ->whereColumn('document_links.document_id', 'documents.id')->where('document_links.entity_type', 'BankStatement'));
        }

        return $query;
    }

    /**
     * Vínculos de cada documento, con el texto que se muestra y, si la persona ve el registro,
     * a dónde lleva.
     *
     * @return Collection<string, array<int, array{type: string, id: string, label: string, href: ?string}>>
     */
    public static function links(array $documentIds): Collection
    {
        $rows = DB::table('document_links')->whereIn('document_id', $documentIds ?: [self::NONE])->orderBy('created_at')
            ->get(['document_id', 'entity_type', 'entity_id']);
        $ids = fn (string $type) => $rows->where('entity_type', $type)->pluck('entity_id')->unique()->values()->all() ?: [self::NONE];
        $can = fn (string $permission) => CheckPermission::granted(request(), $permission);

        $partyName = "COALESCE(o.trade_name, o.legal_name, concat_ws(' ', pe.given_names, pe.family_name))";
        $parties = DB::table('parties')->leftJoin('organizations as o', 'o.party_id', '=', 'parties.id')->leftJoin('persons as pe', 'pe.party_id', '=', 'parties.id')
            ->whereIn('parties.id', $ids('Party'))->get(['parties.id', DB::raw("{$partyName} AS name")])->pluck('name', 'id');
        $customers = DB::table('customers')->leftJoin('organizations as o', 'o.party_id', '=', 'customers.party_id')->leftJoin('persons as pe', 'pe.party_id', '=', 'customers.party_id')
            ->whereIn('customers.id', $ids('Customer'))->get(['customers.id', 'customers.party_id', DB::raw("{$partyName} AS name")])->keyBy('id');
        $contracts = DB::table('contracts')->whereIn('id', $ids('Contract'))->pluck('contract_number', 'id');
        $projects = DB::table('projects')->whereIn('id', $ids('Project'))->pluck('code', 'id');
        $orders = DB::table('work_orders')->whereIn('id', $ids('WorkOrder'))->pluck('code', 'id');
        $deliverables = DB::table('deliverables')->join('projects', 'projects.id', '=', 'deliverables.project_id')
            ->whereIn('deliverables.id', $ids('Deliverable'))->get(['deliverables.id', 'deliverables.name', 'deliverables.project_id', 'projects.code'])->keyBy('id');
        $items = DB::table('work_order_checklist_items')->join('work_orders', 'work_orders.id', '=', 'work_order_checklist_items.work_order_id')
            ->whereIn('work_order_checklist_items.id', $ids('WorkOrderChecklistItem'))
            ->get(['work_order_checklist_items.id', 'work_order_checklist_items.label', 'work_order_checklist_items.work_order_id', 'work_orders.code'])->keyBy('id');

        // Lo que la persona ve, por los modelos con alcance.
        $visibleContracts = $can('contracts.view') ? Contract::query()->whereIn('id', $ids('Contract'))->pluck('id')->flip() : collect();
        $projectIds = [...$ids('Project'), ...$deliverables->pluck('project_id')->all()];
        $visibleProjects = $can('projects.view') ? Project::query()->whereIn('id', $projectIds)->pluck('id')->flip() : collect();
        $orderIds = [...$ids('WorkOrder'), ...$items->pluck('work_order_id')->all()];
        $visibleOrders = $can('work_orders.view') ? WorkOrder::query()->whereIn('id', $orderIds)->pluck('id')->flip() : collect();
        $seesParties = $can('core.view');

        return $rows->groupBy('document_id')->map(fn (Collection $links) => $links->map(function ($link) use (
            $parties, $customers, $contracts, $projects, $orders, $deliverables, $items, $visibleContracts, $visibleProjects, $visibleOrders, $seesParties, $can,
        ) {
            $id = $link->entity_id;
            [$label, $href] = match ($link->entity_type) {
                'Party' => [$parties[$id] ?? 'Cliente o actor', $seesParties ? "/actors/{$id}" : null],
                'Customer' => [$customers[$id]->name ?? 'Cliente', $seesParties && isset($customers[$id]) ? "/actors/{$customers[$id]->party_id}" : null],
                'Contract' => ['Contrato '.($contracts[$id] ?? ''), $visibleContracts->has($id) ? "/contracts/{$id}" : null],
                'Project' => ['Proyecto '.($projects[$id] ?? ''), $visibleProjects->has($id) ? "/projects/{$id}" : null],
                'WorkOrder' => [$orders[$id] ?? 'Orden de trabajo', $visibleOrders->has($id) ? "/work-orders/{$id}" : null],
                'Deliverable' => isset($deliverables[$id])
                    ? ["Entregable {$deliverables[$id]->name} · {$deliverables[$id]->code}", $visibleProjects->has($deliverables[$id]->project_id) ? "/projects/{$deliverables[$id]->project_id}" : null]
                    : ['Entregable', null],
                'WorkOrderChecklistItem' => isset($items[$id])
                    ? ["Evidencia {$items[$id]->code} · {$items[$id]->label}", $visibleOrders->has($items[$id]->work_order_id) ? "/work-orders/{$items[$id]->work_order_id}" : null]
                    : ['Evidencia de OT', null],
                'BankStatement' => ['Extracto bancario', $can('treasury.view') ? '/banks' : null],
                default => [$link->entity_type, null],
            };

            return ['type' => $link->entity_type, 'id' => $id, 'label' => trim($label), 'href' => $href];
        })->values()->all());
    }

    /**
     * ¿El documento lo ve un cliente en su portal? Tiene que estar vinculado a algo de un cliente:
     * el cliente mismo, o un contrato, proyecto u orden suyos.
     */
    public static function belongsToCustomer(string $type, string $id): bool
    {
        return match ($type) {
            'Party' => DB::table('customers')->where('party_id', $id)->exists(),
            'Customer' => DB::table('customers')->where('id', $id)->exists(),
            'Contract' => DB::table('contracts')->where('id', $id)->whereNotNull('customer_id')->exists(),
            'Project' => DB::table('projects')->where('id', $id)->whereNotNull('customer_id')->exists(),
            'WorkOrder' => DB::table('work_orders')->where('id', $id)->whereNotNull('customer_id')->exists(),
            default => false,
        };
    }
}
