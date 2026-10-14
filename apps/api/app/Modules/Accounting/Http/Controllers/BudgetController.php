<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\CostCenter;
use App\Modules\Accounting\Support\Budgets;
use App\Modules\Accounting\Support\Chart;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Centros de costo y presupuestos: presupuesto anual por centro de costo y cuenta frente a lo
 * ejecutado (libro diario) y lo comprometido (órdenes de compra aprobadas sin facturar).
 * Un presupuesto por año: se prepara en borrador, se aprueba y, para ajustarlo, se reabre con motivo.
 */
class BudgetController extends Controller
{
    /** Ejecución por centro de costo del año (solo los centros que el rol alcanza). */
    public function execution(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $year = $this->year($request);
        $budget = DB::table('budgets')->where('tenant_id', $tenantId)->where('fiscal_year', $year)->first();

        $centers = CostCenter::query()->where('cost_centers.tenant_id', $tenantId)
            ->leftJoin('users', 'users.id', '=', 'cost_centers.manager_user_id')
            ->leftJoin('branches', 'branches.id', '=', 'cost_centers.branch_id')
            ->orderBy('cost_centers.code')->limit(1000)
            ->get(['cost_centers.*', 'users.name as manager_name', 'branches.name as branch_name']);
        $ids = $centers->pluck('id')->all();
        $months = $this->monthsElapsed($year);
        $lines = $budget
            ? DB::table('budget_lines')->where('budget_id', $budget->id)->whereNotNull('cost_center_id')->whereIn('cost_center_id', $ids ?: [null])
                ->groupBy('cost_center_id')
                ->selectRaw('cost_center_id, sum(amount) AS annual, sum(amount) FILTER (WHERE period_month <= ?) AS ytd', [$months])
                ->get()->keyBy('cost_center_id')
            : collect();
        $actuals = Budgets::actuals($tenantId, $year, $ids);
        $committed = $year === (int) now()->year ? Budgets::committed($tenantId, $ids) : [];

        $rows = $centers->map(function ($center) use ($lines, $actuals, $committed) {
            $annual = round((float) ($lines[$center->id]->annual ?? 0), 2);
            $ytd = round((float) ($lines[$center->id]->ytd ?? 0), 2);
            $actual = round(array_sum(array_map('array_sum', $actuals[$center->id] ?? [])), 2);
            $held = $center->kind === 'COST' ? round((float) ($committed[$center->id] ?? 0), 2) : 0.0;

            return [
                ...$this->centerRow($center),
                'annual' => $annual, 'ytd' => $ytd, 'actual' => $actual, 'committed' => $center->kind === 'COST' ? $held : null,
                'pct' => $annual > 0 ? (int) round(($actual + $held) / $annual * 100) : 0,
                'over_ytd' => $center->kind === 'COST' && $actual > $ytd + 0.001,
            ];
        })->values();

        $cost = $rows->where('kind', 'COST');
        $revenue = $rows->where('kind', 'REVENUE');
        $revenueAnnual = $revenue->sum('annual');

        return response()->json([
            'year' => $year,
            'budget' => $budget ? $this->budgetRow($budget) : null,
            'kpis' => [
                'cost_budget' => round($cost->sum('annual'), 2),
                'cost_actual' => round($cost->sum('actual'), 2),
                'committed' => round($cost->sum('committed'), 2),
                'revenue_pct' => $revenueAnnual > 0 ? (int) round($revenue->sum('actual') / $revenueAnnual * 100) : null,
            ],
            'centers' => $rows,
        ]);
    }

