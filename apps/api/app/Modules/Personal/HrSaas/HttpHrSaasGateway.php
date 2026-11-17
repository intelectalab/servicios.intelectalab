<?php

namespace App\Modules\Personal\HrSaas;

use App\Services\UnsafeWebhookTarget;
use App\Services\VaultCrypto;
use App\Services\Webhooks;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Llamadas reales al SaaS de RRHH: token de servicio de la empresa (Bearer) y, en los POST, firma
 * HMAC del cuerpo con la clave de la conexión. Solo https a direcciones públicas, sin redirecciones
 * y a la IP ya comprobada (como los webhooks salientes).
 */
class HttpHrSaasGateway implements HrSaasGateway
{
    private const LISTS = ['employees', 'positions', 'leaves'];

    public function fetch(object $connection, string $list, int $page = 1, array $query = []): array
    {
        if (! in_array($list, self::LISTS, true)) {
            throw new RuntimeException('Lista desconocida del SaaS de RRHH.');
        }
        $url = rtrim($connection->base_url, '/')."/api/v1/erp-sync/{$list}";
        $target = Webhooks::safeTarget($url);

        $response = Http::withToken($this->token($connection))
            ->withHeaders(['Accept' => 'application/json', 'User-Agent' => 'IntelectaERP-HR/1.0'])
            ->withOptions(['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => ["{$target['host']}:443:{$target['ip']}"]]])
            ->connectTimeout(5)->timeout(20)
            ->get($url, [...$query, 'page' => $page]);

        if (! $response->successful()) {
            throw new RuntimeException("El SaaS de RRHH respondió {$response->status()} al leer {$list}.");
        }
        $json = $response->json();
        $items = $json['data'] ?? (array_is_list($json ?? []) ? $json : []);
        $last = $json['last_page'] ?? $json['meta']['last_page'] ?? null;
        $next = $json['next_page'] ?? ($last !== null && $page < (int) $last ? $page + 1 : null);

        return ['items' => is_array($items) ? array_values($items) : [], 'next_page' => $next ? (int) $next : null];
    }

    public function send(object $connection, array $envelope): array
    {
        $url = rtrim($connection->base_url, '/').'/api/v1/erp-sync/events';
        try {
            $target = Webhooks::safeTarget($url);
            $secret = $connection->secret_credential_id ? VaultCrypto::revealLatest($connection->secret_credential_id) : null;
            if (! $secret) {
                throw new RuntimeException('La conexión no tiene clave de firma.');
            }
            $body = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $response = Http::withToken($this->token($connection))
                ->withHeaders([
                    'Accept' => 'application/json', 'User-Agent' => 'IntelectaERP-HR/1.0',
                    'X-Event-Id' => $envelope['event_id'],
                    'X-Signature' => 'sha256='.hash_hmac('sha256', $body, $secret),
                ])
                ->withOptions(['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => ["{$target['host']}:443:{$target['ip']}"]]])
                ->connectTimeout(5)->timeout(20)
                ->withBody($body, 'application/json')->post($url);

            $ok = $response->successful() && ($response->json('ack') === true);

            return [
                'ok' => $ok, 'code' => $response->status(), 'body' => Str::limit($response->body(), 900, '…'),
                'error' => $ok ? null : ($response->successful() ? 'El SaaS de RRHH no confirmó el evento (falta "ack": true).' : "El SaaS de RRHH respondió {$response->status()}."),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'code' => null, 'body' => null, 'error' => $e instanceof UnsafeWebhookTarget || $e instanceof RuntimeException
                ? $e->getMessage() : 'No se pudo conectar con el SaaS de RRHH.'];
        }
    }

    private function token(object $connection): string
    {
        $token = $connection->token_credential_id ? VaultCrypto::revealLatest($connection->token_credential_id) : null;
        if (! $token) {
            throw new RuntimeException('La conexión no tiene token de servicio.');
        }

        return $token;
    }
}
