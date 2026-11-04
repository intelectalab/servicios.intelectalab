<?php

namespace App\Modules\Subscription\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Subscription\Models\SaasPlan;
use App\Modules\Subscription\Support\ModuleCatalog;
use App\Modules\Subscription\Support\PlanLimits;
use App\Modules\Subscription\Support\PlatformSettings;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Administración → Módulos y plan, para el administrador de la empresa: su plan y vigencia, sus
 * módulos (apagar o encender los que su plan incluye; apagar no borra nada), el uso frente a los
 * límites, los pagos de la suscripción y quién cambió cada módulo. Cambiar de plan o agregar un
 * módulo fuera del plan lo hace la plataforma, a pedido.
 */
class TenantPlanController extends Controller
{
    /** Lo guardado en cada módulo, para mostrarlo en su tarjeta: [tabla, singular, plural]. */
    private const MODULE_DATA = [
        'SERVICIOS' => [['services', 'servicio', 'servicios']],
        'COTIZACIONES' => [['quotations', 'cotización', 'cotizaciones']],
        'CONTRATOS' => [['contracts', 'contrato', 'contratos']],
        'ORDENES_TRABAJO' => [['work_orders', 'orden de trabajo', 'órdenes de trabajo']],
        'PROYECTOS' => [['projects', 'proyecto', 'proyectos']],
        'TIMESHEETS' => [['time_entries', 'registro de horas', 'registros de horas']],
        'AGENDA' => [['agenda_events', 'evento', 'eventos']],
        'FINANZAS' => [['invoices', 'comprobante', 'comprobantes']],
        'COMPRAS' => [['purchase_orders', 'orden de compra', 'órdenes de compra']],
        'CONTABILIDAD' => [['acc_journal_entries', 'asiento', 'asientos']],
        'SOPORTE' => [['support_tickets', 'ticket', 'tickets']],
        'CRM' => [['crm_leads', 'lead', 'leads'], ['crm_opportunities', 'oportunidad', 'oportunidades']],
        'PORTAL' => [['portal_users', 'usuario del portal', 'usuarios del portal']],
        'DOCUMENTOS' => [['documents', 'documento', 'documentos']],
    ];

    public function show(Request $request)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $subscription = $this->subscription($tenantId);
        $plan = $subscription ? SaasPlan::find($subscription->plan_id) : null;
        $planModules = $plan ? array_keys(ModuleCatalog::normalizePlanModules($plan->modules ?? [])) : null;

        $rows = DB::table('tenant_modules')->where('tenant_id', $tenantId)->get()->keyBy(fn ($row) => strtoupper($row->module_code));
        $catalog = DB::table('module_catalog')->where('active', true)->orderBy('display_order')->get();

        $modules = $catalog->where('is_core', false)->map(function ($module) use ($rows, $planModules, $tenantId) {
            $row = $rows->get($module->code);
            // Sin plan (empresa armada a mano por la plataforma): incluye lo que la plataforma le dejó.
            $inPlan = $planModules !== null ? in_array($module->code, $planModules, true) : $row !== null;
            $config = $row ? (json_decode((string) $row->configuration, true) ?: []) : [];
            $enabled = (bool) ($row->enabled ?? false);

            return [
                'code' => $module->code,
                'name' => $module->name,
                'description' => $module->description,
                'category' => $module->category,
                'in_plan' => $inPlan,
                'enabled' => $enabled,
                // Apagado por la propia empresa (lo puede encender) o por la plataforma/el plan.
                'disabled_by' => $enabled ? null : (($config['disabled_by'] ?? null) === 'tenant' ? 'tenant' : ($row ? 'platform' : null)),
                'since' => $enabled ? $this->iso($row->activated_at ?? null) : $this->iso($row->deactivated_at ?? null),
                'data' => $this->moduleData($module->code, $tenantId),
            ];
        })->values();

        $usage = PlanLimits::usage($tenantId);

