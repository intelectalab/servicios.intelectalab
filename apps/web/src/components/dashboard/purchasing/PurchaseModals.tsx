"use client";

import { useEffect, useState, type FormEvent } from "react";
import Link from "next/link";
import { Plus, Trash2 } from "lucide-react";
import { ApiError, apiFetch } from "@/lib/api";
import { IGV_RATE, isoDateFromNow, money, shortDate, stateOf } from "@/lib/commercial";
import { dateTime, fieldErrors, firstError } from "@/lib/crm";
import {
  DOC_TYPES, PAYABLE_MOVEMENTS, PAYABLE_STATES, SUNAT_VALIDATION, SUPPLIER_INVOICE_STATES, SUPPLIER_PAYMENT_METHODS, docTypeLabel, pctText, purchasingHistoryLabel,
} from "@/lib/purchasing";
import { useAccess } from "@/lib/access-context";
import { Badge, Btn, Chip, Field, FormGrid, INPUT, Kv, LoadError, Modal, Notice, StateTrack, Timeline } from "@/components/ui/proto";
import { PickSelect } from "@/components/ui/pick-select";
import { ReasonModal } from "@/components/dashboard/crm/CrmModals";
import type { Currency } from "@/types/finance";
import type {
  CostCenter, Payable, PurchaseOptions, PurchaseOrderDetail, SupplierDocType, SupplierInvoiceDetail, SupplierInvoiceOptions, SupplierPayment, SupplierPaymentDetail, SupplierPaymentOptions,
} from "@/types/purchasing";

const LINK = "text-[color:var(--p-brand-text)] hover:underline";
const SKELETON = <div className="h-40 animate-pulse rounded bg-[var(--app-surface-muted)]" aria-busy="true" />;
const round2 = (value: number) => Math.round(value * 100) / 100;
// Con otra ventana encima, Escape cierra solo la de arriba.
const keepOpen = () => undefined;

const supplierOptions = (options: PurchaseOptions | null): [string, string][] => [
  ["", options ? "Seleccionar…" : "Cargando…"],
  ...(options?.suppliers ?? []).map((supplier): [string, string] => [supplier.id, supplier.tax_id ? `${supplier.name} · ${supplier.tax_id}` : supplier.name]),
];

const centerOptions = (centers: CostCenter[], current = ""): [string, string][] => [
  ["", "Seleccionar…"],
  // Los inactivos no se eligen en un documento nuevo; el que ya tenía el documento se conserva.
  ...centers.filter((center) => center.active || center.id === current).map((center): [string, string] => [center.id, `${center.code} · ${center.name}`]),
];

const accountOptions = (options: PurchaseOptions | null): [string, string][] =>
  (options?.expense_accounts ?? []).map((account): [string, string] => [account.code, `${account.code} · ${account.name}`]);

/**
 * Campo "Centro de costo" con su alta rápida, mientras no exista su pantalla en Contabilidad.
 * El alta se abre debajo del campo (no en otra ventana) para no salir del formulario.
 */
