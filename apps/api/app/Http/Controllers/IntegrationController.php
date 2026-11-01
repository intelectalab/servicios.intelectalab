<?php

namespace App\Http\Controllers;

use App\Modules\Credentials\Models\CredentialAccessLog;
use App\Modules\Credentials\Models\CredentialRecord;
use App\Modules\Finance\Fiscal\FiscalGateway;
use App\Services\AuditLogger;
use App\Services\ColumnFilters;
use App\Services\ListSort;
use App\Services\UnsafeWebhookTarget;
use App\Services\VaultCrypto;
use App\Services\Webhooks;
use App\Services\Workflows;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Integraciones (Control → Integraciones): el estado real de las conexiones y los webhooks
 * salientes (crear, editar, activar, rotar la clave, enviar una prueba) con su cola de envíos y
 * los fallidos para reprocesar. El envío está en App\Services\Webhooks.
 */
class IntegrationController extends Controller
{
    public const STATES = ['PENDING' => 'Pendiente', 'RETRY' => 'Reintentando', 'SENT' => 'Entregado', 'DEAD' => 'Fallido'];

    public function index(Request $request)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $fiscal = FiscalGateway::summary($tenantId);
        $mailer = (string) config('mail.default');

        $endpoints = DB::table('webhook_endpoints as e')->leftJoin('users as u', 'u.id', '=', 'e.created_by')
            ->where('e.tenant_id', $tenantId)->orderByDesc('e.active')->orderBy('e.name')
            ->get(['e.*', 'u.name as creator',
                DB::raw("(SELECT count(*) FROM webhook_deliveries d WHERE d.endpoint_id = e.id AND d.status = 'SENT') AS sent"),
                DB::raw("(SELECT count(*) FROM webhook_deliveries d WHERE d.endpoint_id = e.id AND d.status = 'DEAD') AS dead"),
                DB::raw("(SELECT count(*) FROM webhook_deliveries d WHERE d.endpoint_id = e.id AND d.status IN ('PENDING', 'RETRY')) AS queued"),
                DB::raw('(SELECT max(d.delivered_at) FROM webhook_deliveries d WHERE d.endpoint_id = e.id) AS last_delivered_at')]);

        $counts = DB::table('webhook_deliveries')->where('tenant_id', $tenantId)->selectRaw('status, count(*) AS n')->groupBy('status')->pluck('n', 'status');

        // Actividad real de las últimas 24 horas (como "Últimas 24 h" del prototipo).
        $since = now()->subDay();
        $fiscal24 = DB::table('invoices')->where('tenant_id', $tenantId)->where('updated_at', '>=', $since)
            ->whereIn('fiscal_status', ['PENDING', 'ACCEPTED', 'REJECTED'])
            ->selectRaw("count(*) AS calls, count(*) FILTER (WHERE fiscal_status = 'REJECTED') AS errors")->first();
        $mail24 = DB::table('notifications')->where('tenant_id', $tenantId)->where('created_at', '>=', $since)
            ->whereRaw("metadata->>'email' = 'true'")->count();
        $mailOn = ! in_array($mailer, ['log', 'array'], true);
        $environment = DB::table('fiscal_configurations')->where('tenant_id', $tenantId)->where('active', true)->value('environment');

