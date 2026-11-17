<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Personal\HrSaas\FakeHrSaasGateway;
use App\Modules\Personal\HrSaas\HrSaasSync;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\OperationsFixtures;
use Tests\TestCase;

/**
 * Personal (kit v1.5, D-44/D-45): trabajadores con costo por hora, ausencias que bloquean la
 * agenda, envío versionado a planilla y conexión con el SaaS de RRHH (contrato §7: webhook
 * repetido sin cambios, versión vieja ignorada, DNI repetido a la bandeja, 422 HR_MASTERED y
 * reenvío de horas en versión 2).
 */
class PersonalTest extends TestCase
{
    use DatabaseTransactions;
    use OperationsFixtures;

    private const MODULES = ['PERSONAL', 'ORDENES_TRABAJO', 'CONTRATOS', 'AGENDA', 'TIMESHEETS', 'PROYECTOS'];

    private const HR_ADMIN = [
        'employees.view', 'employees.create', 'employees.manage', 'employees.export',
        'leaves.view', 'leaves.create', 'leaves.approve', 'leaves.manage', 'leaves.export',
        'hr_sync.view', 'hr_sync.create', 'hr_sync.manage', 'hr_sync.approve', 'hr_sync.export',
        'agenda.view', 'agenda.create', 'agenda.manage', 'core.view', 'contracted_services.view',
        'work_orders.view', 'work_orders.create', 'work_orders.manage', 'work_orders.approve',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        FakeHrSaasGateway::reset();
    }

    private function setupTenant(): User
    {
        $this->makeTenant(self::MODULES);
        DB::table('companies')->where('tenant_id', $this->tenantId)->update(['tax_identifier' => '20123456789']);

        return $this->scoped($this->makeUser('Ana Admin RRHH', self::HR_ADMIN), ['employees' => 'TENANT', 'leaves' => 'TENANT', 'agenda' => 'TENANT']);
    }

    private function scoped(User $user, array $scopes): User
    {
        $roleId = DB::table('user_roles')->where('user_id', $user->id)->value('role_id');
        foreach ($scopes as $resource => $scope) {
            DB::table('role_scopes')->updateOrInsert(['role_id' => $roleId, 'resource' => $resource], ['tenant_id' => $this->tenantId, 'scope' => $scope]);
        }

        return $user;
    }

    private function worker(array $data = []): array
    {
        return $this->postJson('/api/v1/hr/employees', [
            'given_names' => 'Rocío', 'family_name' => 'Quispe Mamani', 'document_type' => 'DNI', 'document_number' => '70000001',
            'hire_date' => '2026-01-05', 'contract_type' => 'INDEFINIDO', 'weekly_hours' => 48, 'hourly_cost' => 24.8, ...$data,
        ])->assertCreated()->json();
    }

    /** Hoy temprano (hora de Lima): siempre dentro del mes de Lima. */
    private function started(): \Illuminate\Support\Carbon
    {
        return now('America/Lima')->startOfDay()->addMinutes(30)->utc();
    }

    private function day(int $days): string
    {
        return now('America/Lima')->addDays($days)->toDateString();
    }

