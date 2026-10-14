<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Http\Controllers\BudgetController;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Centros de costo: a qué área o proyecto se carga una compra o una venta. Se dan de alta en
 * Contabilidad (Centros de costo y presupuestos) y, rápido, desde los formularios de Compras.
 */
class CostCenterController extends Controller
{
    public function store(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/'],
            'name' => ['required', 'string', 'max:150'],
        ], [
            'code.required' => 'Código y nombre son obligatorios.',
            'code.regex' => 'El código solo lleva letras, números, punto y guion.',
            'name.required' => 'Código y nombre son obligatorios.',
        ]);

        if (DB::table('cost_centers')->where('tenant_id', $tenantId)->where('code', $data['code'])->exists()) {
            throw ValidationException::withMessages(['code' => ['El código ya existe.']]);
        }
        // Tipo, sede y responsable: los da la pantalla de Contabilidad; el alta rápida de Compras crea uno de gastos.
        $extra = BudgetController::validateCenter($request, $tenantId);

        $center = [
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'code' => $data['code'], 'name' => trim($data['name']), 'active' => true,
            'kind' => $extra['kind'] ?? 'COST', 'branch_id' => $extra['branch_id'] ?? null, 'manager_user_id' => $extra['manager_user_id'] ?? null,
            'created_by' => $request->user()->id,
        ];
        DB::transaction(function () use ($request, $tenantId, $center) {
            DB::table('cost_centers')->insert($center);
            AuditLogger::log(
                tenantId: $tenantId, userId: $request->user()->id, action: 'cost_center.create', entityType: 'CostCenter', entityId: $center['id'],
                metadata: ['note' => "{$center['code']} · {$center['name']}"],
            );
        });

        return response()->json(['id' => $center['id'], 'code' => $center['code'], 'name' => $center['name'], 'kind' => $center['kind'], 'active' => true], 201);
    }
}
