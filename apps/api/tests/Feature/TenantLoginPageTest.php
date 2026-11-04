<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\OperationsFixtures;
use Tests\Feature\Concerns\PlatformSession;
use Tests\TestCase;

/**
 * Pantalla de ingreso con la marca de cada empresa (/e/{nombre-corto}): la consulta pública solo
 * entrega nombre, logo, color y bienvenida; un enlace inexistente o de una empresa inactiva no se
 * distingue; el Administrador y la plataforma cambian el enlace con sus reglas.
 */
class TenantLoginPageTest extends TestCase
{
    use DatabaseTransactions;
    use OperationsFixtures;
    use PlatformSession;

    protected $connectionsToTransact = [null, 'pgsql_platform'];

    private function slug(string $base): string
    {
        return $base.'-'.Str::lower(Str::random(5));
    }

    public function test_the_public_brand_shows_only_the_login_data_of_an_active_company(): void
    {
        Cache::flush();
        $this->makeTenant(['SERVICIOS']);
        $slug = $this->slug('minera');
        DB::table('tenants')->where('id', $this->tenantId)->update(['login_slug' => $slug]);
        DB::table('company_branding')->insert([
            'tenant_id' => $this->tenantId, 'primary_color' => '#C2410C', 'address' => 'Av. Secreta 123', 'email' => 'interno@minera.test',
            'login_welcome' => 'Bienvenido al sistema de Minera', 'updated_at' => now(),
        ]);
        DB::table('companies')->where('tenant_id', $this->tenantId)->update(['trade_name' => 'Minera Prueba']);

        $brand = $this->getJson("/api/v1/public/login-brand/{$slug}")->assertOk()->json();
        $this->assertEqualsCanonicalizing(['slug', 'name', 'logo', 'logo_width', 'logo_height', 'primary_color', 'welcome'], array_keys($brand));
        $this->assertSame([$slug, 'Minera Prueba', '#C2410C', 'Bienvenido al sistema de Minera'], [$brand['slug'], $brand['name'], $brand['primary_color'], $brand['welcome']]);
        // Mayúsculas en el enlace: la misma empresa.
        $this->getJson('/api/v1/public/login-brand/'.strtoupper($slug))->assertOk()->assertJsonPath('slug', $slug);

        // Inexistente, mal formado o de una empresa suspendida: igual, 404 (sin decir por qué).
        $this->getJson('/api/v1/public/login-brand/no-existe-'.Str::lower(Str::random(6)))->assertNotFound();
        $this->getJson('/api/v1/public/login-brand/%20--x')->assertNotFound();
        $this->asPlatformFixture(fn () => DB::table('tenants')->where('id', $this->tenantId)->update(['status' => 'SUSPENDED']));
        Cache::flush();
        $this->getJson("/api/v1/public/login-brand/{$slug}")->assertNotFound();
    }

