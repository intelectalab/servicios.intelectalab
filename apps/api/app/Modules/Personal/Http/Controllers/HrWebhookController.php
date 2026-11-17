<?php

namespace App\Modules\Personal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Personal\HrSaas\HrSaasSync;
use App\Services\VaultCrypto;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * POST /api/v1/webhooks/hr-saas — cambios del SaaS de RRHH (contrato §3).
 *
 * - La empresa sale del ID de conexión (`X-Connection-Id`) que el ERP entregó al registrarla; el
 *   RUC del sobre (`tenant_ruc`) debe ser el de esa empresa. El SaaS de RRHH nunca manda tenant_id.
 * - Firma `X-Signature: sha256=<HMAC del cuerpo>` con la clave de la conexión.
 * - `X-Event-Id` igual al `event_id` del cuerpo; un mismo evento se registra una sola vez (si
 *   llega repetido se responde 202 sin hacer nada) y solo se aceptan eventos de los últimos
 *   5 minutos (anti-replay).
 * - Se guarda y se responde 202; se procesa después de responder (y, si algo quedó, la tarea
 *   `hr-saas:process` de cada minuto).
 *
 * Cualquier fallo de identidad o firma responde lo mismo (401), sin decir qué falló.
 */
class HrWebhookController extends Controller
{
    private const TYPES = ['employee.upserted', 'employee.terminated', 'position.upserted', 'leave.upserted'];

    public function __invoke(Request $request)
    {
        $connectionId = (string) ($request->header('X-Connection-Id') ?: $request->query('connection', ''));
        $signature = (string) $request->header('X-Signature', '');
        $eventHeader = (string) $request->header('X-Event-Id', '');
        $body = $request->getContent();
        $denied = fn () => response()->json(['message' => 'No autorizado.', 'code' => 'UNAUTHORIZED'], 401);

        if (! Str::isUuid($connectionId) || ! str_starts_with($signature, 'sha256=') || $eventHeader === '' || strlen($body) > 512 * 1024) {
            return $denied();
        }
        $tenantId = DB::selectOne('SELECT hr_connection_tenant(?) AS tenant_id', [$connectionId])?->tenant_id;
        if (! $tenantId) {
            return $denied();
        }

        return DB::transaction(function () use ($request, $tenantId, $connectionId, $signature, $eventHeader, $body, $denied) {
            DB::statement("SELECT set_config('app.tenant_id', ?, true)", [$tenantId]);
            $connection = DB::table('hr_connections')->where('id', $connectionId)->first();
            $secret = $connection?->secret_credential_id ? VaultCrypto::revealLatest($connection->secret_credential_id) : null;
            if (! $secret || ! hash_equals('sha256='.hash_hmac('sha256', $body, $secret), $signature)) {
                return $denied();
            }

            $envelope = json_decode($body, true);
            if (! is_array($envelope) || ($envelope['event_id'] ?? null) !== $eventHeader || ! in_array($envelope['event_type'] ?? '', self::TYPES, true)
                || ! is_array($envelope['data'] ?? null)) {
                return response()->json(['message' => 'Mensaje con formato inválido.', 'code' => 'INVALID_EVENT'], 422);
            }
            if (($envelope['tenant_ruc'] ?? null) !== HrSaasSync::tenantRuc($tenantId)) {
                return $denied();
            }
            try {
                $occurred = Carbon::parse((string) ($envelope['occurred_at'] ?? ''));
            } catch (Throwable) {
                return response()->json(['message' => 'occurred_at no es una fecha válida.', 'code' => 'INVALID_EVENT'], 422);
            }
            if (abs(now()->diffInSeconds($occurred, false)) > HrSaasSync::REPLAY_WINDOW_MINUTES * 60) {
                return response()->json(['message' => 'El evento está fuera de la ventana de 5 minutos: usa la relectura completa.', 'code' => 'EVENT_EXPIRED'], 409);
            }

            $inserted = DB::table('hr_inbound_events')->insertOrIgnore([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'connection_id' => $connectionId,
                'event_id' => Str::limit($eventHeader, 100, ''), 'event_type' => $envelope['event_type'], 'occurred_at' => $occurred,
                'payload' => $body, 'status' => 'RECEIVED', 'received_at' => now(),
            ]);
            if ($inserted) {
                // Se procesa después de responder, en el contexto de la empresa.
                app()->terminating(function () use ($tenantId) {
                    rescue(function () use ($tenantId) {
                        DB::transaction(function () use ($tenantId) {
                            DB::statement("SELECT set_config('app.tenant_id', ?, true)", [$tenantId]);
                            request()->attributes->set('tenant_id', $tenantId);
                            HrSaasSync::processPending($tenantId);
                        });
                    }, report: true);
                });
            }

            return response()->json(['received' => true, 'duplicate' => ! $inserted], 202);
        });
    }
}
