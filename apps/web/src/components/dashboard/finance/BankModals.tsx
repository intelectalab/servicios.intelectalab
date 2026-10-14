"use client";

import { useState, type FormEvent, type ReactNode } from "react";
import { apiFetch } from "@/lib/api";
import { isoDateFromNow, money } from "@/lib/commercial";
import { fieldErrors, firstError } from "@/lib/crm";
import { ACCOUNT_KINDS } from "@/lib/finance";
import { Btn, Field, FormGrid, INPUT, Modal, Notice } from "@/components/ui/proto";
import { PickSelect } from "@/components/ui/pick-select";
import type { AccountKind, BankAccount, Currency } from "@/types/finance";

const accountOption = (account: BankAccount, withBalance = false): [string, string] => [
  account.id,
  withBalance ? `${account.name} · ${money(account.balance, account.currency)}` : `${account.name} (${account.currency})`,
];

/** Envío de un formulario de esta pantalla: errores por campo y, si no los hay, el mensaje general. */
function useSubmit(send: () => Promise<void>, fallback: string) {
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setSaving(true);
    setError("");
    setErrors({});
    try {
      await send();
    } catch (err) {
      const fields = fieldErrors(err);
      setErrors(fields);
      setError(firstError(err, fallback));
      setSaving(false);
    }
  }

  return { errors, error, saving, submit };
}

function FormModal({
  title, size, formId, onClose, submitLabel, saving, permission, danger = false, error, children,
}: {
  title: string; size?: "sm" | "md" | "lg"; formId: string; onClose: () => void; submitLabel: string; saving: boolean; permission: string;
  danger?: boolean; error: string; children: ReactNode;
}) {
  return (
    <Modal
      title={title}
      size={size}
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn type="submit" form={formId} variant={danger ? "danger" : "primary"} disabled={saving} permission={permission}>{saving ? "Guardando…" : submitLabel}</Btn>
        </>
      }
    >
      {error && <Notice tone="warn">{error}</Notice>}
      {children}
    </Modal>
  );
}

