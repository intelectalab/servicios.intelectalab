<?php

namespace Tests\Feature;

use App\Services\AuditCatalog;
use App\Services\VaultCrypto;
use App\Services\Webhooks;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\OperationsFixtures;
use Tests\TestCase;

/**
 * Integraciones: webhooks salientes firmados (clave en el Vault), que salen después de guardar la
 * operación, con reintentos, fallidos y reprocesar; protección de la dirección (https, 443, nunca a
 * una red interna, ni siquiera si el nombre cambia de IP); permisos, otra empresa y la base.
 */
class IntegrationsTest extends TestCase
{
    use DatabaseTransactions;
    use OperationsFixtures;

    private const ADMIN = ['integrations.view', 'integrations.manage', 'integrations.export', 'tickets.view', 'tickets.create'];

    protected function setUp(): void
    {
        parent::setUp();
        // Sin salir a internet: este nombre "resuelve" a una IP pública de ejemplo.
        Webhooks::$resolver = fn (string $host) => match ($host) {
            'hooks.ejemplo.com' => ['93.184.216.34'],
            'rebind.ejemplo.com' => ['10.0.0.8'],
            default => [],
        };
    }

    protected function tearDown(): void
    {
        Webhooks::$resolver = null;
        parent::tearDown();
    }

    private function webhook(array $data = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/integrations/webhooks', [
            'name' => 'Contabilidad externa', 'url' => 'https://hooks.ejemplo.com/erp', 'events' => ['ticket.created'], ...$data,
        ]);
    }

    private function ticket(): array
    {
        return $this->postJson('/api/v1/tickets', [
            'customer_id' => $this->makeCustomer(), 'channel' => 'PHONE', 'category' => 'INCIDENT', 'subject' => 'Aire acondicionado gotea',
        ])->assertCreated()->json();
    }

    public function test_create_a_webhook_with_its_key_in_the_vault_and_safe_address(): void
    {
        $this->makeTenant(['SOPORTE', 'INTEGRACIONES']);
        $this->as($this->makeUser('Admin', self::ADMIN));

        // Dirección: https, puerto 443, nunca interna ni con usuario; eventos del catálogo.
        $this->webhook(['url' => 'http://hooks.ejemplo.com/erp'])->assertStatus(422)->assertJsonValidationErrors('url');
        $this->webhook(['url' => 'https://localhost/erp'])->assertStatus(422)->assertJsonValidationErrors('url');
        $this->webhook(['url' => 'https://10.0.0.5/erp'])->assertStatus(422)->assertJsonValidationErrors('url');
        $this->webhook(['url' => 'https://hooks.ejemplo.com:8080/erp'])->assertStatus(422)->assertJsonValidationErrors('url');
        $this->webhook(['url' => 'https://rebind.ejemplo.com/erp'])->assertStatus(422)->assertJsonPath('errors.url.0', 'La dirección apunta a una red interna: no se permite.');
        $this->webhook(['url' => 'https://noexiste.ejemplo.com/erp'])->assertStatus(422);
        $this->webhook(['events' => ['inventado']])->assertStatus(422)->assertJsonValidationErrors('events.0');

        $created = $this->webhook()->assertCreated()->json();
        $this->assertStringStartsWith('whsec_', $created['secret']);
        $this->assertSame([true, ['ticket.created'], ['Ticket creado']], [$created['active'], $created['events'], $created['event_labels']]);

        // La clave vive cifrada en el Vault (tipo WEBHOOK), no en la tabla del webhook.
        $credential = DB::table('credential_records')->where('id', $created['credential_id'])->first();
        $this->assertSame('WEBHOOK', $credential->credential_type);
        $this->assertSame($created['secret'], VaultCrypto::revealLatest($credential->id));
        $this->assertStringNotContainsString($created['secret'], json_encode(DB::table('webhook_endpoints')->where('id', $created['id'])->first()));
        // Rotar: nueva versión; la anterior queda en el historial del Vault.
        $rotated = $this->postJson("/api/v1/integrations/webhooks/{$created['id']}/rotate")->assertOk()->json('secret');
        $this->assertNotSame($created['secret'], $rotated);
        $this->assertSame($rotated, VaultCrypto::revealLatest($credential->id));
        // A mano no se puede crear una credencial de tipo WEBHOOK.
        $this->assertSame(2, DB::table('credential_versions')->where('credential_id', $credential->id)->count());
    }

    public function test_an_event_is_sent_signed_after_saving_and_retried_until_failed(): void
    {
        $this->makeTenant(['SOPORTE', 'INTEGRACIONES']);
        $this->as($this->makeUser('Admin', self::ADMIN));
        $endpoint = $this->webhook()->assertCreated()->json();

        // El ticket deja un envío pendiente (no sale dentro de la operación).
        // El otro sistema responde con el código de $reply (se cambia entre pasos).
        $reply = 200;
        Http::fake(function () use (&$reply) {
            return Http::response($reply === 200 ? ['ok' => true] : 'caído', $reply);
        });
        $ticket = $this->ticket();
        Http::assertNothingSent();
        $delivery = DB::table('webhook_deliveries')->where('endpoint_id', $endpoint['id'])->first();
        $this->assertSame(['PENDING', 'ticket.created'], [$delivery->status, $delivery->event_type]);

        // Sale firmado: X-ERP-Signature = HMAC sha256 de "{timestamp}.{cuerpo}" con la clave.
        $this->assertSame('SENT', Webhooks::deliver($delivery->id));
        Http::assertSent(function (HttpRequest $request) use ($endpoint, $ticket) {
            $expected = 'sha256='.hash_hmac('sha256', $request->header('X-ERP-Timestamp')[0].'.'.$request->body(), $endpoint['secret']);
            $body = json_decode($request->body(), true);

            return $request->url() === 'https://hooks.ejemplo.com/erp'
                && $request->header('X-ERP-Signature')[0] === $expected
                && $request->header('X-ERP-Event')[0] === 'ticket.created'
                && $body['event'] === 'ticket.created' && $body['data']['id'] === $ticket['id'] && str_starts_with($body['data']['label'], 'TKT-');
        });

        // El mismo evento otra vez no duplica el envío.
        $auditId = DB::table('audit_logs')->where('entity_id', $ticket['id'])->where('action', 'ticket.create')->value('id');
        Webhooks::enqueue($this->tenantId, 'ticket.created', $ticket['id'], $auditId);
        $this->assertSame(1, DB::table('webhook_deliveries')->where('endpoint_id', $endpoint['id'])->count());

        // Si el otro sistema falla: reintenta con espera y a los 5 intentos queda como fallido.
        $reply = 503;
        $this->ticket();
        $failing = DB::table('webhook_deliveries')->where('endpoint_id', $endpoint['id'])->where('status', 'PENDING')->value('id');
        $this->assertSame('RETRY', Webhooks::deliver($failing));
        $row = DB::table('webhook_deliveries')->where('id', $failing)->first();
        $this->assertSame([1, 503, 'El otro sistema respondió 503'], [(int) $row->attempt_count, (int) $row->response_code, $row->last_error]);
        $this->assertTrue(now()->lt($row->next_attempt_at));
        foreach (range(2, Webhooks::MAX_ATTEMPTS) as $attempt) {
            Webhooks::deliver($failing);
        }
        $this->assertSame('DEAD', DB::table('webhook_deliveries')->where('id', $failing)->value('status'));
        $this->assertSame(5, DB::table('integration_attempts')->where('delivery_id', $failing)->count());
        $this->getJson('/api/v1/integration-deliveries?status=DEAD')->assertJsonPath('total', 1)->assertJsonPath('data.0.status', 'DEAD');

        // Reprocesar: vuelve a la cola; si ahora responde, queda entregado.
        $this->postJson("/api/v1/integration-deliveries/{$failing}/retry")->assertOk();
        $reply = 200;
        $this->assertSame('SENT', Webhooks::deliver($failing));
        $detail = $this->getJson("/api/v1/integration-deliveries/{$failing}")->assertOk();
        $this->assertCount(6, $detail->json('attempts'));
        $this->assertSame('ticket.created', $detail->json('payload.event'));
        $this->postJson("/api/v1/integration-deliveries/{$failing}/retry")->assertStatus(422);
    }

    public function test_it_never_calls_an_internal_address_even_if_the_name_changes_ip(): void
    {
        $this->makeTenant(['SOPORTE', 'INTEGRACIONES']);
        $this->as($this->makeUser('Admin', self::ADMIN));
        $this->webhook()->assertCreated();
        $this->ticket();
        // Al enviar, el nombre ya apunta a una red interna (cambio de DNS): no se llama.
        Webhooks::$resolver = fn () => ['127.0.0.1'];
        Http::fake();
        $delivery = DB::table('webhook_deliveries')->value('id');
        $this->assertSame('RETRY', Webhooks::deliver($delivery));
        Http::assertNothingSent();
        $this->assertSame('La dirección apunta a una red interna: no se permite.', DB::table('webhook_deliveries')->where('id', $delivery)->value('last_error'));
        $this->assertFalse(Webhooks::isPublic('100.64.1.1'));
        $this->assertFalse(Webhooks::isPublic('169.254.169.254'));
        $this->assertFalse(Webhooks::isPublic('::1'));
        $this->assertTrue(Webhooks::isPublic('93.184.216.34'));
    }

    public function test_only_subscribed_active_webhooks_with_the_module_on(): void
    {
        $this->makeTenant(['SOPORTE', 'INTEGRACIONES']);
        $this->as($this->makeUser('Admin', self::ADMIN));
        $other = $this->webhook(['name' => 'Solo comprobantes', 'events' => ['invoice.issued']])->assertCreated()->json();
        $off = $this->webhook(['name' => 'Apagado'])->assertCreated()->json();
        $this->postJson("/api/v1/integrations/webhooks/{$off['id']}/toggle", ['active' => false])->assertOk()->assertJsonPath('active', false);

        $this->ticket();
        $this->assertSame(0, DB::table('webhook_deliveries')->count());

        // Prueba: envía ya y devuelve el resultado.
        Http::fake(['hooks.ejemplo.com/*' => Http::response('ok', 200)]);
        $this->postJson("/api/v1/integrations/webhooks/{$other['id']}/test")->assertOk()->assertJsonPath('status', 'SENT')->assertJsonPath('event_label', 'Prueba');
        $this->postJson("/api/v1/integrations/webhooks/{$off['id']}/test")->assertStatus(422);

        // Sin el módulo: no hay envíos ni pantalla.
        DB::table('tenant_modules')->where('tenant_id', $this->tenantId)->where('module_code', 'INTEGRACIONES')->update(['enabled' => false]);
        $this->ticket();
        $this->assertSame(1, DB::table('webhook_deliveries')->count());
        $this->getJson('/api/v1/integrations')->assertForbidden();
    }

    public function test_connections_permissions_other_tenants_and_database_guards(): void
    {
        $this->makeTenant(['SOPORTE', 'INTEGRACIONES']);
        $this->as($this->makeUser('Admin', self::ADMIN));
        $endpoint = $this->webhook()->assertCreated()->json();

        $index = $this->getJson('/api/v1/integrations')->assertOk();
        $this->assertSame(['fiscal', 'mail', 'payments', 'whatsapp'], array_column($index->json('connections'), 'key'));
        $this->assertSame('PENDING_PROVIDER', $index->json('connections.2.status'));
        $this->assertCount(12, $index->json('events'));
        foreach (['integration.webhook_create', 'integration.webhook_rotate', 'integration.delivery_retry'] as $action) {
            $this->assertArrayHasKey($action, AuditCatalog::ACTIONS);
        }

        // Sin permiso: nada.
        $this->as($this->makeUser('Gerencia', ['tickets.view']));
        $this->getJson('/api/v1/integrations')->assertForbidden();

        // La base: el webhook no se borra y solo https; los intentos no se cambian.
        $this->assertDatabaseRejects(fn () => DB::table('webhook_endpoints')->where('id', $endpoint['id'])->delete());
        $this->assertDatabaseRejects(fn () => DB::table('webhook_endpoints')->where('id', $endpoint['id'])->update(['url' => 'http://hooks.ejemplo.com']));
        DB::table('integration_attempts')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'attempt_number' => 1, 'status' => 'FAILED', 'attempted_at' => now()]);
        $this->assertDatabaseRejects(fn () => DB::table('integration_attempts')->where('tenant_id', $this->tenantId)->update(['status' => 'SUCCESS']));

        // Otra empresa: no existe.
        $this->makeTenant(['SOPORTE', 'INTEGRACIONES']);
        $this->as($this->makeUser('Admin B', self::ADMIN));
        $this->getJson('/api/v1/integrations')->assertJsonPath('endpoints', []);
        $this->postJson("/api/v1/integrations/webhooks/{$endpoint['id']}/toggle", ['active' => false])->assertNotFound();
    }
}
