<?php

namespace Tests\Feature;

use App\Mail\SuperAdminInvitationMail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\PlatformSession;
use Tests\TestCase;

/** Dar y quitar el acceso de Super Administrador desde el servidor (platform:super-admin). */
class SuperAdminAccessTest extends TestCase
{
    use DatabaseTransactions;
    use PlatformSession;

    public function test_a_new_super_admin_gets_an_invitation_and_creates_the_password(): void
    {
        Mail::fake();
        $by = $this->superAdmin();
        $email = 'nueva-'.Str::lower(Str::random(6)).'@ejemplo.test';

        // Sin quien lo autorice, no.
        $this->artisan('platform:super-admin', ['email' => $email])->assertFailed();
        $this->artisan('platform:super-admin', ['email' => $email, '--by' => 'nadie@ejemplo.test'])->assertFailed();
        $this->assertDatabaseMissing('users', ['email' => $email]);

        $this->artisan('platform:super-admin', ['email' => strtoupper($email), '--by' => $by->email, '--name' => 'Ana'])->assertSuccessful();
        $user = User::where('email', $email)->firstOrFail();
        $this->assertSame([true, 'ACTIVE', true, 'Ana'], [(bool) $user->is_super_admin, $user->status, (bool) $user->mfa_enabled, $user->name]);

        $mail = Mail::sent(SuperAdminInvitationMail::class)->first();
        $this->assertNotNull($mail, 'No salió la invitación.');
        $this->assertTrue($mail->hasTo($email));
        $this->assertSame([$by->name, 3], [$mail->invitedBy, $mail->days]);
        $this->assertStringEndsWith('/reset-password?email='.rawurlencode($email), $mail->resetUrl);
        $code = $mail->code;
        $html = (new SuperAdminInvitationMail($by->name, 'ABC123', 3, 'https://x.test'))->render();
        $this->assertStringContainsString('Super Administrador de la plataforma', $html);

        // Crea su contraseña con el código; antes no podía entrar con ninguna.
        $this->postJson('/api/v1/reset-password', ['email' => $email, 'token' => $code, 'password' => 'Plataforma2026'])->assertOk();
        $this->postJson('/api/v1/login', ['email' => $email, 'password' => 'Plataforma2026'])->assertOk()->assertJsonPath('mfa_required', true);

        $this->assertDatabaseHas('platform_audit_logs', ['user_id' => $by->id, 'action' => 'platform.super_admin_invited', 'entity_id' => $user->id]);
    }

    public function test_it_does_not_take_accounts_of_companies_and_reinvites_only_before_the_first_login(): void
    {
        Mail::fake();
        $by = $this->superAdmin();
        $existing = User::create(['email' => 'empresa-'.Str::random(6).'@ejemplo.test', 'password_hash' => bcrypt('x'), 'name' => 'Usuario', 'status' => 'ACTIVE']);

        $this->artisan('platform:super-admin', ['email' => $existing->email, '--by' => $by->email])->assertFailed();
        $this->assertFalse((bool) $existing->fresh()->is_super_admin);

        $invited = $this->superAdmin();
        $this->artisan('platform:super-admin', ['email' => $invited->email, '--by' => $by->email])->assertSuccessful();
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'platform.super_admin_reinvited', 'entity_id' => $invited->id]);

        $invited->forceFill(['last_login_at' => now()])->save();
        $this->artisan('platform:super-admin', ['email' => $invited->email, '--by' => $by->email])->assertFailed();
        Mail::assertSent(SuperAdminInvitationMail::class, 1);
    }

    public function test_a_super_admin_changes_their_name_in_the_platform_audit(): void
    {
        $user = $this->superAdmin();
        $this->signIn($user);

        $this->putJson('/api/v1/me/name', ['name' => 'Ana Torres'])->assertOk()->assertJsonPath('name', 'Ana Torres');
        $this->assertSame('Ana Torres', $user->fresh()->name);
        $this->assertDatabaseHas('platform_audit_logs', ['user_id' => $user->id, 'action' => 'auth.name_changed']);
    }

    public function test_revoking_deactivates_and_never_leaves_the_platform_without_one(): void
    {
        $by = $this->superAdmin();
        $other = $this->superAdmin();
        $this->signIn($other);

        $this->artisan('platform:super-admin', ['email' => $other->email, '--by' => $by->email, '--revoke' => true])->assertSuccessful();
        $this->assertSame('DISABLED', $other->fresh()->status);
        $this->assertSame(0, $other->tokens()->count());
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'platform.super_admin_revoked', 'entity_id' => $other->id]);

        // Solo queda uno activo: no se le quita.
        DB::table('users')->where('is_super_admin', true)->where('id', '<>', $by->id)->update(['status' => 'DISABLED']);
        $this->artisan('platform:super-admin', ['email' => $by->email, '--by' => $by->email, '--revoke' => true])->assertFailed();
        $this->assertSame('ACTIVE', $by->fresh()->status);
    }
}
