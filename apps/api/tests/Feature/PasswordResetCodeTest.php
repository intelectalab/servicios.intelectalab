<?php

namespace Tests\Feature;

use App\Mail\PasswordResetCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class PasswordResetCodeTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(): User
    {
        return User::create([
            'email' => 'reset-'.Str::random(8).'@erp-saas.test',
            'password_hash' => bcrypt('password123'),
            'name' => 'Test Reset',
            'status' => 'ACTIVE',
        ]);
    }

    private function sentCode(): string
    {
        $code = null;
        Mail::assertSent(PasswordResetCodeMail::class, function (PasswordResetCodeMail $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        return $code;
    }

    public function test_unknown_email_gets_the_same_answer_and_no_mail(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/forgot-password', ['email' => 'nadie-'.Str::random(8).'@erp-saas.test'])
            ->assertOk();

        Mail::assertNothingSent();
    }

    public function test_code_from_mail_resets_the_password_once(): void
    {
        Mail::fake();
        $user = $this->makeUser();

        $this->postJson('/api/v1/forgot-password', ['email' => $user->email])->assertOk();
        $code = $this->sentCode();
        $this->assertSame(6, strlen($code));

        // Código incorrecto
        $this->postJson('/api/v1/reset-password', ['email' => $user->email, 'token' => 'ZZZZZZ', 'password' => 'NuevaClave1'])
            ->assertStatus(422)->assertJsonValidationErrors('token');

        // Contraseña que no cumple la regla (sin mayúscula ni número)
        $this->postJson('/api/v1/reset-password', ['email' => $user->email, 'token' => $code, 'password' => 'solominusculas'])
            ->assertStatus(422)->assertJsonValidationErrors('password');

        // Acepta el código en minúsculas
        $this->postJson('/api/v1/reset-password', ['email' => $user->email, 'token' => strtolower($code), 'password' => 'NuevaClave1'])
            ->assertOk();

        $this->assertTrue(Hash::check('NuevaClave1', $user->fresh()->password_hash));

        // Un solo uso
        $this->postJson('/api/v1/reset-password', ['email' => $user->email, 'token' => $code, 'password' => 'OtraClave22'])
            ->assertStatus(422);
        $this->assertTrue(Hash::check('NuevaClave1', $user->fresh()->password_hash));
    }

    public function test_code_is_discarded_after_too_many_attempts(): void
    {
        Mail::fake();
        $user = $this->makeUser();

        $this->postJson('/api/v1/forgot-password', ['email' => $user->email])->assertOk();
        $code = $this->sentCode();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/reset-password', ['email' => $user->email, 'token' => 'ZZZZZZ', 'password' => 'NuevaClave1'])
                ->assertStatus(422);
        }

        $this->postJson('/api/v1/reset-password', ['email' => $user->email, 'token' => $code, 'password' => 'NuevaClave1'])
            ->assertStatus(422);
        $this->assertTrue(Hash::check('password123', $user->fresh()->password_hash));
    }
}