/** Alta o edición de una cuenta de banco, una caja o la cuenta de detracciones. Tipo y moneda no cambian después. */
export function AccountFormModal({
  account, custodians, onClose, onSaved,
}: { account: BankAccount | null; custodians: { id: string; name: string }[]; onClose: () => void; onSaved: () => void }) {
  const [name, setName] = useState(account?.name ?? "");
  const [kind, setKind] = useState<AccountKind>(account?.account_type ?? "BANK");
  const [currency, setCurrency] = useState<Currency>(account?.currency ?? "PEN");
  const [bank, setBank] = useState(account?.bank_name ?? "");
  const [number, setNumber] = useState(account?.account_number ?? "");
  const [cci, setCci] = useState(account?.cci ?? "");
  const [ledger, setLedger] = useState(account?.ledger_account ?? "");
  const [fund, setFund] = useState(account?.fund_amount != null ? String(account.fund_amount) : "");
  const [limit, setLimit] = useState(account?.own_expense_limit != null ? String(account.own_expense_limit) : "");
  const [custodian, setCustodian] = useState(account?.custodian?.id ?? "");
  const [opening, setOpening] = useState("");
  const [openingDate, setOpeningDate] = useState(isoDateFromNow(0));
  const [active, setActive] = useState(account ? account.status === "ACTIVE" : true);

  const cash = kind === "CASH";
  const { errors, error, saving, submit } = useSubmit(async () => {
    const body = {
      name: name.trim(), bank_name: bank.trim() || null, account_number: number.trim() || null, cci: cci.trim() || null, ledger_account: ledger.trim() || null,
      fund_amount: cash && fund !== "" ? Number(fund) : null, expense_limit: cash && limit !== "" ? Number(limit) : null, custodian_user_id: cash && custodian ? custodian : null,
      ...(account
        ? { status: active ? "ACTIVE" : "INACTIVE" }
        : { account_type: kind, currency: kind === "DETRACTION" ? "PEN" : currency, opening_balance: opening === "" ? null : Number(opening), opening_date: openingDate }),
    };
    await apiFetch(account ? `/v1/bank-accounts/${account.id}` : "/v1/bank-accounts", { method: account ? "PUT" : "POST", body: JSON.stringify(body) });
    onSaved();
  }, "No se pudo guardar la cuenta.");
  const generalError = Object.keys(errors).some((key) => ["name", "account_type", "currency", "account_number", "custodian_user_id", "status", "fund_amount", "expense_limit", "opening_balance", "opening_date"].includes(key)) ? "" : error;

  return (
    <FormModal title={account ? "Editar cuenta" : "Nueva cuenta"} formId="account-form" onClose={onClose} submitLabel="Guardar" saving={saving} permission="treasury.manage" error={generalError}>
      <form id="account-form" onSubmit={submit}>
        <FormGrid>
          <Field label="Nombre" required span={2} error={errors.name}>
            <input className={INPUT} value={name} onChange={(e) => setName(e.target.value)} maxLength={255} required autoFocus placeholder="BCP Soles" />
          </Field>
          <Field label="Tipo" error={errors.account_type} hint={account ? "No se modifica" : undefined}>
            <PickSelect
              className={`${INPUT} disabled:opacity-60`} ariaLabel="Tipo" disabled={Boolean(account)} value={kind}
              onChange={(value) => {
                setKind(value as AccountKind);
                if (value === "DETRACTION") setCurrency("PEN");
              }}
              options={Object.entries(ACCOUNT_KINDS)}
            />
          </Field>
          <Field label="Moneda" error={errors.currency} hint={account ? "No se modifica" : kind === "DETRACTION" ? "La cuenta de detracciones es en soles" : undefined}>
            <PickSelect
              className={`${INPUT} disabled:opacity-60`} ariaLabel="Moneda" disabled={Boolean(account) || kind === "DETRACTION"} value={currency}
              onChange={(value) => setCurrency(value as Currency)} options={[["PEN", "Soles"], ["USD", "Dólares"]]}
            />
          </Field>
          {!cash && (
            <>
              <Field label="Banco" span={2}>
                <input className={INPUT} value={bank} onChange={(e) => setBank(e.target.value)} maxLength={150} placeholder={kind === "DETRACTION" ? "Banco de la Nación" : "BCP"} />
              </Field>
              <Field label="Número" required error={errors.account_number}>
                <input className={INPUT} value={number} onChange={(e) => setNumber(e.target.value)} maxLength={60} required placeholder={kind === "DETRACTION" ? "00-000-000000" : undefined} />
              </Field>
              <Field label="CCI">
                <input className={INPUT} value={cci} onChange={(e) => setCci(e.target.value)} maxLength={40} />
              </Field>
            </>
          )}
          {cash && (
            <>
              <Field label="Custodio" span={2} error={errors.custodian_user_id}>
                <PickSelect
                  className={INPUT} ariaLabel="Custodio" searchable value={custodian} onChange={setCustodian}
                  options={[["", "Sin custodio"], ...custodians.map((person): [string, string] => [person.id, person.name])]}
                />
              </Field>
              <Field label="Fondo fijo" error={errors.fund_amount} hint="Lo que la caja debe tener al reponerse">
                <input className={INPUT} type="number" min="0" step="0.01" inputMode="decimal" value={fund} onChange={(e) => setFund(e.target.value)} />
              </Field>
              <Field label="Tope por comprobante" error={errors.expense_limit} hint="Sin dato, S/ 350">
                <input className={INPUT} type="number" min="0.01" step="0.01" inputMode="decimal" value={limit} onChange={(e) => setLimit(e.target.value)} />
              </Field>
            </>
          )}
          <Field label="Cuenta contable" hint="Opcional">
            <input className={INPUT} value={ledger} onChange={(e) => setLedger(e.target.value)} maxLength={30} placeholder={cash ? "1011" : "1041"} />
          </Field>
          {!account && (
            <>
              <Field label="Saldo de apertura" error={errors.opening_balance} hint="Lo que la cuenta tiene hoy">
                <input className={INPUT} type="number" min="0" step="0.01" inputMode="decimal" value={opening} onChange={(e) => setOpening(e.target.value)} />
              </Field>
              <Field label="Fecha de apertura" error={errors.opening_date}>
                <input className={INPUT} type="date" value={openingDate} max={isoDateFromNow(0)} onChange={(e) => setOpeningDate(e.target.value)} />
              </Field>
            </>
          )}
        </FormGrid>
        {account && (
          <>
            <label className="relative mt-3.5 flex cursor-pointer items-start gap-2 text-[13px]">
              <input type="checkbox" className="mt-0.5 h-4 w-4 accent-[#1B8FC9]" checked={active} onChange={(e) => setActive(e.target.checked)} />
              <span>Cuenta activa</span>
            </label>
            {errors.status && <small className="mt-1 block text-xs text-[color:var(--p-danger-text)]">{errors.status}</small>}
          </>
        )}
        <p className="app-muted mt-2 text-xs">El saldo de la cuenta es la suma de sus movimientos: no se escribe a mano. El tipo y la moneda no se pueden cambiar después.</p>
      </form>
    </FormModal>
  );
}