    public function test_workers_reuse_people_keep_one_hourly_cost_and_value_hours(): void
    {
        $admin = $this->setupTenant();
        $technician = $this->makeUser('Tito Técnico', ['time_entries.view', 'time_entries.create']);
        $this->as($admin);

        $worker = $this->worker(['user_id' => $technician->id]);
        $this->assertSame(['EMP-001', 'Rocío Quispe Mamani', 24.8], [$worker['code'], $worker['name'], $worker['hourly_cost']]);
        // El costo por hora queda también en su usuario (un solo dato).
        $this->assertEquals(24.8, DB::table('tenant_users')->where('user_id', $technician->id)->value('hourly_cost'));

        // El mismo documento no crea otra persona ni otro trabajador.
        $this->postJson('/api/v1/hr/employees', [
            'given_names' => 'Rocío', 'family_name' => 'Quispe', 'document_type' => 'DNI', 'document_number' => '70000001',
            'hire_date' => '2026-01-05', 'contract_type' => 'INDEFINIDO', 'weekly_hours' => 48, 'hourly_cost' => 20,
        ])->assertStatus(422)->assertJsonValidationErrors('party_id');
        $this->assertSame(1, DB::table('persons')->join('parties', 'parties.id', '=', 'persons.party_id')
            ->where('parties.tenant_id', $this->tenantId)->where('document_number', '70000001')->count());

        // Reglas básicas: costo positivo, jornada hasta 48 h, DNI de 8 dígitos.
        $this->postJson('/api/v1/hr/employees', ['given_names' => 'X', 'family_name' => 'Y', 'document_type' => 'DNI', 'document_number' => '123',
            'hire_date' => '2026-01-05', 'contract_type' => 'INDEFINIDO', 'weekly_hours' => 60, 'hourly_cost' => 0])
            ->assertStatus(422)->assertJsonValidationErrors(['document_number', 'weekly_hours', 'hourly_cost']);

        // Las horas que registra el técnico se valorizan con el costo de su ficha.
        $this->putJson("/api/v1/hr/employees/{$worker['id']}", ['hourly_cost' => 30])->assertOk()->assertJsonPath('hourly_cost', 30);
        $this->assertSame(30.0, \App\Modules\Personal\Support\Personal::hourlyCost($this->tenantId, $technician->id));
        $this->assertEquals(30, DB::table('tenant_users')->where('user_id', $technician->id)->value('hourly_cost'));

        // Lista con filtros y alcance: quien solo ve lo propio no ve a los demás.
        $this->assertSame(1, $this->getJson('/api/v1/hr/employees?search=quispe')->assertOk()->json('total'));
        $other = $this->makeUser('Otro', ['employees.view']);
        $this->as($other);
        $this->assertSame(0, $this->getJson('/api/v1/hr/employees')->assertOk()->json('total'));
        $this->getJson("/api/v1/hr/employees/{$worker['id']}")->assertNotFound();

        // Cese: con fecha y motivo; no antes del ingreso; nada se borra.
        $this->as($admin);
        $this->postJson("/api/v1/hr/employees/{$worker['id']}/terminate", ['termination_date' => '2025-12-01', 'reason' => 'Renuncia'])
            ->assertStatus(422)->assertJsonValidationErrors('termination_date');
        $this->postJson("/api/v1/hr/employees/{$worker['id']}/terminate", ['termination_date' => $this->day(0), 'reason' => 'Renuncia'])
            ->assertOk()->assertJsonPath('status', 'TERMINATED');
        $this->assertDatabaseRejects(fn () => DB::table('employees')->where('id', $worker['id'])->update(['hourly_cost' => -1]));
    }