function CostCenterField({
  centers, value, onChange, onCreated, error, disabled,
}: { centers: CostCenter[]; value: string; onChange: (value: string) => void; onCreated: (center: CostCenter) => void; error?: string; disabled?: boolean }) {
  const [creating, setCreating] = useState(false);
  const [code, setCode] = useState("");
  const [name, setName] = useState("");
  const [problem, setProblem] = useState("");
  const [saving, setSaving] = useState(false);

  async function create() {
    if (!code.trim() || !name.trim()) {
      setProblem("Código y nombre son obligatorios.");
      return;
    }
    setSaving(true);
    setProblem("");
    try {
      const center = await apiFetch<CostCenter>("/v1/cost-centers", { method: "POST", body: JSON.stringify({ code: code.trim(), name: name.trim() }) });
      setCreating(false);
      setCode("");
      setName("");
      onCreated(center);
    } catch (err) {
      setProblem(firstError(err, "No se pudo crear el centro de costo."));
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="grid min-w-0 content-start gap-1.5">
      <Field label="Centro de costo" required error={error}>
        <PickSelect className={INPUT} ariaLabel="Centro de costo" searchable disabled={disabled} value={value} onChange={onChange} options={centerOptions(centers, value)} />
      </Field>
      {creating ? (
        <div className="grid gap-2 rounded-[10px] border border-[color:var(--app-border)] bg-[var(--app-surface-muted)] p-2.5">
          <input className={INPUT} aria-label="Código del centro de costo" placeholder="Código (p. ej. CC-OPS)" value={code} onChange={(e) => setCode(e.target.value.toUpperCase())} maxLength={30} autoFocus />
          <input
            className={INPUT} aria-label="Nombre del centro de costo" placeholder="Nombre" value={name} onChange={(e) => setName(e.target.value)} maxLength={150}
            onKeyDown={(event) => {
              if (event.key === "Enter") {
                event.preventDefault();
                create();
              }
            }}
          />
          {problem && <small className="text-xs text-[color:var(--p-danger-text)]">{problem}</small>}
          <div className="flex flex-wrap justify-end gap-1.5">
            <Btn variant="ghost" size="sm" onClick={() => { setCreating(false); setProblem(""); }}>Cancelar</Btn>
            <Btn size="sm" disabled={saving} onClick={create} permission="budgets.manage">{saving ? "Creando…" : "Crear centro"}</Btn>
          </div>
        </div>
      ) : (
        <div>
          <Btn variant="ghost" size="sm" onClick={() => setCreating(true)} permission="budgets.manage">
            <Plus size={14} />
            <span>Nuevo centro de costo</span>
          </Btn>
        </div>
      )}
    </div>
  );
}

type Line = { description: string; quantity: string; unit_cost: string; account_code: string };

const emptyLine = (account: string): Line => ({ description: "", quantity: "", unit_cost: "", account_code: account });

/** "Nueva orden de compra" o edición de un borrador. */
export function PurchaseOrderFormModal({
  order, onClose, onSaved,
}: {
  /** Borrador a editar; sin él, es una orden nueva. */
  order?: PurchaseOrderDetail;
  onClose: () => void;
  onSaved: (order: PurchaseOrderDetail, submitted: boolean) => void;
}) {
  const [options, setOptions] = useState<PurchaseOptions | null>(null);
  const [centers, setCenters] = useState<CostCenter[]>([]);
  const [supplierId, setSupplierId] = useState(order?.supplier.id ?? "");
  const [centerId, setCenterId] = useState(order?.cost_center.id ?? "");
  const [currency, setCurrency] = useState<Currency>(order?.currency ?? "PEN");
  const [neededBy, setNeededBy] = useState(order?.needed_by ?? isoDateFromNow(10));
  const [notes, setNotes] = useState(order?.notes ?? "");
  // Como el prototipo, el formulario nace con tres líneas; se pueden agregar más.
  const [lines, setLines] = useState<Line[]>(() =>
    order
      ? order.items.map((item) => ({ description: item.description, quantity: String(item.quantity), unit_cost: String(item.unit_cost), account_code: item.account_code }))
      : [emptyLine(""), emptyLine(""), emptyLine("")],
  );
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    let cancelled = false;
    apiFetch<PurchaseOptions>("/v1/purchase-orders/options")
      .then((next) => {
        if (cancelled) return;
        setOptions(next);
        setCenters(next.cost_centers);
        setLines((current) => current.map((line) => (line.account_code ? line : { ...line, account_code: next.default_account })));
      })
      .catch(() => { if (!cancelled) setError("No se pudieron cargar los proveedores y centros de costo."); });
    return () => { cancelled = true; };
  }, []);

  const setLine = (index: number, patch: Partial<Line>) => setLines((current) => current.map((line, i) => (i === index ? { ...line, ...patch } : line)));
  const filled = lines.filter((line) => line.description.trim());
  const subtotal = round2(filled.reduce((sum, line) => sum + round2(Number(line.quantity || 0) * Number(line.unit_cost || 0)), 0));
  const total = round2(subtotal * (1 + IGV_RATE / 100));

  async function save(submit: boolean) {
    setSaving(true);
    setError("");
    setErrors({});
    try {
      const saved = await apiFetch<PurchaseOrderDetail>(order ? `/v1/purchase-orders/${order.id}` : "/v1/purchase-orders", {
        method: order ? "PUT" : "POST",
        body: JSON.stringify({
          supplier_id: supplierId, cost_center_id: centerId, currency, needed_by: neededBy || null, notes: notes.trim() || null, submit,
          // Solo las líneas con descripción, como en el prototipo.
          items: filled.map((line) => ({ description: line.description.trim(), quantity: Number(line.quantity), unit_cost: Number(line.unit_cost), account_code: line.account_code })),
        }),
      });
      onSaved(saved, submit);
    } catch (err) {
      const fields = fieldErrors(err);
      setErrors(fields);
      const line = Object.keys(fields).find((key) => key.startsWith("items"));
      if (line || !Object.keys(fields).some((key) => ["supplier_id", "cost_center_id", "currency", "needed_by", "notes"].includes(key))) {
        setError(line ? fields[line] : firstError(err, "No se pudo guardar la orden de compra."));
      }
      setSaving(false);
    }
  }

  return (
    <Modal
      title={order ? `Editar ${order.order_number}` : "Nueva orden de compra"}
      size="lg"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn variant="secondary" disabled={saving || !options} onClick={() => save(false)} permission={order ? "purchases.manage" : "purchases.create"}>Guardar borrador</Btn>
          <Btn disabled={saving || !options} onClick={() => save(true)} permission="purchases.manage">
            {saving ? "Guardando…" : "Guardar y enviar a aprobación"}
          </Btn>
        </>
      }
    >
      <form onSubmit={(event) => { event.preventDefault(); save(false); }}>
        {error && <Notice tone="warn">{error}</Notice>}
        <FormGrid>
          <Field label="Proveedor" required span={2} error={errors.supplier_id} hint="De Clientes y actores, con rol Proveedor.">
            <PickSelect className={INPUT} ariaLabel="Proveedor" searchable disabled={!options} value={supplierId} onChange={setSupplierId} options={supplierOptions(options)} />
          </Field>
          <CostCenterField
            centers={centers} value={centerId} onChange={setCenterId} error={errors.cost_center_id} disabled={!options}
            onCreated={(center) => {
              setCenters((current) => [...current, center].sort((a, b) => a.code.localeCompare(b.code)));
              setCenterId(center.id);
            }}
          />
          <Field label="Moneda" error={errors.currency}>
            <PickSelect className={INPUT} ariaLabel="Moneda" value={currency} onChange={(value) => setCurrency(value as Currency)} options={[["PEN", "Soles (PEN)"], ["USD", "Dólares (USD)"]]} />
          </Field>
          <Field label="Requerida para" error={errors.needed_by}>
            <input className={INPUT} type="date" value={neededBy} onChange={(e) => setNeededBy(e.target.value)} />
          </Field>
          <Field label="Notas" error={errors.notes}>
            <input className={INPUT} value={notes} onChange={(e) => setNotes(e.target.value)} maxLength={2000} />
          </Field>
        </FormGrid>

        <h4 className="mb-2 mt-5 border-b border-[color:var(--app-border)] pb-1.5 text-[13px] font-semibold">Líneas</h4>
        <div className="grid gap-3.5">
          {lines.map((line, index) => (
            <div key={index} className="grid gap-3 border-b border-dashed border-[color:var(--app-border)] pb-3.5 last:border-b-0 last:pb-0">
              <FormGrid>
                <Field label={`Descripción ${index + 1}`} required={index === 0} span={2}>
                  <input className={INPUT} value={line.description} onChange={(e) => setLine(index, { description: e.target.value })} maxLength={500} />
                </Field>
                <Field label="Cantidad">
                  <input className={INPUT} type="number" min="0" step="any" inputMode="decimal" value={line.quantity} onChange={(e) => setLine(index, { quantity: e.target.value })} />
                </Field>
                <Field label="Costo unit. (sin IGV)">
                  <input className={INPUT} type="number" min="0" step="0.01" inputMode="decimal" value={line.unit_cost} onChange={(e) => setLine(index, { unit_cost: e.target.value })} />
                </Field>
                <Field label="Cuenta de gasto" span={2}>
                  <PickSelect className={INPUT} ariaLabel={`Cuenta de gasto ${index + 1}`} searchable disabled={!options} value={line.account_code} onChange={(value) => setLine(index, { account_code: value })} options={accountOptions(options)} />
                </Field>
              </FormGrid>
              {lines.length > 1 && (
                <div className="flex justify-end">
                  <Btn variant="ghost" size="sm" onClick={() => setLines((current) => current.filter((_, i) => i !== index))}>
                    <Trash2 size={14} />
                    <span>Quitar línea {index + 1}</span>
                  </Btn>
                </div>
              )}
            </div>
          ))}
        </div>
        <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
          <Btn variant="secondary" size="sm" disabled={lines.length >= 50} onClick={() => setLines((current) => [...current, emptyLine(options?.default_account ?? "")])}>
            <Plus size={14} />
            <span>Agregar línea</span>
          </Btn>
          <span className="text-[13px] tabular-nums">
            <span className="app-muted">Subtotal {money(subtotal, currency)} · IGV {IGV_RATE} % {money(round2(total - subtotal), currency)} · </span>
            <strong className="font-semibold">Total {money(total, currency)}</strong>
          </span>
        </div>
        <p className="app-muted mt-3 text-xs">Al enviarla, la aprueba otra persona: quien aprueba no puede ser quien la creó.</p>
      </form>
    </Modal>
  );
}

