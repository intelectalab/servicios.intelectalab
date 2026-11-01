<?php

namespace App\Services;

use App\Modules\Subscription\Support\TenantModules;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Webhooks salientes (Control → Integraciones): avisan a otro sistema cuando pasa algo en la
 * empresa (los mismos disparadores que Workflows).
 *
 * - El evento se arma DESPUÉS de que la operación se guardó (desde la auditoría) y queda en la cola:
 *   nunca se llama a otro sistema dentro de una transacción.
 * - Cada envío va firmado: X-ERP-Signature = sha256 HMAC de "{timestamp}.{cuerpo}" con la clave del
 *   webhook, que vive en el Credential Vault.
 * - Reintentos con espera creciente (1, 5, 30 y 120 minutos); tras MAX_ATTEMPTS fallidos, a Fallidos.
 * - Protección de la dirección: solo https al puerto 443, nunca a direcciones internas (se resuelve el
 *   nombre, se comprueba cada IP y la llamada va a esa IP: no se puede cambiar entre la comprobación y
 *   el envío), sin seguir redirecciones.
 */
final class Webhooks
{
    public const MAX_ATTEMPTS = 5;

    /** Minutos de espera antes del intento 2, 3, 4 y 5. */
    private const BACKOFF = [1, 5, 30, 120];

    /** Para las pruebas: resuelve un nombre a sus IP sin salir a internet. */
    public static ?Closure $resolver = null;

    public static function audited(string $tenantId, string $action, ?string $entityId, string $auditId): void
    {
        if (! $entityId) {
            return;
        }
        $trigger = collect(Workflows::TRIGGERS)->search(fn (array $definition) => $definition[1] === $action);
        if ($trigger === false) {
            return;
        }

        DB::afterCommit(fn () => self::enqueue($tenantId, $trigger, $entityId, $auditId));
    }

