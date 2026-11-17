<?php

namespace App\Modules\Personal\HrSaas;

use App\Modules\Personal\Support\Personal;
use App\Services\AuditLogger;
use App\Services\Notifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sincronización con el SaaS de RRHH (contrato docs/14-INTEGRACION-RRHH.md, D-45).
 *
 * - Cada cambio se aplica por `external_id` y solo si su `external_version` es mayor que la
 *   guardada: un mensaje repetido o atrasado no cambia nada (RN-HRM-06).
 * - La carga inicial empareja por tipo y número de documento; sin pareja o con varias, el
 *   trabajador queda en la bandeja (RN-HRM-07). Después, un alta nueva de RRHH se crea sola.
 * - Lo que el ERP envía (horas del periodo, vínculo con un usuario) sale por una cola propia con
 *   reintentos; tras el último intento queda como fallido y se reenvía desde Sincronización.
 *
 * Todo corre dentro del contexto de la empresa (RLS).
 */
class HrSaasSync
{
    public const MAX_ATTEMPTS = 5;

    /** Minutos de espera tras cada intento fallido. */
    private const BACKOFF = [1, 5, 30, 120];

    /** Ventana para aceptar un webhook (anti-replay, §3). */
    public const REPLAY_WINDOW_MINUTES = 5;

    private const DOC_TYPES = ['DNI' => 'DNI', 'CE' => 'CE', 'PASAPORTE' => 'PASSPORT', 'PASSPORT' => 'PASSPORT'];

    public static function gateway(): HrSaasGateway
    {
        return app(HrSaasGateway::class);
    }

    // ---------------------------------------------------------------- Lectura completa

    /** Carga inicial (al activar) o relectura completa. Devuelve la ejecución terminada. */
    public static function pull(string $tenantId, object $connection, string $kind, ?string $userId): object
    {
        $runId = self::startRun($tenantId, $connection->id, $kind, $userId);
        $counts = ['received' => 0, 'applied' => 0, 'skipped' => 0, 'unmatched' => 0];
        $initial = $kind === 'INITIAL_MATCH';
        $failed = 0;

        try {
            $lists = [
                'positions' => fn (array $data) => self::applyPosition($tenantId, $data),
                'employees' => fn (array $data) => self::applyEmployee($tenantId, $data, $runId, $initial),
                'leaves' => fn (array $data) => self::applyLeave($tenantId, $data),
            ];
            foreach ($lists as $list => $apply) {
                $query = $list === 'leaves'
                    ? ['from' => now('America/Lima')->subDays(60)->toDateString(), 'to' => now('America/Lima')->addYear()->toDateString()]
                    : [];
                $page = 1;
                $guard = 0;
                do {
                    $result = self::gateway()->fetch($connection, $list, $page, $query);
                    foreach ($result['items'] as $item) {
                        $counts['received']++;
                        $outcome = self::safely(fn () => $apply((array) $item));
                        $counts[$outcome === 'APPLIED' ? 'applied' : ($outcome === 'UNMATCHED' ? 'unmatched' : 'skipped')]++;
                        if ($outcome === 'FAILED') {
                            $failed++;
                        }
                    }
                    $page = $result['next_page'];
                } while ($page && ++$guard < 500);
            }
            self::finishRun($runId, 'COMPLETED', $counts, $failed ? "{$failed} registro(s) no se pudieron aplicar." : null);
        } catch (Throwable $e) {
            self::finishRun($runId, 'FAILED', $counts, self::friendly($e));
        }

        return DB::table('hr_sync_runs')->where('id', $runId)->first();
    }

    // ---------------------------------------------------------------- Webhook

