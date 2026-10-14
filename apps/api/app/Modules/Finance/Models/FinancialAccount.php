<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Cuenta de banco, caja o la cuenta de detracciones. No guarda su saldo: es la suma de sus
 * movimientos (`bank_movements`). Su moneda y su tipo no cambian.
 */
class FinancialAccount extends Model
{
    /** Tipo => texto visible. */
    public const KINDS = ['BANK' => 'Cuenta bancaria', 'CASH' => 'Caja', 'DETRACTION' => 'Detracciones (BN)'];

    /** Tope por comprobante de un gasto de caja chica cuando la caja no define el suyo. */
    public const DEFAULT_EXPENSE_LIMIT = 350;

    protected $table = 'financial_accounts';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id', 'code', 'name', 'account_type', 'currency', 'status', 'bank_name', 'account_number', 'cci', 'ledger_account',
        'fund_amount', 'expense_limit', 'custodian_user_id', 'created_by',
    ];

    protected $casts = ['fund_amount' => 'decimal:4', 'expense_limit' => 'decimal:4'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function custodian()
    {
        return $this->belongsTo(\App\Models\User::class, 'custodian_user_id');
    }

    public function expenseLimit(): float
    {
        return (float) ($this->expense_limit ?? self::DEFAULT_EXPENSE_LIMIT);
    }
}