    /** Detalle de un centro: presupuesto y ejecución por cuenta. */
    public function center(string $costCenter, Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $center = $this->findCenter($costCenter);
        $year = $this->year($request);
        $budget = DB::table('budgets')->where('tenant_id', $tenantId)->where('fiscal_year', $year)->first();
        $months = $this->monthsElapsed($year);
        $lines = $budget
            ? DB::table('budget_lines')->where('budget_id', $budget->id)->where('cost_center_id', $center->id)
                ->groupBy('account_code')
                ->selectRaw('account_code, sum(amount) AS annual, sum(amount) FILTER (WHERE period_month <= ?) AS ytd', [$months])
                ->get()->keyBy('account_code')
            : collect();
        $actuals = Budgets::actuals($tenantId, $year, [$center->id])[$center->id] ?? [];
        // Las cuentas presupuestadas y, al final, las que tienen ejecución sin presupuesto.
        $codes = collect($lines->keys())->merge(array_keys($actuals))->unique()
            ->sortBy(fn ($code) => [$lines->has($code) ? 0 : 1, $code])->values();

        return response()->json([
            'center' => [...$this->centerRow($center), 'lock_version' => (int) $center->lock_version],
            'year' => $year,
            'lines' => $codes->map(function (string $code) use ($tenantId, $lines, $actuals) {
                $annual = round((float) ($lines[$code]->annual ?? 0), 2);
                $actual = round(array_sum($actuals[$code] ?? []), 2);

                return [
                    'account_code' => $code, 'account_name' => Chart::name($tenantId, $code), 'budgeted' => $lines->has($code),
                    'annual' => $annual, 'ytd' => round((float) ($lines[$code]->ytd ?? 0), 2), 'actual' => $actual,
                    'pct' => $annual > 0 ? (int) round($actual / $annual * 100) : null,
                ];
            })->values(),
        ]);
    }

    /** Listas de los formularios: responsables, sedes, cuentas de gasto e ingreso y años. */
    public function options(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $years = DB::table('budgets')->where('tenant_id', $tenantId)->pluck('fiscal_year')
            ->merge([(int) now()->year, (int) now()->year + 1])->map(fn ($year) => (int) $year)->unique()->sortDesc()->values();

        return response()->json([
            'years' => $years,
            'users' => DB::table('tenant_users')->join('users', 'users.id', '=', 'tenant_users.user_id')
                ->where('tenant_users.tenant_id', $tenantId)->where('tenant_users.status', 'ACTIVE')
                ->orderBy('users.name')->limit(500)->get(['users.id', 'users.name'])->map(fn ($user) => ['id' => $user->id, 'name' => $user->name])->values(),
            'branches' => DB::table('branches')->where('tenant_id', $tenantId)->where('status', 'ACTIVE')->orderBy('name')
                ->get(['id', 'name'])->map(fn ($branch) => ['id' => $branch->id, 'name' => $branch->name])->values(),
            'accounts' => Chart::options($tenantId, ['EXPENSE', 'INCOME']),
        ]);
    }