    /** Procesa los mensajes recibidos pendientes de la empresa, en una sola ejecución. */
    public static function processPending(string $tenantId): int
    {
        $events = DB::table('hr_inbound_events')->where('tenant_id', $tenantId)->where('status', 'RECEIVED')
            ->orderBy('received_at')->limit(200)->get();
        if ($events->isEmpty()) {
            return 0;
        }
        $connectionId = $events->first()->connection_id;
        $runId = self::startRun($tenantId, $connectionId, 'WEBHOOK', null);
        $counts = ['received' => 0, 'applied' => 0, 'skipped' => 0, 'unmatched' => 0];

        foreach ($events as $event) {
            $counts['received']++;
            $payload = json_decode((string) $event->payload, true) ?: [];
            $data = (array) ($payload['data'] ?? []);
            $note = null;
            $outcome = self::safely(function () use ($tenantId, $event, $data, $runId) {
                return match ($event->event_type) {
                    'employee.upserted' => self::applyEmployee($tenantId, $data, $runId, false),
                    'employee.terminated' => self::applyTermination($tenantId, $data),
                    'position.upserted' => self::applyPosition($tenantId, $data),
                    'leave.upserted' => self::applyLeave($tenantId, $data),
                    default => 'SKIPPED',
                };
            }, $note);
            $counts[$outcome === 'APPLIED' ? 'applied' : ($outcome === 'UNMATCHED' ? 'unmatched' : 'skipped')]++;
            DB::table('hr_inbound_events')->where('id', $event->id)->update([
                'status' => $outcome, 'result' => $note, 'attempts' => DB::raw('attempts + 1'), 'processed_at' => now(),
            ]);
        }
        self::finishRun($runId, 'COMPLETED', $counts);
        DB::table('hr_connections')->where('id', $connectionId)->update(['last_event_at' => now()]);

        return $counts['received'];
    }

    // ---------------------------------------------------------------- Aplicar cada cambio

    /** Trabajador de RRHH → ERP. APPLIED, SKIPPED (versión vieja o repetida) o UNMATCHED (bandeja). */
    public static function applyEmployee(string $tenantId, array $data, ?string $runId, bool $initial): string
    {
        $externalId = trim((string) ($data['external_id'] ?? ''));
        $version = (int) ($data['external_version'] ?? 0);
        if ($externalId === '' || $version < 1) {
            throw new HrSyncSkip('Mensaje sin external_id o external_version.');
        }

        $employee = DB::table('employees')->where('tenant_id', $tenantId)->where('payroll_external_id', $externalId)->first();
        if ($employee && (int) $employee->external_version >= $version) {
            return 'SKIPPED';
        }

        if (! $employee) {
            $docType = self::DOC_TYPES[strtoupper((string) ($data['doc_type'] ?? ''))] ?? null;
            $docNumber = trim((string) ($data['doc_number'] ?? ''));
            $people = $docType && $docNumber !== ''
                ? DB::table('persons')->join('parties', 'parties.id', '=', 'persons.party_id')
                    ->where('parties.tenant_id', $tenantId)->where('persons.document_type', $docType)->where('persons.document_number', $docNumber)
                    ->pluck('persons.party_id')->all()
                : [];

            if (count($people) > 1) {
                return self::toInbox($tenantId, $runId, $data, 'MULTIPLE_MATCH', $people);
            }
            if (count($people) === 1) {
                $partyId = $people[0];
                $current = DB::table('employees')->where('tenant_id', $tenantId)->where('person_party_id', $partyId)->first();
                if ($current && $current->payroll_external_id && $current->payroll_external_id !== $externalId) {
                    return self::toInbox($tenantId, $runId, $data, 'CONFLICT', [$current->id]);
                }
                $employee = $current ?: self::newEmployeeFor($tenantId, $partyId);
            } elseif ($initial) {
                return self::toInbox($tenantId, $runId, $data, 'NO_MATCH', []);
            } else {
                $employee = self::newEmployeeFor($tenantId, self::newPerson($tenantId, $data));
            }
        }

        self::writeEmployee($tenantId, $employee, $data);

        return 'APPLIED';
    }

