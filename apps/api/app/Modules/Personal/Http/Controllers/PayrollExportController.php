<?php

namespace App\Modules\Personal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Personal\HrSaas\HrSaasSync;
use App\Modules\Personal\Support\Personal;
use App\Services\AuditLogger;
use App\Services\Branding;
use App\Services\LocalTime;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Personal → Envío de horas a planilla (P-HRM-04, RN-HRM-05). El ERP no calcula planilla: cada mes
 * genera un envío versionado con las horas aprobadas.
 *
 * - Con el SaaS de RRHH conectado: evento `timesheet.period_approved` firmado (solo horas de los
 *   trabajadores que RRHH conoce); se reenvía con la misma versión, y regenerar crea la siguiente.
 * - Sin conexión: un Excel para el proveedor de planilla que use la empresa, con trabajadores,
 *   altas y ceses, horas y ausencias aprobadas.
 *
 * Lo generado no se reescribe (la base lo impide): el archivo sale siempre del contenido guardado.
 */
class PayrollExportController extends Controller
{
    public const STATES = ['GENERATED' => 'Generado', 'SENT' => 'Enviado', 'ACKNOWLEDGED' => 'Recibido por RRHH', 'FAILED' => 'Fallido'];

    public function index(Request $request)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $query = DB::table('hr_payroll_exports as x')->leftJoin('users as u', 'u.id', '=', 'x.generated_by')
            ->where('x.tenant_id', $tenantId)
            ->when($request->filled('period'), fn ($q) => $q->where('x.period', $request->query('period')))
            ->when(isset(self::STATES[(string) $request->query('status')]), fn ($q) => $q->where('x.status', $request->query('status')))
            ->select('x.id', 'x.period', 'x.version', 'x.destination', 'x.employee_count', 'x.minutes_total', 'x.status', 'x.sent_at',
                'x.acknowledged_at', 'x.provider_response', 'x.created_at', 'x.payload_sha256', 'u.name as generated_by_name')
            ->orderByDesc('x.period')->orderByDesc('x.version');

        $page = $query->paginate(min(max($request->integer('per_page', 20), 1), 200));
        $page->getCollection()->transform(fn ($x) => $this->row($x));
        $connection = Personal::activeConnection($tenantId);