/** "Registrar recepción": conformidad por porcentaje. */
export function ReceiptModal({ order, onClose, onDone }: { order: PurchaseOrderDetail; onClose: () => void; onDone: (order: PurchaseOrderDetail) => void }) {
  const pending = round2(100 - order.received_pct);
  const [pct, setPct] = useState(String(pending));
  const [note, setNote] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setSaving(true);
    setError("");
    setErrors({});
    try {
      onDone(await apiFetch<PurchaseOrderDetail>(`/v1/purchase-orders/${order.id}/receipts`, { method: "POST", body: JSON.stringify({ pct: Number(pct), note: note.trim() || null }) }));
    } catch (err) {
      const fields = fieldErrors(err);
      setErrors(fields);
      if (!fields.pct && !fields.note) setError(firstError(err, "No se pudo registrar la recepción."));
      setSaving(false);
    }
  }

  return (
    <Modal
      title={`Recepción conforme · ${order.order_number}`}
      size="sm"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn type="submit" form="receipt-form" disabled={saving} permission="purchases.manage">{saving ? "Registrando…" : "Registrar conformidad"}</Btn>
        </>
      }
    >
      <form id="receipt-form" onSubmit={submit} className="grid gap-3">
        {error && <Notice tone="warn">{error}</Notice>}
        <Field label="Porcentaje recibido (%)" required error={errors.pct} hint={`Pendiente por recibir: ${pctText(pending)} %`}>
          <input className={INPUT} type="number" min="0.01" max={pending} step="0.01" inputMode="decimal" value={pct} onChange={(e) => setPct(e.target.value)} required autoFocus />
        </Field>
        <Field label="Observación / guía de remisión" error={errors.note}>
          <textarea className={INPUT} rows={3} value={note} onChange={(e) => setNote(e.target.value)} maxLength={1000} />
        </Field>
        <p className="app-muted text-xs">La conformidad habilita el registro y la aprobación del comprobante del proveedor (cruce de 3 vías).</p>
      </form>
    </Modal>
  );
}

