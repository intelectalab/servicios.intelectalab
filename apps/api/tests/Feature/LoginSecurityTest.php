<?php

namespace Tests\Feature;

use App\Mail\MfaCodeMail;
use App\Mail\PasswordResetCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LoginSecurityTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(array $attributes = []): User
    {
        return User::create($attributes + [
            'email' => 'sec-'.Str::random(8).'@erp-saas.test',
            'password_hash' => bcrypt('password123'),
            'name' => 'Test Seguridad',
            'status' => 'ACTIVE',
        ]);
    }

    /** Crea un tenant con el usuario como miembro y, si se indica, con ese rol. */
    private function makeTenantFor(User $user, string $status = 'ACTIVE', ?string $roleCode = null): string
    {
        $tenantId = (string) Str::uuid();
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'code' => 'test-'.Str::random(8),
            'name' => 'Test Tenant',
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::statement("SET app.tenant_id = '{$tenantId}'");
        DB::table('tenant_users')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($roleCode) {
            $roleId = (string) Str::uuid();
            DB::table('roles')->insert([
                'id' => $roleId,
                'tenant_id' => $tenantId,
                'code' => $roleCode,
                'name' => $roleCode,
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('user_roles')->insert([
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'role_id' => $roleId,
                'scope' => 'TENANT',
                'created_at' => now(),
            ]);
        }

        return $tenantId;
    }

    private function login(User $user, string $password = 'password123')
    {
        return $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => $password]);
    }

    public function test_account_is_locked_after_five_wrong_passwords(): void
    {
        Mail::fake();
        $user = $this->makeUser();

        for ($i = 0; $i < 4; $i++) {
            $this->login($user, 'incorrecta')->assertStatus(422)
                ->assertJsonPath('errors.email.0', 'Las credenciales no son correctas.');
        }

        $locked = $this->login($user, 'incorrecta')->assertStatus(422)->json('errors.email.0');
        $this->assertStringContainsString('bloqueada', $locked);

        // Bloqueada: ni la contraseña correcta entra.
        $this->login($user)->assertStatus(422)->assertJsonMissingPath('token');

        // Restablecer la contraseña la desbloquea.
        $this->postJson('/api/v1/forgot-password', ['email' => $user->email])->assertOk();
        $code = null;
        Mail::assertSent(PasswordResetCodeMail::class, function (PasswordResetCodeMail $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });
        $this->postJson('/api/v1/reset-password', ['email' => $user->email, 'token' => $code, 'password' => 'NuevaClave1'])
            ->assertOk();

        $this->login($user, 'NuevaClave1')->assertOk()->assertJsonStructure(['token']);
    }

    public function test_lock_applies_to_unknown_emails_too(): void
    {
        $email = 'nadie-'.Str::random(8).'@erp-saas.test';

        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/v1/login', ['email' => $email, 'password' => 'x']);
        }

        $this->assertStringContainsString('bloqueada', $response->json('errors.email.0'));
    }

    public function test_successful_login_resets_the_failed_attempts(): void
    {
        $user = $this->makeUser();

        for ($i = 0; $i < 4; $i++) {
            $this->login($user, 'incorrecta')->assertStatus(422);
        }
        $this->login($user)->assertOk();

        $this->login($user, 'incorrecta')->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Las credenciales no son correctas.');
    }

    public function test_user_of_a_suspended_tenant_cannot_log_in(): void
    {
        Mail::fake();
        $user = $this->makeUser();
        $this->makeTenantFor($user, 'SUSPENDED');

        $message = $this->login($user)->assertStatus(422)->assertJsonMissingPath('token')->json('errors.email.0');
        $this->assertStringContainsString('Test Tenant está suspendida', $message);
    }

    public function test_open_session_of_a_suspended_tenant_is_rejected(): void
    {
        $user = $this->makeUser();
        $tenantId = $this->makeTenantFor($user);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/me')->assertOk();

        DB::table('tenants')->where('id', $tenantId)->update(['status' => 'SUSPENDED']);
        $this->getJson('/api/v1/me')->assertStatus(403)
            ->assertJson(['code' => 'TENANT_BLOCKED', 'tenant_status' => 'SUSPENDED']);
    }

    public function test_user_of_a_blocked_tenant_can_still_log_out(): void
    {
        $user = $this->makeUser();
        $tenantId = $this->makeTenantFor($user);
        $token = $user->createToken('api-token')->plainTextToken;

        DB::table('tenants')->where('id', $tenantId)->update(['status' => 'CLOSED']);

        $this->withToken($token)->postJson('/api/v1/logout')->assertOk();
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_session_lasts_a_workday_unless_remember_is_checked(): void
    {
        $user = $this->makeUser();

        $this->login($user)->assertOk();
        $normal = $user->tokens()->latest('id')->first();
        $this->assertEqualsWithDelta(config('sanctum.session_minutes'), now()->diffInMinutes($normal->expires_at), 1);

        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password123', 'remember' => true])
            ->assertOk();
        $remembered = $user->tokens()->latest('id')->first();
        $this->assertEqualsWithDelta(config('sanctum.remember_minutes'), now()->diffInMinutes($remembered->expires_at), 1);
    }

    public function test_remember_survives_the_second_step(): void
    {
        Mail::fake();
        $user = $this->makeUser(['mfa_enabled' => true]);

        $mfaToken = $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password123', 'remember' => true])
            ->json('mfa_token');

        $code = null;
        Mail::assertSent(MfaCodeMail::class, function (MfaCodeMail $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        $this->postJson('/api/v1/login/mfa', ['mfa_token' => $mfaToken, 'code' => $code])->assertOk();
        $token = $user->tokens()->latest('id')->first();
        $this->assertEqualsWithDelta(config('sanctum.remember_minutes'), now()->diffInMinutes($token->expires_at), 1);
    }

    public function test_expired_session_is_rejected(): void
    {
        $user = $this->makeUser();
        $this->makeTenantFor($user);
        $token = $user->createToken('api-token', ['*'], now()->addMinutes(config('sanctum.session_minutes')));

        $this->withToken($token->plainTextToken)->getJson('/api/v1/me')->assertOk();

        $token->accessToken->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->app['auth']->forgetGuards();

        $this->withToken($token->plainTextToken)->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_each_blocked_tenant_status_has_its_own_message(): void
    {
        Mail::fake();
        $expected = [
            'SUSPENDED' => 'está suspendida',
            'PROVISIONING' => 'se está configurando',
            'MAINTENANCE' => 'está en mantenimiento',
            'CLOSED' => 'está cerrada',
        ];

        foreach ($expected as $status => $word) {
            $user = $this->makeUser();
            $this->makeTenantFor($user, $status);

            $message = $this->login($user)->assertStatus(422)->assertJsonMissingPath('token')->json('errors.email.0');
            $this->assertStringContainsString($word, $message, $status);
        }
    }

    public function test_tenant_admin_always_needs_the_second_step(): void
    {
        Mail::fake();
        $admin = $this->makeUser(['mfa_enabled' => false]);
        $this->makeTenantFor($admin, 'ACTIVE', 'ADMIN');

        $this->login($admin)->assertOk()->assertJson(['mfa_required' => true])->assertJsonMissingPath('token');
        Mail::assertSent(MfaCodeMail::class);
    }

    public function test_regular_tenant_user_without_mfa_enters_directly(): void
    {
        Mail::fake();
        $user = $this->makeUser(['mfa_enabled' => false]);
        $this->makeTenantFor($user, 'ACTIVE', 'VENTAS');

        $this->login($user)->assertOk()->assertJsonStructure(['token']);
        Mail::assertNothingSent();
    }

    public function test_super_admin_always_needs_the_second_step(): void
    {
        Mail::fake();
        $superAdmin = $this->makeUser(['mfa_enabled' => false, 'is_super_admin' => true]);

        $this->login($superAdmin)->assertOk()->assertJson(['mfa_required' => true])->assertJsonMissingPath('token');
        Mail::assertSent(MfaCodeMail::class);
    }
}
