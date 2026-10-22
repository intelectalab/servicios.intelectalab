<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\OperationsFixtures;
use Tests\TestCase;
use Throwable;

/**
 * Panel de la consola de plataforma: solo superadmin activo con sesión verificada en dos
 * pasos; lo demás (cuentas de empresa, del portal, sin 2FA) recibe 403 y queda en la
 * auditoría de plataforma. Lee con su propio usuario de base, que solo ve totales.
 */
class PlatformDashboardTest extends TestCase
{
    use DatabaseTransactions;
    use OperationsFixtures;

    // La consola escribe su auditoría con su propia conexión: también se deshace al terminar.
    protected $connectionsToTransact = [null, 'pgsql_platform'];

    private const TOP_KEYS = ['generated_at', 'tenants', 'subscriptions', 'revenue', 'plans', 'signups', 'users', 'trials', 'usage', 'health', 'audit'];

    private const USAGE_KEYS = ['tenant_id', 'code', 'name', 'status', 'plan', 'trial', 'created_at', 'users', 'users_limit', 'portal_users', 'portal_users_limit', 'customers', 'customers_limit', 'storage_mb'];

    private function superAdmin(string $status = 'ACTIVE'): User
    {
        $user = new User();
        $user->forceFill([
            'email' => 'sa-'.Str::random(8).'@erp-saas.test', 'password_hash' => bcrypt('password123'),
            'name' => 'Plataforma Prueba', 'status' => $status, 'is_super_admin' => true,
        ])->save();

        return $user;
    }

    /** Sesión real (token de Sanctum), con o sin verificación en dos pasos. */
    private function signIn(User $user, bool $mfa = true): void
    {
        $this->app['auth']->forgetGuards();
        $token = $user->createToken('api-token', ['*'], now()->addHour());
        $token->accessToken->forceFill(['mfa' => $mfa])->save();
        $this->withToken($token->plainTextToken);
    }

    private function denials(User $user): array
    {
        return DB::table('platform_audit_logs')->where('user_id', $user->id)->where('action', 'platform.access_denied')
            ->pluck('metadata')->map(fn ($m) => json_decode($m, true)['reason'])->all();
    }

    public function test_super_admin_with_verified_session_sees_only_platform_totals(): void
    {
        $this->signIn($this->superAdmin());

        $data = $this->getJson('/api/v1/superadmin/dashboard')->assertOk()->json();

        $this->assertEqualsCanonicalizing(self::TOP_KEYS, array_keys($data));
        $this->assertSame(DB::table('tenants')->count(), $data['tenants']['total']);
        $this->assertSame($data['tenants']['total'], array_sum($data['tenants']['by_status']));
        $this->assertCount($data['tenants']['total'], $data['usage']);
        $this->assertCount(12, $data['signups']);
        $this->assertSame(now()->format('Y-m'), end($data['signups'])['month']);
        $this->assertSame($data['subscriptions']['current'], array_sum(array_column($data['plans'], 'tenants')));
        $this->assertSame($data['subscriptions']['paid'] + $data['subscriptions']['trial'], $data['subscriptions']['current']);
        $this->assertArrayHasKey('mail', $data['health']);

        // Uso: solo conteos y límites; nada de clientes, comprobantes ni documentos.
        foreach ($data['usage'] as $row) {
            $this->assertEqualsCanonicalizing(self::USAGE_KEYS, array_keys($row));
        }
        foreach ($data['audit'] as $row) {
            $this->assertEqualsCanonicalizing(['created_at', 'action', 'entity_type', 'user_name'], array_keys($row));
        }
    }

    public function test_session_without_two_step_verification_is_rejected_and_audited(): void
    {
        $user = $this->superAdmin();
        $this->signIn($user, mfa: false);

        $this->getJson('/api/v1/superadmin/dashboard')->assertStatus(403)->assertJsonPath('code', 'MFA_REQUIRED');
        $this->assertSame(['mfa_missing'], $this->denials($user));
    }