/** "Transferir entre cuentas": un egreso y un ingreso con la misma referencia, en la misma moneda. */
export function TransferModal({ accounts, onClose, onDone }: { accounts: BankAccount[]; onClose: () => void; onDone: () => void }) {
  const usable = accounts.filter((account) => account.status === "ACTIVE" && account.account_type !== "DETRACTION");
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [amount, setAmount] = useState("");
  const [reference, setReference] = useState("");

  const origin = usable.find((account) => account.id === from);
  // Solo destinos de la misma moneda que el origen.
  const targets = usable.filter((account) => account.id !== from && (!origin || account.currency === origin.currency));
  const { errors, error, saving, submit } = useSubmit(async () => {
    await apiFetch("/v1/bank-transfers", { method: "POST", body: JSON.stringify({ from_account_id: from, to_account_id: to, amount: Number(amount), reference: reference.trim() || null }) });
    onDone();
  }, "No se pudo registrar la transferencia.");

  return (
    <FormModal
      title="Transferencia entre cuentas" size="sm" formId="transfer-form" onClose={onClose} submitLabel="Transferir" saving={saving} permission="treasury.create"
      error={errors.from_account_id || errors.to_account_id || errors.amount ? "" : error}
    >
      <form id="transfer-form" onSubmit={submit} className="grid gap-3">
        <Field label="Desde" required error={errors.from_account_id}>
          <PickSelect
            className={INPUT} ariaLabel="Desde" value={from}
            onChange={(value) => {
              setFrom(value);
              setTo("");
            }}
            options={[["", "Seleccionar…"], ...usable.map((account) => accountOption(account, true))]}
          />
        </Field>
        <Field label="Hacia" required error={errors.to_account_id} hint={origin ? `Solo cuentas en ${origin.currency === "USD" ? "dólares" : "soles"}` : undefined}>
          <PickSelect className={INPUT} ariaLabel="Hacia" disabled={!from} value={to} onChange={setTo} options={[["", "Seleccionar…"], ...targets.map((account) => accountOption(account))]} />
        </Field>
        <Field label="Monto" required error={errors.amount}>
          <input className={INPUT} type="number" min="0.01" step="0.01" inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} required />
        </Field>
        <Field label="N.º de operación">
          <input className={INPUT} value={reference} onChange={(e) => setReference(e.target.value)} maxLength={255} />
        </Field>
      </form>
    </FormModal>
  );
}

/** "Registrar gasto" de caja chica. */
export function CashExpenseModal({ cash, onClose, onDone }: { cash: BankAccount; onClose: () => void; onDone: (number: string) => void }) {
  const [description, setDescription] = useState("");
  const [amount, setAmount] = useState("");
  const [costCenter, setCostCenter] = useState("");
  const [expenseAccount, setExpenseAccount] = useState("");
  const [receipt, setReceipt] = useState("");
  const [date, setDate] = useState(isoDateFromNow(0));

  const { errors, error, saving, submit } = useSubmit(async () => {
    const saved = await apiFetch<{ expense_number: string }>("/v1/cash-expenses", {
      method: "POST",
      body: JSON.stringify({
        account_id: cash.id, description: description.trim(), amount: Number(amount), expense_date: date,
        receipt: receipt.trim() || null, cost_center: costCenter.trim() || null, expense_account: expenseAccount.trim() || null,
      }),
    });
    onDone(saved.expense_number);
  }, "No se pudo registrar el gasto.");

  return (
    <FormModal
      title="Gasto de caja chica" formId="cash-expense-form" onClose={onClose} submitLabel="Registrar" saving={saving} permission="treasury.create"
      error={errors.description || errors.amount || errors.expense_date ? "" : error}
    >
      <form id="cash-expense-form" onSubmit={submit}>
        <FormGrid>
          <Field label="Concepto" required span={2} error={errors.description}>
            <input className={INPUT} value={description} onChange={(e) => setDescription(e.target.value)} maxLength={500} required autoFocus />
          </Field>
          <Field label={`Monto (${cash.currency === "USD" ? "US$" : "S/"})`} required error={errors.amount} hint={`Tope ${money(cash.expense_limit, cash.currency)} por comprobante`}>
            <input className={INPUT} type="number" min="0.01" max={cash.expense_limit} step="0.01" inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} required />
          </Field>
          <Field label="Centro de costo">
            <input className={INPUT} value={costCenter} onChange={(e) => setCostCenter(e.target.value)} maxLength={100} />
          </Field>
          <Field label="Cuenta de gasto">
            <input className={INPUT} value={expenseAccount} onChange={(e) => setExpenseAccount(e.target.value)} maxLength={100} />
          </Field>
          <Field label="Comprobante">
            <input className={INPUT} value={receipt} onChange={(e) => setReceipt(e.target.value)} maxLength={150} placeholder="Boleta B001-123 / Ticket" />
          </Field>
          <Field label="Fecha" error={errors.expense_date}>
            <input className={INPUT} type="date" value={date} max={isoDateFromNow(0)} onChange={(e) => setDate(e.target.value)} required />
          </Field>
        </FormGrid>
        <p className="app-muted mt-2 text-xs">Saldo de la caja: {money(cash.balance, cash.currency)}. Enviar dos veces no duplica el gasto.</p>
      </form>
    </FormModal>
  );
}

