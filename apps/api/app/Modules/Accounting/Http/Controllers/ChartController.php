<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\Chart;
use App\Modules\Accounting\Support\Ledger;
use App\Modules\Accounting\Support\Posting;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Plan de cuentas, reglas de asientos automáticos y periodos contables. */
class ChartController extends Controller
{
    private const USED = '(SELECT EXISTS (SELECT 1 FROM acc_journal_lines l WHERE l.tenant_id = acc_accounts.tenant_id AND l.account_code = acc_accounts.code))';

    public function accounts(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        Chart::ensure($tenantId);

        return response()->json(
            DB::table('acc_accounts')->where('tenant_id', $tenantId)->orderBy('code')->limit(3000)
                ->select('acc_accounts.*')->selectRaw(self::USED.' AS used')->get()
                ->map(fn ($account) => $this->accountRow($account))->values()
        );
    }

    /** Cuenta nueva del plan. Cuelga de la cuenta título cuyo código es el prefijo más largo del suyo. */
    public function storeAccount(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        Chart::ensure($tenantId);
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^\d{2,10}$/'],
            'name' => ['required', 'string', 'max:255'],
            'account_type' => ['required', 'in:'.implode(',', array_keys(Chart::TYPES))],
            'postable' => ['required', 'boolean'],
        ], [
            'code.required' => 'Indica el código de la cuenta.',
            'code.regex' => 'El código lleva solo dígitos (de 2 a 10), como en el PCGE.',
            'name.required' => 'Indica el nombre de la cuenta.',
            'account_type.required' => 'Indica el tipo de la cuenta.',
        ]);

        $codes = DB::table('acc_accounts')->where('tenant_id', $tenantId)->pluck('code')->all();
        if (in_array($data['code'], $codes, true)) {
            throw ValidationException::withMessages(['code' => ['Ya existe una cuenta con ese código.']]);
        }
        $parent = Chart::parentOf($data['code'], $codes);
        // Una cuenta con subcuentas pasa a ser título (no imputable): solo si nada la usa.
        $parentPostable = $parent && DB::table('acc_accounts')->where('tenant_id', $tenantId)->where('code', $parent)->value('postable');
        if ($parentPostable && in_array($parent, $this->ruleAccounts(), true)) {
            throw ValidationException::withMessages(['code' => ["La cuenta {$parent} la usan las reglas de asientos automáticos: no puede tener subcuentas."]]);
        }
        if ($parentPostable && DB::table('acc_journal_lines')->where('tenant_id', $tenantId)->where('account_code', $parent)->exists()) {
            throw ValidationException::withMessages(['code' => ["La cuenta {$parent} ya tiene movimientos: no puede tener subcuentas."]]);
        }

        $id = (string) Str::uuid();
        DB::transaction(function () use ($request, $tenantId, $data, $parent, $parentPostable, $id) {
            if ($parentPostable) {
                DB::table('acc_accounts')->where('tenant_id', $tenantId)->where('code', $parent)->update(['postable' => false]);
            }
            DB::table('acc_accounts')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'code' => $data['code'], 'name' => trim($data['name']), 'account_type' => $data['account_type'],
                'nature' => Chart::nature($data['account_type']), 'postable' => (bool) $data['postable'], 'parent_code' => $parent,
                'active' => true, 'is_system' => false, 'created_by' => $request->user()->id,
            ]);
            AuditLogger::log(
                tenantId: $tenantId, userId: $request->user()->id, action: 'accounting_account.create', entityType: 'AccountingAccount', entityId: $id,
                metadata: ['note' => "{$data['code']} · ".trim($data['name'])],
            );
        });
        Chart::forget();

        return response()->json($this->accountDetail($tenantId, $id), 201);
    }

    /** Cambia el nombre o activa / desactiva una cuenta. Su código y su tipo no cambian. */
    public function updateAccount(string $account, Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        abort_unless(Str::isUuid($account), 404);
        $current = DB::table('acc_accounts')->where('tenant_id', $tenantId)->where('id', $account)->first();
        abort_unless((bool) $current, 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'active' => ['required', 'boolean'],
        ], ['name.required' => 'Indica el nombre de la cuenta.']);

        if (! $data['active'] && $current->active && in_array($current->code, $this->ruleAccounts(), true)) {
            throw ValidationException::withMessages(['active' => ["La cuenta {$current->code} la usan las reglas de asientos automáticos: no se desactiva."]]);
        }

        DB::transaction(function () use ($request, $tenantId, $current, $data) {
            DB::table('acc_accounts')->where('id', $current->id)->update(['name' => trim($data['name']), 'active' => (bool) $data['active']]);
            AuditLogger::log(
                tenantId: $tenantId, userId: $request->user()->id, action: 'accounting_account.update', entityType: 'AccountingAccount', entityId: $current->id,
                beforeData: ['name' => $current->name, 'active' => (bool) $current->active],
                afterData: ['name' => trim($data['name']), 'active' => (bool) $data['active']],
                metadata: ['note' => "{$current->code} · ".trim($data['name'])],
            );
        });
        Chart::forget();

        return response()->json($this->accountDetail($tenantId, $current->id));
    }

    public function rules()
    {
        return response()->json(Posting::RULES);
    }

    /** Periodos con su número de asientos y los movimientos bancarios que faltan conciliar. */
    public function periods(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        Ledger::openPeriod($tenantId, now()->format('Y-m'));

        return response()->json(
            DB::table('acc_periods')->leftJoin('users', 'users.id', '=', 'acc_periods.closed_by')
                ->where('acc_periods.tenant_id', $tenantId)->orderByDesc('acc_periods.period')->limit(240)
                ->get(['acc_periods.*', 'users.name as closed_by_name'])
                ->map(fn ($period) => $this->periodRow($tenantId, $period))->values()
        );
    }

    /**
     * Cierra un periodo ya terminado: deja de admitir asientos. Exige la conciliación bancaria del
     * periodo al día.
     */
    public function closePeriod(string $period, Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $row = $this->findPeriod($tenantId, $period);
        if ($row->status === 'CLOSED') {
            throw ValidationException::withMessages(['status' => ['El periodo ya está cerrado.']]);
        }
        if ($row->period >= now()->format('Y-m')) {
            throw ValidationException::withMessages(['status' => ['Solo se cierra un periodo que ya terminó.']]);
        }
        $pending = $this->unreconciled($tenantId, trim($row->period));
        if ($pending > 0) {
            throw ValidationException::withMessages(['status' => ["Hay {$pending} movimiento(s) bancario(s) del periodo sin conciliar. Concilia antes de cerrar."]]);
        }

        DB::transaction(function () use ($request, $tenantId, $row) {
            DB::table('acc_periods')->where('id', $row->id)->update(['status' => 'CLOSED', 'closed_at' => now(), 'closed_by' => $request->user()->id, 'reason' => null]);
            AuditLogger::log(
                tenantId: $tenantId, userId: $request->user()->id, action: 'period.close', entityType: 'AccountingPeriod', entityId: $row->id,
                metadata: ['from' => 'OPEN', 'to' => 'CLOSED', 'note' => trim($row->period)],
            );
        });

        return response()->json($this->periodRow($tenantId, $this->findPeriod($tenantId, $period)));
    }

    /** Reabre un periodo cerrado, con motivo (queda en auditoría). */
    public function reopenPeriod(string $period, Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $row = $this->findPeriod($tenantId, $period);
        if ($row->status !== 'CLOSED') {
            throw ValidationException::withMessages(['status' => ['El periodo ya está abierto.']]);
        }
        $reason = trim($request->validate(['reason' => ['required', 'string', 'max:1000']], ['reason.required' => 'Indica el motivo para reabrir el periodo.'])['reason']);

        DB::transaction(function () use ($request, $tenantId, $row, $reason) {
            DB::table('acc_periods')->where('id', $row->id)->update(['status' => 'OPEN', 'reason' => $reason]);
            AuditLogger::log(
                tenantId: $tenantId, userId: $request->user()->id, action: 'period.reopen', entityType: 'AccountingPeriod', entityId: $row->id,
                metadata: ['from' => 'CLOSED', 'to' => 'OPEN', 'note' => trim($row->period).": {$reason}"],
            );
        });

        return response()->json($this->periodRow($tenantId, $this->findPeriod($tenantId, $period)));
    }

    private function accountRow(object $account): array
    {
        return [
            'id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'type' => $account->account_type,
            'type_label' => Chart::TYPES[$account->account_type] ?? $account->account_type, 'nature' => $account->nature,
            'postable' => (bool) $account->postable, 'parent_code' => $account->parent_code, 'active' => (bool) $account->active,
            'system' => (bool) $account->is_system, 'used' => (bool) $account->used, 'lock_version' => (int) $account->lock_version,
        ];
    }

    private function accountDetail(string $tenantId, string $id): array
    {
        return $this->accountRow(DB::table('acc_accounts')->where('tenant_id', $tenantId)->where('id', $id)->select('acc_accounts.*')->selectRaw(self::USED.' AS used')->first());
    }

    private function findPeriod(string $tenantId, string $period): object
    {
        abort_unless((bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period), 404);
        $row = DB::table('acc_periods')->where('tenant_id', $tenantId)->where('period', $period)->first();
        abort_unless((bool) $row, 404);

        return $row;
    }

    private function periodRow(string $tenantId, object $period): array
    {
        $key = trim($period->period);

        return [
            'id' => $period->id, 'period' => $key, 'status' => $period->status,
            'entries' => DB::table('acc_journal_entries')->where('tenant_id', $tenantId)->where('period', $key)->count(),
            'unreconciled' => $this->unreconciled($tenantId, $key),
            'closed_at' => $period->closed_at ? Carbon::parse($period->closed_at)->toIso8601String() : null,
            'closed_by' => $period->closed_by_name ?? ($period->closed_by ? DB::table('users')->where('id', $period->closed_by)->value('name') : null),
            'reason' => $period->reason,
            'ended' => $key < now()->format('Y-m'),
        ];
    }

    /** Movimientos bancarios del periodo sin conciliar (la apertura no se concilia). */
    private function unreconciled(string $tenantId, string $period): int
    {
        return DB::table('bank_movements')->where('tenant_id', $tenantId)->where('reconciled', false)->where('ref_type', '<>', 'OPENING')
            ->whereRaw("to_char(movement_date, 'YYYY-MM') = ?", [$period])->count();
    }

    /** Cuentas que usan las reglas automáticas: desactivarlas haría fallar el registro de documentos. */
    private function ruleAccounts(): array
    {
        return ['1011', '1041', '10412', '1042', '1212', '122', '40111', '40113', '40172', '4212', '5911', '6391', '6392', '6563', '6761', '7041', '7761'];
    }
}