    public function test_leaves_cannot_be_self_approved_overlap_or_be_assigned_work(): void
    {
        $admin = $this->setupTenant();
        $technician = $this->scoped($this->makeUser('Tito Técnico', ['leaves.view', 'leaves.create', 'leaves.approve', 'agenda.view', 'work_orders.view', 'work_orders.manage']),
            ['leaves' => 'OWN']);
        $contracted = $this->makeContracted();
        $this->as($admin);
        $worker = $this->worker(['user_id' => $technician->id]);
        $other = $this->worker(['given_names' => 'Julio', 'family_name' => 'Ccori', 'document_number' => '70000002']);
        $types = collect($this->getJson('/api/v1/hr/leave-types')->assertOk()->json('data'))->keyBy('code');

        // El técnico pide sus vacaciones; no puede pedir por otro (alcance propio).
        $this->as($technician);
        $options = $this->getJson('/api/v1/hr/leaves/options')->assertOk()->json('employees');
        $this->assertSame([$worker['id']], array_column($options, 'id'));
        $this->postJson('/api/v1/hr/leaves', ['employee_id' => $other['id'], 'leave_type_id' => $types['VAC']['id'], 'date_from' => $this->day(10), 'date_to' => $this->day(14)])
            ->assertStatus(422)->assertJsonValidationErrors('employee_id');
        $leave = $this->postJson('/api/v1/hr/leaves', ['employee_id' => $worker['id'], 'leave_type_id' => $types['VAC']['id'],
            'date_from' => $this->day(10), 'date_to' => $this->day(14), 'reason' => 'Viaje familiar'])->assertCreated()->json();
        $this->assertSame([5, 'REQUESTED'], [$leave['days'], $leave['status']]);

        // Nadie aprueba su propia solicitud (ni la base lo deja).
        $this->postJson("/api/v1/hr/leaves/{$leave['id']}/approve")->assertStatus(422);
        $this->assertDatabaseRejects(fn () => DB::table('hr_leave_requests')->where('id', $leave['id'])->update(['status' => 'APPROVED', 'decided_by' => $technician->id]));

        // Rechazar exige motivo; aprobar la deja en firme.
        $this->as($admin);
        $this->postJson("/api/v1/hr/leaves/{$leave['id']}/reject")->assertStatus(422)->assertJsonValidationErrors('note');
        $this->postJson("/api/v1/hr/leaves/{$leave['id']}/approve")->assertOk()->assertJsonPath('status', 'APPROVED');

        // Otra ausencia que se cruza no pasa (ni pedida, ni forzada en la base).
        $this->postJson('/api/v1/hr/leaves', ['employee_id' => $worker['id'], 'leave_type_id' => $types['DM']['id'], 'date_from' => $this->day(12), 'date_to' => $this->day(16)])
            ->assertStatus(422)->assertJsonValidationErrors('date_from');
        $this->assertDatabaseRejects(fn () => DB::table('hr_leave_requests')->insert([
            'tenant_id' => $this->tenantId, 'employee_id' => $worker['id'], 'leave_type_id' => $types['DM']['id'], 'user_id' => $technician->id,
            'date_from' => $this->day(13), 'date_to' => $this->day(13), 'days' => 1, 'status' => 'APPROVED',
        ]));

        // La ausencia bloquea la agenda y la asignación de órdenes de trabajo.
        $at = now('America/Lima')->addDays(11)->setTime(9, 0)->toIso8601String();
        $this->postJson('/api/v1/agenda', ['title' => 'Visita', 'starts_at' => $at, 'assigned_user_id' => $technician->id, 'force' => true])
            ->assertStatus(422)->assertJsonPath('errors.assigned_user_id.0', fn ($m) => str_contains($m, 'está ausente'));
        $this->postJson('/api/v1/work-orders', ['contracted_service_id' => $contracted, 'title' => 'Mantenimiento', 'assigned_user_id' => $technician->id, 'scheduled_start' => $at])
            ->assertStatus(422)->assertJsonValidationErrors('assigned_user_id');
        // Fuera de la ausencia, sí.
        $this->postJson('/api/v1/work-orders', ['contracted_service_id' => $contracted, 'title' => 'Mantenimiento', 'assigned_user_id' => $technician->id,
            'scheduled_start' => now('America/Lima')->addDays(20)->setTime(9, 0)->toIso8601String()])->assertCreated();
        // La agenda la muestra como bloqueo.
        $range = http_build_query(['from' => now()->addDays(9)->toIso8601String(), 'to' => now()->addDays(16)->toIso8601String()]);
        $this->assertSame('Vacaciones', $this->getJson("/api/v1/agenda?{$range}")->assertOk()->json('absences.0.label'));

        // Anulada, libera la agenda.
        $this->postJson("/api/v1/hr/leaves/{$leave['id']}/cancel")->assertOk()->assertJsonPath('status', 'CANCELLED');
        $this->postJson('/api/v1/agenda', ['title' => 'Visita', 'starts_at' => $at, 'assigned_user_id' => $technician->id])->assertCreated();
    }