        return response()->json([
            ...$page->toArray(),
            'mode' => $connection ? 'HR_SAAS_MASTER' : 'ERP_MASTER',
            'suggested_period' => LocalTime::today()->subMonthNoOverflow()->format('Y-m'),
        ]);
    }

    /** Lo que llevaría el envío del periodo, antes de generarlo. */
    public function preview(Request $request)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $period = $this->period($request);
        $payload = $this->build($tenantId, $period, Personal::activeConnection($tenantId) !== null);

        return response()->json([
            'period' => $period,
            'destination' => $payload['destination'],
            'employee_count' => count($payload['employees_with_hours']),
            'rows' => count($payload['rows']),
            'minutes_total' => array_sum(array_column($payload['rows'], 'minutes')),
            'unlinked' => $payload['unlinked'],
            'pending_hours' => $payload['pending_hours'],
            'next_version' => (int) DB::table('hr_payroll_exports')->where('tenant_id', $tenantId)->where('period', $period)->max('version') + 1,
            'current_month' => $period === LocalTime::today()->format('Y-m'),
        ]);
    }

    public function show(Request $request, string $export)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $row = $this->find($tenantId, $export);
        $detail = $this->row($row);
        $payload = json_decode((string) $row->payload, true) ?: [];
        $detail['summary'] = [
            'rows' => count($payload['rows'] ?? []),
            'hires' => count($payload['hires'] ?? []),
            'terminations' => count($payload['terminations'] ?? []),
            'leaves' => count($payload['leaves'] ?? []),
            'unlinked' => $payload['unlinked'] ?? [],
        ];
        $detail['by_employee'] = collect($payload['rows'] ?? [])->groupBy('employee_name')
            ->map(fn ($rows, $name) => ['name' => $name, 'code' => $rows->first()['employee_code'] ?? null,
                'minutes' => $rows->sum('minutes'), 'billable_minutes' => $rows->where('billable', true)->sum('minutes')])
            ->sortBy('name')->values();
        $detail['deliveries'] = DB::table('hr_outbound_events')->where('tenant_id', $tenantId)->where('export_id', $row->id)
            ->orderByDesc('created_at')->get(['id', 'event_id', 'status', 'attempts', 'response_code', 'last_error', 'sent_at', 'next_attempt_at', 'created_at']);

        return response()->json($detail);
    }

    /** Genera el envío del periodo (versión nueva si ya existía) y, con RRHH conectado, lo envía. */
    public function store(Request $request)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $period = $this->period($request);
        $connection = Personal::activeConnection($tenantId);
        $payload = $this->build($tenantId, $period, $connection !== null);
        if (! $payload['rows'] && ! $connection && ! $payload['employees']) {
            throw ValidationException::withMessages(['period' => ['No hay trabajadores ni horas aprobadas en ese periodo.']]);
        }
        if ($connection && ! $payload['rows']) {
            throw ValidationException::withMessages(['period' => ['No hay horas aprobadas de trabajadores vinculados con el SaaS de RRHH en ese periodo.']]);
        }

        [$id, $outbound] = DB::transaction(function () use ($request, $tenantId, $period, $connection, $payload) {
            // Dos generaciones a la vez no toman la misma versión.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["hr_payroll:{$tenantId}:{$period}"]);
            $version = (int) DB::table('hr_payroll_exports')->where('tenant_id', $tenantId)->where('period', $period)->max('version') + 1;
            $payload['version'] = $version;
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $id = (string) Str::uuid();
            DB::table('hr_payroll_exports')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'period' => $period, 'version' => $version,
                'destination' => $connection ? 'HR_SAAS' : 'FILE', 'employee_count' => count($payload['employees_with_hours']),
                'minutes_total' => array_sum(array_column($payload['rows'], 'minutes')), 'payload' => $json, 'payload_sha256' => hash('sha256', $json),
                'connection_id' => $connection?->id, 'status' => $connection ? 'SENT' : 'GENERATED', 'sent_at' => $connection ? now() : null,
                'generated_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $outbound = $connection ? $this->queue($tenantId, $connection, $payload, $id, $request->user()->id) : null;
            AuditLogger::log(tenantId: $tenantId, userId: $request->user()->id, action: 'payroll_export.create', entityType: 'PayrollExport', entityId: $id,
                afterData: ['period' => $period, 'version' => $version, 'destination' => $connection ? 'HR_SAAS' : 'FILE']);

            return [$id, $outbound];
        });
        if ($outbound) {
            rescue(fn () => HrSaasSync::deliver($tenantId, $outbound), report: false);
        }

        return response()->json($this->row($this->find($tenantId, $id)), 201);
    }

    /** Reenviar al SaaS de RRHH (misma versión) o dejar constancia de que el archivo se entregó. */
    public function send(Request $request, string $export)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $row = $this->find($tenantId, $export);
        $outbound = null;

        if ($row->destination === 'HR_SAAS') {
            $connection = Personal::activeConnection($tenantId);
            if (! $connection) {
                throw ValidationException::withMessages(['status' => ['La conexión con el SaaS de RRHH está desactivada: actívala en Sincronización.']]);
            }
            if (DB::table('hr_outbound_events')->where('export_id', $row->id)->whereIn('status', ['PENDING', 'RETRY'])->exists()) {
                throw ValidationException::withMessages(['status' => ['El envío ya está en curso: espera su respuesta.']]);
            }
            $outbound = DB::transaction(function () use ($request, $tenantId, $connection, $row) {
                $payload = json_decode((string) $row->payload, true);
                $id = $this->queue($tenantId, $connection, $payload, $row->id, $request->user()->id);
                DB::table('hr_payroll_exports')->where('id', $row->id)->update(['status' => 'SENT', 'sent_at' => now(), 'provider_response' => null]);
                AuditLogger::log(tenantId: $tenantId, userId: $request->user()->id, action: 'payroll_export.resend', entityType: 'PayrollExport', entityId: $row->id,
                    afterData: ['period' => $row->period, 'version' => $row->version]);

                return $id;
            });
        } else {
            if ($row->status === 'SENT') {
                throw ValidationException::withMessages(['status' => ['Ya está marcado como entregado.']]);
            }
            DB::transaction(function () use ($request, $tenantId, $row) {
                DB::table('hr_payroll_exports')->where('id', $row->id)->update(['status' => 'SENT', 'sent_at' => now(),
                    'provider_response' => trim((string) $request->input('note', '')) ?: 'Archivo entregado al proveedor de planilla.']);
                AuditLogger::log(tenantId: $tenantId, userId: $request->user()->id, action: 'payroll_export.delivered', entityType: 'PayrollExport', entityId: $row->id,
                    afterData: ['period' => $row->period, 'version' => $row->version]);
            });
        }
        if ($outbound) {
            rescue(fn () => HrSaasSync::deliver($tenantId, $outbound), report: false);
        }

        return response()->json($this->row($this->find($tenantId, $row->id)));
    }

    /** El Excel del envío, armado con lo guardado (no cambia aunque cambien las horas después). */
    public function file(Request $request, string $export)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $row = $this->find($tenantId, $export);
        $payload = json_decode((string) $row->payload, true) ?: [];
        AuditLogger::log(tenantId: $tenantId, userId: $request->user()->id, action: 'payroll_export.download', entityType: 'PayrollExport', entityId: $row->id,
            afterData: ['period' => $row->period, 'version' => $row->version]);

        $book = $this->workbook($tenantId, $row, $payload);
        $name = "envio_planilla_{$row->period}_v{$row->version}.xlsx";

        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
        }, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    // ------------------------------------------------------------------ Contenido

    /**
     * Contenido del envío. Las horas son las aprobadas cuyo inicio cae en el mes (hora de Lima).
     *
     * @return array<string, mixed>
     */
    private function build(string $tenantId, string $period, bool $toHrSaas): array
    {
        $start = Carbon::createFromFormat('Y-m-d H:i:s', "{$period}-01 00:00:00", 'America/Lima');
        $end = $start->copy()->addMonth();
        $name = "btrim(p.given_names || ' ' || p.family_name)";

        $entries = DB::table('time_entries as t')
            ->join('employees as e', fn ($j) => $j->on('e.user_id', '=', 't.user_id')->where('e.tenant_id', $tenantId))
            ->join('persons as p', 'p.party_id', '=', 'e.person_party_id')
            ->leftJoin('work_orders as w', 'w.id', '=', 't.work_order_id')
            ->leftJoin('projects as pr', 'pr.id', '=', 't.project_id')
            ->where('t.tenant_id', $tenantId)->where('t.status', 'APPROVED')
            ->where('t.started_at', '>=', $start->copy()->utc())->where('t.started_at', '<', $end->copy()->utc())
            ->orderBy('p.family_name')->orderBy('t.started_at')
            ->get(['e.id as employee_id', 'e.employee_code', 'e.payroll_external_id', DB::raw("{$name} as employee_name"),
                DB::raw("to_char(t.started_at AT TIME ZONE 'America/Lima', 'YYYY-MM-DD') as date"),
                DB::raw('round(t.hours * 60)::int as minutes'), 't.billable', 'w.code as work_order_number', 'pr.code as project_number']);

        $unlinked = [];
        $rows = [];
        foreach ($entries as $entry) {
            if ($toHrSaas && ! $entry->payroll_external_id) {
                $unlinked[$entry->employee_id] = $entry->employee_name;
                continue;
            }
            $rows[] = [
                'employee_external_id' => $entry->payroll_external_id, 'employee_code' => $entry->employee_code, 'employee_name' => $entry->employee_name,
                'date' => $entry->date, 'minutes' => (int) $entry->minutes, 'billable' => (bool) $entry->billable,
                'work_order_number' => $entry->work_order_number, 'project_number' => $entry->project_number,
                // El ERP aún no distingue sobretiempo: lo calcula la planilla con la jornada.
                'overtime' => false,
            ];
        }

        $payload = [
            'period' => $period, 'destination' => $toHrSaas ? 'HR_SAAS' : 'FILE', 'rows' => $rows,
            'employees_with_hours' => array_values(array_unique(array_column($rows, 'employee_code'))),
            'unlinked' => array_values($unlinked),
            'pending_hours' => (int) DB::table('time_entries')->where('tenant_id', $tenantId)->where('status', 'SUBMITTED')
                ->where('started_at', '>=', $start->copy()->utc())->where('started_at', '<', $end->copy()->utc())->count(),
            'employees' => [], 'hires' => [], 'terminations' => [], 'leaves' => [],
        ];
        if ($toHrSaas) {
            return $payload;
        }

        // Sin SaaS de RRHH: el proveedor de planilla también necesita el personal y sus ausencias.
        $from = $start->toDateString();
        $to = $end->copy()->subDay()->toDateString();
        $people = DB::table('employees as e')->join('persons as p', 'p.party_id', '=', 'e.person_party_id')
            ->leftJoin('hr_positions as pos', 'pos.id', '=', 'e.position_id')->leftJoin('branches as b', 'b.id', '=', 'e.branch_id')
            ->where('e.tenant_id', $tenantId)
            ->where(fn ($q) => $q->where('e.status', 'ACTIVE')->orWhereBetween('e.termination_date', [$from, $to]))
            ->where(fn ($q) => $q->whereNull('e.hire_date')->orWhere('e.hire_date', '<=', $to))
            ->orderBy('p.family_name')
            ->get(['e.employee_code', DB::raw("{$name} as name"), 'p.document_type', 'p.document_number', 'pos.name as position', 'b.name as branch',
                'e.contract_type', 'e.weekly_hours', 'e.hourly_cost', 'e.currency', 'e.hire_date', 'e.termination_date', 'e.termination_reason', 'e.payroll_external_id']);
        $payload['employees'] = $people->map(fn ($p) => [
            'employee_code' => $p->employee_code, 'name' => $p->name, 'document' => trim(($p->document_type ?? '').' '.($p->document_number ?? '')),
            'position' => $p->position, 'branch' => $p->branch, 'contract_type' => Personal::CONTRACT_TYPES[$p->contract_type] ?? null,
            'weekly_hours' => (float) $p->weekly_hours, 'hourly_cost' => $p->hourly_cost !== null ? (float) $p->hourly_cost : null,
            'currency' => $p->currency, 'hire_date' => $p->hire_date, 'payroll_code' => $p->payroll_external_id,
        ])->values()->all();
        $payload['hires'] = $people->filter(fn ($p) => $p->hire_date && $p->hire_date >= $from && $p->hire_date <= $to)
            ->map(fn ($p) => ['employee_code' => $p->employee_code, 'name' => $p->name, 'date' => $p->hire_date])->values()->all();
        $payload['terminations'] = $people->filter(fn ($p) => $p->termination_date && $p->termination_date >= $from && $p->termination_date <= $to)
            ->map(fn ($p) => ['employee_code' => $p->employee_code, 'name' => $p->name, 'date' => $p->termination_date, 'reason' => $p->termination_reason])->values()->all();
        $payload['leaves'] = DB::table('hr_leave_requests as r')->join('hr_leave_types as lt', 'lt.id', '=', 'r.leave_type_id')
            ->join('employees as e', 'e.id', '=', 'r.employee_id')->join('persons as p', 'p.party_id', '=', 'e.person_party_id')
            ->where('r.tenant_id', $tenantId)->where('r.status', 'APPROVED')->where('r.date_from', '<=', $to)->where('r.date_to', '>=', $from)
            ->orderBy('r.date_from')
            ->get(['e.employee_code', DB::raw("{$name} as name"), 'lt.name as type', 'lt.paid', 'r.date_from', 'r.date_to', 'r.days'])
            ->map(fn ($l) => (array) $l + ['paid' => (bool) $l->paid])->all();

        return $payload;
    }

    private function queue(string $tenantId, object $connection, array $payload, string $exportId, string $userId): string
    {
        return HrSaasSync::queue($tenantId, $connection, 'timesheet.period_approved', [
            'period' => $payload['period'], 'version' => $payload['version'],
            'rows' => array_map(fn ($r) => array_intersect_key($r, array_flip(['employee_external_id', 'date', 'minutes', 'billable', 'work_order_number', 'project_number', 'overtime'])), $payload['rows']),
        ], $exportId, $userId);
    }

    private function workbook(string $tenantId, object $row, array $payload): Spreadsheet
    {
        $brand = DB::table('company_branding')->where('tenant_id', $tenantId)->value('primary_color');
        $dark = 'FF'.substr($brand ? Branding::readable($brand) : '#1B8FC9', 1);
        $company = DB::table('companies')->where('tenant_id', $tenantId)->orderBy('created_at')->first(['legal_name', 'tax_identifier']);
        $book = new Spreadsheet();
        $book->removeSheetByIndex(0);

        $sheets = [
            'Horas' => [['Código', 'Trabajador', 'Código en planilla', 'Fecha', 'Minutos', 'Horas', 'Facturable', 'OT', 'Proyecto'],
                array_map(fn ($r) => [$r['employee_code'], $r['employee_name'], $r['employee_external_id'], $r['date'], $r['minutes'], round($r['minutes'] / 60, 2),
                    $r['billable'] ? 'Sí' : 'No', $r['work_order_number'], $r['project_number']], $payload['rows'] ?? [])],
        ];
        if (($payload['destination'] ?? 'FILE') === 'FILE') {
            $sheets['Trabajadores'] = [['Código', 'Trabajador', 'Documento', 'Puesto', 'Sede', 'Contrato', 'Jornada semanal', 'Costo por hora', 'Moneda', 'Ingreso', 'Código en planilla'],
                array_map(fn ($e) => [$e['employee_code'], $e['name'], $e['document'], $e['position'], $e['branch'], $e['contract_type'], $e['weekly_hours'],
                    $e['hourly_cost'], $e['currency'], $e['hire_date'], $e['payroll_code']], $payload['employees'] ?? [])];
            $sheets['Altas y ceses'] = [['Movimiento', 'Código', 'Trabajador', 'Fecha', 'Motivo'],
                [...array_map(fn ($h) => ['Alta', $h['employee_code'], $h['name'], $h['date'], null], $payload['hires'] ?? []),
                    ...array_map(fn ($t) => ['Cese', $t['employee_code'], $t['name'], $t['date'], $t['reason']], $payload['terminations'] ?? [])]];
            $sheets['Ausencias'] = [['Código', 'Trabajador', 'Tipo', 'Con goce', 'Desde', 'Hasta', 'Días'],
                array_map(fn ($l) => [$l['employee_code'], $l['name'], $l['type'], $l['paid'] ? 'Sí' : 'No', $l['date_from'], $l['date_to'], $l['days']], $payload['leaves'] ?? [])];
        }

        foreach ($sheets as $title => [$headers, $rows]) {
            $sheet = $book->createSheet();
            $sheet->setTitle($title);
            $sheet->setShowGridlines(false);
            $last = Coordinate::stringFromColumnIndex(count($headers));
            $sheet->mergeCells("A1:{$last}1");
            $sheet->setCellValue('A1', "Envío de horas a planilla · {$row->period} · versión {$row->version}");
            $sheet->getStyle('A1')->applyFromArray(['font' => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FFFFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $dark]]]);
            $sheet->getRowDimension(1)->setRowHeight(24);
            $sheet->mergeCells("A2:{$last}2");
            $sheet->setCellValue('A2', trim(($company->legal_name ?? '').' · RUC '.($company->tax_identifier ?? '—').' · Generado el '
                .Carbon::parse($row->created_at)->setTimezone('America/Lima')->format('d/m/Y H:i')));
            $sheet->getStyle('A2')->getFont()->setSize(9)->getColor()->setARGB('FF555555');
            $sheet->fromArray($headers, null, 'A4');
            $sheet->getStyle("A4:{$last}4")->applyFromArray(['font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $dark]]]);
            if ($rows) {
                $sheet->fromArray(array_values($rows), null, 'A5', true);
            } else {
                $sheet->setCellValue('A5', 'Sin registros en el periodo.');
            }
            foreach (range(1, count($headers)) as $column) {
                $sheet->getColumnDimensionByColumn($column)->setWidth(max(12, mb_strlen($headers[$column - 1]) + 6));
            }
            $sheet->getColumnDimension('B')->setWidth(32);
            $sheet->freezePane('A5');
        }
        $book->setActiveSheetIndex(0);

        return $book;
    }

    // ------------------------------------------------------------------ Apoyo

    private function period(Request $request): string
    {
        $period = (string) $request->input('period', '');
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw ValidationException::withMessages(['period' => ['Elige el mes (AAAA-MM).']]);
        }
        if ($period > LocalTime::today()->format('Y-m')) {
            throw ValidationException::withMessages(['period' => ['El mes aún no empieza.']]);
        }

        return $period;
    }

    private function find(string $tenantId, string $id): object
    {
        return DB::table('hr_payroll_exports as x')->leftJoin('users as u', 'u.id', '=', 'x.generated_by')
            ->where('x.tenant_id', $tenantId)->where('x.id', $id)->select('x.*', 'u.name as generated_by_name')->first()
            ?? abort(response()->json(['message' => 'Envío no encontrado.', 'code' => 'NOT_FOUND'], 404));
    }

    private function row(object $x): array
    {
        return [
            'id' => $x->id, 'period' => $x->period, 'version' => (int) $x->version, 'destination' => $x->destination,
            'destination_label' => $x->destination === 'HR_SAAS' ? 'SaaS de RRHH' : 'Archivo para planilla',
            'employee_count' => (int) $x->employee_count, 'minutes_total' => (int) $x->minutes_total, 'hours_total' => round($x->minutes_total / 60, 2),
            'status' => $x->status, 'status_label' => self::STATES[$x->status] ?? $x->status, 'sent_at' => $x->sent_at,
            'acknowledged_at' => $x->acknowledged_at, 'provider_response' => $x->provider_response, 'created_at' => $x->created_at,
            'generated_by' => $x->generated_by_name, 'sha256' => $x->payload_sha256,
        ];
    }
}