    /** Escribe en el trabajador lo que manda RRHH (nunca el sueldo: solo el costo por hora). */
    public static function writeEmployee(string $tenantId, object $employee, array $data): void
    {
        $positionId = null;
        if (! empty($data['position_external_id'])) {
            $positionId = DB::table('hr_positions')->where('tenant_id', $tenantId)->where('external_id', $data['position_external_id'])->value('id');
        }
        $branchId = null;
        if (! empty($data['branch_code'])) {
            $branchId = DB::table('branches')->where('tenant_id', $tenantId)->where('code', $data['branch_code'])->value('id');
            if (! $branchId) {
                self::notify($tenantId, 'Sede desconocida desde RRHH',
                    "El SaaS de RRHH asignó a {$data['given_names']} {$data['family_name']} la sede «{$data['branch_code']}», que no existe en el ERP. Quedó sin sede.");
            }
        }
        $contract = strtoupper((string) ($data['contract_type'] ?? ''));
        $cost = isset($data['hourly_cost']) && is_numeric($data['hourly_cost']) && (float) $data['hourly_cost'] > 0 ? round((float) $data['hourly_cost'], 2) : null;
        $hours = isset($data['weekly_hours']) && is_numeric($data['weekly_hours']) ? max(0.5, min(48, (float) $data['weekly_hours'])) : 48;

        $values = [
            'payroll_external_id' => $data['external_id'],
            'external_version' => (int) $data['external_version'],
            'source' => 'HR_SAAS',
            'synced_at' => now(),
            'position_id' => $positionId,
            'hire_date' => self::date($data['hire_date'] ?? null),
            'contract_type' => isset(Personal::CONTRACT_TYPES[$contract]) ? $contract : null,
            'weekly_hours' => $hours,
            'hourly_cost' => $cost,
            'currency' => in_array($data['currency'] ?? 'PEN', ['PEN', 'USD'], true) ? ($data['currency'] ?? 'PEN') : 'PEN',
            'updated_at' => now(),
            'lock_version' => DB::raw('lock_version + 1'),
        ];
        // La sede operativa se edita en el ERP: solo se pone si RRHH la manda y existe.
        if ($branchId) {
            $values['branch_id'] = $branchId;
        }
        if ($positionId) {
            $values['position'] = DB::table('hr_positions')->where('id', $positionId)->value('name');
        }
        if ($employee->status === 'TERMINATED' && empty($data['termination_date'])) {
            // Un reingreso en RRHH vuelve a activar al trabajador.
            $values['status'] = 'ACTIVE';
            $values['termination_date'] = null;
            $values['termination_reason'] = null;
        }
        DB::table('employees')->where('id', $employee->id)->update($values);

        $names = array_filter(['given_names' => trim((string) ($data['given_names'] ?? '')), 'family_name' => trim((string) ($data['family_name'] ?? ''))]);
        if ($names) {
            DB::table('persons')->where('party_id', $employee->person_party_id)->update($names);
        }
        if ($cost !== null) {
            Personal::copyCostToUser($tenantId, $employee->user_id, $cost);
        }
    }

    public static function applyTermination(string $tenantId, array $data): string
    {
        $employee = DB::table('employees')->where('tenant_id', $tenantId)->where('payroll_external_id', (string) ($data['external_id'] ?? ''))->first();
        if (! $employee) {
            return 'UNMATCHED';
        }
        if ((int) $employee->external_version >= (int) ($data['external_version'] ?? 0)) {
            return 'SKIPPED';
        }
        $date = self::date($data['termination_date'] ?? null) ?? now('America/Lima')->toDateString();
        DB::table('employees')->where('id', $employee->id)->update([
            'status' => 'TERMINATED', 'termination_date' => $employee->hire_date && $date < $employee->hire_date ? $employee->hire_date : $date,
            'termination_reason' => Str::limit((string) ($data['reason'] ?? 'Cese registrado en el SaaS de RRHH'), 490, ''),
            'external_version' => (int) $data['external_version'], 'synced_at' => now(), 'updated_at' => now(),
            'lock_version' => DB::raw('lock_version + 1'),
        ]);
        self::warnOpenWork($tenantId, $employee);

        return 'APPLIED';
    }