    public function test_the_admin_changes_the_link_and_the_welcome_with_rules(): void
    {
        Cache::flush();
        $this->makeTenant(['SERVICIOS']);
        $own = $this->tenantId;
        $mine = $this->slug('propia');
        DB::table('tenants')->where('id', $own)->update(['login_slug' => $mine]);
        $admin = $this->makeUser('Administración', ['tenant.manage']);
        $reader = $this->makeUser('Ventas', ['core.view']);
        $other = $this->slug('otra');
        $otherTenant = $this->makeTenant(['SERVICIOS']);
        DB::table('tenants')->where('id', $otherTenant)->update(['login_slug' => $other]);
        $this->tenantId = $own;
        DB::statement("SET app.tenant_id = '{$own}'");

        $this->getJson("/api/v1/public/login-brand/{$mine}")->assertOk();

        // Sin permiso, no.
        $this->as($reader);
        $this->putJson('/api/v1/branding/login-page', ['login_slug' => 'nuevo-enlace'])->assertForbidden();

        $this->as($admin);
        foreach (['ab', '-mala', 'mala-', 'dos--guiones', 'con espacio', 'superadmin', 'login', $other] as $bad) {
            $this->putJson('/api/v1/branding/login-page', ['login_slug' => $bad])->assertStatus(422)->assertJsonValidationErrors('login_slug');
        }

        $new = $this->slug('nueva');
        $this->putJson('/api/v1/branding/login-page', ['login_slug' => strtoupper($new), 'login_welcome' => '  Hola equipo  '])
            ->assertOk()->assertJsonPath('login_slug', $new)->assertJsonPath('login_welcome', 'Hola equipo');
        // El anterior deja de funcionar enseguida; el nuevo responde con la bienvenida.
        $this->getJson("/api/v1/public/login-brand/{$mine}")->assertNotFound();
        $this->getJson("/api/v1/public/login-brand/{$new}")->assertOk()->assertJsonPath('welcome', 'Hola equipo');
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $own, 'action' => 'branding.login_page']);

        // Cambiar el color se ve enseguida en la pantalla de ingreso.
        $this->putJson('/api/v1/branding', ['primary_color' => '#15803D'])->assertOk();
        $this->getJson("/api/v1/public/login-brand/{$new}")->assertOk()->assertJsonPath('primary_color', '#15803D');
    }

    public function test_the_platform_changes_the_link_from_the_console(): void
    {
        Cache::flush();
        $tenantId = $this->makeTenant(['SERVICIOS']);
        $taken = $this->slug('ocupado');
        $this->makeTenant(['SERVICIOS']);
        DB::table('tenants')->where('id', $this->tenantId)->update(['login_slug' => $taken]);
        $this->signIn($this->superAdmin());

        $this->putJson("/api/v1/superadmin/tenants/{$tenantId}/login-slug", ['login_slug' => $taken])->assertStatus(422)->assertJsonValidationErrors('login_slug');
        $new = $this->slug('consola');
        $this->putJson("/api/v1/superadmin/tenants/{$tenantId}/login-slug", ['login_slug' => $new])->assertOk()->assertJsonPath('login_slug', $new);
        // (La ficha de la consola lee con su propio usuario y no ve la empresa creada en esta prueba: se mira la base.)
        $this->assertSame($new, (string) DB::table('tenants')->where('id', $tenantId)->value('login_slug'));
        $this->assertDatabaseHas('platform_audit_logs', ['entity_id' => $tenantId, 'action' => 'tenant.login_slug_changed']);
    }

    public function test_the_platform_sends_the_link_to_the_active_admins(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $tenantId = $this->makeTenant(['SERVICIOS']);
        $slug = $this->slug('enviar');
        DB::table('tenants')->where('id', $tenantId)->update(['login_slug' => $slug]);
        $admin = $this->makeUser('Administración', ['tenant.manage']);
        $roleId = DB::table('user_roles')->where('user_id', $admin->id)->value('role_id');
        DB::table('roles')->where('id', $roleId)->update(['code' => 'ADMIN']);
        // Otra persona sin rol de administrador no lo recibe.
        $this->makeUser('Ventas', ['core.view']);
        $this->signIn($this->superAdmin());

        $res = $this->postJson("/api/v1/superadmin/tenants/{$tenantId}/login-link")->assertOk()->json();
        $this->assertSame([$admin->email], $res['sent']);
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\TenantLoginLinkMail::class, 1);
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\TenantLoginLinkMail::class, fn ($mail) => $mail->hasTo($admin->email) && str_ends_with($mail->loginUrl, "/e/{$slug}"));
        $this->assertDatabaseHas('platform_audit_logs', ['entity_id' => $tenantId, 'action' => 'tenant.login_link_sent']);

        // Empresa suspendida: no se envía (su enlace no funciona).
        $this->asPlatformFixture(fn () => DB::table('tenants')->where('id', $tenantId)->update(['status' => 'SUSPENDED']));
        $this->postJson("/api/v1/superadmin/tenants/{$tenantId}/login-link")->assertStatus(422);
    }
}
