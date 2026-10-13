"use client";

import { useEffect, useState, type FormEvent } from "react";
import { apiFetch } from "@/lib/api";
import { customerName, isoDateFromNow, money, shortDate } from "@/lib/commercial";
import { fieldErrors, firstError } from "@/lib/crm";
import { PAYMENT_METHODS } from "@/lib/finance";
import { Btn, Field, FormGrid, INPUT, LoadError, Modal, Notice } from "@/components/ui/proto";
import { PickSelect } from "@/components/ui/pick-select";
import type { Currency, Payment, PaymentDetail, PaymentOptions } from "@/types/finance";

const EMPTY_OPTIONS: PaymentOptions = { customers: [], accounts: [], methods: Object.keys(PAYMENT_METHODS) };

/** "Registrar pago": un cobro confirmado que, si se pide, se aplica a las cuentas más antiguas del cliente. */
export function PaymentFormModal({
  customerId: fixedCustomer, onClose, onSaved,
}: {
  /** Cliente ya elegido (al registrar desde una cuenta por cobrar). */
  customerId?: string;
  onClose: () => void;
  onSaved: (payment: PaymentDetail) => void;
}) {
  const [options, setOptions] = useState<PaymentOptions | null>(null);
  const [customerId, setCustomerId] = useState(fixedCustomer ?? "");
  const [amount, setAmount] = useState("");
  const [currency, setCurrency] = useState<Currency>("PEN");
  const [accountId, setAccountId] = useState("");
  const [method, setMethod] = useState("TRANSFERENCIA");
  const [reference, setReference] = useState("");
  const [paidAt, setPaidAt] = useState(isoDateFromNow(0));
  const [autoAllocate, setAutoAllocate] = useState(true);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  // Las listas del formulario se piden al abrirlo.
  useEffect(() => {
    let cancelled = false;
    apiFetch<PaymentOptions>("/v1/payments/options")
      .then((next) => { if (!cancelled) setOptions(next); })
      .catch(() => { if (!cancelled) setOptions(EMPTY_OPTIONS); });
    return () => { cancelled = true; };
  }, []);

  // Solo las cuentas de la moneda del cobro.
  const accounts = options?.accounts.filter((account) => account.currency === currency) ?? [];
  const cash = method === "EFECTIVO";

  async function submit(event: FormEvent) {
    event.preventDefault();
    setSaving(true);
    setError("");
    setErrors({});
    try {
      const payment = await apiFetch<PaymentDetail>("/v1/payments", {
        method: "POST",
        body: JSON.stringify({
          customer_id: customerId, amount: Number(amount), currency, payment_method: method, reference: reference.trim() || null,
          paid_at: paidAt, financial_account_id: accountId || null, auto_allocate: autoAllocate,
        }),
      });
      onSaved(payment);
    } catch (err) {
      const fields = fieldErrors(err);
      setErrors(fields);
      if (!Object.keys(fields).length) setError(firstError(err, "No se pudo registrar el pago."));
      setSaving(false);
    }
  }

  return (
    <Modal
      title="Registrar pago"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn type="submit" form="payment-form" disabled={saving || !options} permission="payments.create">{saving ? "Guardando…" : "Registrar"}</Btn>
        </>
      }
    >
      <form id="payment-form" onSubmit={submit}>
        {error && <Notice tone="warn">{error}</Notice>}
        <FormGrid>
          <Field label="Cliente" required span={2} error={errors.customer_id}>
            <PickSelect
              className={INPUT} ariaLabel="Cliente" searchable disabled={!options || Boolean(fixedCustomer)} value={customerId}
              onChange={(value) => {
                setCustomerId(value);
                const customer = options?.customers.find((item) => item.id === value);
                if (customer) {
                  setCurrency(customer.currency);
                  setAccountId("");
                }
              }}
              options={[["", options ? "Seleccionar…" : "Cargando…"], ...(options?.customers ?? []).map((customer): [string, string] => [customer.id, customer.name])]}
            />
          </Field>
          <Field label="Monto" required error={errors.amount}>
            <input className={INPUT} type="number" min="0.01" step="0.01" inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} required />
          </Field>
          <Field label="Moneda" error={errors.currency}>
            <PickSelect
              className={INPUT} ariaLabel="Moneda" value={currency}
              onChange={(value) => {
                setCurrency(value as Currency);
                setAccountId("");
              }}
              options={[["PEN", "Soles"], ["USD", "Dólares"]]}
            />
          </Field>
          <Field
            label="Cuenta de destino" span={2} error={errors.financial_account_id}
            hint={options && !accounts.length ? "Sin cuentas registradas en esta moneda: llegarán con Bancos y caja." : undefined}
          >
            <PickSelect
              className={INPUT} ariaLabel="Cuenta de destino" disabled={!accounts.length} value={accountId} onChange={setAccountId}
              options={[["", "Según el método"], ...accounts.map((account): [string, string] => [account.id, `${account.name} (${account.currency})`])]}
            />
          </Field>
          <Field label="Método" error={errors.payment_method}>
            <PickSelect
              className={INPUT} ariaLabel="Método" value={method} onChange={setMethod}
              options={(options?.methods ?? EMPTY_OPTIONS.methods).map((key): [string, string] => [key, PAYMENT_METHODS[key] ?? key])}
            />
          </Field>
          <Field label="N.º de operación" required={!cash} error={errors.reference} hint={cash ? "El efectivo no lleva número de operación." : undefined}>
            <input className={INPUT} value={reference} onChange={(e) => setReference(e.target.value)} maxLength={255} required={!cash} />
          </Field>
          <Field label="Fecha" error={errors.paid_at}>
            <input className={INPUT} type="date" value={paidAt} max={isoDateFromNow(0)} onChange={(e) => setPaidAt(e.target.value)} required />
          </Field>
        </FormGrid>
        <label className="relative mt-3.5 flex cursor-pointer items-start gap-2 text-[13px]">
          <input type="checkbox" className="mt-0.5 h-4 w-4 accent-[#1B8FC9]" checked={autoAllocate} onChange={(e) => setAutoAllocate(e.target.checked)} />
          <span>Aplicar automáticamente a las CxC más antiguas</span>
        </label>
        <p className="app-muted mt-2 text-xs">Enviar dos veces no duplica el pago. Lo que no se aplique queda como anticipo del cliente.</p>
      </form>
    </Modal>
  );
}