/** "Registrar comprobante de proveedor". Desde una orden de compra llega con sus datos. */
export function SupplierInvoiceFormModal({
  purchaseOrderId, onClose, onSaved,
}: { purchaseOrderId?: string; onClose: () => void; onSaved: (invoice: SupplierInvoiceDetail) => void }) {
  const [options, setOptions] = useState<SupplierInvoiceOptions | null>(null);
  const [centers, setCenters] = useState<CostCenter[]>([]);
  const [supplierId, setSupplierId] = useState("");
  const [docType, setDocType] = useState<SupplierDocType>("FACTURA");
  const [docNumber, setDocNumber] = useState("");
  const [issueDate, setIssueDate] = useState(isoDateFromNow(0));
  const [dueDate, setDueDate] = useState("");
  const [subtotal, setSubtotal] = useState("");
  const [currency, setCurrency] = useState<Currency>("PEN");
  const [orderId, setOrderId] = useState("");
  const [centerId, setCenterId] = useState("");
  const [account, setAccount] = useState("");
  const [description, setDescription] = useState("");
  const [detraction, setDetraction] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  /** Al elegir una orden, el comprobante toma sus datos y lo que ya se recibió de ella. */
  function applyOrder(id: string, from: SupplierInvoiceOptions | null = options) {
    setOrderId(id);
    const order = from?.purchase_orders.find((item) => item.id === id);
    if (!order) return;
    setSupplierId(order.supplier_id);
    setCenterId(order.cost_center_id);
    setCurrency(order.currency);
    if (order.first_account) setAccount(order.first_account);
    setSubtotal(String(round2((order.subtotal * order.received_pct) / 100) || ""));
    setDescription(order.first_item ?? "");
  }

  useEffect(() => {
    let cancelled = false;
    apiFetch<SupplierInvoiceOptions>("/v1/supplier-invoices/options")
      .then((next) => {
        if (cancelled) return;
        setOptions(next);
        setCenters(next.cost_centers);
        setAccount(next.default_account);
        if (purchaseOrderId) applyOrder(purchaseOrderId, next);
      })
      .catch(() => { if (!cancelled) setError("No se pudieron cargar los proveedores y centros de costo."); });
    return () => { cancelled = true; };
    // Solo al abrir.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const honorarium = docType === "RECIBO_HONORARIOS";
  const base = Number(subtotal || 0);
  const total = round2(honorarium ? base : base * (1 + IGV_RATE / 100));
  // Las órdenes del proveedor elegido (todas, si aún no se eligió).
  const orders = (options?.purchase_orders ?? []).filter((order) => !supplierId || order.supplier_id === supplierId);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setSaving(true);
    setError("");
    setErrors({});
    try {
      const invoice = await apiFetch<SupplierInvoiceDetail>("/v1/supplier-invoices", {
        method: "POST",
        body: JSON.stringify({
          supplier_id: supplierId, doc_type: docType, doc_number: docNumber.trim().toUpperCase(), issue_date: issueDate, due_date: dueDate || null,
          subtotal: Number(subtotal), currency, purchase_order_id: orderId || null, cost_center_id: centerId, expense_account: account,
          description: description.trim() || null, detraction_code: honorarium ? null : detraction || null,
        }),
      });
      onSaved(invoice);
    } catch (err) {
      const fields = fieldErrors(err);
      setErrors(fields);
      if (!Object.keys(fields).length) setError(firstError(err, "No se pudo registrar el comprobante."));
      setSaving(false);
    }
  }

  return (
    <Modal
      title="Registrar comprobante de proveedor"
      size="lg"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn type="submit" form="supplier-invoice-form" disabled={saving || !options} permission="payables.create">{saving ? "Registrando…" : "Registrar"}</Btn>
        </>
      }
    >
      <form id="supplier-invoice-form" onSubmit={submit}>
        {error && <Notice tone="warn">{error}</Notice>}
        <FormGrid>
          <Field label="Proveedor" required span={2} error={errors.supplier_id}>
            <PickSelect
              className={INPUT} ariaLabel="Proveedor" searchable disabled={!options} value={supplierId} options={supplierOptions(options)}
              onChange={(value) => {
                setSupplierId(value);
                // La orden elegida era de otro proveedor.
                if (orderId && options?.purchase_orders.find((order) => order.id === orderId)?.supplier_id !== value) setOrderId("");
              }}
            />
          </Field>
          <Field label="Tipo" error={errors.doc_type}>
            <PickSelect className={INPUT} ariaLabel="Tipo" value={docType} onChange={(value) => setDocType(value as SupplierDocType)} options={DOC_TYPES} />
          </Field>
          <Field label="Serie-número" required error={errors.doc_number}>
            <input className={INPUT} value={docNumber} onChange={(e) => setDocNumber(e.target.value.toUpperCase())} placeholder="E001-1234" maxLength={13} required />
          </Field>
          <Field label="Emisión" required error={errors.issue_date}>
            <input className={INPUT} type="date" value={issueDate} max={isoDateFromNow(0)} onChange={(e) => setIssueDate(e.target.value)} required />
          </Field>
          <Field label="Vencimiento" error={errors.due_date} hint={`Vacío: ${options?.terms_days ?? 30} días desde la emisión`}>
            <input className={INPUT} type="date" value={dueDate} min={issueDate} onChange={(e) => setDueDate(e.target.value)} />
          </Field>
          <Field label={honorarium ? "Honorarios" : "Valor de venta (sin IGV)"} required error={errors.subtotal}>
            <input className={INPUT} type="number" min="0.01" step="0.01" inputMode="decimal" value={subtotal} onChange={(e) => setSubtotal(e.target.value)} required />
          </Field>
          <Field label="Moneda" error={errors.currency}>
            <PickSelect className={INPUT} ariaLabel="Moneda" value={currency} onChange={(value) => setCurrency(value as Currency)} options={[["PEN", "Soles"], ["USD", "Dólares"]]} />
          </Field>
          <Field label="Orden de compra" error={errors.purchase_order_id}>
            <PickSelect
              className={INPUT} ariaLabel="Orden de compra" searchable disabled={!options} value={orderId} onChange={applyOrder}
              options={[["", "Sin OC (gasto recurrente)"], ...orders.map((order): [string, string] => [order.id, `${order.order_number} · ${order.supplier_name ?? ""}`])]}
            />
          </Field>
          <CostCenterField
            centers={centers} value={centerId} onChange={setCenterId} error={errors.cost_center_id} disabled={!options}
            onCreated={(center) => {
              setCenters((current) => [...current, center].sort((a, b) => a.code.localeCompare(b.code)));
              setCenterId(center.id);
            }}
          />
          <Field label="Cuenta de gasto" required span={2} error={errors.expense_account}>
            <PickSelect className={INPUT} ariaLabel="Cuenta de gasto" searchable disabled={!options} value={account} onChange={setAccount} options={accountOptions(options)} />
          </Field>
          <Field label="Concepto" span={3} error={errors.description}>
            <input className={INPUT} value={description} onChange={(e) => setDescription(e.target.value)} maxLength={500} />
          </Field>
          <Field
            label="Detracción" span={3} error={errors.detraction_code}
            hint={honorarium ? "Un recibo por honorarios no lleva detracción." : "El código que indica la factura. Aplica si el total supera S/ 700; se deposita en soles en la cuenta del Banco de la Nación del proveedor al pagar."}
          >
            <PickSelect
              className={`${INPUT} disabled:opacity-60`} ariaLabel="Detracción" searchable disabled={!options || honorarium} value={honorarium ? "" : detraction} onChange={setDetraction}
              options={[["", "No aplica"], ...(options?.detraction_codes ?? []).map((code): [string, string] => [code.code, `${code.rate} % · ${code.code} ${code.name}`])]}
            />
          </Field>
        </FormGrid>
        <p className="mt-3 text-[13px] tabular-nums">
          {honorarium ? "Sin IGV" : `IGV ${IGV_RATE} % (crédito fiscal): ${money(round2(total - base), currency)}`} · <strong className="font-semibold">Total {money(total, currency)}</strong>
        </p>
        <p className="app-muted mt-1 text-xs">
          {honorarium
            ? "Por más de S/ 1500 se retiene el 8 % (4.ª categoría): no se le paga al proveedor."
            : "Si el servicio está sujeto a detracción, se calcula con su código y se descontará al pagar."}
        </p>
      </form>
    </Modal>
  );
}

