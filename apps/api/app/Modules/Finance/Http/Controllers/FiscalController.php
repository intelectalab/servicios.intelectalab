<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Fiscal\FiscalGateway;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Support\Billing;
use App\Modules\Finance\Support\FiscalSettings;
use App\Modules\Finance\Support\Withholding;
use App\Services\AuditHistory;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Facturación electrónica: proveedor (OSE/PSE) de la empresa, RUC emisor, series, detracciones,
 * emisión en contingencia y comunicaciones de baja. Aquí se configura; el envío a SUNAT pasa
 * siempre por `FiscalGateway`.
 */
class FiscalController extends Controller
{
    private const WITH = ['customer.party.person', 'customer.party.organization'];

    /** Indicadores y conteos de las pestañas, sobre los comprobantes que la persona alcanza. */
    public function summary(Request $request)
    {
        $counts = Invoice::query()->where('status', '<>', 'DRAFT')->where('status', '<>', 'CANCELLED')->selectRaw("
            count(*) FILTER (WHERE fiscal_status = 'ACCEPTED') AS accepted,
            count(*) FILTER (WHERE fiscal_status = 'PENDING') AS pending,
            count(*) FILTER (WHERE fiscal_status = 'REJECTED') AS rejected,
            count(*) FILTER (WHERE fiscal_status = 'CONTINGENCY') AS contingency")->toBase()->first();

        return response()->json([
            'accepted' => (int) $counts->accepted, 'pending' => (int) $counts->pending, 'rejected' => (int) $counts->rejected,
            'contingency' => (int) $counts->contingency,
            'voids' => DB::table('fiscal_void_requests')->where('tenant_id', $request->attributes->get('tenant_id'))->count(),
        ]);
    }

    /** Pestaña Configuración: proveedor, series, detracciones y lo que los clientes aún deben entregar. */
    public function settings(Request $request)
    {
        return response()->json($this->settingsPayload($request->attributes->get('tenant_id')));
    }

    public function updateSettings(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $providers = array_keys((array) config('fiscal.providers'));
        $data = $request->validate([
            'provider_code' => ['nullable', 'string', 'in:'.implode(',', $providers ?: ['__ninguno__'])],
            'tax_identifier' => ['nullable', 'regex:/^(10|15|16|17|20)\d{9}$/'],
            'environment' => ['required', 'in:BETA,PRODUCTION'],
            'detraction_enabled' => ['required', 'boolean'],
            'detraction_code' => ['required', 'in:'.implode(',', array_keys(Withholding::CODES))],
            'detraction_account' => ['nullable', 'regex:/^\d{2}-\d{3}-\d{6}$/'],
        ], [
            'provider_code.in' => 'Ese proveedor no está disponible en esta instalación.',
            'tax_identifier.regex' => 'El RUC tiene 11 dígitos y empieza con 10, 15, 16, 17 o 20.',
            'detraction_account.regex' => 'La cuenta de detracciones tiene el formato 00-000-000000.',
        ]);
        if ($request->boolean('detraction_enabled') && empty($data['detraction_account'])) {
            throw ValidationException::withMessages(['detraction_account' => ['Para aplicar detracción indica la cuenta de detracciones del Banco de la Nación.']]);
        }

        DB::transaction(function () use ($request, $tenantId, $data) {
            FiscalSettings::patch($tenantId, [
                'provider_code' => $data['provider_code'] ?? null,
                'tax_identifier' => $data['tax_identifier'] ?? null,
                'environment' => $data['environment'],
            ], ['detraction' => [
                'enabled' => (bool) $data['detraction_enabled'], 'code' => $data['detraction_code'], 'account' => $data['detraction_account'] ?? null,
            ]]);
            // El RUC emisor es el de la empresa: es el que viaja en cada comprobante.
            $companyId = $this->companyId($tenantId);
            if ($companyId && ! empty($data['tax_identifier'])) {
                DB::table('companies')->where('id', $companyId)->update(['tax_identifier' => $data['tax_identifier'], 'updated_at' => now()]);
            }
            $this->audit($request, 'fiscal.settings_update', [
                'note' => 'Detracción '.($data['detraction_enabled'] ? "activa · código {$data['detraction_code']}" : 'inactiva').' · proveedor '.($data['provider_code'] ?? 'ninguno'),
            ]);
        });

        return response()->json($this->settingsPayload($tenantId));
    }

    /**
     * Series con las que emite la empresa y, si viene de otro sistema, desde qué correlativo
     * continúa. Un correlativo solo se adelanta: nunca vuelve a un número ya usado.
     */
    public function updateSeries(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $rules = [];
        foreach (FiscalSettings::SERIES as $key => [, $label, , $pattern]) {
            $rules["series.{$key}"] = ['required', "regex:{$pattern}"];
            $rules["next.{$key}"] = ['nullable', 'integer', 'min:1', 'max:99999999'];
        }
        foreach (array_keys(FiscalSettings::CONTINGENCY_SERIES) as $key) {
            $rules["contingency_series.{$key}"] = ['required', 'regex:'.FiscalSettings::CONTINGENCY_PATTERN];
        }
        $data = $request->validate($rules, [
            'series.*.regex' => 'La serie tiene 4 caracteres: empieza con F (facturas y sus notas) o con B (boletas y sus notas).',
            'series.*.required' => 'Indica la serie.',
            'contingency_series.*.regex' => 'Una serie de contingencia es numérica y empieza con cero (p. ej. 0001).',
            'contingency_series.*.required' => 'Indica la serie de contingencia.',
        ]);

        $series = array_map('strtoupper', $data['series']);
        // El número de un comprobante (serie + correlativo) no se repite en la empresa: cada serie es de un solo uso.
        $all = [...array_values($series), ...array_values($data['contingency_series'])];
        if (count($all) !== count(array_unique($all))) {
            throw ValidationException::withMessages(['series' => ['Cada serie debe ser distinta: una misma serie no sirve para dos tipos de comprobante.']]);
        }

        DB::transaction(function () use ($request, $tenantId, $series, $data) {
            $configurationId = FiscalSettings::ensure($tenantId);
            foreach (FiscalSettings::SERIES as $key => [$type]) {
                $next = $data['next'][$key] ?? null;
                if ($next === null) {
                    continue;
                }
                DB::table('fiscal_series')->insertOrIgnore([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'fiscal_configuration_id' => $configurationId,
                    'document_type' => $type, 'series' => $series[$key], 'next_number' => 1,
                ]);
                $row = DB::table('fiscal_series')->where('tenant_id', $tenantId)->where('document_type', $type)->where('series', $series[$key])->lockForUpdate()->first(['id', 'next_number']);
                if ((int) $next < (int) $row->next_number) {
                    throw ValidationException::withMessages(["next.{$key}" => ["El próximo correlativo de {$series[$key]} no puede ser menor que {$row->next_number}: esos números ya se usaron."]]);
                }
                DB::table('fiscal_series')->where('id', $row->id)->update(['next_number' => (int) $next]);
            }
            FiscalSettings::patch($tenantId, [], ['series' => $series, 'contingency_series' => $data['contingency_series']]);
            $this->audit($request, 'fiscal.series_update', ['note' => implode(', ', $series).' · contingencia '.implode(', ', $data['contingency_series'])]);
        });

        return response()->json($this->settingsPayload($tenantId));
    }

    /** Pestaña Contingencia: modo de emisión, bitácora y comprobantes pendientes de informar. */
    public function contingency(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $settings = FiscalSettings::for($tenantId);
        $pending = Invoice::query()->with(self::WITH)->where('fiscal_status', 'CONTINGENCY')->whereNotNull('number');

        return response()->json([
            'mode' => $settings['mode'],
            'provider' => FiscalGateway::summary($tenantId),
            'pending_count' => $pending->clone()->count(),
            'invoices' => $pending->orderBy('issued_at')->limit(200)->get()->map(fn (Invoice $invoice) => [
                'id' => $invoice->id, 'invoice_number' => $invoice->invoice_number, 'customer_name' => $invoice->customer_name ?: Billing::identity($invoice->customer)['name'],
                'issued_at' => $invoice->issued_at?->toIso8601String(), 'total' => (float) $invoice->total, 'currency' => $invoice->currency, 'fiscal_status' => $invoice->fiscal_status,
            ])->values(),
            'log' => $settings['id']
                ? collect(AuditHistory::for('FiscalConfiguration', $settings['id']))->filter(fn ($entry) => str_starts_with((string) data_get($entry, 'action'), 'fiscal.contingency'))->values()
                : [],
        ]);
    }

    public function startContingency(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $reason = trim($request->validate(
            ['reason' => ['required', 'string', 'max:1000']],
            ['reason.required' => 'Indica el motivo de la contingencia (p. ej. caída del proveedor o de internet).'],
        )['reason']);
        if (FiscalSettings::for($tenantId)['mode'] === 'CONTINGENCY') {
            throw ValidationException::withMessages(['status' => ['La emisión ya está en contingencia.']]);
        }

        DB::transaction(function () use ($request, $tenantId, $reason) {
            FiscalSettings::patch($tenantId, [], ['mode' => 'CONTINGENCY']);
            $this->audit($request, 'fiscal.contingency_start', ['note' => $reason, 'mode' => 'CONTINGENCY']);
        });

        return $this->contingency($request);
    }

    /**
     * Vuelve a la emisión en línea. Lo emitido en contingencia sale hacia SUNAT por el proveedor;
     * sin proveedor conectado sigue pendiente de informar: no se da por enviado.
     */
    public function endContingency(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        if (FiscalSettings::for($tenantId)['mode'] !== 'CONTINGENCY') {
            throw ValidationException::withMessages(['status' => ['La emisión ya está en línea.']]);
        }

        DB::transaction(function () use ($request, $tenantId) {
            FiscalSettings::patch($tenantId, [], ['mode' => 'ONLINE']);
            $sent = 0;
            // Todos los de la empresa, los alcance o no quien restablece el servicio.
            foreach (Invoice::ignoringAccessScope()->where('tenant_id', $tenantId)->where('fiscal_status', 'CONTINGENCY')->whereNotNull('number')->orderBy('issued_at')->limit(500)->get() as $invoice) {
                $sent += FiscalGateway::queue($invoice, $request->user()->id) ? 1 : 0;
            }
            $this->audit($request, 'fiscal.contingency_end', [
                'note' => $sent ? "{$sent} ".($sent === 1 ? 'comprobante enviado' : 'comprobantes enviados').' a SUNAT' : 'Sin comprobantes enviados', 'mode' => 'ONLINE',
            ]);
        });

        return $this->contingency($request);
    }

    /** Pestaña Bajas: comunicaciones de baja solicitadas. */
    public function voids(Request $request)
    {
        return response()->json(
            DB::table('fiscal_void_requests as v')->leftJoin('users', 'users.id', '=', 'v.requested_by')
                ->where('v.tenant_id', $request->attributes->get('tenant_id'))
                // Por su modelo: solo las de los comprobantes que la persona alcanza.
                ->whereIn('v.invoice_id', Invoice::query()->select('invoices.id'))
                // Entre dos del mismo segundo, primero la que sigue viva o fue aceptada (un comprobante tiene una sola).
                ->orderByDesc('v.created_at')->orderByRaw("CASE v.status WHEN 'PENDING' THEN 0 WHEN 'ACCEPTED' THEN 1 ELSE 2 END")->orderByDesc('v.id')->limit(200)
                ->get(['v.id', 'v.invoice_id', 'v.invoice_number', 'v.ticket', 'v.reason', 'v.status', 'v.response', 'v.created_at', 'users.name as by'])
                ->map(fn ($void) => [
                    'id' => $void->id, 'invoice_id' => $void->invoice_id, 'invoice_number' => $void->invoice_number, 'ticket' => $void->ticket,
                    'reason' => $void->reason, 'status' => $void->status, 'response' => $void->response, 'by' => $void->by,
                    'at' => Carbon::parse($void->created_at)->toIso8601String(),
                ])->values()
        );
    }

    private function settingsPayload(string $tenantId): array
    {
        $settings = FiscalSettings::for($tenantId);
        $company = DB::table('companies')->where('id', $this->companyId($tenantId))->first(['legal_name', 'tax_identifier']);
        $registered = (array) config('fiscal.providers');
        $pending = fn (string $type) => Invoice::query()->where('withholding_type', $type)->where('withholding_status', 'PENDING')->where('status', 'ISSUED');

        return [
            'provider' => [
                ...FiscalGateway::summary($tenantId),
                'code' => $settings['provider_code'],
                // Proveedores que esta instalación tiene disponibles (su clase está escrita y registrada).
                'available' => collect($registered)->map(fn (string $class, string $code) => ['code' => $code, 'name' => app($class)->name()])->values(),
                'supports_void' => FiscalGateway::supportsVoid($tenantId),
            ],
            'tax_identifier' => $company?->tax_identifier ?: $settings['tax_identifier'],
            'legal_name' => $company?->legal_name,
            'environment' => $settings['environment'],
            'mode' => $settings['mode'],
            'series' => collect(FiscalSettings::SERIES)->map(fn (array $definition, string $key) => [
                'key' => $key,
                'label' => $definition[1],
                'series' => $settings['series'][$key],
                'contingency_series' => $settings['contingency_series'][$key] ?? null,
                'last_number' => FiscalSettings::lastNumber($tenantId, $definition[0], $settings['series'][$key]),
                'contingency_last_number' => isset($settings['contingency_series'][$key]) ? FiscalSettings::lastNumber($tenantId, $definition[0], $settings['contingency_series'][$key]) : null,
            ])->values(),
            'detraction' => [
                ...$settings['detraction'],
                'threshold' => Withholding::THRESHOLD,
                'retention_rate' => Withholding::RETENTION_RATE,
                'codes' => collect(Withholding::CODES)->map(fn (array $code, string $key) => ['code' => $key, 'name' => $code[0], 'rate' => $code[1]])->values(),
            ],
            // Constancias y certificados que los clientes aún no entregan, sobre lo que la persona alcanza.
            'pending' => [
                'detractions' => ['count' => $pending('DETRACTION')->count(), 'amount' => round((float) $pending('DETRACTION')->sum('withholding_amount'), 2)],
                'retentions' => ['count' => $pending('RETENTION')->count(), 'amount' => round((float) $pending('RETENTION')->sum('withholding_amount'), 2)],
                'invoices' => Invoice::query()->with(self::WITH)->whereNotNull('withholding_type')->where('withholding_status', 'PENDING')->where('status', 'ISSUED')
                    ->orderBy('issue_date')->limit(8)->get()->map(fn (Invoice $invoice) => [
                        'id' => $invoice->id, 'invoice_number' => $invoice->invoice_number, 'customer_name' => $invoice->customer_name ?: Billing::identity($invoice->customer)['name'],
                        'label' => Withholding::label($invoice->withholding_type, null, (float) $invoice->withholding_rate), 'amount' => (float) $invoice->withholding_amount,
                    ])->values(),
            ],
        ];
    }

    private function companyId(string $tenantId): ?string
    {
        return DB::table('companies')->where('tenant_id', $tenantId)->where('status', 'ACTIVE')->orderBy('created_at')->value('id');
    }

    private function audit(Request $request, string $action, array $metadata = []): void
    {
        $tenantId = $request->attributes->get('tenant_id');
        AuditLogger::log(
            tenantId: $tenantId, userId: $request->user()->id, action: $action,
            entityType: 'FiscalConfiguration', entityId: FiscalSettings::ensure($tenantId), metadata: $metadata,
        );
    }
}