        return response()->json([
            'tenant' => DB::table('tenants')->where('id', $tenantId)->value('name'),
            'plan' => $plan ? [
                'name' => $plan->name,
                'description' => $plan->description,
                'status' => $subscription->status,
                'is_trial' => (bool) (json_decode((string) $subscription->configuration, true)['is_trial'] ?? false),
                'starts_at' => $this->iso($subscription->starts_at),
                'ends_at' => $this->iso($subscription->ends_at),
                'monthly_price' => $plan->monthly_price !== null ? (float) $plan->monthly_price : null,
                'annual_price' => $plan->annual_price !== null ? (float) $plan->annual_price : null,
                'currency' => $plan->currency ?: 'PEN',
                'support_level' => $plan->support_level,
            ] : null,
            'core' => $catalog->where('is_core', true)->pluck('name')->values(),
            'modules' => $modules,
            'usage' => $usage,
            'payments' => DB::table('tenant_subscription_payments')->where('tenant_id', $tenantId)->orderByDesc('created_at')->limit(100)
                ->get(['id', 'amount', 'currency', 'payment_method', 'reference', 'months', 'previous_ends_at', 'new_ends_at', 'created_at'])
                ->map(fn ($payment) => [
                    'id' => $payment->id, 'amount' => (float) $payment->amount, 'currency' => $payment->currency,
                    'method' => $payment->payment_method, 'reference' => $payment->reference, 'months' => (int) $payment->months,
                    'previous_ends_at' => $this->iso($payment->previous_ends_at), 'new_ends_at' => $this->iso($payment->new_ends_at),
                    'at' => $this->iso($payment->created_at),
                ]),
            'history' => DB::table('audit_logs as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')
                ->where('a.tenant_id', $tenantId)->whereIn('a.action', ['module.enable', 'module.disable', 'plan.change_requested'])
                ->orderByDesc('a.created_at')->limit(50)->get(['a.action', 'a.metadata', 'a.created_at', 'u.name as user'])
                ->map(function ($row) {
                    $data = json_decode((string) $row->metadata, true) ?: [];

                    return ['action' => $row->action, 'module' => $data['module_name'] ?? $data['module'] ?? null, 'reason' => $data['reason'] ?? null,
                        'detail' => $data['detail'] ?? null, 'user' => $row->user, 'at' => $this->iso($row->created_at)];
                }),
            'support' => PlatformSettings::support(),
        ]);
    }

    /** Apagar o encender un módulo que el plan incluye. Apagar no borra nada; pide motivo. */
    public function toggle(Request $request, string $module)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:300'],
        ]);
        $code = strtoupper($module);
        $catalog = DB::table('module_catalog')->where('code', $code)->where('active', true)->first();
        abort_unless($catalog, 404);
        if ($catalog->is_core) {
            throw ValidationException::withMessages(['module' => ['Este módulo es parte de la base del sistema: no se apaga.']]);
        }
        if (! $data['enabled'] && trim((string) ($data['reason'] ?? '')) === '') {
            throw ValidationException::withMessages(['reason' => ['Indica por qué se apaga el módulo.']]);
        }

        $subscription = $this->subscription($tenantId);
        $plan = $subscription ? SaasPlan::find($subscription->plan_id) : null;
        $row = DB::table('tenant_modules')->where('tenant_id', $tenantId)->whereRaw('upper(module_code) = ?', [$code])->first();
        $inPlan = $plan ? in_array($code, array_keys(ModuleCatalog::normalizePlanModules($plan->modules ?? [])), true) : $row !== null;
        if (! $inPlan) {
            throw ValidationException::withMessages(['module' => ['Tu plan no incluye este módulo. Solicítalo a la plataforma.']]);
        }
        // Lo que apagó la plataforma (no la empresa) no se enciende desde aquí.
        $config = $row ? (json_decode((string) $row->configuration, true) ?: []) : [];
        if ($data['enabled'] && $row && ! $row->enabled && ($config['disabled_by'] ?? null) !== 'tenant' && ! $plan) {
            throw ValidationException::withMessages(['module' => ['Este módulo lo apagó la plataforma. Solicítalo para volver a usarlo.']]);
        }

        if ((bool) ($row->enabled ?? false) !== (bool) $data['enabled']) {
            DB::transaction(function () use ($request, $tenantId, $code, $catalog, $row, $config, $data) {
                $next = $data['enabled']
                    ? array_diff_key($config, array_flip(['disabled_by', 'disabled_by_user']))
                    : [...$config, 'disabled_by' => 'tenant', 'disabled_by_user' => $request->user()->id];
                if ($row) {
                    DB::table('tenant_modules')->where('id', $row->id)->update([
                        'enabled' => $data['enabled'],
                        'configuration' => json_encode((object) $next),
                        $data['enabled'] ? 'activated_at' : 'deactivated_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    DB::table('tenant_modules')->insert([
                        'tenant_id' => $tenantId, 'module_code' => $code, 'enabled' => $data['enabled'], 'configuration' => json_encode((object) $next),
                        'activated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                AuditLogger::log(
                    tenantId: $tenantId,
                    userId: $request->user()->id,
                    action: $data['enabled'] ? 'module.enable' : 'module.disable',
                    entityType: 'Tenant',
                    entityId: $tenantId,
                    metadata: array_filter(['module' => $code, 'module_name' => $catalog->name, 'reason' => trim((string) ($data['reason'] ?? '')) ?: null]),
                );
            });
        }

        return $this->show($request);
    }

    /** Deja constancia de que la empresa pidió un cambio de plan o un módulo (el pedido sale por WhatsApp o correo). */
    public function requestChange(Request $request)
    {
        $data = $request->validate(['detail' => ['required', 'string', 'max:300']]);
        AuditLogger::log(
            tenantId: (string) $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: 'plan.change_requested',
            entityType: 'Tenant',
            entityId: (string) $request->attributes->get('tenant_id'),
            metadata: ['detail' => trim($data['detail'])],
        );

        return response()->json(['recorded' => true]);
    }

    private function subscription(string $tenantId): ?object
    {
        return DB::table('tenant_subscriptions')->where('tenant_id', $tenantId)->where('status', 'ACTIVE')->orderByDesc('starts_at')->first();
    }

    private function moduleData(string $code, string $tenantId): ?string
    {
        $parts = [];
        foreach (self::MODULE_DATA[$code] ?? [] as [$table, $one, $many]) {
            $count = DB::table($table)->where('tenant_id', $tenantId)->count();
            $parts[] = number_format($count, 0, '.', ',').' '.($count === 1 ? $one : $many);
        }

        return $parts ? implode(' · ', $parts) : null;
    }

    private function iso($value): ?string
    {
        return $value ? Carbon::parse($value)->toIso8601String() : null;
    }
}
