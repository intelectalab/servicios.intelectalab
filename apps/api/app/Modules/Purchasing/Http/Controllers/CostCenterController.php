<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Centros de costo: a qué área o proyecto se carga una compra. Mientras no exista la pantalla
 * de Contabilidad (Centros de costo y presupuestos) se dan de alta desde los formularios de Compras.
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

        $center = ['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'code' => $data['code'], 'name' => trim($data['name']), 'active' => true];
        DB::transaction(function () use ($request, $tenantId, $center) {
            DB::table('cost_centers')->insert($center);
            AuditLogger::log(
                tenantId: $tenantId, userId: $request->user()->id, action: 'cost_center.create', entityType: 'CostCenter', entityId: $center['id'],
                metadata: ['note' => "{$center['code']} · {$center['name']}"],
            );
        });

        return response()->json(['id' => $center['id'], 'code' => $center['code'], 'name' => $center['name'], 'active' => true], 201);
    }
}