    public function test_payroll_export_is_versioned_and_immutable(): void
    {
        $admin = $this->setupTenant();
        $technician = $this->makeUser('Tito Técnico', ['time_entries.view']);
        $this->as($admin);
        $this->worker(['user_id' => $technician->id]);
        $period = now('America/Lima')->format('Y-m');
        $order = $this->order($admin, $this->makeContracted());
        DB::table('time_entries')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'user_id' => $technician->id, 'work_order_id' => $order['id'],
            'started_at' => $this->started(), 'ended_at' => $this->started()->addHours(2), 'hours' => 2, 'hourly_cost' => 24.8,
            'billable' => true, 'status' => 'APPROVED', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $preview = $this->getJson("/api/v1/hr/payroll-exports/preview?period={$period}")->assertOk();
        $this->assertSame(['FILE', 120, 1], [$preview->json('destination'), $preview->json('minutes_total'), $preview->json('next_version')]);

        $first = $this->postJson('/api/v1/hr/payroll-exports', ['period' => $period])->assertCreated()->json();
        $this->assertSame([1, 'FILE', 'GENERATED', 2.0], [$first['version'], $first['destination'], $first['status'], (float) $first['hours_total']]);
        $this->assertSame(2, $this->postJson('/api/v1/hr/payroll-exports', ['period' => $period])->assertCreated()->json('version'));

        $file = $this->get("/api/v1/hr/payroll-exports/{$first['id']}/file")->assertOk();
        $this->assertStringContainsString('spreadsheetml', $file->headers->get('Content-Type'));
        $this->postJson("/api/v1/hr/payroll-exports/{$first['id']}/send")->assertOk()->assertJsonPath('status', 'SENT');

        // Lo generado no se reescribe; el mes que no empieza no se genera.
        $this->assertDatabaseRejects(fn () => DB::table('hr_payroll_exports')->where('id', $first['id'])->update(['payload' => '{}']));
        $this->postJson('/api/v1/hr/payroll-exports', ['period' => now()->addMonths(2)->format('Y-m')])->assertStatus(422);
    }

