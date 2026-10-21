<?php

namespace Tests\Feature;

use App\Mail\MfaCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class MfaLoginTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(bool $mfa): User
    {
        $user = User::create([
            'email' => 'mfa-'.Str::random(8).'@erp-saas.test',
            'password_hash' => bcrypt('password123'),
            'name' => 'Test MFA',
            'status' => 'ACTIVE',
            'mfa_enabled' => $mfa,
        ]);

        // Sin acceso vigente a una empresa no se entrega sesión.
        $tenantId = (string) Str::uuid();
        DB::table('tenants')->insert([
            'id' => $tenantId, 'code' => 'test-'.Str::random(8), 'name' => 'Test Tenant', 'status' => 'ACTIVE',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement("SET app.tenant_id = '{$tenantId}'");
        DB::table('tenant_users')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'user_id' => $user->id, 'status' => 'ACTIVE',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    /** Usuario sin MFA propio con el rol Administrador de su empresa. */
    private function makeAdmin(): User
    {
        $user = $this->makeUser(false);
        $tenantId = DB::table('tenant_users')->where('user_id', $user->id)->value('tenant_id');
        $roleId = (string) Str::uuid();
        DB::table('roles')->insert(['id' => $roleId, 'tenant_id' => $tenantId, 'code' => 'admin', 'name' => 'Administrador']);
        DB::table('user_roles')->insert(['tenant_id' => $tenantId, 'user_id' => $user->id, 'role_id' => $roleId]);

        return $user;
    }

    public function test_tenant_admin_must_verify_while_the_rule_is_on(): void
    {
        Mail::fake();
        config(['auth.admin_mfa_required' => true]);
        $admin = $this->makeAdmin();

        $this->postJson('/api/v1/login', ['email' => $admin->email, 'password' => 'password123'])
            ->assertOk()
            ->assertJson(['mfa_required' => true])
            ->assertJsonMissingPath('token');
    }

    public function test_tenant_admin_follows_their_own_setting_when_the_rule_is_off(): void
    {
        Mail::fake();
        config(['auth.admin_mfa_required' => false]);

        $admin = $this->makeAdmin();
        $this->postJson('/api/v1/login', ['email' => $admin->email, 'password' => 'password123'])
            ->assertOk()
            ->assertJsonStructure(['token', 'user'])
            ->assertJsonMissingPath('mfa_required');
        Mail::assertNothingSent();

        // Si el administrador tiene marcada su casilla, se le sigue pidiendo el código.
        $withMfa = $this->makeAdmin();
        $withMfa->forceFill(['mfa_enabled' => true])->save();
        $this->postJson('/api/v1/login', ['email' => $withMfa->email, 'password' => 'password123'])
            ->assertOk()
            ->assertJson(['mfa_required' => true]);
    }

    public function test_user_without_mfa_gets_token_directly(): void
    {
        Mail::fake();
        $user = $this->makeUser(false);

        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertOk()
            ->assertJsonStructure(['token', 'user'])
            ->assertJsonMissingPath('mfa_required');

        Mail::assertNothingSent();
    }

    public function test_user_with_mfa_must_verify_the_code(): void
    {
        Mail::fake();
        $user = $this->makeUser(true);

        $login = $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertOk()
            ->assertJson(['mfa_required' => true])
            ->assertJsonMissingPath('token');

        $code = null;
        Mail::assertSent(MfaCodeMail::class, function (MfaCodeMail $mail) use (&$code, $user) {
            $code = $mail->code;

            return $mail->hasTo($user->email);
        });

        $mfaToken = $login->json('mfa_token');
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->postJson('/api/v1/login/mfa', ['mfa_token' => $mfaToken, 'code' => $wrong])
            ->assertStatus(422)
            ->assertJsonMissingPath('token');

        $this->postJson('/api/v1/login/mfa', ['mfa_token' => $mfaToken, 'code' => $code])
            ->assertOk()
            ->assertJsonStructure(['token', 'user']);

        // El código es de un solo uso.
        $this->postJson('/api/v1/login/mfa', ['mfa_token' => $mfaToken, 'code' => $code])
            ->assertStatus(422);
    }

    public function test_challenge_is_discarded_after_too_many_attempts(): void
    {
        Mail::fake();
        $user = $this->makeUser(true);

        $mfaToken = $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password123'])
            ->json('mfa_token');

        $code = null;
        Mail::assertSent(MfaCodeMail::class, function (MfaCodeMail $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/login/mfa', ['mfa_token' => $mfaToken, 'code' => $wrong])->assertStatus(422);
        }

        $this->postJson('/api/v1/login/mfa', ['mfa_token' => $mfaToken, 'code' => $code])
            ->assertStatus(422)
            ->assertJsonMissingPath('token');
    }
}