    /** Arma el evento y deja un envío por cada webhook activo suscrito a él. */
    public static function enqueue(string $tenantId, string $trigger, string $entityId, string $eventKey, ?string $onlyEndpoint = null): ?string
    {
        try {
            if (! TenantModules::isEnabled($tenantId, 'INTEGRACIONES')) {
                return null;
            }
            $endpoints = DB::table('webhook_endpoints')->where('tenant_id', $tenantId)->where('active', true)
                ->when($onlyEndpoint, fn ($q) => $q->where('id', $onlyEndpoint), fn ($q) => $q->whereRaw('jsonb_exists(event_types, ?)', [$trigger]))
                ->pluck('id');
            if ($endpoints->isEmpty()) {
                return null;
            }

            if ($trigger === 'ping') {
                $data = ['label' => 'Evento de prueba', 'message' => 'Si recibes esto, el webhook está bien configurado.'];
                $entityType = 'Ping';
            } else {
                [, , $model] = Workflows::TRIGGERS[$trigger];
                $record = $model::ignoringAccessScope()->find($entityId);
                if (! $record) {
                    return null;
                }
                $payload = Workflows::payload($trigger, $record);
                $data = array_filter([
                    'id' => $entityId,
                    'label' => $payload['label'],
                    'url' => rtrim((string) config('app.frontend_url', config('app.url')), '/').$payload['link'],
                    ...array_intersect_key($payload, array_flip(['customer_id', 'amount', 'hours', 'billable', 'priority', 'category', 'channel', 'type', 'score'])),
                ], fn ($value) => $value !== null);
                $entityType = class_basename($model);
            }

            $eventId = (string) Str::uuid();
            $occurred = now();
            DB::table('outbox_events')->insertOrIgnore([
                'id' => $eventId,
                'tenant_id' => $tenantId,
                'aggregate_type' => $entityType,
                'aggregate_id' => Str::isUuid($entityId) ? $entityId : $eventId,
                'event_type' => $trigger,
                'event_key' => $eventKey,
                'payload' => json_encode([
                    'id' => $eventId,
                    'event' => $trigger,
                    'event_label' => $trigger === 'ping' ? 'Prueba' : Workflows::TRIGGERS[$trigger][0],
                    'occurred_at' => $occurred->toIso8601String(),
                    'data' => $data,
                ]),
                'correlation_id' => Str::isUuid($eventKey) ? $eventKey : null,
                'occurred_at' => $occurred,
                'published_at' => $occurred,
                'status' => 'PUBLISHED',
                'attempts' => 0,
            ]);
            // Ya estaba (el mismo evento otra vez): no se duplica.
            if (! DB::table('outbox_events')->where('id', $eventId)->exists()) {
                return null;
            }

            foreach ($endpoints as $endpointId) {
                DB::table('webhook_deliveries')->insertOrIgnore([
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $tenantId,
                    'endpoint_id' => $endpointId,
                    'event_id' => $eventId,
                    'event_type' => $trigger,
                    'entity_label' => Str::limit((string) $data['label'], 250),
                    'status' => 'PENDING',
                    'attempt_count' => 0,
                    'next_attempt_at' => $occurred,
                    'created_at' => $occurred,
                    'updated_at' => $occurred,
                ]);
            }

            return $eventId;
        } catch (Throwable $e) {
            // Nunca rompe la operación que lo originó.
            Log::warning('Webhooks: no se pudo armar el evento', ['trigger' => $trigger, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** La tarea de cada minuto: envía lo pendiente de todas las empresas (por empresa, bajo su RLS). */
    public static function deliverDue(int $perTenant = 50): int
    {
        $sent = 0;
        foreach (DB::table('tenants')->where('status', 'ACTIVE')->pluck('id') as $tenantId) {
            DB::statement("SELECT set_config('app.tenant_id', ?, false)", [(string) $tenantId]);
            $due = DB::table('webhook_deliveries')->where('tenant_id', $tenantId)->whereIn('status', ['PENDING', 'RETRY'])
                ->where('next_attempt_at', '<=', now())->orderBy('next_attempt_at')->limit($perTenant)->pluck('id');
            foreach ($due as $deliveryId) {
                self::deliver((string) $deliveryId);
                $sent++;
            }
        }
        DB::statement("SELECT set_config('app.tenant_id', '', false)");

        return $sent;
    }

    /** Un intento de envío: firma, llama (fuera de transacción), registra el intento y decide qué sigue. */
    public static function deliver(string $deliveryId): string
    {
        $delivery = DB::table('webhook_deliveries')->where('id', $deliveryId)->first();
        if (! $delivery || ! in_array($delivery->status, ['PENDING', 'RETRY'], true)) {
            return $delivery->status ?? 'MISSING';
        }
        $endpoint = DB::table('webhook_endpoints')->where('id', $delivery->endpoint_id)->first();
        $event = DB::table('outbox_events')->where('id', $delivery->event_id)->first();
        $attempt = (int) $delivery->attempt_count + 1;

        $started = microtime(true);
        $code = null;
        $body = null;
        $error = null;

        if (! $endpoint || ! $endpoint->active) {
            // Desactivado: el envío espera (al activarlo, sale).
            DB::table('webhook_deliveries')->where('id', $deliveryId)->update(['next_attempt_at' => now()->addMinutes(30), 'updated_at' => now()]);

            return 'WAITING';
        }

        try {
            $target = self::safeTarget($endpoint->url);
            $secret = $endpoint->secret_credential_id ? VaultCrypto::revealLatest($endpoint->secret_credential_id) : null;
            if (! $secret) {
                throw new \RuntimeException('El webhook no tiene clave de firma.');
            }
            $payload = (string) $event->payload;
            $timestamp = (string) now()->timestamp;

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'User-Agent' => 'ERP-Webhooks/1.0',
                'X-ERP-Event' => $delivery->event_type,
                'X-ERP-Delivery' => $deliveryId,
                'X-ERP-Timestamp' => $timestamp,
                'X-ERP-Signature' => 'sha256='.hash_hmac('sha256', "{$timestamp}.{$payload}", $secret),
            ])->withOptions([
                'allow_redirects' => false,
                // La llamada va a la IP ya comprobada (no se vuelve a resolver el nombre).
                'curl' => [CURLOPT_RESOLVE => ["{$target['host']}:443:{$target['ip']}"]],
            ])->connectTimeout(3)->timeout(8)->withBody($payload, 'application/json')->post($endpoint->url);

            $code = $response->status();
            $body = Str::limit($response->body(), 1000, '…');
            if (! $response->successful()) {
                $error = "El otro sistema respondió {$code}";
            }
        } catch (Throwable $e) {
            $error = $e instanceof UnsafeWebhookTarget ? $e->getMessage() : self::friendlyError($e);
        }

        $ok = $error === null;
        $duration = (int) round((microtime(true) - $started) * 1000);
        $status = $ok ? 'SENT' : ($attempt >= self::MAX_ATTEMPTS ? 'DEAD' : 'RETRY');

        DB::table('integration_attempts')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $delivery->tenant_id,
            'outbox_event_id' => $delivery->event_id,
            'delivery_id' => $deliveryId,
            'attempt_number' => $attempt,
            'status' => $ok ? 'SUCCESS' : 'FAILED',
            'response_code' => $code,
            'response_body' => $body,
            'error_code' => $ok ? null : Str::limit($error, 250),
            'attempted_at' => now(),
            'duration_ms' => $duration,
        ]);
        DB::table('webhook_deliveries')->where('id', $deliveryId)->update([
            'status' => $status,
            'attempt_count' => $attempt,
            'response_code' => $code,
            'last_error' => $error,
            'delivered_at' => $ok ? now() : null,
            'next_attempt_at' => $status === 'RETRY' ? now()->addMinutes(self::BACKOFF[min($attempt - 1, count(self::BACKOFF) - 1)]) : null,
            'updated_at' => now(),
        ]);
        DB::table('outbox_events')->where('id', $delivery->event_id)->update(['attempts' => DB::raw('attempts + 1')]);

        return $status;
    }

