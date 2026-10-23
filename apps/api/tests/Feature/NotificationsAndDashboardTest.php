<?php

namespace Tests\Feature;

use App\Mail\NotificationMail;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\OperationsFixtures;
use Tests\TestCase;

/** Notificaciones (campana, centro, preferencias) y panel de control por permiso, alcance y plan. */
class NotificationsAndDashboardTest extends TestCase
{
    use DatabaseTransactions;
    use OperationsFixtures;

    private const PLANNER = ['contracted_services.view', 'work_orders.view', 'work_orders.create', 'work_orders.manage', 'work_orders.approve'];

    private const TECHNICIAN = ['work_orders.view', 'work_orders.manage', 'time_entries.view', 'time_entries.create'];

    private function preferences(string $userId, bool $inApp, bool $email): void
    {
        DB::table('notification_preferences')->insert([
            'tenant_id' => $this->tenantId, 'user_id' => $userId, 'in_app' => $inApp, 'email' => $email, 'whatsapp' => false,
        ]);
    }

    private function inbox(string $userId, string $channel = 'IN_APP')
    {
        return DB::table('notifications')->where('tenant_id', $this->tenantId)->where('user_id', $userId)->where('channel', $channel)->get();
    }

    public function test_assigning_a_work_order_notifies_the_technician_by_their_chosen_channels(): void
    {
        Mail::fake();
        $this->makeTenant();
        $planner = $this->makeUser('Planificación', self::PLANNER);
        $bell = $this->makeUser('Técnico campana', self::TECHNICIAN, 'OWN');
        $mailOnly = $this->makeUser('Técnico solo correo', self::TECHNICIAN, 'OWN');
        $silent = $this->makeUser('Técnico sin avisos', self::TECHNICIAN, 'OWN');
        $this->preferences($mailOnly->id, false, true);
        $this->preferences($silent->id, false, false);
        $contracted = $this->makeContracted();

        // Crear con técnico es asignar: le llega a él, no a quien asigna.
        $order = $this->order($planner, $contracted, ['assigned_user_id' => $bell->id]);
        $rows = $this->inbox($bell->id);
        $this->assertCount(1, $rows);
        $this->assertSame('work_order.assigned', $rows[0]->event);
        $this->assertSame('Nueva orden de trabajo asignada', $rows[0]->subject);
        $this->assertSame("/work-orders/{$order['id']}", $rows[0]->link);
        $this->assertSame($planner->id, $rows[0]->actor_user_id);
        $this->assertCount(0, DB::table('notifications')->where('user_id', $planner->id)->get());
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail) => $mail->hasTo($bell->email) && str_contains($mail->body, $order['code']));

        // Sin "En la aplicación": no aparece en su campana, pero el correo sí sale (y queda el registro).
        $second = $this->order($planner, $contracted);
        $this->postJson("/api/v1/work-orders/{$second['id']}/assign", ['assigned_user_id' => $mailOnly->id])->assertOk();
        $this->assertCount(0, $this->inbox($mailOnly->id));
        $this->assertCount(1, $this->inbox($mailOnly->id, 'EMAIL'));
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail) => $mail->hasTo($mailOnly->email));
        $this->as($mailOnly);
        $this->getJson('/api/v1/me/notifications/summary')->assertOk()->assertJsonPath('unread', 0);

        // Todo apagado: nada. Reasignar a la misma persona tampoco repite el aviso.
        $third = $this->order($planner, $contracted, ['assigned_user_id' => $silent->id]);
        $this->assertCount(0, DB::table('notifications')->where('user_id', $silent->id)->get());
        Mail::assertNotSent(NotificationMail::class, fn (NotificationMail $mail) => $mail->hasTo($silent->email));
        $this->as($planner);
        $this->postJson("/api/v1/work-orders/{$order['id']}/assign", ['assigned_user_id' => $bell->id])->assertOk();
        $this->assertCount(1, $this->inbox($bell->id));
        $this->assertNotNull($third['id']);
    }

    public function test_only_people_with_the_permission_who_reach_the_record_are_notified(): void
    {
        Mail::fake();
        $this->makeTenant();
        $north = $this->makeBranch('Norte');
        $south = $this->makeBranch('Sur');
        $planner = $this->makeUser('Planificación', self::PLANNER);
        $technician = $this->makeUser('Técnico', [...self::TECHNICIAN, 'time_entries.approve'], 'OWN', $north);
        $wholeCompany = $this->makeUser('Gerencia', ['time_entries.view', 'time_entries.approve'], 'TENANT');
        $northBoss = $this->makeUser('Jefe norte', ['time_entries.view', 'time_entries.approve'], 'BRANCH', $north);
        $southBoss = $this->makeUser('Jefe sur', ['time_entries.view', 'time_entries.approve'], 'BRANCH', $south);
        $ownOnly = $this->makeUser('Supervisor propio', ['time_entries.view', 'time_entries.approve'], 'OWN');
        $noPermission = $this->makeUser('Ventas', ['time_entries.view'], 'TENANT');
        $order = $this->order($planner, $this->makeContracted(['branch_id' => $north]), ['assigned_user_id' => $technician->id]);

        $this->as($technician);
        $this->postJson('/api/v1/time-entries', [
            'work_order_id' => $order['id'], 'started_at' => now()->subHours(3)->toIso8601String(), 'ended_at' => now()->subHour()->toIso8601String(),
        ])->assertCreated();

        $notified = DB::table('notifications')->where('tenant_id', $this->tenantId)->where('event', 'time_entry.submitted')->pluck('user_id')->sort()->values()->all();
        $expected = collect([$wholeCompany->id, $northBoss->id])->sort()->values()->all();
        // Ni quien registró (aunque pueda aprobar), ni el jefe de otra sede, ni quien solo alcanza lo propio, ni quien no aprueba.
        $this->assertSame($expected, $notified);
        $this->assertNotContains($southBoss->id, $notified);
        $this->assertNotContains($ownOnly->id, $notified);
        $this->assertNotContains($noPermission->id, $notified);
        $this->assertStringContainsString($order['code'], DB::table('notifications')->where('user_id', $northBoss->id)->value('body'));
    }

    public function test_notification_center_lists_marks_and_protects_each_persons_notifications(): void
    {
        $this->makeTenant();
        $planner = $this->makeUser('Planificación', self::PLANNER);
        $technician = $this->makeUser('Técnico', self::TECHNICIAN, 'OWN');
        $contracted = $this->makeContracted();
        $first = $this->order($planner, $contracted, ['assigned_user_id' => $technician->id]);
        $this->order($planner, $contracted, ['assigned_user_id' => $technician->id]);

        $this->as($technician);
        $summary = $this->getJson('/api/v1/me/notifications/summary')->assertOk()->assertJsonPath('unread', 2)->json();
        $this->assertCount(2, $summary['latest']);
        $id = collect($summary['latest'])->firstWhere('link', "/work-orders/{$first['id']}")['id'];

        $this->getJson('/api/v1/me/notifications?q='.urlencode($first['code']))->assertOk()->assertJsonPath('total', 1);
        $this->postJson("/api/v1/me/notifications/{$id}/read")->assertOk()->assertJsonPath('read', true);
        $this->getJson('/api/v1/me/notifications?status=unread')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('unread', 1);
        $this->getJson('/api/v1/me/notifications?status=read')->assertOk()->assertJsonPath('data.0.id', $id);
        // Marcarla otra vez no cambia la fecha de lectura.
        $readAt = DB::table('notifications')->where('id', $id)->value('read_at');
        $this->postJson("/api/v1/me/notifications/{$id}/read")->assertOk();
        $this->assertSame($readAt, DB::table('notifications')->where('id', $id)->value('read_at'));

        $this->postJson('/api/v1/me/notifications/read-all')->assertOk()->assertJsonPath('marked', 1);
        $this->getJson('/api/v1/me/notifications/summary')->assertJsonPath('unread', 0);

        // La de otra persona de la misma empresa "no existe", ni se cuenta ni se lista.
        $this->as($planner);
        $this->postJson("/api/v1/me/notifications/{$id}/read")->assertNotFound();
        $this->getJson('/api/v1/me/notifications')->assertOk()->assertJsonPath('total', 0);
        $this->postJson('/api/v1/me/notifications/not-a-uuid/read')->assertNotFound();

        // Ni la de otra empresa.
        $otherTenant = $this->tenantId;
        $this->makeTenant();
        $stranger = $this->makeUser('Otra empresa', self::PLANNER);
        $this->as($stranger);
        $this->postJson("/api/v1/me/notifications/{$id}/read")->assertNotFound();
        DB::statement("SET app.tenant_id = '{$otherTenant}'");

        // La base: leída es para siempre, y la aplicación no cambia el texto ni borra avisos.
        $this->assertDatabaseRejects(fn () => DB::table('notifications')->where('id', $id)->update(['read_at' => null]));
        $this->assertDatabaseRejects(fn () => DB::table('notifications')->where('id', $id)->update(['subject' => 'Otro texto']));
        $this->assertDatabaseRejects(fn () => DB::table('notifications')->where('id', $id)->delete());
        $this->assertDatabaseRejects(fn () => DB::table('notifications')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $otherTenant, 'user_id' => $technician->id, 'channel' => 'IN_APP',
            'subject' => 'X', 'body' => 'Y', 'link' => 'https://otro-sitio.example/robo',
        ]));
    }

    public function test_dashboard_blocks_follow_permission_scope_and_plan(): void
    {
        $this->makeTenant(['ORDENES_TRABAJO']);
        $planner = $this->makeUser('Planificación', [...self::PLANNER, 'receivables.view', 'audit.view']);
        $technician = $this->makeUser('Técnico', self::TECHNICIAN, 'OWN');
        $nobody = $this->makeUser('Sin permisos', []);
        $contracted = $this->makeContracted();
        $this->order($planner, $contracted, ['assigned_user_id' => $technician->id]);
        $this->order($planner, $contracted);
        $this->order($planner, $contracted);

        // Toda la empresa: tres OT, dos sin técnico; Cuentas por cobrar con candado (FINANZAS no está en el plan).
        $this->as($planner);
        $data = $this->getJson('/api/v1/dashboard')->assertOk()->json();
        $kpis = collect($data['kpis'])->keyBy('key');
        $this->assertSame('1 en curso', $kpis['work_orders']['text']);
        $this->assertTrue($kpis['receivables']['locked']);
        $this->assertArrayNotHasKey('value', $kpis['receivables']);
        $this->assertSame(2, collect($data['attention'])->firstWhere('key', 'wo_unassigned')['count']);
        $this->assertSame([['label' => 'OT', 'scope' => 'Toda la empresa']], $data['scope']);
        $this->assertSame('Nueva orden de trabajo', $data['hero']['cta']['label']);
        $this->assertNotEmpty($data['recent']);
        $this->assertTrue(collect($data['charts'])->firstWhere('key', 'aging')['locked']);

        // El técnico: solo lo suyo, su acceso a su app y sus órdenes asignadas.
        $this->as($technician);
        $data = $this->getJson('/api/v1/dashboard')->assertOk()->json();
        $this->assertSame('1 en curso', collect($data['kpis'])->firstWhere('key', 'work_orders')['text']);
        $this->assertNull(collect($data['attention'])->firstWhere('key', 'wo_unassigned'));
        $this->assertSame(1, collect($data['attention'])->firstWhere('key', 'my_work_orders')['count']);
        $this->assertSame('/campo', $data['hero']['cta']['href']);
        $this->assertSame([['label' => 'OT', 'scope' => 'Propios']], $data['scope']);
        $this->assertNull($data['recent']);
        $this->assertNotNull(collect($data['kpis'])->firstWhere('key', 'my_hours'));

        // Sin permisos: el saludo y nada más (ni candados de lo que su rol no tiene).
        $this->as($nobody);
        $data = $this->getJson('/api/v1/dashboard')->assertOk()->json();
        $this->assertSame([], $data['kpis']);
        $this->assertSame([], $data['attention']);
        $this->assertSame([], $data['charts']);
        $this->assertNull($data['health']);
        $this->assertNull($data['recent']);

        // Otra empresa no ve nada de esta.
        $this->makeTenant(['ORDENES_TRABAJO']);
        $stranger = $this->makeUser('Otra empresa', self::PLANNER);
        $this->as($stranger);
        $data = $this->getJson('/api/v1/dashboard')->assertOk()->json();
        $this->assertSame('0 en curso', collect($data['kpis'])->firstWhere('key', 'work_orders')['text']);
        $this->assertNull(collect($data['attention'])->firstWhere('key', 'wo_unassigned'));
    }

    public function test_exporting_the_recent_activity_requires_its_permission(): void
    {
        $this->makeTenant();
        $viewer = $this->makeUser('Gerencia', ['audit.view']);
        $this->as($viewer);
        $this->postJson('/api/v1/exports', ['resource' => 'audit', 'format' => 'xlsx', 'rows' => 6, 'selection' => true])
            ->assertForbidden()->assertJsonPath('code', 'PERMISSION_DENIED');

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/dashboard')->assertUnauthorized();
        $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/me/notifications/summary')->assertUnauthorized();
    }
}