        $connections = [
            [
                'key' => 'fiscal', 'name' => 'Emisión electrónica SUNAT', 'status' => $fiscal['connected'] ? 'ACTIVE' : 'PENDING_SETUP',
                'provider' => $fiscal['connected'] ? "{$fiscal['name']}".($environment ? ' · '.($environment === 'PRODUCTION' ? 'producción' : 'pruebas') : '') : 'OSE/PSE por configurar',
                'events' => ['invoice.issued', 'credit_note.issued'], 'credential' => $fiscal['connected'] ? 'Certificado digital del proveedor' : null,
                'calls' => (int) $fiscal24->calls, 'errors' => (int) $fiscal24->errors,
                'note' => $fiscal['connected'] ? null : 'Sin proveedor conectado: los comprobantes se emiten sin enviar a SUNAT.',
                'link' => ['href' => '/fiscal', 'label' => 'Facturación electrónica'],
            ],
            [
                'key' => 'mail', 'name' => 'Email transaccional', 'status' => $mailOn ? 'ACTIVE' : 'PENDING_SETUP',
                'provider' => $mailOn ? 'Servidor de correo de la plataforma' : 'Sin servidor de correo',
                'events' => ['notification.email'], 'credential' => $mailOn ? 'SMTP (lo administra la plataforma)' : null,
                'calls' => $mail24, 'errors' => null,
                'note' => $mailOn ? null : 'La plataforma no tiene un servidor de correo configurado: los correos no salen.',
                'link' => null,
            ],
            [
                'key' => 'payments', 'name' => 'Pasarela de pagos', 'status' => 'PENDING_PROVIDER', 'provider' => 'Proveedor por definir',
                'events' => [], 'credential' => null, 'calls' => null, 'errors' => null,
                'note' => 'Los cobros se registran a mano en Finanzas.', 'link' => null,
            ],
            [
                'key' => 'whatsapp', 'name' => 'WhatsApp Business', 'status' => 'PENDING_PROVIDER', 'provider' => 'Proveedor por definir',
                'events' => [], 'credential' => null, 'calls' => null, 'errors' => null,
                'note' => 'Para enviar avisos y comprobantes por WhatsApp.', 'link' => null,
            ],
        ];

        // Cada webhook es una conexión más ("Webhook · Contabilidad externa" en el prototipo).
        $attempts24 = DB::table('integration_attempts as a')->join('webhook_deliveries as d', 'd.id', '=', 'a.delivery_id')
            ->where('a.tenant_id', $tenantId)->where('a.attempted_at', '>=', $since)
            ->selectRaw("d.endpoint_id, count(*) AS calls, count(*) FILTER (WHERE a.status <> 'SUCCESS') AS errors")
            ->groupBy('d.endpoint_id')->get()->keyBy('endpoint_id');
        foreach ($endpoints as $endpoint) {
            $stats = $attempts24->get($endpoint->id);
            $connections[] = [
                'key' => "webhook:{$endpoint->id}", 'name' => "Webhook · {$endpoint->name}", 'status' => $endpoint->active ? 'ACTIVE' : 'DISABLED',
                'provider' => 'Webhook saliente firmado (HMAC)',
                'events' => json_decode((string) $endpoint->event_types, true) ?: [], 'credential' => 'Clave de webhook (Vault)',
                'calls' => (int) ($stats->calls ?? 0), 'errors' => (int) ($stats->errors ?? 0),
                'note' => null, 'link' => null, 'endpoint_id' => $endpoint->id,
            ];
        }

