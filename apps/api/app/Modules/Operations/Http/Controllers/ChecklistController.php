<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Http\Requests\StoreChecklistRequest;
use App\Modules\Operations\Models\Checklist;
use App\Modules\Operations\Models\ChecklistItem;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChecklistController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(Checklist::with('items')->paginate($request->integer('per_page', 20)));
    }

    public function show(string $checklist)
    {
        return response()->json(Checklist::with('items')->findOrFail($checklist));
    }

    public function store(StoreChecklistRequest $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        $checklist = DB::transaction(function () use ($request, $tenantId) {
            $checklist = Checklist::create([
                'tenant_id' => $tenantId,
                'name' => $request->name,
                'description' => $request->description,
                'active' => true,
            ]);

            foreach ($request->items as $position => $item) {
                ChecklistItem::create([
                    'tenant_id' => $tenantId,
                    'checklist_id' => $checklist->id,
                    'position' => $position + 1,
                    'label' => $item['label'],
                    'required' => $item['required'] ?? false,
                ]);
            }

            AuditLogger::log(
                tenantId: $tenantId,
                userId: $request->user()->id,
                action: 'checklist.create',
                entityType: 'Checklist',
                entityId: $checklist->id,
            );

            return $checklist;
        });

        return response()->json($checklist->load('items'), 201);
    }
}