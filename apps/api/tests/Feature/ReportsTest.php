<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\OperationsFixtures;
use Tests\TestCase;

/**
 * Reportes: cada uno existe si la empresa tiene su módulo y el rol puede verlo, respeta el alcance
 * de quien lo mira, filtra por fechas, exporta con su permiso (queda en "Mis exportaciones") y no
 * muestra nada de otra empresa.
 */
class ReportsTest extends TestCase
{
    use DatabaseTransactions;
    use OperationsFixtures;

    private const MODULES = ['COTIZACIONES', 'CRM', 'FINANZAS', 'PROYECTOS', 'SOPORTE', 'ORDENES_TRABAJO', 'CONTRATOS'];

    private const ALL = [
        'reports.view', 'reports.export', 'quotations.view', 'opportunities.view', 'receivables.view', 'projects.view',
        'tickets.view', 'recurring.view', 'work_orders.view',
    ];

    private function quotation(?User $by, string $created, float $total, string $currency = 'PEN', string $status = 'SUBMITTED'): string
    {
        $id = (string) Str::uuid();
        DB::table('quotations')->insert([
            'id' => $id, 'tenant_id' => $this->tenantId, 'customer_id' => $this->makeCustomer('Minera '.Str::random(4)),
            'quotation_number' => 'COT-'.Str::upper(Str::random(6)), 'status' => $status, 'currency' => $currency,
            'total' => $total, 'subtotal' => $total, 'created_by' => $by?->id, 'created_at' => $created, 'updated_at' => $created,
        ]);

        return $id;
    }

    private function scope(User $user, string $resource, string $scope): void
    {
        $roleId = DB::table('user_roles')->where('user_id', $user->id)->value('role_id');
        DB::table('role_scopes')->updateOrInsert(['role_id' => $roleId, 'resource' => $resource], ['tenant_id' => $this->tenantId, 'scope' => $scope]);
    }

    public function test_reports_follow_modules_and_permissions(): void
    {
        $this->makeTenant(['COTIZACIONES', 'ORDENES_TRABAJO']);
        $this->as($this->makeUser('Gerencia', self::ALL));

        // Solo los de los módulos que la empresa tiene.
        $this->getJson('/api/v1/reports')->assertOk()->assertJsonPath('reports', [
            ['key' => 'ventas', 'label' => 'Ventas y cotizaciones'],
            ['key' => 'operaciones', 'label' => 'Productividad operativa'],
        ]);
        $this->getJson('/api/v1/reports/pipeline')->assertForbidden()->assertJsonPath('code', 'PERMISSION_DENIED');
        $this->getJson('/api/v1/reports/inventado')->assertNotFound();

        // Con el módulo pero sin el permiso del módulo: no lo ve.
        $this->as($this->makeUser('Soporte', ['reports.view', 'work_orders.view']));
        $this->getJson('/api/v1/reports')->assertJsonPath('reports', [['key' => 'operaciones', 'label' => 'Productividad operativa']]);
        $this->getJson('/api/v1/reports/ventas')->assertForbidden();

        // Sin reports.view: nada.
        $this->as($this->makeUser('Técnico', ['work_orders.view']));
        $this->getJson('/api/v1/reports')->assertForbidden();
    }

    public function test_sales_report_scope_dates_chart_and_export(): void
    {
        $this->makeTenant(self::MODULES);
        $manager = $this->makeUser('Gerencia', self::ALL);
        $this->scope($manager, 'quotations', 'TENANT');
        $seller = $this->makeUser('Ventas', ['reports.view', 'quotations.view']);
        $this->scope($seller, 'quotations', 'OWN');

        $this->quotation($seller, now()->subDays(2)->toDateTimeString(), 1000);
        $this->quotation($manager, now()->subMonths(2)->toDateTimeString(), 500, 'USD');
        $this->quotation($manager, now()->subYear()->toDateTimeString(), 9999);

        // Gerencia: todo; con rango, solo lo de esas fechas.
        $this->as($manager);
        $all = $this->getJson('/api/v1/reports/ventas')->assertOk();
        $this->assertSame(3, $all->json('total'));
        $this->assertEquals(['SUBMITTED', 'PEN', 1000], [$all->json('data.0.status'), $all->json('data.0.currency'), $all->json('data.0.total')]);
        $from = now('America/Lima')->subMonths(3)->toDateString();
        $this->getJson("/api/v1/reports/ventas?from={$from}")->assertJsonPath('total', 2);
        $this->getJson('/api/v1/reports/ventas?sort=total&dir=desc')->assertJsonPath('data.0.total', 9999);

        // Gráfico: 6 meses, en soles (lo de dólares con el tipo de cambio guardado o el vigente).
        $months = $all->json('summary.months');
        $this->assertCount(6, $months);
        $this->assertSame(now('America/Lima')->format('Y-m'), end($months)['key']);
        $this->assertGreaterThanOrEqual(1000, array_sum(array_column($months, 'amount')));

        // Exportar: pide reports.export y queda en "Mis exportaciones".
        $this->getJson('/api/v1/reports/ventas?export=1')->assertOk()->assertJsonPath('summary', null);
        $this->postJson('/api/v1/exports', ['resource' => 'reports', 'format' => 'xlsx', 'rows' => 3, 'filters' => 'report=ventas&from='.$from])->assertOk();
        $this->getJson('/api/v1/reports')->assertJsonPath('exports.0.label', 'Ventas y cotizaciones')->assertJsonPath('exports.0.rows', 3);
        // La auditoría guarda la hora al segundo: la segunda exportación, un segundo después.
        $this->travel(2)->seconds();
        $this->postJson('/api/v1/exports', ['resource' => 'reports', 'format' => 'pdf', 'rows' => 2, 'filters' => 'report=cobranza'])->assertOk();
        $this->getJson('/api/v1/reports')->assertJsonPath('exports.0.format', 'pdf')->assertJsonPath('exports.0.label', 'Cobranza y antigüedad');
        $this->postJson('/api/v1/exports', ['resource' => 'reports', 'format' => 'docx', 'rows' => 2])->assertStatus(422);

        // Ventas: solo lo suyo y sin exportar.
        $this->as($seller);
        $this->getJson('/api/v1/reports/ventas')->assertJsonPath('total', 1)->assertJsonPath('data.0.total', 1000);
        $this->getJson('/api/v1/reports/ventas?export=1')->assertForbidden();
        $this->postJson('/api/v1/exports', ['resource' => 'reports', 'format' => 'csv', 'rows' => 1])->assertForbidden();

        // Otra empresa: nada.
        $this->makeTenant(self::MODULES);
        $stranger = $this->makeUser('Gerencia B', self::ALL);
        $this->scope($stranger, 'quotations', 'TENANT');
        $this->as($stranger);
        $this->getJson('/api/v1/reports/ventas')->assertJsonPath('total', 0);
    }