/** Pide la ficha del cobro para los modales que la necesitan (saldo libre, cuentas pendientes). */
function usePaymentDetail(id: string) {
  const [payment, setPayment] = useState<PaymentDetail | null>(null);
  const [error, setError] = useState("");
  const [retry, setRetry] = useState(0);

  useEffect(() => {
    let cancelled = false;
    apiFetch<PaymentDetail>(`/v1/payments/${id}`)
      .then((next) => {
        if (cancelled) return;
        setPayment(next);
        setError("");
      })
      .catch(() => { if (!cancelled) setError("No se pudo cargar el cobro."); });
    return () => { cancelled = true; };
  }, [id, retry]);

  return { payment, error, retry: () => setRetry((n) => n + 1) };
}

const SKELETON = <div className="h-32 animate-pulse rounded bg-[var(--app-surface-muted)]" aria-busy="true" />;

/** "Aplicar": reparte el saldo libre del cobro entre las cuentas por cobrar elegidas, en el orden mostrado. */
export function AllocatePaymentModal({
  payment: row, onClose, onDone, onError,
}: { payment: Payment; onClose: () => void; onDone: () => void; onError: (text: string) => void }) {
  const { payment, error, retry } = usePaymentDetail(row.id);
  const [unchecked, setUnchecked] = useState<Set<string>>(new Set());
  const [saving, setSaving] = useState(false);

  const open = payment?.open_receivables ?? [];
  const chosen = open.filter((receivable) => !unchecked.has(receivable.id));

  async function submit() {
    setSaving(true);
    try {
      await apiFetch(`/v1/payments/${row.id}/allocate`, { method: "POST", body: JSON.stringify({ receivable_ids: chosen.map((receivable) => receivable.id) }) });
      onDone();
    } catch (err) {
      onError(firstError(err, "No se pudo aplicar el pago."));
      setSaving(false);
    }
  }

  return (
    <Modal
      title={`Aplicar ${row.payment_number}`}
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          {open.length > 0 && <Btn onClick={submit} disabled={saving || !chosen.length} permission="payments.manage">{saving ? "Aplicando…" : "Aplicar"}</Btn>}
        </>
      }
    >
      {error ? <LoadError text={error} onRetry={retry} /> : !payment ? SKELETON : (
        <>
          <p className="text-[15px]">Saldo por aplicar: <strong className="font-semibold tabular-nums">{money(payment.free, payment.currency)}</strong></p>
          <p className="app-muted mb-3 text-[13px]">{customerName(payment.customer)}</p>
          {open.length ? (
            <>
              <ul className="grid">
                {open.map((receivable) => (
                  <li key={receivable.id} className="border-b border-[color:var(--app-border)] py-2 last:border-b-0">
                    <label className="relative flex cursor-pointer items-start gap-2">
                      <input
                        type="checkbox"
                        className="mt-0.5 h-4 w-4 accent-[#1B8FC9]"
                        checked={!unchecked.has(receivable.id)}
                        onChange={() =>
                          setUnchecked((current) => {
                            const next = new Set(current);
                            if (!next.delete(receivable.id)) next.add(receivable.id);
                            return next;
                          })
                        }
                      />
                      <span>
                        <strong className="font-semibold">{receivable.invoice_number ?? "—"}</strong> · vence {shortDate(receivable.due_date)} · saldo {money(receivable.balance_amount, payment.currency)}
                      </span>
                    </label>
                  </li>
                ))}
              </ul>
              <p className="app-muted mt-2 text-xs">Se aplica en el orden mostrado hasta agotar el saldo. Un pago puede cubrir varias CxC.</p>
            </>
          ) : (
            <p className="app-muted">El cliente no tiene cuentas pendientes en {payment.currency === "USD" ? "dólares" : "soles"}; el saldo queda como anticipo.</p>
          )}
        </>
      )}
    </Modal>
  );
}