/** Ficha de un comprobante de proveedor, sobre el listado: datos, cruce de 3 vías, estados e historial. */
export function SupplierInvoiceModal({
  id, onClose, onChanged, toast,
}: { id: string; onClose: () => void; onChanged: () => void; toast: (text: string, tone?: "success" | "warning" | "danger") => void }) {
  const { can } = useAccess();
  const [invoice, setInvoice] = useState<SupplierInvoiceDetail | null>(null);
  const [error, setError] = useState("");
  const [retry, setRetry] = useState(0);
  const [busy, setBusy] = useState(false);
  const [asking, setAsking] = useState<"reject" | "void" | null>(null);

  useEffect(() => {
    let cancelled = false;
    apiFetch<SupplierInvoiceDetail>(`/v1/supplier-invoices/${id}`)
      .then((next) => {
        if (cancelled) return;
        setInvoice(next);
        setError("");
      })
      .catch((err) => {
        if (!cancelled) setError(err instanceof ApiError && err.status === 404 ? "El comprobante no existe." : "No se pudo cargar el comprobante.");
      });
    return () => { cancelled = true; };
  }, [id, retry]);

  async function act(path: "approve" | "reject" | "void", body: unknown, done: string): Promise<boolean> {
    setBusy(true);
    try {
      setInvoice(await apiFetch<SupplierInvoiceDetail>(`/v1/supplier-invoices/${id}/${path}`, { method: "POST", body: body ? JSON.stringify(body) : undefined }));
      toast(done, "success");
      onChanged();
      return true;
    } catch (err) {
      toast(firstError(err, "No se pudo completar la acción."), "danger");
      // Si otra persona lo cambió, se ve la versión vigente.
      setRetry((n) => n + 1);
      return false;
    } finally {
      setBusy(false);
    }
  }

  const history = invoice?.history ?? [];
  const visited = history.flatMap((entry) => (entry.metadata.to ? [entry.metadata.to] : [])).concat("REGISTERED");
  const currency = invoice?.currency ?? "PEN";

  return (
    <>
      <Modal title={invoice ? `Comprobante ${invoice.doc_number}` : "Comprobante de proveedor"} size="lg" onClose={asking ? keepOpen : onClose} footer={<Btn variant="ghost" onClick={onClose}>Cerrar</Btn>}>
        {error ? <LoadError text={error} onRetry={() => setRetry((n) => n + 1)} /> : !invoice ? SKELETON : (
          <div className="grid gap-4">
            <Kv
              rows={[
                ["Proveedor", <span key="supplier">{invoice.supplier.name}{invoice.supplier.tax_id && <small className="app-muted block text-xs">{invoice.supplier.tax_id}</small>}</span>],
                ["Tipo", docTypeLabel(invoice.doc_type)],
                ["Concepto", invoice.description ?? "—"],
                ["Emisión / vencimiento", `${shortDate(invoice.issue_date)} → ${shortDate(invoice.due_date)}`],
                [invoice.doc_type === "RECIBO_HONORARIOS" ? "Honorarios" : "Valor de venta", money(invoice.subtotal, currency)],
                ["IGV (crédito fiscal)", money(invoice.tax, currency)],
                ["Total", <strong key="total" className="font-semibold tabular-nums">{money(invoice.total, currency)}</strong>],
                currency === "USD" && invoice.exchange_rate ? ["Tipo de cambio", `${invoice.exchange_rate.toFixed(3)} · equivale a ${money(invoice.total * invoice.exchange_rate)}`] : null,
                invoice.detraction ? [
                  "Detracción",
                  `${pctText(invoice.detraction.pct)} % · ${money(invoice.detraction.amount)} (código ${invoice.detraction.code}; se deposita en la cuenta BN del proveedor al pagar)`,
                ] : null,
                invoice.retention_4ta ? ["Retención 4.ª categoría", money(invoice.retention_4ta, currency)] : null,
                ["Centro de costo", invoice.cost_center.name ?? "—"],
                ["Cuenta de gasto", invoice.expense_account_label],
                [
                  "Validez SUNAT",
                  <span key="sunat" className="inline-flex flex-wrap items-center gap-1.5">
                    <Chip tone={(SUNAT_VALIDATION[invoice.sunat_validation] ?? SUNAT_VALIDATION.NOT_CHECKED)[1]}>{(SUNAT_VALIDATION[invoice.sunat_validation] ?? SUNAT_VALIDATION.NOT_CHECKED)[0]}</Chip>
                    {invoice.sunat_validation === "NOT_CHECKED" && <small className="app-muted text-xs">La consulta de validez ante SUNAT aún no está conectada.</small>}
                  </span>,
                ],
                [
                  "Cruce con OC (3 vías)",
                  <span key="match" className="inline-flex flex-wrap items-center gap-1.5">
                    <Chip tone={invoice.match.tone}>{invoice.match.tone === "neutral" ? "Sin OC" : invoice.match.ok ? "Cuadra" : "No cuadra"}</Chip>
                    <small className="text-xs">{invoice.match.label}</small>
                  </span>,
                ],
                invoice.purchase_order ? [
                  "Orden de compra",
                  can("purchases.view")
                    ? <Link key="order" href={`/purchase-orders/${invoice.purchase_order.id}`} className={LINK}>{invoice.purchase_order.order_number ?? "—"}</Link>
                    : invoice.purchase_order.order_number ?? "—",
                ] : null,
                invoice.payable ? [
                  "Cuenta por pagar",
                  <span key="payable" className="inline-flex flex-wrap items-center gap-1.5">
                    <Badge tone={stateOf(PAYABLE_STATES, invoice.payable.status)[1]}>{stateOf(PAYABLE_STATES, invoice.payable.status)[0]}</Badge>
                    saldo {money(invoice.payable.balance_amount, invoice.payable.currency)}
                  </span>,
                ] : null,
                // Como en el prototipo: el enlace lleva al libro diario buscando ese asiento.
                [
                  "Asiento",
                  invoice.journal_entry ? (
                    can("accounting.view")
                      ? <Link key="journal" href={`/journal?q=${encodeURIComponent(invoice.journal_entry.number)}`} className={`${LINK} tabular-nums`}>{invoice.journal_entry.number}</Link>
                      : <span key="journal" className="tabular-nums">{invoice.journal_entry.number}</span>
                  ) : (
                    <span key="journal" className="app-muted text-[12.5px]">
                      {invoice.status === "APPROVED" ? "Sin asiento en el libro diario." : "Se genera al aprobar para pago."}
                    </span>
                  ),
                ],
                invoice.reason ? ["Motivo", invoice.reason] : null,
                ["Registró", invoice.creator ?? "—"],
                invoice.approver ? ["Aprobó", invoice.approver] : null,
              ]}
            />

            {invoice.payable && invoice.payable.movements.length > 0 && (
              <section>
                <h4 className="mb-1 text-[15px] font-semibold">Movimientos de la cuenta por pagar</h4>
                <ul className="grid">
                  {invoice.payable.movements.map((movement) => (
                    <li key={movement.id} className="flex flex-wrap items-baseline gap-x-2 border-b border-[color:var(--app-border)] py-1.5 last:border-b-0">
                      <span className="min-w-0 flex-1">{PAYABLE_MOVEMENTS[movement.movement_type] ?? movement.movement_type} · {movement.reference ?? "—"}</span>
                      <strong className={`font-semibold tabular-nums ${movement.amount < 0 ? "text-[color:var(--p-success-text)]" : ""}`}>{money(movement.amount, invoice.payable!.currency)}</strong>
                      <small className="app-muted w-full text-xs">{dateTime(movement.at)}</small>
                    </li>
                  ))}
                </ul>
              </section>
            )}

            <section className="grid gap-2.5">
              <h4 className="text-[15px] font-semibold">Estados</h4>
              <StateTrack
                states={invoice.status === "VOIDED" ? SUPPLIER_INVOICE_STATES.filter(([key]) => visited.includes(key) || key === "VOIDED") : SUPPLIER_INVOICE_STATES.filter(([key]) => key !== "VOIDED")}
                current={invoice.status} visited={visited}
              />
              {(invoice.status === "REGISTERED" || invoice.status === "REJECTED") && (
                <div className="flex flex-wrap gap-2">
                  {invoice.status === "REGISTERED" && (
                    <>
                      <Btn size="sm" disabled={busy} onClick={() => act("approve", null, "Comprobante aprobado: se creó su cuenta por pagar")} permission="payables.approve">Aprobar para pago</Btn>
                      <Btn size="sm" variant="danger-ghost" disabled={busy} onClick={() => setAsking("reject")} permission="payables.approve">Rechazar</Btn>
                    </>
                  )}
                  <Btn size="sm" variant="danger-ghost" disabled={busy} onClick={() => setAsking("void")} permission="payables.manage">Anular</Btn>
                </div>
              )}
              {invoice.status === "REGISTERED" && !invoice.match.ok && (
                <p className="app-muted text-[12.5px]">No se puede aprobar mientras no cuadre con su orden de compra y lo recibido de ella.</p>
              )}
              {invoice.status === "APPROVED" && <p className="app-muted text-[12.5px]">Sin transiciones disponibles desde este estado. Aprobado para pago: ya no se modifica ni se anula; se paga desde Cuentas por pagar.</p>}
              {invoice.status === "VOIDED" && <p className="app-muted text-[12.5px]">Sin transiciones disponibles desde este estado.</p>}
            </section>

            <section>
              <h4 className="text-[15px] font-semibold">Historial</h4>
              <Timeline
                items={history.map((entry, index) => {
                  const to = entry.action === "supplier_invoice.payment" ? null : entry.metadata.to;
                  return {
                    key: `${entry.at}-${index}`,
                    head: to ? <Badge tone={stateOf(SUPPLIER_INVOICE_STATES, to)[1]}>{stateOf(SUPPLIER_INVOICE_STATES, to)[0]}</Badge> : <span className="font-medium">{purchasingHistoryLabel(entry)}</span>,
                    meta: `${entry.user ?? "Sistema"} · ${dateTime(entry.at)}`,
                    note: entry.metadata.note,
                  };
                })}
              />
            </section>
          </div>
        )}
      </Modal>
      {asking && invoice && (
        <ReasonModal
          title={asking === "reject" ? "Rechazar comprobante" : "Anular comprobante"}
          text={asking === "reject"
            ? `${invoice.doc_number} de ${invoice.supplier.name} queda rechazado y no genera cuenta por pagar.`
            : `${invoice.doc_number} deja de contar como facturado en su orden de compra. Su número queda libre para registrarlo de nuevo.`}
          label="Motivo"
          confirmLabel={asking === "reject" ? "Rechazar" : "Anular"}
          busy={busy}
          onClose={() => setAsking(null)}
          onConfirm={async (reason) => {
            if (await act(asking, { reason }, asking === "reject" ? "Comprobante rechazado" : "Comprobante anulado")) setAsking(null);
          }}
        />
      )}
    </>
  );
}