    public function test_inactive_super_admin_is_rejected(): void
    {
        $user = $this->superAdmin('DISABLED');
        $this->signIn($user);

        $this->getJson('/api/v1/superadmin/dashboard')->assertStatus(403);
    }

    public function test_tenant_account_cannot_open_the_console(): void
    {
        $this->makeTenant();
        $admin = $this->makeUser('Administrador', ['users.view', 'audit.view']);
        $this->signIn($admin);

        $this->getJson('/api/v1/superadmin/dashboard')->assertStatus(403)->assertJsonPath('code', 'PLATFORM_FORBIDDEN');
        $this->postJson('/api/v1/superadmin/dashboard/exports', ['table' => 'usage', 'format' => 'xlsx', 'rows' => 3])->assertStatus(403);
        $this->assertSame(['not_super_admin', 'not_super_admin'], $this->denials($admin));
    }

    public function test_portal_account_cannot_open_the_console(): void
    {
        $this->makeTenant(['PORTAL']);
        $customerId = $this->makeCustomer();
        $client = User::create([
            'email' => 'cliente-'.Str::random(8).'@cliente.test', 'password_hash' => bcrypt('Cliente123'),
            'name' => 'Cliente Portal', 'status' => 'ACTIVE', 'mfa_enabled' => true,
        ]);
        DB::table('portal_users')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'user_id' => $client->id, 'customer_id' => $customerId,
            'role' => 'PORTAL_ADMIN', 'status' => 'ACTIVE', 'created_at' => now(),
        ]);
        $this->signIn($client);

        $this->getJson('/api/v1/superadmin/dashboard')->assertStatus(403);
        $this->getJson('/api/v1/superadmin/tenants')->assertStatus(403);
        $this->assertSame(['not_super_admin', 'not_super_admin'], $this->denials($client));
    }

    public function test_without_session_the_console_answers_401(): void
    {
        $this->getJson('/api/v1/superadmin/dashboard')->assertUnauthorized();
    }

    public function test_exports_are_recorded_in_the_platform_audit(): void
    {
        $user = $this->superAdmin();
        $this->signIn($user);

        $this->postJson('/api/v1/superadmin/dashboard/exports', ['table' => 'clientes', 'format' => 'xlsx', 'rows' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('table');
        $this->postJson('/api/v1/superadmin/dashboard/exports', ['table' => 'usage', 'format' => 'xlsx', 'rows' => 6, 'selection' => true])
            ->assertOk();

        // La consola escribe con su propia conexión (su transacción aún no se ve desde la de la app)
        // y el registro sale primero en la actividad reciente del panel.
        $this->assertNull(DB::table('platform_audit_logs')->where('user_id', $user->id)->where('action', 'platform.export')->first());
        $audit = $this->getJson('/api/v1/superadmin/dashboard')->assertOk()->json('audit.0');
        $this->assertSame('platform.export', $audit['action']);
    }

    public function test_the_app_user_cannot_run_the_console_function(): void
    {
        $this->assertRejected(fn () => DB::select('SELECT platform_dashboard()'));
    }

    public function test_the_console_user_cannot_read_business_data(): void
    {
        foreach (['customers', 'parties', 'invoices', 'documents', 'file_objects', 'tenant_subscriptions', 'users'] as $table) {
            $this->assertRejected(fn () => DB::connection('pgsql_platform')->select("SELECT 1 FROM {$table} LIMIT 1"), 'pgsql_platform');
        }
        $this->assertRejected(fn () => DB::connection('pgsql_platform')->select('SELECT * FROM superadmin_companies(gen_random_uuid())'), 'pgsql_platform');
        $this->assertRejected(fn () => DB::connection('pgsql_platform')->table('platform_audit_logs')->delete(), 'pgsql_platform');
    }

    private function assertRejected(callable $attempt, ?string $connection = null): void
    {
        try {
            DB::connection($connection)->transaction($attempt);
            $this->fail('La base aceptó una lectura que debía rechazar.');
        } catch (Throwable $e) {
            $this->assertInstanceOf(QueryException::class, $e);
            $this->assertStringContainsString('permission denied', $e->getMessage());
        }
    }
}