/** "Arqueo de caja": lo contado contra el saldo en libros. */
export function CashCountModal({ cash, onClose, onDone }: { cash: BankAccount; onClose: () => void; onDone: (difference: number) => void }) {
  const [counted, setCounted] = useState("");
  const [note, setNote] = useState("");

  const { errors, error, saving, submit } = useSubmit(async () => {
    const saved = await apiFetch<{ difference: number }>("/v1/cash-counts", { method: "POST", body: JSON.stringify({ account_id: cash.id, counted: Number(counted), note: note.trim() || null }) });
    onDone(saved.difference);
  }, "No se pudo registrar el arqueo.");

  return (
    <FormModal title="Arqueo de caja" size="sm" formId="cash-count-form" onClose={onClose} submitLabel="Registrar arqueo" saving={saving} permission="treasury.create" error={errors.counted ? "" : error}>
      <form id="cash-count-form" onSubmit={submit} className="grid gap-3">
        <p>Saldo en libros: <strong className="font-semibold tabular-nums">{money(cash.balance, cash.currency)}</strong></p>
        <Field label={`Efectivo contado (${cash.currency === "USD" ? "US$" : "S/"})`} required error={errors.counted}>
          <input className={INPUT} type="number" min="0" step="0.01" inputMode="decimal" value={counted} onChange={(e) => setCounted(e.target.value)} required autoFocus />
        </Field>
        <Field label="Observación">
          <textarea className={INPUT} rows={3} value={note} onChange={(e) => setNote(e.target.value)} maxLength={1000} />
        </Field>
        <p className="app-muted text-xs">El arqueo no cambia el saldo: deja constancia de la diferencia, que queda en auditoría.</p>
      </form>
    </FormModal>
  );
}

/** "Reponer fondo": transfiere a la caja lo que le falta, desde una cuenta bancaria de su moneda. */
export function RefillModal({ cash, accounts, onClose, onDone }: { cash: BankAccount; accounts: BankAccount[]; onClose: () => void; onDone: () => void }) {
  const banks = accounts.filter((account) => account.status === "ACTIVE" && account.account_type === "BANK" && account.currency === cash.currency);
  const [from, setFrom] = useState(banks.length === 1 ? banks[0].id : "");
  const missing = Math.max(0, Math.round(((cash.fund_amount ?? 0) - cash.balance) * 100) / 100);

  const { error, saving, submit } = useSubmit(async () => {
    await apiFetch(`/v1/bank-accounts/${cash.id}/refill`, { method: "POST", body: JSON.stringify({ from_account_id: from }) });
    onDone();
  }, "No se pudo reponer el fondo.");

  return (
    <FormModal title="Reponer fondo" size="sm" formId="refill-form" onClose={onClose} submitLabel="Reponer" saving={saving} permission="treasury.create" error={error}>
      <form id="refill-form" onSubmit={submit} className="grid gap-3">
        <p>Se transfiere a {cash.name} lo que le falta a su fondo: <strong className="font-semibold tabular-nums">{money(missing, cash.currency)}</strong></p>
        <Field label="Desde" required hint={banks.length ? undefined : "No hay cuentas bancarias en esta moneda"}>
          <PickSelect className={INPUT} ariaLabel="Desde" disabled={!banks.length} value={from} onChange={setFrom} options={[["", "Seleccionar…"], ...banks.map((account) => accountOption(account, true))]} />
        </Field>
      </form>
    </FormModal>
  );
}