    /**
     * La dirección es segura: https, puerto 443, sin usuario ni clave, y el nombre resuelve solo a IP
     * públicas. Devuelve el nombre y la IP a la que se llamará.
     *
     * @return array{host: string, ip: string}
     */
    public static function safeTarget(string $url): array
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https' || $host === '') {
            throw new UnsafeWebhookTarget('La dirección debe empezar con https://');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeWebhookTarget('La dirección no puede llevar usuario ni clave.');
        }
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            throw new UnsafeWebhookTarget('Solo se permite el puerto 443 (https normal).');
        }
        if ($host === 'localhost' || preg_match('/\.(localhost|local|internal|lan|home|corp|test|invalid)$/', $host) || ! str_contains($host, '.')) {
            throw new UnsafeWebhookTarget('La dirección no puede ser de una red interna.');
        }

        $ips = self::$resolver ? (self::$resolver)($host) : self::resolve($host);
        if (! $ips) {
            throw new UnsafeWebhookTarget('No se encontró la dirección (el nombre no existe).');
        }
        foreach ($ips as $ip) {
            if (! self::isPublic($ip)) {
                throw new UnsafeWebhookTarget('La dirección apunta a una red interna: no se permite.');
            }
        }

        return ['host' => $host, 'ip' => $ips[0]];
    }

    public static function isPublic(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        // 100.64.0.0/10 (red compartida de proveedores) tampoco es pública.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);

            return ! ($long >= ip2long('100.64.0.0') && $long <= ip2long('100.127.255.255'));
        }

        return true;
    }

    /** IP del nombre (si ya es una IP, ella misma). */
    private static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $ips = [];
        foreach ([DNS_A, DNS_AAAA] as $type) {
            foreach (@dns_get_record($host, $type) ?: [] as $record) {
                $ips[] = $record['ip'] ?? $record['ipv6'] ?? null;
            }
        }

        return array_values(array_filter(array_unique($ips)));
    }

    private static function friendlyError(Throwable $e): string
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, 'timed out'), str_contains($message, 'Operation timed out') => 'El otro sistema no respondió a tiempo',
            str_contains($message, 'Could not resolve') => 'No se encontró la dirección',
            str_contains($message, 'Connection refused') => 'El otro sistema rechazó la conexión',
            str_contains($message, 'SSL'), str_contains($message, 'certificate') => 'El certificado https del otro sistema no es válido',
            default => Str::limit('No se pudo conectar: '.$message, 240),
        };
    }
}