    public function test_every_report_answers_with_its_rows(): void
    {
        $this->makeTenant(self::MODULES);
        $admin = $this->makeUser('Admin', [...self::ALL, 'work_orders.create', 'work_orders.manage', 'work_orders.approve', 'contracted_services.view', 'contracted_services.manage']);
        $tech = $this->makeUser('Técnico', ['work_orders.view', 'work_orders.manage']);
        $contracted = $this->makeContracted();
        $order = $this->order($admin, $contracted, ['assigned_user_id' => $tech->id, 'scheduled_start' => $this->at(1, 9), 'scheduled_end' => $this->at(1, 11)]);

        $this->as($admin);
        foreach (array_keys(\App\Http\Controllers\ReportController::REPORTS) as $key) {
            $this->getJson("/api/v1/reports/{$key}")->assertOk()->assertJsonStructure(['data', 'total', 'summary']);
        }

        $ops = $this->getJson('/api/v1/reports/operaciones')->assertOk();
        $this->assertSame([$order['code'], 'Técnico', 'ASSIGNED'], [$ops->json('data.0.code'), $ops->json('data.0.technician'), $ops->json('data.0.status')]);
        $this->assertSame(1, $ops->json('summary.states.ASSIGNED'));
        $this->getJson('/api/v1/reports/operaciones?from='.now()->addDays(5)->toDateString())->assertJsonPath('total', 0);
        $this->assertSame(['CURRENT', 'D30', 'D60', 'D90', 'OVER'], array_column($this->getJson('/api/v1/reports/cobranza')->json('summary.aging'), 'key'));
    }

    public function test_indicators_compare_with_the_previous_period(): void
    {
        $this->makeTenant(self::MODULES);
        $manager = $this->makeUser('Gerencia', self::ALL);
        $this->scope($manager, 'quotations', 'TENANT');
        $this->quotation($manager, now()->subDays(3)->toDateTimeString(), 1000);
        $this->quotation($manager, now()->subDays(4)->toDateTimeString(), 300, 'PEN', 'CANCELLED');
        $this->quotation($manager, now()->subDays(15)->toDateTimeString(), 500, 'PEN', 'APPROVED');
        $this->as($manager);

        // Últimos 11 días frente a los 11 anteriores; las canceladas no cuentan.
        $from = now('America/Lima')->subDays(10)->toDateString();
        $to = now('America/Lima')->toDateString();
        $response = $this->getJson("/api/v1/reports/ventas?from={$from}&to={$to}")->assertOk();
        $this->assertSame(['from' => $from, 'to' => $to, 'prev_from' => now('America/Lima')->subDays(21)->toDateString(), 'prev_to' => now('America/Lima')->subDays(11)->toDateString(), 'custom' => true], $response->json('period'));
        $kpis = $response->json('kpis');
        $this->assertSame(['Total cotizado', 'Cotizaciones', 'Aprobación'], array_column($kpis, 'label'));
        $this->assertEquals([1000, 500], [$kpis[0]['value'], $kpis[0]['previous']]);
        $this->assertSame([1, 1], [$kpis[1]['value'], $kpis[1]['previous']]);
        $this->assertSame([0, 100], [$kpis[2]['value'], $kpis[2]['previous']]);

        // Un mes completo se compara con el mes anterior.
        $this->assertSame(['2026-09-01', '2026-09-30'], array_values(array_intersect_key(
            $this->getJson('/api/v1/reports/ventas?from=2026-10-01&to=2026-10-31')->json('period'), array_flip(['prev_from', 'prev_to'])
        )));

        // Sin rango: lo que va del mes frente a los mismos días del mes anterior.
        $this->assertFalse($this->getJson('/api/v1/reports/ventas')->json('period.custom'));
        $this->assertSame(now('America/Lima')->startOfMonth()->toDateString(), $this->getJson('/api/v1/reports/ventas')->json('period.from'));

        // Cada reporte trae sus tres indicadores; al exportar, ninguno.
        foreach (array_keys(\App\Http\Controllers\ReportController::REPORTS) as $key) {
            $this->assertCount(3, $this->getJson("/api/v1/reports/{$key}")->assertOk()->json('kpis'), $key);
        }
        $this->getJson('/api/v1/reports/ventas?export=1')->assertJsonPath('kpis', null);
    }

    public function test_new_tenants_get_reports_with_the_base_plan(): void
    {
        $codes = \App\Modules\Subscription\Support\ModulePermissions::codesFor([]);
        $this->assertContains('reports.view', $codes);
        $this->assertContains('reports.export', $codes);
    }
}