    /** Crea el presupuesto del año, en borrador. */
    public function store(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $year = (int) $request->validate(['year' => ['required', 'integer', 'min:2000', 'max:2100']])['year'];
        if ($year < (int) now()->year - 1 || $year > (int) now()->year + 1) {
            throw ValidationException::withMessages(['year' => ['Se prepara el presupuesto del año en curso o del siguiente.']]);
        }
        if (DB::table('budgets')->where('tenant_id', $tenantId)->where('fiscal_year', $year)->exists()) {
            throw ValidationException::withMessages(['year' => ["Ya existe el presupuesto {$year}."]]);
        }

        $id = (string) Str::uuid();
        DB::transaction(function () use ($request, $tenantId, $year, $id) {
            DB::table('budgets')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'name' => "Presupuesto {$year}", 'fiscal_year' => $year, 'currency' => 'PEN',
                'status' => 'DRAFT', 'created_by' => $request->user()->id,
            ]);
            $this->audit($request, 'budget.create', 'Budget', $id, ['to' => 'DRAFT', 'note' => "Presupuesto {$year}"]);
        });

        return response()->json($this->budgetRow(DB::table('budgets')->where('id', $id)->first()), 201);
    }

    /** Monto anual de una cuenta en un centro: se reparte en doce meses iguales. Solo en borrador. */
    public function saveLine(string $budget, Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $row = $this->findBudget($tenantId, $budget);
        $data = $request->validate([
            'cost_center_id' => ['required', 'uuid'],
            'account_code' => ['required', 'string', 'max:20'],
            'annual' => ['required', 'numeric', 'min:0', 'max:9999999999'],
        ], [
            'cost_center_id.required' => 'Selecciona el centro de costo.',
            'account_code.required' => 'Selecciona la cuenta.',
            'annual.required' => 'Indica el monto anual.',
            'annual.min' => 'Monto anual inválido.',
        ]);
        if ($row->status !== 'DRAFT') {
            throw ValidationException::withMessages(['status' => ['Reabre el presupuesto para ajustarlo (queda auditado).']]);
        }
        $center = $this->findCenter($data['cost_center_id']);
        if (! Chart::postable($tenantId, $data['account_code'], ['EXPENSE', 'INCOME'])) {
            throw ValidationException::withMessages(['account_code' => ['Selecciona una cuenta de gasto o de ingreso del plan de cuentas.']]);
        }
        $annual = round((float) $data['annual'], 2);

        DB::transaction(function () use ($request, $tenantId, $row, $center, $data, $annual) {
            $before = round((float) DB::table('budget_lines')->where('budget_id', $row->id)->where('cost_center_id', $center->id)
                ->where('account_code', $data['account_code'])->sum('amount'), 2);
            // Doce meses iguales; los céntimos que sobran van a diciembre.
            $monthly = floor($annual / 12 * 100) / 100;
            foreach (range(1, 12) as $month) {
                $amount = $month === 12 ? round($annual - $monthly * 11, 2) : $monthly;
                $existing = DB::table('budget_lines')->where('budget_id', $row->id)->where('cost_center_id', $center->id)
                    ->where('account_code', $data['account_code'])->where('period_month', $month)->value('id');
                $existing
                    ? DB::table('budget_lines')->where('id', $existing)->update(['amount' => $amount])
                    : DB::table('budget_lines')->insert([
                        'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'budget_id' => $row->id, 'cost_center_id' => $center->id,
                        'account_code' => $data['account_code'], 'period_month' => $month, 'amount' => $amount,
                    ]);
            }
            $this->audit($request, 'budget.line_update', 'Budget', $row->id, [
                'note' => "{$center->code} · {$data['account_code']}: S/ ".number_format($before, 2).' → S/ '.number_format($annual, 2),
                'amount' => $annual,
            ]);
        });

        return response()->json($this->budgetRow($this->findBudget($tenantId, $budget)));
    }

    public function approve(string $budget, Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $row = $this->findBudget($tenantId, $budget);
        if ($row->status !== 'DRAFT') {
            throw ValidationException::withMessages(['status' => ['Solo se aprueba un presupuesto en borrador.']]);
        }
        if (! DB::table('budget_lines')->where('budget_id', $row->id)->whereNotNull('cost_center_id')->where('amount', '>', 0)->exists()) {
            throw ValidationException::withMessages(['status' => ['El presupuesto no tiene montos: agrega al menos una cuenta a un centro de costo.']]);
        }

        DB::transaction(function () use ($request, $row) {
            DB::table('budgets')->where('id', $row->id)->update(['status' => 'APPROVED', 'approved_by' => $request->user()->id, 'approved_at' => now(), 'reason' => null]);
            $this->audit($request, 'budget.approve', 'Budget', $row->id, ['from' => 'DRAFT', 'to' => 'APPROVED', 'note' => $row->name]);
        });

        return response()->json($this->budgetRow($this->findBudget($tenantId, $budget)));
    }

    /** Reabre para ajuste un presupuesto aprobado, con motivo. Mientras está en borrador, las órdenes de compra no se controlan contra él. */
    public function reopen(string $budget, Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $row = $this->findBudget($tenantId, $budget);
        if ($row->status !== 'APPROVED') {
            throw ValidationException::withMessages(['status' => ['Solo se reabre un presupuesto aprobado.']]);
        }
        $reason = trim($request->validate(['reason' => ['required', 'string', 'max:1000']], ['reason.required' => 'Indica el motivo del ajuste.'])['reason']);

        DB::transaction(function () use ($request, $row, $reason) {
            DB::table('budgets')->where('id', $row->id)->update(['status' => 'DRAFT', 'reason' => $reason]);
            $this->audit($request, 'budget.reopen', 'Budget', $row->id, ['from' => 'APPROVED', 'to' => 'DRAFT', 'note' => "{$row->name}: {$reason}"]);
        });

        return response()->json($this->budgetRow($this->findBudget($tenantId, $budget)));
    }

    /** Edita un centro de costo: nombre, tipo, sede, responsable y si está activo. Su código no cambia. */
    public function updateCenter(string $costCenter, Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $center = $this->findCenter($costCenter);
        $data = $this->validateCenter($request, $tenantId, true);

        DB::transaction(function () use ($request, $center, $data) {
            $before = $center->only(['name', 'kind', 'branch_id', 'manager_user_id', 'active']);
            $center->update([
                'name' => trim($data['name']), 'kind' => $data['kind'], 'branch_id' => $data['branch_id'] ?? null,
                'manager_user_id' => $data['manager_user_id'] ?? null, 'active' => (bool) ($data['active'] ?? $center->active),
            ]);
            AuditLogger::log(
                tenantId: $center->tenant_id, userId: $request->user()->id, action: 'cost_center.update', entityType: 'CostCenter', entityId: $center->id,
                beforeData: $before, afterData: $center->only(['name', 'kind', 'branch_id', 'manager_user_id', 'active']),
                metadata: ['note' => "{$center->code} · {$center->name}"],
            );
        });

        return response()->json($this->center($center->id, $request)->getData(true));
    }

    /** Valida los datos de un centro de costo (también los usa el alta desde Compras). */
    public static function validateCenter(Request $request, string $tenantId, bool $editing = false): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'kind' => [$editing ? 'required' : 'sometimes', 'in:COST,REVENUE'],
            'branch_id' => ['nullable', 'uuid'],
            'manager_user_id' => ['nullable', 'uuid'],
            'active' => ['sometimes', 'boolean'],
        ], ['name.required' => 'Código y nombre son obligatorios.']);

        if (! empty($data['branch_id']) && ! DB::table('branches')->where('tenant_id', $tenantId)->where('id', $data['branch_id'])->exists()) {
            throw ValidationException::withMessages(['branch_id' => ['La sede indicada no existe.']]);
        }
        if (! empty($data['manager_user_id']) && ! DB::table('tenant_users')->where('tenant_id', $tenantId)->where('user_id', $data['manager_user_id'])->where('status', 'ACTIVE')->exists()) {
            throw ValidationException::withMessages(['manager_user_id' => ['El responsable indicado no pertenece a la empresa.']]);
        }

        return $data;
    }

    private function centerRow(CostCenter $center): array
    {
        return [
            'id' => $center->id, 'code' => $center->code, 'name' => $center->name, 'kind' => $center->kind,
            'active' => (bool) $center->active, 'branch_id' => $center->branch_id, 'branch' => $center->branch_name ?? ($center->branch_id ? DB::table('branches')->where('id', $center->branch_id)->value('name') : null),
            'manager_user_id' => $center->manager_user_id,
            'manager' => $center->manager_name ?? ($center->manager_user_id ? DB::table('users')->where('id', $center->manager_user_id)->value('name') : null),
        ];
    }

    private function budgetRow(object $budget): array
    {
        return [
            'id' => $budget->id, 'name' => $budget->name, 'year' => (int) $budget->fiscal_year, 'status' => $budget->status,
            'approved_by' => $budget->approved_by ? DB::table('users')->where('id', $budget->approved_by)->value('name') : null,
            'approved_at' => $budget->approved_at ? Carbon::parse($budget->approved_at)->toIso8601String() : null,
            'reason' => $budget->reason, 'lock_version' => (int) $budget->lock_version,
        ];
    }

    /** Por su modelo: un centro fuera del alcance del rol no existe para quien pregunta. */
    private function findCenter(string $id): CostCenter
    {
        abort_unless(Str::isUuid($id), 404);

        return CostCenter::findOrFail($id);
    }

    private function findBudget(string $tenantId, string $id): object
    {
        abort_unless(Str::isUuid($id), 404);
        $row = DB::table('budgets')->where('tenant_id', $tenantId)->where('id', $id)->first();
        abort_unless((bool) $row, 404);

        return $row;
    }

    private function year(Request $request): int
    {
        $year = $request->integer('year', (int) now()->year);

        return $year >= 2000 && $year <= 2100 ? $year : (int) now()->year;
    }

    /** Meses del año ya transcurridos (para "a la fecha"): todos en un año pasado, ninguno en uno futuro. */
    private function monthsElapsed(int $year): int
    {
        return $year < (int) now()->year ? 12 : ($year > (int) now()->year ? 0 : (int) now()->month);
    }

    private function audit(Request $request, string $action, string $type, string $id, array $metadata): void
    {
        AuditLogger::log(tenantId: $request->attributes->get('tenant_id'), userId: $request->user()->id, action: $action, entityType: $type, entityId: $id, metadata: $metadata);
    }
}