/** "Reembolsar": devuelve todo o parte del cobro; si estaba aplicado, esas cuentas vuelven a tener saldo. */
export function RefundPaymentModal({
  payment, onClose, onDone, onError,
}: { payment: Payment; onClose: () => void; onDone: () => void; onError: (text: string) => void }) {
  const refundable = Math.round((payment.amount - payment.refunded_amount) * 100) / 100;
  const [amount, setAmount] = useState(String(refundable));
  const [reason, setReason] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setSaving(true);
    setErrors({});
    try {
      await apiFetch(`/v1/payments/${payment.id}/refund`, { method: "POST", body: JSON.stringify({ amount: Number(amount), reason: reason.trim() }) });
      onDone();
    } catch (err) {
      const fields = fieldErrors(err);
      setErrors(fields);
      if (!fields.amount && !fields.reason) onError(firstError(err, "No se pudo registrar el reembolso."));
      setSaving(false);
    }
  }

  return (
    <Modal
      title={`Reembolsar ${payment.payment_number}`}
      size="sm"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn type="submit" form="refund-form" variant="danger" disabled={saving} permission="payments.approve">{saving ? "Reembolsando…" : "Reembolsar"}</Btn>
        </>
      }
    >
      <form id="refund-form" onSubmit={submit} className="grid gap-3">
        <Field label={`Monto a reembolsar (${payment.currency === "USD" ? "US$" : "S/"})`} required error={errors.amount} hint={`Queda por reembolsar: ${money(refundable, payment.currency)}`}>
          <input className={INPUT} type="number" min="0.01" max={refundable} step="0.01" inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} required autoFocus />
        </Field>
        <Field label="Motivo" required error={errors.reason}>
          <textarea className={INPUT} rows={3} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={1000} required />
        </Field>
        <p className="app-muted text-xs">Si el pago estaba aplicado, se revierte la aplicación y la CxC vuelve a tener saldo.</p>
      </form>
    </Modal>
  );
}