    public function test_hr_saas_connection_initial_match_webhook_and_hours(): void
    {
        $admin = $this->setupTenant();
        $technician = $this->makeUser('Julio Ccori', ['time_entries.view']);
        $this->as($admin);
        // Una persona del ERP con el DNI de RRHH (se empareja) y dos con el mismo DNI (van a la bandeja).
        $julio = $this->worker(['given_names' => 'Julio', 'family_name' => 'Ccori', 'document_number' => '70000002', 'user_id' => $technician->id]);
        foreach (['A', 'B'] as $suffix) {
            $party = (string) Str::uuid();
            DB::table('parties')->insert(['id' => $party, 'tenant_id' => $this->tenantId, 'party_type' => 'PERSON', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('persons')->insert(['party_id' => $party, 'given_names' => "Elena {$suffix}", 'family_name' => 'Torres', 'document_type' => 'DNI', 'document_number' => '70000003', 'metadata' => '{}']);
        }

        // Alta de la conexión: la clave de firma se muestra una sola vez y queda cifrada en el Vault.
        $saved = $this->putJson('/api/v1/hr/sync/connection', ['base_url' => 'https://rrhh.ejemplo.pe', 'token' => str_repeat('t', 32)])->assertCreated();
        $secret = $saved->json('secret');
        $this->assertStringStartsWith('whsec_', $secret);
        $this->assertSame('ERP_MASTER', $saved->json('status.mode'));
        $this->assertNull($this->getJson('/api/v1/hr/sync/status')->json('secret'));
        $connectionId = $saved->json('status.connection.id');

        // Activar = carga inicial: Julio se empareja por DNI, Rocío no existe (bandeja), Elena tiene dos (bandeja).
        $activated = $this->postJson('/api/v1/hr/sync/activate')->assertOk();
        $this->assertSame('HR_SAAS_MASTER', $activated->json('status.mode'));
        $this->assertSame([6, 2], [$activated->json('run.received'), $activated->json('run.unmatched')]);
        $inbox = collect($this->getJson('/api/v1/hr/sync/unmatched')->assertOk()->json('data'))->keyBy('external_id');
        $this->assertSame(['NO_MATCH', 'MULTIPLE_MATCH'], [$inbox['AYNI-EMP-0001']['reason'], $inbox['AYNI-EMP-0003']['reason']]);
        $synced = $this->getJson("/api/v1/hr/employees/{$julio['id']}")->assertOk();
        $this->assertSame(['HR_SAAS', 'AYNI-EMP-0002', 23.1, 'Técnico de campo'], [$synced->json('source'), $synced->json('payroll_external_id'), $synced->json('hourly_cost'), $synced->json('position.name')]);
        // Su ausencia de RRHH también llegó, ya aprobada.
        $this->assertSame('APPROVED', DB::table('hr_leave_requests')->where('external_id', 'AYNI-LEA-0001')->value('status'));

        // RRHH maestro: editar sus datos en el ERP → 422 HR_MASTERED; la sede y el usuario sí.
        $this->putJson("/api/v1/hr/employees/{$julio['id']}", ['hourly_cost' => 99])->assertStatus(422)->assertJsonPath('code', 'HR_MASTERED');
        $this->putJson("/api/v1/hr/employees/{$julio['id']}", ['branch_id' => null])->assertOk();
        $this->postJson('/api/v1/hr/employees', ['given_names' => 'Nuevo', 'family_name' => 'X', 'document_type' => 'DNI', 'document_number' => '70000009',
            'hire_date' => '2026-01-05', 'contract_type' => 'INDEFINIDO', 'weekly_hours' => 48, 'hourly_cost' => 20])->assertStatus(422)->assertJsonPath('code', 'HR_MASTERED');

        // Resolver la bandeja: crear a Rocío.
        $this->postJson("/api/v1/hr/sync/unmatched/{$inbox['AYNI-EMP-0001']['id']}/resolve", ['action' => 'create'])->assertOk();
        $this->assertSame('HR_SAAS', DB::table('employees')->where('payroll_external_id', 'AYNI-EMP-0001')->value('source'));

        // Webhook firmado: se registra, se aplica y responde 202.
        $send = function (array $data, string $type = 'employee.upserted', ?string $eventId = null, ?string $sign = null, ?string $ruc = null, $occurred = null) use ($secret, $connectionId) {
            $body = json_encode(['event_id' => $eventId ??= 'evt_'.Str::random(10), 'event_type' => $type, 'occurred_at' => ($occurred ?? now())->toIso8601String(),
                'tenant_ruc' => $ruc ?? '20123456789', 'data' => $data]);

            return $this->call('POST', '/api/v1/webhooks/hr-saas', [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_CONNECTION_ID' => $connectionId, 'HTTP_X_EVENT_ID' => $eventId,
                'HTTP_X_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, $sign ?? $secret),
            ], $body);
        };
        $upsert = [...FakeHrSaasGateway::examples()['employees'][1], 'external_version' => 2, 'hourly_cost' => '26.00'];
        $send($upsert, eventId: 'evt_cambio_1')->assertStatus(202)->assertJsonPath('duplicate', false);
        HrSaasSync::processPending($this->tenantId);
        $this->assertEquals(26, DB::table('employees')->where('id', $julio['id'])->value('hourly_cost'));
        $this->assertEquals(26, DB::table('tenant_users')->where('user_id', $technician->id)->value('hourly_cost'));

        // Repetido: no cambia nada. Versión vieja: se ignora.
        $send($upsert, eventId: 'evt_cambio_1')->assertStatus(202)->assertJsonPath('duplicate', true);
        $send([...$upsert, 'external_version' => 1, 'hourly_cost' => '10.00'])->assertStatus(202);
        HrSaasSync::processPending($this->tenantId);
        $this->assertEquals(26, DB::table('employees')->where('id', $julio['id'])->value('hourly_cost'));

        // Firma, RUC o ventana de tiempo incorrectos: rechazado.
        $send($upsert, sign: 'otra-clave')->assertStatus(401);
        $send($upsert, ruc: '20999999999')->assertStatus(401);
        $send($upsert, occurred: now()->subMinutes(10))->assertStatus(409);

        // Horas a RRHH: versión 1 y, al regenerar, versión 2 (el simulador las recibe firmadas).
        $order = $this->order($admin, $this->makeContracted());
        DB::table('time_entries')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'user_id' => $technician->id, 'work_order_id' => $order['id'],
            'started_at' => $this->started(), 'ended_at' => $this->started()->addHours(3), 'hours' => 3, 'hourly_cost' => 26,
            'billable' => false, 'status' => 'APPROVED', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $period = now('America/Lima')->format('Y-m');
        $first = $this->postJson('/api/v1/hr/payroll-exports', ['period' => $period])->assertCreated()->json();
        $this->assertSame(['HR_SAAS', 'ACKNOWLEDGED'], [$first['destination'], $first['status']]);
        $this->postJson('/api/v1/hr/payroll-exports', ['period' => $period])->assertCreated()->assertJsonPath('version', 2);
        $sent = collect(FakeHrSaasGateway::$sent)->where('event_type', 'timesheet.period_approved')->values();
        $this->assertSame([1, 2], $sent->pluck('data.version')->all());
        $this->assertSame([['AYNI-EMP-0002', 180, false, $order['code']]], array_map(fn ($r) => [$r['employee_external_id'], $r['minutes'], $r['billable'], $r['work_order_number']], $sent[0]['data']['rows']));
        $this->assertSame('20123456789', $sent[0]['tenant_ruc']);

        // Si RRHH no confirma, se reintenta; tras el último intento queda fallido y se reenvía.
        FakeHrSaasGateway::$ack = false;
        $failed = $this->postJson('/api/v1/hr/payroll-exports', ['period' => $period])->assertCreated()->json();
        $outbound = DB::table('hr_outbound_events')->where('export_id', $failed['id'])->first();
        $this->assertSame('RETRY', $outbound->status);
        DB::table('hr_outbound_events')->where('id', $outbound->id)->update(['attempts' => HrSaasSync::MAX_ATTEMPTS - 1]);
        HrSaasSync::deliver($this->tenantId, $outbound->id);
        $this->assertSame(['DEAD', 'FAILED'], [DB::table('hr_outbound_events')->where('id', $outbound->id)->value('status'), DB::table('hr_payroll_exports')->where('id', $failed['id'])->value('status')]);
        FakeHrSaasGateway::$ack = true;
        $this->postJson("/api/v1/hr/sync/events/{$outbound->id}/retry")->assertOk()->assertJsonPath('status', 'SENT');

        // Desactivar no borra nada: el personal vuelve a editarse en el ERP.
        $this->postJson('/api/v1/hr/sync/deactivate')->assertOk()->assertJsonPath('mode', 'ERP_MASTER');
        $this->putJson("/api/v1/hr/employees/{$julio['id']}", ['hourly_cost' => 27])->assertOk();
        $this->assertSame('AYNI-EMP-0002', DB::table('employees')->where('id', $julio['id'])->value('payroll_external_id'));
        // Sin conexión activa, el webhook ya no entra.
        $send($upsert)->assertStatus(401);
    }

    public function test_personal_needs_its_module_and_permissions(): void
    {
        $this->makeTenant(['ORDENES_TRABAJO']);
        $this->as($this->makeUser('Admin', self::HR_ADMIN));
        $this->getJson('/api/v1/hr/employees')->assertStatus(403);

        $this->makeTenant(self::MODULES);
        $this->as($this->makeUser('Sin permisos', ['agenda.view']));
        $this->getJson('/api/v1/hr/employees')->assertStatus(403);
        $this->postJson('/api/v1/hr/sync/activate')->assertStatus(403);
    }
}