    public static function applyPosition(string $tenantId, array $data): string
    {
        $externalId = trim((string) ($data['external_id'] ?? ''));
        $version = (int) ($data['external_version'] ?? 0);
        $name = trim((string) ($data['name'] ?? ''));
        if ($externalId === '' || $version < 1 || $name === '') {
            throw new HrSyncSkip('Puesto sin external_id, versión o nombre.');
        }
        $position = DB::table('hr_positions')->where('tenant_id', $tenantId)->where('external_id', $externalId)->first();
        if ($position && (int) $position->external_version >= $version) {
            return 'SKIPPED';
        }
        $code = Str::limit(trim((string) ($data['code'] ?? '')) ?: $externalId, 50, '');
        // Un puesto del ERP con el mismo código pasa a ser el de RRHH.
        $position ??= DB::table('hr_positions')->where('tenant_id', $tenantId)->where('code', $code)->whereNull('external_id')->first();
        $cost = isset($data['default_hourly_cost']) && is_numeric($data['default_hourly_cost']) && (float) $data['default_hourly_cost'] > 0
            ? round((float) $data['default_hourly_cost'], 2) : null;
        $values = [
            'name' => Str::limit($name, 150, ''), 'department' => Str::limit((string) ($data['department'] ?? ''), 150, '') ?: null,
            'default_hourly_cost' => $cost, 'active' => (bool) ($data['active'] ?? true), 'source' => 'HR_SAAS',
            'external_id' => $externalId, 'external_version' => $version, 'synced_at' => now(),
        ];
        if ($position) {
            DB::table('hr_positions')->where('id', $position->id)->update([...$values, 'lock_version' => DB::raw('lock_version + 1')]);
            DB::table('employees')->where('position_id', $position->id)->update(['position' => $values['name']]);
        } else {
            if (DB::table('hr_positions')->where('tenant_id', $tenantId)->where('code', $code)->exists()) {
                $code = Str::limit($code, 40, '').'-'.Str::upper(Str::random(4));
            }
            DB::table('hr_positions')->insert([...$values, 'tenant_id' => $tenantId, 'code' => $code, 'created_at' => now(), 'updated_at' => now()]);
        }

        return 'APPLIED';
    }

    public static function applyLeave(string $tenantId, array $data): string
    {
        $externalId = trim((string) ($data['external_id'] ?? ''));
        $version = (int) ($data['external_version'] ?? 0);
        $status = strtoupper((string) ($data['status'] ?? ''));
        $from = self::date($data['date_from'] ?? null);
        $to = self::date($data['date_to'] ?? null);
        if ($externalId === '' || $version < 1 || ! in_array($status, ['APPROVED', 'CANCELLED'], true) || ! $from || ! $to || $to < $from) {
            throw new HrSyncSkip('Ausencia incompleta: se esperan external_id, versión, fechas y estado APPROVED o CANCELLED.');
        }
        $employee = DB::table('employees')->where('tenant_id', $tenantId)->where('payroll_external_id', (string) ($data['employee_external_id'] ?? ''))->first();
        if (! $employee) {
            throw new HrSyncSkip('La ausencia es de un trabajador que aún no está en el ERP (pendiente en la bandeja o sin sincronizar).', 'UNMATCHED');
        }
        $leave = DB::table('hr_leave_requests')->where('tenant_id', $tenantId)->where('external_id', $externalId)->first();
        if ($leave && (int) $leave->external_version >= $version) {
            return 'SKIPPED';
        }

        Personal::ensureLeaveTypes($tenantId);
        $code = Str::limit(strtoupper(trim((string) ($data['leave_type_code'] ?? 'OTRO'))) ?: 'OTRO', 30, '');
        $typeId = DB::table('hr_leave_types')->where('tenant_id', $tenantId)->where('code', $code)->value('id');
        if (! $typeId) {
            $typeId = DB::table('hr_leave_types')->insertGetId([
                'tenant_id' => $tenantId, 'code' => $code, 'name' => "Ausencia {$code} (RRHH)", 'created_at' => now(), 'updated_at' => now(),
            ], 'id');
        }
        $days = (int) ($data['days'] ?? 0);
        $span = Personal::days($from, $to);
        $values = [
            'employee_id' => $employee->id, 'leave_type_id' => $typeId, 'user_id' => $employee->user_id, 'branch_id' => $employee->branch_id,
            'date_from' => $from, 'date_to' => $to, 'days' => $days > 0 && $days <= $span ? $days : $span,
            'status' => $status, 'decided_by' => null, 'decided_at' => now(), 'decision_note' => null,
            'source' => 'HR_SAAS', 'external_id' => $externalId, 'external_version' => $version, 'updated_at' => now(),
        ];
        if ($leave) {
            DB::table('hr_leave_requests')->where('id', $leave->id)->update([...$values, 'lock_version' => DB::raw('lock_version + 1')]);
        } else {
            DB::table('hr_leave_requests')->insert([...$values, 'tenant_id' => $tenantId, 'created_at' => now()]);
        }

        return 'APPLIED';
    }

