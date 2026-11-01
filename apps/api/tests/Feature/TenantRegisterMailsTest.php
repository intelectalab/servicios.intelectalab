<?php

namespace Tests\Feature;

use App\Mail\NewSubscriptionMail;
use App\Mail\TenantWelcomeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantRegisterMailsTest extends TestCase
{
    use DatabaseTransactions;

    private function payload(array $overrides = []): array
    {
        $planId = DB::table('saas_plans')->insertGetId([
            'code' => 'TEST-'.Str::random(6), 'name' => 'Plan de prueba', 'active' => true, 'is_public' => true,
            'monthly_price' => 349, 'annual_price' => 3490, 'currency' => 'PEN', 'has_trial' => true, 'trial_days' => 15,
            'modules' => json_encode(['SERVICIOS' => true]),
        ], 'id');

        return $overrides + [
            'plan_id' => $planId,
            'billing_period' => 'MONTHLY',
            'ruc' => '20'.random_int(100000000, 999999999),
            'razon_social' => 'Empresa Correo SAC',
            'telefono' => '01 555 1234',
            'nombres' => 'Ana',
            'apellidos' => 'Prueba',
            'email' => 'registro-'.Str::lower(Str::random(8)).'@erp-saas.test',
            'password' => 'password123',
        ];
    }

    public function test_registration_sends_a_welcome_and_notifies_the_super_admins(): void
    {
        Mail::fake();
        // is_super_admin no es asignable en masa (protección): se fija aparte.
        $superAdmin = User::create([
            'email' => 'super-'.Str::lower(Str::random(8)).'@erp-saas.test', 'password_hash' => bcrypt('x'),
            'name' => 'Super', 'status' => 'ACTIVE',
        ]);
        $superAdmin->forceFill(['is_super_admin' => true])->save();
        $data = $this->payload();

        $this->postJson('/api/v1/tenants/register', $data)->assertCreated();

        Mail::assertSent(TenantWelcomeMail::class, function (TenantWelcomeMail $mail) use ($data) {
            return $mail->hasTo($data['email'])
                && $mail->rows['Plan'] === 'Plan de prueba'
                && str_starts_with($mail->rows['Vigencia'], 'Prueba gratis hasta el ')
                && $mail->rows['Precio al terminar la prueba'] === 'S/ 349.00 al mes'
                && $mail->rows['Correo de acceso'] === $data['email']
                // Su propio enlace de ingreso, con la marca de la empresa.
                && (bool) preg_match('#/e/[a-z0-9-]+$#', $mail->loginUrl)
                && $mail->rows['Tu enlace de ingreso'] === $mail->loginUrl;
        });
        Mail::assertSent(NewSubscriptionMail::class, function (NewSubscriptionMail $mail) use ($superAdmin, $data) {
            return $mail->hasTo($superAdmin->email)
                && $mail->rows['RUC'] === $data['ruc']
                && $mail->rows['Teléfono'] === '01 555 1234'
                && ! array_key_exists('Celular', $mail->rows);
        });

        // La bienvenida nunca lleva la contraseña.
        $html = (new TenantWelcomeMail('Empresa Correo SAC', ['Plan' => 'Plan de prueba'], 'https://ejemplo.test/login'))->render();
        $this->assertStringNotContainsString('password123', $html);
    }

    public function test_registration_is_kept_when_the_mail_server_fails(): void
    {
        // Un servidor SMTP que rechaza la conexión: el envío falla, el registro no.
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 2]);
        $data = $this->payload(['billing_period' => 'ANNUAL']);

        $this->postJson('/api/v1/tenants/register', $data)->assertCreated()->assertJsonStructure(['tenant_code']);

        $this->assertDatabaseHas('users', ['email' => $data['email']]);
    }
}