        return response()->json([
            'connections' => $connections,
            'endpoints' => $endpoints->map(fn ($endpoint) => $this->endpointRow($endpoint)),
            'events' => collect(Workflows::TRIGGERS)->map(fn (array $t, string $key) => ['key' => $key, 'label' => $t[0]])->values(),
            'counts' => collect(self::STATES)->map(fn ($label, $key) => (int) ($counts[$key] ?? 0)),
            'max_attempts' => Webhooks::MAX_ATTEMPTS,
        ]);
    }

    /** Nuevo webhook: la clave de firma se genera aquí, se guarda cifrada en el Vault y se muestra una sola vez. */
    public function store(Request $request)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $data = $this->validated($request);
        $secret = 'whsec_'.bin2hex(random_bytes(24));

        $id = DB::transaction(function () use ($request, $tenantId, $data, $secret) {
            $credential = CredentialRecord::create([
                'tenant_id' => $tenantId, 'name' => "Clave del webhook «{$data['name']}»", 'credential_type' => 'WEBHOOK',
                'owner_label' => 'Integraciones', 'status' => 'ACTIVE', 'created_by' => $request->user()->id,
            ]);
            VaultCrypto::storeVersion($tenantId, $credential->id, $secret, $request->user()->id, 1, 'Alta del webhook');
            CredentialAccessLog::create(['tenant_id' => $tenantId, 'credential_id' => $credential->id, 'user_id' => $request->user()->id, 'action' => 'create', 'success' => true, 'reason' => 'Alta del webhook']);

            $id = (string) Str::uuid();
            DB::table('webhook_endpoints')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'name' => $data['name'], 'url' => $data['url'], 'description' => $data['description'],
                'secret_credential_id' => $credential->id, 'active' => true, 'event_types' => json_encode($data['events']),
                'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            AuditLogger::log(tenantId: $tenantId, userId: $request->user()->id, action: 'integration.webhook_create', entityType: 'WebhookEndpoint', entityId: $id,
                afterData: ['name' => $data['name'], 'url' => $data['url'], 'events' => $data['events']]);

            return $id;
        });

        return response()->json([...$this->endpoint($id, $tenantId), 'secret' => $secret], 201);
    }

    public function update(Request $request, string $endpoint)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $before = $this->find($endpoint, $tenantId);
        $data = $this->validated($request);

        DB::transaction(function () use ($request, $tenantId, $before, $data) {
            DB::table('webhook_endpoints')->where('id', $before->id)->update([
                'name' => $data['name'], 'url' => $data['url'], 'description' => $data['description'],
                'event_types' => json_encode($data['events']), 'updated_at' => now(),
            ]);
            AuditLogger::log(tenantId: $tenantId, userId: $request->user()->id, action: 'integration.webhook_update', entityType: 'WebhookEndpoint', entityId: $before->id,
                beforeData: ['name' => $before->name, 'url' => $before->url, 'events' => json_decode((string) $before->event_types, true)],
                afterData: ['name' => $data['name'], 'url' => $data['url'], 'events' => $data['events']]);
        });

        return response()->json($this->endpoint($before->id, $tenantId));
    }

    public function toggle(Request $request, string $endpoint)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $active = $request->validate(['active' => ['required', 'boolean']])['active'];
        $row = $this->find($endpoint, $tenantId);
        if ((bool) $row->active !== (bool) $active) {
            DB::transaction(function () use ($request, $tenantId, $row, $active) {
                DB::table('webhook_endpoints')->where('id', $row->id)->update(['active' => $active, 'updated_at' => now()]);
                // Al activarlo, lo que esperaba sale en el próximo minuto.
                if ($active) {
                    DB::table('webhook_deliveries')->where('endpoint_id', $row->id)->whereIn('status', ['PENDING', 'RETRY'])->update(['next_attempt_at' => now(), 'updated_at' => now()]);
                }
                AuditLogger::log(tenantId: $tenantId, userId: $request->user()->id, action: $active ? 'integration.webhook_activate' : 'integration.webhook_deactivate',
                    entityType: 'WebhookEndpoint', entityId: $row->id);
            });
        }

        return response()->json($this->endpoint($row->id, $tenantId));
    }

    /** Nueva clave de firma (nueva versión en el Vault); se muestra una sola vez. */
    public function rotate(Request $request, string $endpoint)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $row = $this->find($endpoint, $tenantId);
        $secret = 'whsec_'.bin2hex(random_bytes(24));

        DB::transaction(function () use ($request, $tenantId, $row, $secret) {
            $version = (int) DB::table('credential_versions')->where('credential_id', $row->secret_credential_id)->max('version') + 1;
            VaultCrypto::storeVersion($tenantId, $row->secret_credential_id, $secret, $request->user()->id, $version, 'Rotación desde Integraciones');
            CredentialAccessLog::create(['tenant_id' => $tenantId, 'credential_id' => $row->secret_credential_id, 'user_id' => $request->user()->id, 'action' => 'rotate', 'success' => true, 'reason' => 'Rotación desde Integraciones']);
            AuditLogger::log(tenantId: $tenantId, userId: $request->user()->id, action: 'integration.webhook_rotate', entityType: 'WebhookEndpoint', entityId: $row->id, metadata: ['version' => $version]);
        });

        return response()->json(['secret' => $secret]);
    }

    /** Envía ya un evento de prueba a ese webhook y devuelve qué respondió. */
    public function test(Request $request, string $endpoint)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $row = $this->find($endpoint, $tenantId);
        if (! $row->active) {
            throw ValidationException::withMessages(['endpoint' => ['Activa el webhook para enviarle una prueba.']]);
        }
        $eventId = Webhooks::enqueue($tenantId, 'ping', (string) Str::uuid(), 'ping-'.Str::uuid(), $row->id);
        $deliveryId = DB::table('webhook_deliveries')->where('event_id', $eventId)->value('id');
        $status = $deliveryId ? Webhooks::deliver((string) $deliveryId) : 'MISSING';
        AuditLogger::log(tenantId: $tenantId, userId: $request->user()->id, action: 'integration.webhook_test', entityType: 'WebhookEndpoint', entityId: $row->id, metadata: ['result' => $status]);

        return response()->json($deliveryId ? $this->deliveryRow(DB::table('webhook_deliveries as d')->join('webhook_endpoints as e', 'e.id', '=', 'd.endpoint_id')->where('d.id', $deliveryId)->first(['d.*', 'e.name as endpoint'])) : null);
    }

    /** Cola de envíos (y Fallidos con status=DEAD), con filtros, orden y exportación. */
    public function deliveries(Request $request)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $query = DB::table('webhook_deliveries as d')->join('webhook_endpoints as e', 'e.id', '=', 'd.endpoint_id')
            ->where('d.tenant_id', $tenantId)
            ->when(isset(self::STATES[$request->query('status')]), fn ($q) => $q->where('d.status', $request->query('status')))
            ->when($request->query('status') === 'QUEUED', fn ($q) => $q->whereIn('d.status', ['PENDING', 'RETRY']))
            ->when(Str::isUuid((string) $request->query('endpoint_id')), fn ($q) => $q->where('d.endpoint_id', $request->query('endpoint_id')))
            ->select(['d.*', 'e.name as endpoint']);

        ColumnFilters::apply($query, $request, [
            'endpoint' => ColumnFilters::text('e.name'),
            'record' => ColumnFilters::text('d.entity_label'),
            'error' => ColumnFilters::text('d.last_error'),
            'date' => ColumnFilters::date("(d.created_at AT TIME ZONE 'America/Lima')"),
        ]);
        ListSort::apply($query, $request, [
            'endpoint' => 'lower(e.name)', 'record' => 'lower(d.entity_label)', 'status' => 'd.status',
            'attempts' => 'd.attempt_count', 'date' => 'd.created_at', 'next' => 'd.next_attempt_at',
        ], fn ($q) => $q->orderByDesc('d.created_at'), 'd.id');

        $page = $query->paginate(min(max($request->integer('per_page', 20), 1), 200));
        $page->getCollection()->transform(fn ($row) => $this->deliveryRow($row));

        return response()->json($page);
    }

    /** Un envío con el cuerpo que se mandó y cada intento con su respuesta. */
    public function delivery(Request $request, string $delivery)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        abort_unless(Str::isUuid($delivery), 404);
        $row = DB::table('webhook_deliveries as d')->join('webhook_endpoints as e', 'e.id', '=', 'd.endpoint_id')
            ->where('d.tenant_id', $tenantId)->where('d.id', $delivery)->first(['d.*', 'e.name as endpoint', 'e.url']) ?? abort(404);

        return response()->json([
            ...$this->deliveryRow($row),
            'url' => $row->url,
            'payload' => json_decode((string) DB::table('outbox_events')->where('id', $row->event_id)->value('payload'), true),
            'attempts' => DB::table('integration_attempts')->where('delivery_id', $row->id)->orderByDesc('attempted_at')
                ->get(['attempt_number', 'status', 'response_code', 'response_body', 'error_code', 'duration_ms', 'attempted_at'])
                ->map(fn ($a) => [
                    'number' => (int) $a->attempt_number, 'ok' => $a->status === 'SUCCESS', 'code' => $a->response_code,
                    'body' => $a->response_body, 'error' => $a->error_code, 'ms' => $a->duration_ms, 'at' => $this->iso($a->attempted_at),
                ]),
        ]);
    }

    /** Reprocesar un fallido (o adelantar un reintento): vuelve a la cola con sus intentos de nuevo. */
    public function retry(Request $request, string $delivery)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        abort_unless(Str::isUuid($delivery), 404);
        $row = DB::table('webhook_deliveries')->where('tenant_id', $tenantId)->where('id', $delivery)->first() ?? abort(404);
        if (! in_array($row->status, ['DEAD', 'RETRY'], true)) {
            throw ValidationException::withMessages(['status' => ['Solo se reprocesa un envío fallido o en espera de reintento.']]);
        }

        DB::transaction(function () use ($request, $tenantId, $row) {
            DB::table('webhook_deliveries')->where('id', $row->id)->update(['status' => 'RETRY', 'attempt_count' => 0, 'next_attempt_at' => now(), 'updated_at' => now()]);
            AuditLogger::log(tenantId: $tenantId, userId: $request->user()->id, action: 'integration.delivery_retry', entityType: 'WebhookEndpoint', entityId: $row->endpoint_id,
                metadata: ['delivery' => $row->id, 'from' => $row->status, 'event' => $row->event_type]);
        });

        return response()->json(['status' => 'RETRY']);
    }

    // ------------------------------------------------------------------------------------------

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'url' => ['required', 'string', 'max:500', 'url:https'],
            'description' => ['nullable', 'string', 'max:300'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['required', Rule::in(array_keys(Workflows::TRIGGERS))],
        ], ['url.url' => 'La dirección debe ser una URL https válida.', 'events.required' => 'Elige al menos un evento.'], ['name' => 'nombre', 'url' => 'dirección', 'events' => 'eventos']);

        // La misma protección que al enviar: https al 443 y nunca a una red interna.
        try {
            Webhooks::safeTarget($data['url']);
        } catch (UnsafeWebhookTarget $e) {
            throw ValidationException::withMessages(['url' => [$e->getMessage()]]);
        }

        return [
            'name' => trim($data['name']),
            'url' => trim($data['url']),
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'events' => array_values(array_unique($data['events'])),
        ];
    }

    private function find(string $id, string $tenantId): object
    {
        abort_unless(Str::isUuid($id), 404);

        return DB::table('webhook_endpoints')->where('tenant_id', $tenantId)->where('id', $id)->first() ?? abort(404);
    }

    private function endpoint(string $id, string $tenantId): array
    {
        $row = DB::table('webhook_endpoints as e')->leftJoin('users as u', 'u.id', '=', 'e.created_by')->where('e.id', $id)->where('e.tenant_id', $tenantId)
            ->first(['e.*', 'u.name as creator',
                DB::raw("(SELECT count(*) FROM webhook_deliveries d WHERE d.endpoint_id = e.id AND d.status = 'SENT') AS sent"),
                DB::raw("(SELECT count(*) FROM webhook_deliveries d WHERE d.endpoint_id = e.id AND d.status = 'DEAD') AS dead"),
                DB::raw("(SELECT count(*) FROM webhook_deliveries d WHERE d.endpoint_id = e.id AND d.status IN ('PENDING', 'RETRY')) AS queued"),
                DB::raw('(SELECT max(d.delivered_at) FROM webhook_deliveries d WHERE d.endpoint_id = e.id) AS last_delivered_at')]);

        return $this->endpointRow($row);
    }

    private function endpointRow(object $e): array
    {
        $events = json_decode((string) $e->event_types, true) ?: [];

        return [
            'id' => $e->id,
            'name' => $e->name,
            'url' => $e->url,
            'description' => $e->description,
            'active' => (bool) $e->active,
            'events' => $events,
            'event_labels' => array_map(fn ($key) => Workflows::TRIGGERS[$key][0] ?? $key, $events),
            'credential_id' => $e->secret_credential_id,
            'sent' => (int) $e->sent,
            'dead' => (int) $e->dead,
            'queued' => (int) $e->queued,
            'last_delivered_at' => $this->iso($e->last_delivered_at),
            'creator' => $e->creator,
            'created_at' => $this->iso($e->created_at),
        ];
    }

    private function deliveryRow(object $d): array
    {
        return [
            'id' => $d->id,
            'endpoint_id' => $d->endpoint_id,
            'endpoint' => $d->endpoint,
            'event' => $d->event_type,
            'event_label' => $d->event_type === 'ping' ? 'Prueba' : (Workflows::TRIGGERS[$d->event_type][0] ?? $d->event_type),
            'record' => $d->entity_label,
            'status' => $d->status,
            'attempts' => (int) $d->attempt_count,
            'response_code' => $d->response_code,
            'error' => $d->last_error,
            'next_attempt_at' => $this->iso($d->next_attempt_at),
            'delivered_at' => $this->iso($d->delivered_at),
            'created_at' => $this->iso($d->created_at),
        ];
    }

    private function iso($value): ?string
    {
        return $value ? Carbon::parse($value)->toIso8601String() : null;
    }
}