/** "Pagar seleccionadas": un pago a un proveedor por una o varias cuentas por pagar de la misma moneda. */
export function PaySuppliersModal({ payables, onClose, onPaid }: { payables: Payable[]; onClose: () => void; onPaid: (payment: SupplierPayment) => void }) {
  const first = payables[0];
  const currency = first.currency;
  const gross = round2(payables.reduce((sum, payable) => sum + payable.balance_amount, 0));
  // La detracción va en soles; en la cuenta por pagar, en la moneda del comprobante.
  const pending = payables.map((payable) => {
    const pen = payable.detraction_deposited ? 0 : payable.detraction_amount;
    const rate = currency === "USD" ? payable.exchange_rate ?? 0 : 1;
    return { pen, doc: pen > 0 && rate > 0 ? Math.min(payable.balance_amount, round2(pen / rate)) : 0 };
  });
  const detraction = round2(pending.reduce((sum, item) => sum + item.pen, 0));
  const net = round2(gross - pending.reduce((sum, item) => sum + item.doc, 0));

  const [options, setOptions] = useState<SupplierPaymentOptions | null>(null);
  const [accountId, setAccountId] = useState("");
  const [method, setMethod] = useState("TRANSFERENCIA");
  const [reference, setReference] = useState("");
  const [paidAt, setPaidAt] = useState(isoDateFromNow(0));
  const [deposit, setDeposit] = useState(detraction > 0);
  const [constancy, setConstancy] = useState("");
  const [detractionAccountId, setDetractionAccountId] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    let cancelled = false;
    apiFetch<SupplierPaymentOptions>("/v1/supplier-payments/options")
      .then((next) => { if (!cancelled) setOptions(next); })
      .catch(() => { if (!cancelled) setError("No se pudieron cargar las cuentas de banco y caja."); });
    return () => { cancelled = true; };
  }, []);

  const accounts = options?.accounts.filter((account) => account.currency === currency) ?? [];
  const solesAccounts = options?.accounts.filter((account) => account.currency === "PEN") ?? [];
  const cash = method === "EFECTIVO";
  const withDeposit = deposit && detraction > 0;
  const accountLabel = (account: SupplierPaymentOptions["accounts"][number]) => `${account.name} · saldo ${money(account.balance, account.currency)}`;

  async function submit(event: FormEvent) {
    event.preventDefault();
    setSaving(true);
    setError("");
    setErrors({});
    try {
      const payment = await apiFetch<SupplierPayment>("/v1/supplier-payments", {
        method: "POST",
        body: JSON.stringify({
          payable_ids: payables.map((payable) => payable.id), financial_account_id: accountId, payment_method: method, reference: reference.trim() || null, paid_at: paidAt,
          deposit_detraction: withDeposit, detraction_number: withDeposit ? constancy.trim() : null,
          detraction_account_id: withDeposit && currency === "USD" ? detractionAccountId || null : null,
        }),
      });
      onPaid(payment);
    } catch (err) {
      const fields = fieldErrors(err);
      setErrors(fields);
      const shown = ["financial_account_id", "payment_method", "reference", "paid_at", "detraction_number", "detraction_account_id"];
      if (!Object.keys(fields).some((key) => shown.includes(key))) setError(firstError(err, "No se pudo registrar el pago."));
      setSaving(false);
    }
  }

  return (
    <Modal
      title={`Pago a ${first.supplier.name ?? "proveedor"}`}
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn type="submit" form="supplier-payment-form" disabled={saving || !options} permission="payables.pay">{saving ? "Registrando…" : "Registrar pago"}</Btn>
        </>
      }
    >
      <form id="supplier-payment-form" onSubmit={submit}>
        {error && <Notice tone="warn">{error}</Notice>}
        <Kv
          rows={[
            ["Total de documentos", `${money(gross, currency)} · ${payables.length} ${payables.length === 1 ? "documento" : "documentos"}`],
            ["Detracción a depositar (BN del proveedor)", money(detraction)],
            ["Neto a transferir", <strong key="net" className="font-semibold tabular-nums">{money(net, currency)}</strong>],
            ["Cuenta BN del proveedor", first.supplier.detraction_account ?? <span key="bn" className="app-muted">No registrada (se indica en Clientes y actores, rol Proveedor)</span>],
          ]}
        />
        <FormGrid>
          <Field label="Pagar desde" required span={2} error={errors.financial_account_id} hint={options && !accounts.length ? `Sin cuentas en ${currency === "USD" ? "dólares" : "soles"}: se registran en Bancos y caja.` : undefined}>
            <PickSelect
              className={INPUT} ariaLabel="Pagar desde" disabled={!accounts.length} value={accountId} onChange={setAccountId}
              options={[["", options ? "Seleccionar…" : "Cargando…"], ...accounts.map((account): [string, string] => [account.id, accountLabel(account)])]}
            />
          </Field>
          <Field label="Medio" error={errors.payment_method}>
            <PickSelect className={INPUT} ariaLabel="Medio" value={method} onChange={setMethod} options={(options?.methods ?? Object.keys(SUPPLIER_PAYMENT_METHODS)).map((key): [string, string] => [key, SUPPLIER_PAYMENT_METHODS[key] ?? key])} />
          </Field>
          <Field label="N.º de operación" required={!cash} error={errors.reference} hint={cash ? "El efectivo no lleva número de operación." : undefined}>
            <input className={INPUT} value={reference} onChange={(e) => setReference(e.target.value)} maxLength={255} required={!cash} />
          </Field>
          <Field label="Fecha" error={errors.paid_at}>
            <input className={INPUT} type="date" value={paidAt} max={isoDateFromNow(0)} onChange={(e) => setPaidAt(e.target.value)} required />
          </Field>
          {withDeposit && (
            <Field label="N.º de constancia de detracción" required error={errors.detraction_number}>
              <input className={INPUT} value={constancy} onChange={(e) => setConstancy(e.target.value)} maxLength={100} required />
            </Field>
          )}
          {withDeposit && currency === "USD" && (
            <Field label="Detracción desde (cuenta en soles)" required span={2} error={errors.detraction_account_id}>
              <PickSelect
                className={INPUT} ariaLabel="Detracción desde" disabled={!solesAccounts.length} value={detractionAccountId} onChange={setDetractionAccountId}
                options={[["", "Seleccionar…"], ...solesAccounts.map((account): [string, string] => [account.id, accountLabel(account)])]}
              />
            </Field>
          )}
        </FormGrid>
        <label className={`relative mt-3.5 flex items-start gap-2 text-[13px] ${detraction > 0 ? "cursor-pointer" : "opacity-60"}`}>
          <input type="checkbox" className="mt-0.5 h-4 w-4 accent-[#1B8FC9]" disabled={detraction <= 0} checked={withDeposit} onChange={(e) => setDeposit(e.target.checked)} />
          <span>
            Incluir el depósito de la detracción
            <small className="app-muted block text-xs">
              {detraction <= 0
                ? "Estos documentos no tienen detracción por depositar."
                : withDeposit
                  ? `Salen ${money(net, currency)} al proveedor y ${money(detraction)} al Banco de la Nación. Las cuentas quedan pagadas.`
                  : `Sale solo el neto. La detracción (${money(detraction)}) queda por depositar y la cuenta sigue abierta por ese importe.`}
            </small>
          </span>
        </label>
        <p className="app-muted mt-2 text-xs">Enviar dos veces no duplica el pago. El dinero sale de la cuenta elegida y queda como movimiento en Bancos y caja.</p>
      </form>
    </Modal>
  );
}

