<?php

namespace App\Modules\Services\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Services\Models\Quotation;
use App\Modules\Services\Support\QuotationAuthorization;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de aprobación (Administración) y la autorización interna de cada cotización.
 */
class QuotationAuthorizationController extends Controller
{
    /** Reglas, niveles, roles de la empresa y las cotizaciones que esperan autorización (las que la persona ve). */
    public function show(Request $request)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $settings = QuotationAuthorization::settings($tenantId);

        $pending = Quotation::query()
            ->with('customer.party.organization', 'customer.party.person')
            ->where('status', 'DRAFT')
            ->whereIn('id', DB::table('quotation_authorizations')->where('tenant_id', $tenantId)->where('status', 'PENDING')->select('quotation_id'))
            ->orderBy('created_at')
            ->limit(100)
            ->get()
            ->map(function (Quotation $quotation) use ($request) {
                $summary = QuotationAuthorization::summary($quotation, $request->user()->id);

                return [
                    'id' => $quotation->id,
                    'number' => $quotation->quotation_number,
                    'customer' => $quotation->customer?->party?->organization?->legal_name
                        ?? trim(($quotation->customer?->party?->person?->given_names ?? '').' '.($quotation->customer?->party?->person?->family_name ?? '')),
                    'total' => (float) $quotation->total,
                    'currency' => $quotation->currency,
                    'reasons' => $summary['reasons'],
                    'next_level' => $summary['next_level'],
                    'required_level' => $summary['required_level'],
                ];
            });

        return response()->json([
            'levels' => $settings['levels'],
            'rules' => $settings['rules'],
            'metrics' => QuotationAuthorization::METRICS,
            'roles' => DB::table('roles')->where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name']),
            'pending' => $pending,
        ]);
    }

    public function update(Request $request)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $roleIds = DB::table('roles')->where('tenant_id', $tenantId)->pluck('id')->all();
        $data = $request->validate([
            'levels' => ['required', 'array'],
            'levels.1.name' => ['required', 'string', 'max:60'],
            'levels.1.role_ids' => ['array'],
            'levels.1.role_ids.*' => [Rule::in($roleIds)],
            'levels.2.name' => ['required', 'string', 'max:60'],
            'levels.2.role_ids' => ['array'],
            'levels.2.role_ids.*' => [Rule::in($roleIds)],
            'rules' => ['present', 'array', 'max:20'],
            'rules.*.name' => ['required', 'string', 'max:80'],
            'rules.*.metric' => ['required', Rule::in(array_keys(QuotationAuthorization::METRICS))],
            'rules.*.value' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'rules.*.level' => ['required', 'integer', 'in:1,2'],
        ], [
            'levels.*.name.required' => 'Ponle nombre a cada nivel.',
            'rules.*.name.required' => 'Ponle nombre a cada regla.',
            'rules.*.value.required' => 'Indica el valor de cada regla.',
            'levels.*.role_ids.*.in' => 'Uno de los roles no existe.',
        ]);

        foreach ($data['rules'] as $index => $rule) {
            if ($rule['metric'] === 'discount' && (float) $rule['value'] > 100) {
                throw ValidationException::withMessages(["rules.{$index}.value" => ['Un descuento va de 0 a 100 %.']]);
            }
            if (empty($data['levels'][(string) $rule['level']]['role_ids'] ?? $data['levels'][$rule['level']]['role_ids'] ?? [])) {
                throw ValidationException::withMessages(["rules.{$index}.level" => ["Elige quién autoriza el nivel {$rule['level']}: sin roles, nadie podría autorizarla."]]);
            }
        }

        DB::transaction(function () use ($request, $tenantId, $data) {
            $row = DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first(['configuration']);
            $configuration = json_decode($row->configuration ?: '{}', true) ?: [];
            $before = QuotationAuthorization::settings($tenantId);
            $configuration['approval'] = [
                'levels' => [
                    '1' => ['name' => trim($data['levels'][1]['name']), 'role_ids' => array_values(array_unique($data['levels'][1]['role_ids'] ?? []))],
                    '2' => ['name' => trim($data['levels'][2]['name']), 'role_ids' => array_values(array_unique($data['levels'][2]['role_ids'] ?? []))],
                ],
                'rules' => array_map(fn ($rule) => [
                    'name' => trim($rule['name']), 'metric' => $rule['metric'], 'value' => round((float) $rule['value'], 2), 'level' => (int) $rule['level'],
                ], $data['rules']),
            ];
            DB::table('tenants')->where('id', $tenantId)->update(['configuration' => json_encode($configuration), 'updated_at' => now()]);
            AuditLogger::log($tenantId, $request->user()->id, 'approval_rules.update', 'ApprovalRules', $tenantId,
                ['rules' => $before['rules'], 'levels' => $before['levels']], $configuration['approval']);
        });

        return $this->show($request);
    }

    public function request(Request $request, string $quotation)
    {
        $quotation = Quotation::findOrFail($quotation);
        QuotationAuthorization::request($request, $quotation);

        return response()->json(QuotationAuthorization::summary($quotation, $request->user()->id));
    }

    public function approve(Request $request, string $quotation)
    {
        $quotation = Quotation::findOrFail($quotation);
        QuotationAuthorization::decide($request, $quotation, true, $request->input('comment'));

        return response()->json(QuotationAuthorization::summary($quotation, $request->user()->id));
    }

    public function reject(Request $request, string $quotation)
    {
        $quotation = Quotation::findOrFail($quotation);
        $request->validate(['comment' => ['required', 'string', 'max:1000']], ['comment.required' => 'Indica el motivo del rechazo.']);
        QuotationAuthorization::decide($request, $quotation, false, $request->input('comment'));

        return response()->json(QuotationAuthorization::summary($quotation, $request->user()->id));
    }
}