    // ---------------------------------------------------------------- Bandeja

    private static function toInbox(string $tenantId, ?string $runId, array $data, string $reason, array $candidates): string
    {
        if (! $runId) {
            return 'UNMATCHED';
        }
        $externalId = (string) $data['external_id'];
        $row = [
            'run_id' => $runId, 'doc_type' => Str::limit((string) ($data['doc_type'] ?? ''), 20, '') ?: null,
            'doc_number' => Str::limit((string) ($data['doc_number'] ?? ''), 30, '') ?: null,
            'full_name' => Str::limit(trim(($data['given_names'] ?? '').' '.($data['family_name'] ?? '')) ?: $externalId, 300, ''),
            'reason' => $reason, 'candidate_ids' => json_encode(array_values($candidates)), 'payload' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ];
        $open = DB::table('hr_sync_unmatched')->where('tenant_id', $tenantId)->where('external_id', $externalId)->where('status', 'OPEN')->value('id');
        if ($open) {
            DB::table('hr_sync_unmatched')->where('id', $open)->update($row);
        } else {
            DB::table('hr_sync_unmatched')->insert([...$row, 'tenant_id' => $tenantId, 'external_id' => $externalId, 'created_at' => now()]);
        }

        return 'UNMATCHED';
    }

    /** Resolver un caso de la bandeja: vincular con un trabajador, crearlo o ignorarlo. */
    public static function resolve(string $tenantId, object $item, string $action, ?string $employeeId, string $userId): void
    {
        $data = json_decode((string) $item->payload, true) ?: [];
        $data['external_version'] = max(1, (int) ($data['external_version'] ?? 1));
        $status = 'IGNORED';
        $target = null;

        if ($action === 'link') {
            $target = DB::table('employees')->where('tenant_id', $tenantId)->where('id', $employeeId)->first();
            if (! $target) {
                throw \Illuminate\Validation\ValidationException::withMessages(['employee_id' => ['Elige el trabajador del ERP con el que se vincula.']]);
            }
            if ($target->payroll_external_id && $target->payroll_external_id !== $item->external_id) {
                throw \Illuminate\Validation\ValidationException::withMessages(['employee_id' => ['Ese trabajador ya está vinculado con otro registro del SaaS de RRHH.']]);
            }
            $status = 'LINKED';
        } elseif ($action === 'create') {
            $target = self::newEmployeeFor($tenantId, self::newPerson($tenantId, $data));
            $status = 'CREATED';
        }

        if ($target) {
            // El que ya tenía ese external_id (no debería) lo suelta antes de vincular.
            DB::table('employees')->where('tenant_id', $tenantId)->where('payroll_external_id', $item->external_id)->where('id', '<>', $target->id)
                ->update(['payroll_external_id' => null, 'source' => 'LOCAL']);
            DB::table('employees')->where('id', $target->id)->update(['external_version' => 0]);
            self::writeEmployee($tenantId, DB::table('employees')->where('id', $target->id)->first(), $data);
        }
        DB::table('hr_sync_unmatched')->where('id', $item->id)->update([
            'status' => $status, 'employee_id' => $target?->id, 'resolved_by' => $userId, 'resolved_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------- Del ERP a RRHH

    /** Pone en cola un evento para el SaaS de RRHH (se envía al momento y, si falla, con reintentos). */
    public static function queue(string $tenantId, object $connection, string $type, array $data, ?string $exportId = null, ?string $userId = null): string
    {
        $eventId = 'evt_'.Str::lower((string) Str::ulid());
        $envelope = [
            'event_id' => $eventId, 'event_type' => $type, 'occurred_at' => now('America/Lima')->toIso8601String(),
            'tenant_ruc' => self::tenantRuc($tenantId), 'data' => $data,
        ];
        $id = (string) Str::uuid();
        DB::table('hr_outbound_events')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'connection_id' => $connection->id, 'event_id' => $eventId, 'event_type' => $type,
            'payload' => json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'export_id' => $exportId,
            'status' => 'PENDING', 'next_attempt_at' => now(), 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** Un intento de envío (fuera de transacción: la llamada puede tardar). */
    public static function deliver(string $tenantId, string $outboundId): string
    {
        $event = DB::table('hr_outbound_events')->where('tenant_id', $tenantId)->where('id', $outboundId)->first();
        if (! $event || ! in_array($event->status, ['PENDING', 'RETRY'], true)) {
            return $event->status ?? 'MISSING';
        }
        $connection = DB::table('hr_connections')->where('id', $event->connection_id)->first();
        if (! $connection || $connection->status !== 'ACTIVE') {
            DB::table('hr_outbound_events')->where('id', $event->id)->update(['next_attempt_at' => now()->addMinutes(30), 'last_error' => 'La conexión con el SaaS de RRHH está desactivada.']);

            return 'WAITING';
        }

        $result = self::gateway()->send($connection, json_decode((string) $event->payload, true));
        $attempt = (int) $event->attempts + 1;
        $status = $result['ok'] ? 'SENT' : ($attempt >= self::MAX_ATTEMPTS ? 'DEAD' : 'RETRY');
        DB::table('hr_outbound_events')->where('id', $event->id)->update([
            'status' => $status, 'attempts' => $attempt, 'response_code' => $result['code'],
            'last_error' => $result['ok'] ? null : Str::limit((string) $result['error'], 490, '…'),
            'sent_at' => $result['ok'] ? now() : null,
            'next_attempt_at' => $status === 'RETRY' ? now()->addMinutes(self::BACKOFF[min($attempt - 1, count(self::BACKOFF) - 1)]) : null,
        ]);
        if ($event->export_id) {
            DB::table('hr_payroll_exports')->where('id', $event->export_id)->update(match ($status) {
                'SENT' => ['status' => 'ACKNOWLEDGED', 'acknowledged_at' => now(), 'provider_response' => Str::limit((string) $result['body'], 990, '…')],
                'DEAD' => ['status' => 'FAILED', 'provider_response' => Str::limit((string) $result['error'], 990, '…')],
                default => ['provider_response' => Str::limit('Reintentando: '.$result['error'], 990, '…')],
            });
        }

        return $status;
    }

    public static function deliverDue(string $tenantId, int $limit = 50): int
    {
        $due = DB::table('hr_outbound_events')->where('tenant_id', $tenantId)->whereIn('status', ['PENDING', 'RETRY'])
            ->where('next_attempt_at', '<=', now())->orderBy('next_attempt_at')->limit($limit)->pluck('id');
        foreach ($due as $id) {
            self::deliver($tenantId, $id);
        }

        return $due->count();
    }

    // ---------------------------------------------------------------- Apoyo

    public static function tenantRuc(string $tenantId): ?string
    {
        return DB::table('companies')->where('tenant_id', $tenantId)->orderBy('created_at')->value('tax_identifier');
    }

    /** Ejecuta un cambio en su propio punto de guardado: si falla, no arrastra a los demás. */
    private static function safely(callable $apply, ?string &$note = null): string
    {
        try {
            return DB::transaction(fn () => $apply());
        } catch (HrSyncSkip $skip) {
            $note = $skip->getMessage();

            return $skip->outcome;
        } catch (Throwable $e) {
            report($e);
            $note = self::friendly($e);

            return 'FAILED';
        }
    }

    private static function friendly(Throwable $e): string
    {
        $message = $e->getMessage();
        if (str_contains($message, 'HR_LEAVE_OVERLAP')) {
            return 'La ausencia se cruza con otra aprobada del mismo trabajador.';
        }
        if ($e instanceof \RuntimeException || $e instanceof \App\Services\UnsafeWebhookTarget) {
            return Str::limit($message, 480, '…');
        }

        return 'No se pudo aplicar el cambio: revisa los datos que envió el SaaS de RRHH.';
    }

    private static function startRun(string $tenantId, string $connectionId, string $kind, ?string $userId): string
    {
        $id = (string) Str::uuid();
        DB::table('hr_sync_runs')->insert(['id' => $id, 'tenant_id' => $tenantId, 'connection_id' => $connectionId, 'kind' => $kind, 'started_by' => $userId, 'started_at' => now()]);

        return $id;
    }

    private static function finishRun(string $runId, string $status, array $counts, ?string $error = null): void
    {
        DB::table('hr_sync_runs')->where('id', $runId)->update([...$counts, 'status' => $status, 'error' => $error, 'finished_at' => now()]);
    }

    private static function newPerson(string $tenantId, array $data): string
    {
        $partyId = (string) Str::uuid();
        DB::table('parties')->insert(['id' => $partyId, 'tenant_id' => $tenantId, 'party_type' => 'PERSON', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('persons')->insert([
            'party_id' => $partyId,
            'given_names' => Str::limit(trim((string) ($data['given_names'] ?? '')) ?: 'Sin nombre', 255, ''),
            'family_name' => Str::limit(trim((string) ($data['family_name'] ?? '')) ?: '—', 255, ''),
            'document_type' => self::DOC_TYPES[strtoupper((string) ($data['doc_type'] ?? ''))] ?? null,
            'document_number' => trim((string) ($data['doc_number'] ?? '')) ?: null,
            'metadata' => '{}',
        ]);
        foreach (['email' => 'EMAIL', 'phone' => 'PHONE'] as $field => $type) {
            $value = trim((string) ($data[$field] ?? ''));
            if ($value !== '') {
                DB::table('contacts')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'party_id' => $partyId, 'contact_type' => $type, 'value' => Str::limit($value, 250, ''), 'is_primary' => true, 'created_at' => now()]);
            }
        }

        return $partyId;
    }

    private static function newEmployeeFor(string $tenantId, string $partyId): object
    {
        $id = (string) Str::uuid();
        DB::table('employees')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'person_party_id' => $partyId, 'employee_code' => self::nextCode($tenantId),
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('employees')->where('id', $id)->first();
    }

    /** Siguiente código EMP-000 libre (mismo correlativo que Clientes y actores). */
    public static function nextCode(string $tenantId): string
    {
        $count = DB::table('employees')->where('tenant_id', $tenantId)->count();
        do {
            $count++;
            $code = 'EMP-'.str_pad((string) $count, 3, '0', STR_PAD_LEFT);
        } while (DB::table('employees')->where('tenant_id', $tenantId)->where('employee_code', $code)->exists());

        return $code;
    }

    /** Cese con OT abiertas: se aplica igual y se avisa para reasignarlas (§3). */
    public static function warnOpenWork(string $tenantId, object $employee): void
    {
        if (! $employee->user_id) {
            return;
        }
        $open = DB::table('work_orders')->where('tenant_id', $tenantId)->where('assigned_user_id', $employee->user_id)
            ->whereNotIn('status', ['COMPLETED', 'CANCELLED'])->pluck('code');
        if ($open->isEmpty()) {
            return;
        }
        $name = DB::table('persons')->where('party_id', $employee->person_party_id)->selectRaw("btrim(given_names || ' ' || family_name) as n")->value('n');
        $list = $open->take(10)->implode(', ').($open->count() > 10 ? '…' : '');
        Notifier::send('hr.termination_open_work', "Reasignar órdenes de {$name}",
            "{$name} cesó y tiene {$open->count()} orden(es) de trabajo abiertas: {$list}. Reasígnalas a otra persona.",
            '/work-orders', Notifier::withPermission('work_orders.manage'));
    }

    private static function notify(string $tenantId, string $title, string $body): void
    {
        Notifier::send('hr.sync_warning', $title, $body, '/hr/sync', Notifier::withPermission('hr_sync.manage'));
    }

    private static function date($value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
