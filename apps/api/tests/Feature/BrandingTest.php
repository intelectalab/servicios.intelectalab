<?php

namespace Tests\Feature;

use App\Mail\PortalInvitationMail;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\OperationsFixtures;
use Tests\TestCase;

/** Configuración → Marca de la empresa: logo limpio, sus colores, contacto, permisos y aislamiento. */
class BrandingTest extends TestCase
{
    use DatabaseTransactions;
    use OperationsFixtures;

    /** Un logo de prueba: fondo transparente, una barra azul y texto negro. */
    private function logoFile(string $name = 'logo.png'): UploadedFile
    {
        $image = imagecreatetruecolor(300, 100);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 0, 0, 120, 100, imagecolorallocate($image, 27, 143, 201));
        imagefilledrectangle($image, 140, 30, 290, 70, imagecolorallocate($image, 29, 29, 27));
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        return UploadedFile::fake()->createWithContent($name, $png);
    }

    public function test_the_admin_uploads_a_logo_that_is_cleaned_and_gives_its_colors(): void
    {
        $this->makeTenant();
        $admin = $this->makeUser('Administración', ['tenant.manage']);
        $this->as($admin);

        // Sin marca: el azul de siempre y sin logo.
        $this->getJson('/api/v1/branding')->assertOk()
            ->assertJsonPath('primary_color', '#1B8FC9')->assertJsonPath('has_logo', false)->assertJsonPath('logo', null);

        $data = $this->post('/api/v1/branding/logo', ['logo' => $this->logoFile()], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertTrue($data['has_logo']);
        $this->assertStringStartsWith('data:image/png;base64,', $data['logo']);
        $this->assertSame([300, 100], [$data['logo_width'], $data['logo_height']]);
        // El color con tono va de principal; el negro, de secundario.
        $this->assertContains($data['primary_color'], $data['palette']);
        $this->assertGreaterThan(150, hexdec(substr($data['primary_color'], 5, 2)));
        $this->assertSame('#1D1D1B', $data['secondary_color']);
        $this->assertSame(1, DB::table('audit_logs')->where('tenant_id', $this->tenantId)->where('action', 'branding.logo')->count());

        // Lo guardado es una imagen PNG nueva (no el archivo recibido).
        $stored = DB::table('company_branding')->where('tenant_id', $this->tenantId)->first(['logo_sha256']);
        $bytes = base64_decode(substr($data['logo'], strlen('data:image/png;base64,')));
        $this->assertSame(hash('sha256', $bytes), $stored->logo_sha256);
        $this->assertSame("\x89PNG", substr($bytes, 0, 4));

        // Colores y pie: se normalizan y se validan.
        $this->putJson('/api/v1/branding', ['primary_color' => 'rojo'])->assertStatus(422)->assertJsonValidationErrors('primary_color');
        $this->putJson('/api/v1/branding', ['primary_color' => '#0a7c3e', 'website' => 'no es web'])->assertStatus(422)->assertJsonValidationErrors('website');
        $saved = $this->putJson('/api/v1/branding', [
            'primary_color' => '#0a7c3e', 'secondary_color' => null, 'address' => 'Av. Industrial 450, Lima',
            'phone' => '+51 1 555 0101', 'email' => 'contacto@empresa.pe', 'website' => 'www.empresa.pe',
        ])->assertOk()->json();
        $this->assertSame(['#0A7C3E', null, 'www.empresa.pe'], [$saved['primary_color'], $saved['secondary_color'], $saved['website']]);
        $this->assertTrue($saved['has_logo']);

        // Quitar el logo devuelve los colores a los de GsCreativas (#1B8FC9) y limpia las muestras del logo.
        $this->deleteJson('/api/v1/branding/logo')->assertOk()
            ->assertJsonPath('has_logo', false)
            ->assertJsonPath('primary_color', '#1B8FC9')
            ->assertJsonPath('secondary_color', null)
            ->assertJsonPath('palette', [])
            ->assertJsonPath('customized', false);
    }

    public function test_only_images_are_accepted_and_only_the_admin_changes_the_brand(): void
    {
        $this->makeTenant();
        $admin = $this->makeUser('Administración', ['tenant.manage']);
        $seller = $this->makeUser('Ventas', ['quotations.view']);

        $this->as($admin);
        $fake = UploadedFile::fake()->createWithContent('logo.png', '<?php echo "no soy una imagen"; ?>');
        $this->post('/api/v1/branding/logo', ['logo' => $fake], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('logo');
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->post('/api/v1/branding/logo', ['logo' => $svg], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('logo');
        $this->assertSame(0, DB::table('company_branding')->where('tenant_id', $this->tenantId)->count());

        // Sin tenant.manage: lee la marca (sus documentos la usan), pero no la cambia.
        $this->as($seller);
        $this->getJson('/api/v1/branding')->assertOk();
        $this->putJson('/api/v1/branding', ['primary_color' => '#000000'])->assertForbidden()->assertJsonPath('code', 'PERMISSION_DENIED');
        $this->post('/api/v1/branding/logo', ['logo' => $this->logoFile()], ['Accept' => 'application/json'])->assertForbidden();
        $this->deleteJson('/api/v1/branding/logo')->assertForbidden();
    }

    public function test_each_company_sees_only_its_own_brand(): void
    {
        $this->makeTenant();
        $first = $this->tenantId;
        $admin = $this->makeUser('Administración', ['tenant.manage']);
        $this->as($admin);
        $this->putJson('/api/v1/branding', ['primary_color' => '#AA0000', 'email' => 'ventas@primera.pe'])->assertOk();

        $this->makeTenant();
        $other = $this->makeUser('Otra empresa', ['tenant.manage']);
        $this->as($other);
        $this->getJson('/api/v1/branding')->assertOk()->assertJsonPath('primary_color', '#1B8FC9')->assertJsonPath('email', null);
        $this->putJson('/api/v1/branding', ['primary_color' => '#00AA00'])->assertOk();

        // La base: cada empresa solo ve su fila, y la aplicación no borra marcas.
        $this->assertSame(['#00AA00'], DB::table('company_branding')->pluck('primary_color')->all());
        DB::statement("SET app.tenant_id = '{$first}'");
        $this->assertSame('#AA0000', DB::table('company_branding')->value('primary_color'));
        $this->assertDatabaseRejects(fn () => DB::table('company_branding')->where('tenant_id', $first)->delete());
        $this->assertDatabaseRejects(fn () => DB::table('company_branding')->where('tenant_id', $first)->update(['primary_color' => 'azul']));
    }

    public function test_import_templates_take_the_company_colors(): void
    {
        $this->makeTenant(['SERVICIOS']);
        $admin = $this->makeUser('Administración', ['tenant.manage', 'services.view']);
        $this->as($admin);
        $fill = function (): array {
            $path = tempnam(sys_get_temp_dir(), 'tpl');
            file_put_contents($path, $this->get('/api/v1/services/template')->assertOk()->streamedContent());
            $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet();
            unlink($path);
            $header = $sheet->getHighestRow() > 1 ? collect(range(2, 8))->first(fn ($row) => $sheet->getCell("A{$row}")->getValue() !== null && ! str_starts_with((string) $sheet->getCell("A{$row}")->getValue(), '#')) : 2;

            return [$sheet->getStyle('A1')->getFill()->getStartColor()->getARGB(), $sheet->getStyle("A{$header}")->getFill()->getStartColor()->getARGB()];
        };

        // Sin marca: los colores de siempre.
        $this->assertSame(['FF0F5A82', 'FF1B8FC9'], $fill());

        $this->putJson('/api/v1/branding', ['primary_color' => '#0E8A6B'])->assertOk();
        [$title, $header] = $fill();
        $this->assertSame('FF'.substr(\App\Services\Branding::readable('#0E8A6B'), 1), $header);
        $this->assertNotSame('FF0F5A82', $title);
    }

    public function test_customer_mails_carry_the_company_brand(): void
    {
        $this->makeTenant();
        $admin = $this->makeUser('Administración', ['tenant.manage']);
        $this->as($admin);

        $plain = (new PortalInvitationMail('ABCD1234', 'Empresa de Prueba SAC', 'Minera', 7, 'https://app/portal/activate'))->withBrand($this->tenantId);
        $html = $plain->render();
        // Sin marca definida: el correo de siempre.
        $this->assertNull($plain->brand);
        $this->assertStringContainsString('#1B8FC9', $html);

        $this->post('/api/v1/branding/logo', ['logo' => $this->logoFile()], ['Accept' => 'application/json'])->assertOk();
        $this->putJson('/api/v1/branding', ['primary_color' => '#F5D000'])->assertOk();
        $branded = (new PortalInvitationMail('ABCD1234', 'Empresa de Prueba SAC', 'Minera', 7, 'https://app/portal/activate'))->withBrand($this->tenantId);
        $html = $branded->render();
        $this->assertSame('Empresa de Prueba SAC', $branded->brand['name']);
        $this->assertNotNull($branded->brand['logo']);
        // Un amarillo claro se oscurece para que el botón y los textos se lean sobre blanco.
        $this->assertNotSame('#F5D000', $branded->accent);
        $this->assertStringContainsString($branded->accent, $html);
        $this->assertStringContainsString('Enviado por Empresa de Prueba SAC', $html);
        $this->assertStringNotContainsString('Grupo de Soluciones Creativas', $html);
    }
}