/**
 * Ficha de un pago a proveedor: a qué comprobantes se aplicó, sus movimientos de banco o caja, su
 * asiento e historial, y "Revertir pago" (con motivo). Un pago con depósito de detracción no se revierte.
 */
export function SupplierPaymentModal({
  id, toast, onClose, onChanged, onInvoice,
}: {
  id: string;
  toast: (text: string, tone?: "success" | "danger" | "warning", detail?: string) => void;
  onClose: () => void;
  onChanged: () => void;
  onInvoice: (invoiceId: string) => void;
}) {
  const { can } = useAccess();
  const [payment, setPayment] = useState<SupplierPaymentDetail | null>(null);
  const [error, setError] = useState("");
  const [retry, setRetry] = useState(0);
  const [asking, setAsking] = useState(false);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    let cancelled = false;
    apiFetch<SupplierPaymentDetail>(`/v1/supplier-payments/${id}`)
      .then((next) => { if (!cancelled) { setPayment(next); setError(""); } })
      .catch((err) => { if (!cancelled) setError(err instanceof ApiError && err.status === 404 ? "El pago no existe." : "No se pudo cargar el pago."); });
    return () => { cancelled = true; };
  }, [id, retry]);

  async function reverse(reason: string) {
    setBusy(true);
    try {
      const next = await apiFetch<SupplierPaymentDetail>(`/v1/supplier-payments/${id}/reverse`, { method: "POST", body: JSON.stringify({ reason }) });
      setPayment(next);
      setAsking(false);
      toast("Pago revertido", "success", next.payment_number);
      onChanged();
    } catch (err) {
      toast(firstError(err, "No se pudo revertir el pago."), "danger");
      setRetry((n) => n + 1);
    } finally {
      setBusy(false);
    }
  }

  const currency = payment?.currency ?? "PEN";
  const withDetraction = (payment?.detraction_amount ?? 0) > 0;
  const TH = "app-muted border-b border-[color:var(--app-border)] px-2.5 py-2 text-left text-[10.5px] font-semibold uppercase tracking-[.06em]";
  const TD = "border-b border-[color:var(--app-border)] px-2.5 py-2 align-top";

  return (
    <>
      <Modal
        title={payment ? `Pago ${payment.payment_number}` : "Pago a proveedor"} size="lg" onClose={asking ? keepOpen : onClose}
        footer={
          <>
            <Btn variant="ghost" onClick={onClose}>Cerrar</Btn>
            {payment?.status === "PAID" && (
              <Btn
                variant="danger-ghost" permission="payables.pay" disabled={busy || withDetraction} onClick={() => setAsking(true)}
                title={withDetraction ? `Incluye el depósito de la detracción (constancia ${payment.detraction_number}): no se revierte desde aquí.` : undefined}
              >
                Revertir pago
              </Btn>
            )}
          </>
        }
      >
        {error ? <LoadError text={error} onRetry={() => setRetry((n) => n + 1)} /> : !payment ? SKELETON : (
          <div className="grid gap-4">
            {payment.status === "REVERSED" && (
              <Notice tone="warn">
                <strong className="font-semibold">Revertido</strong>{payment.reversed_by ? ` por ${payment.reversed_by}` : ""}{payment.reversed_at ? ` · ${dateTime(payment.reversed_at)}` : ""}: {payment.reversal_reason}
              </Notice>
            )}
            <Kv
              rows={[
                ["Proveedor", payment.supplier.name ?? "—"],
                ["Fecha", shortDate(payment.paid_at)],
                ["Medio", `${SUPPLIER_PAYMENT_METHODS[payment.payment_method] ?? payment.payment_method}${payment.reference ? ` · ${payment.reference}` : ""}`],
                ["Pagado desde", payment.account_name ?? "—"],
                ["Neto pagado", <strong key="net" className="font-semibold tabular-nums">{money(payment.amount, currency)}</strong>],
                withDetraction ? ["Detracción depositada", `${money(payment.detraction_amount)} · constancia ${payment.detraction_number ?? "—"}`] : null,
                [
                  "Asiento",
                  payment.journal_entry ? (
                    <span key="journal" className="inline-flex flex-wrap items-center gap-1.5">
                      {can("accounting.view")
                        ? <Link href={`/journal?q=${encodeURIComponent(payment.journal_entry.number)}`} className={`${LINK} tabular-nums`}>{payment.journal_entry.number}</Link>
                        : <span className="tabular-nums">{payment.journal_entry.number}</span>}
                      {payment.journal_entry.status === "REVERSED" && <Chip tone="muted">Extornado</Chip>}
                    </span>
                  ) : <span key="journal" className="app-muted text-[12.5px]">Sin asiento en el libro diario.</span>,
                ],
                ["Registró", payment.creator ?? "—"],
              ]}
            />

            <section>
              <h4 className="mb-1 text-[15px] font-semibold">Comprobantes pagados</h4>
              <div className="overflow-x-auto rounded-lg border border-[color:var(--app-border)]">
                <table className="proto-tbl w-full text-[13px]">
                  <thead><tr><th className={TH}>Comprobante</th><th className={`${TH} text-right`}>Aplicado</th><th className={TH}>Cuenta por pagar</th></tr></thead>
                  <tbody>
                    {payment.allocations.map((allocation) => (
                      <tr key={allocation.payable_id}>
                        <td className={TD} data-label="Comprobante">
                          <button type="button" className={`${LINK} cursor-pointer tabular-nums`} onClick={() => onInvoice(allocation.invoice_id)}>{allocation.doc_number}</button>
                          {allocation.description && <small className="app-muted block text-xs">{allocation.description}</small>}
                        </td>
                        <td className={`${TD} text-right tabular-nums`} data-label="Aplicado">{money(allocation.amount, currency)}</td>
                        <td className={TD} data-label="Cuenta por pagar">
                          <Badge tone={stateOf(PAYABLE_STATES, allocation.payable_status)[1]}>{stateOf(PAYABLE_STATES, allocation.payable_status)[0]}</Badge>
                          <small className="app-muted block text-xs">saldo {money(allocation.balance, currency)}</small>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </section>

            <section>
              <h4 className="mb-1 text-[15px] font-semibold">Movimientos de banco o caja</h4>
              <ul className="grid">
                {payment.movements.map((movement) => (
                  <li key={movement.id} className="flex flex-wrap items-baseline gap-x-2 border-b border-[color:var(--app-border)] py-1.5 last:border-b-0">
                    <span className="min-w-0 flex-1">{movement.type} · {movement.account}<small className="app-muted block text-xs">{movement.description}</small></span>
                    <strong className={`font-semibold tabular-nums ${movement.amount > 0 ? "text-[color:var(--p-success-text)]" : ""}`}>{money(movement.amount, currency)}</strong>
                    <small className="app-muted w-full text-xs">{shortDate(movement.date)}</small>
                  </li>
                ))}
              </ul>
            </section>

            <section>
              <h4 className="text-[15px] font-semibold">Historial</h4>
              <Timeline
                items={payment.history.map((entry, index) => ({
                  key: `${entry.at}-${index}`,
                  head: <span className="font-medium">{purchasingHistoryLabel(entry)}</span>,
                  meta: `${entry.user ?? "Sistema"} · ${dateTime(entry.at)}`,
                  note: entry.metadata.note,
                }))}
              />
            </section>
          </div>
        )}
      </Modal>
      {asking && payment && (
        <ReasonModal
          title={`Revertir pago ${payment.payment_number}`}
          text={`Cada comprobante recupera lo pagado, ${money(payment.amount, currency)} vuelve a ${payment.account_name ?? "la cuenta de origen"} y el asiento se extorna. El pago queda como revertido.`}
          label="Motivo"
          confirmLabel="Revertir"
          busy={busy}
          onClose={() => setAsking(false)}
          onConfirm={reverse}
        />
      )}
    </>
  );
}
