"use client";

import { useEffect, useState } from "react";
import { Clock, Lock, ShieldCheck, TriangleAlert, Wallet } from "lucide-react";
import { apiFetch, apiFetchLater } from "@/lib/api";
import { excelTone, money, shortDate, stateOf } from "@/lib/commercial";
import { PAYABLE_STATES, kMoney, supplierMethodLabel } from "@/lib/purchasing";
import { exportCsv, exportXlsx, recordExport, fetchAllPages, type ExportColumn } from "@/lib/export-table";
import {
  Badge, Btn, Card, Chip, DataTable, ExportButtons, FilterSelect, Filters, Kpi, Kpis, LoadError, Notice, PAGE_SIZES, PageHead, Pager, Screen,
  SearchInput, SelectionBar, deniedText, useRowSelection, useToasts, type Column,
  applySort, type SortState,
} from "@/components/ui/proto";
import { ScopeNote } from "@/components/dashboard/ScopeNote";
import { ColumnFilterBar, addColumnFilter, applyFilters, useServerColumnFilters } from "@/components/dashboard/ColumnFilters";
import { PaySuppliersModal, SupplierInvoiceModal, SupplierPaymentModal } from "@/components/dashboard/purchasing/PurchaseModals";
import type { PaginatedResponse } from "@/types/customer";
import type { Payable, PayableSummary, SupplierPayment } from "@/types/purchasing";

const LINK = "cursor-pointer text-left text-[color:var(--p-brand-text)] hover:underline";

const payableKey = (payable: Payable) => payable.id;
const paymentKey = (payment: SupplierPayment) => payment.id;
const isOpen = (payable: Payable) => payable.status === "OPEN" || payable.status === "PARTIAL";

// "Vencida" no es un estado: se calcula por la fecha. El filtro la ofrece junto a los estados, como el prototipo.
const STATE_FILTER: [string, string][] = [["OPEN", "Pendiente"], ["OVERDUE", "Vencida"], ["PAID", "Pagada"]];

// Color de cada tramo: vencido, por vencer esta semana y con margen.
const BAR_TONES = ["bg-[var(--p-danger-text)]", "bg-[var(--p-warning-text)]", "bg-[var(--p-success)]", "bg-[var(--p-success)]"];
const BUCKET_LABELS = ["Vencido", "0–7 días", "8–30 días", "> 30 días"];

const EXPORT_COLUMNS: ExportColumn<Payable>[] = [
  { label: "Comprobante", value: (payable) => payable.supplier_invoice.doc_number ?? "", width: 18, kind: "center" },
  { label: "Concepto", value: (payable) => payable.supplier_invoice.description ?? "", width: 36 },
  { label: "Proveedor", value: (payable) => payable.supplier.name ?? "", width: 34 },
  { label: "Vence", value: (payable) => shortDate(payable.due_date), width: 15, kind: "center" },
  { label: "Días de atraso", value: (payable) => payable.days_overdue, width: 14, kind: "number" },
  { label: "Moneda", value: (payable) => payable.currency, width: 10, kind: "center" },
  { label: "Original", value: (payable) => payable.original_amount, width: 14, kind: "money", currency: (payable) => payable.currency },
  { label: "Detracción (S/)", value: (payable) => payable.detraction_amount || "", width: 16, kind: "number" },
  { label: "Detracción depositada", value: (payable) => (payable.detraction_amount ? (payable.detraction_deposited ? "Sí" : "No") : ""), width: 20, kind: "center" },
  { label: "Saldo", value: (payable) => payable.balance_amount, width: 14, kind: "money", currency: (payable) => payable.currency },
  {
    label: "Estado",
    value: (payable) => (payable.overdue ? "Vencida" : stateOf(PAYABLE_STATES, payable.status)[0]),
    width: 15,
    kind: "status",
    tone: (payable) => (payable.overdue ? "danger" : excelTone(stateOf(PAYABLE_STATES, payable.status)[1])),
  },
];

const PAYMENT_EXPORT_COLUMNS: ExportColumn<SupplierPayment>[] = [
  { label: "Pago", value: (payment) => payment.payment_number, width: 18, kind: "center" },
  { label: "Medio", value: (payment) => supplierMethodLabel(payment.payment_method), width: 16 },
  { label: "N.º de operación", value: (payment) => payment.reference ?? "", width: 20 },
  { label: "Proveedor", value: (payment) => payment.supplier.name ?? "", width: 34 },
  { label: "Cuenta", value: (payment) => payment.account_name ?? "", width: 24 },
  { label: "Fecha", value: (payment) => shortDate(payment.paid_at), width: 15, kind: "center" },
  { label: "Moneda", value: (payment) => payment.currency, width: 10, kind: "center" },
  { label: "Neto pagado", value: (payment) => payment.amount, width: 14, kind: "money", currency: (payment) => payment.currency },
  { label: "Detracción (S/)", value: (payment) => payment.detraction_amount || "", width: 16, kind: "number" },
  { label: "Constancia", value: (payment) => payment.detraction_number ?? "", width: 18, kind: "center" },
  { label: "Registró", value: (payment) => payment.creator ?? "", width: 24 },
  { label: "Estado", value: (payment) => (payment.status === "REVERSED" ? "Revertido" : "Pagado"), width: 13, kind: "center" },
  { label: "Motivo de la reversión", value: (payment) => payment.reversal_reason ?? "", width: 34 },
];

const PAYMENT_COLUMNS: Column<SupplierPayment>[] = [
  {
    label: "Pago", sortKey: "payment", filter: "text",
    render: (payment) => (
      <div className="whitespace-nowrap">
        <strong className="font-semibold tabular-nums">{payment.payment_number}</strong>
        {payment.status === "REVERSED" && <span className="ml-1.5"><Chip tone="muted">Revertido</Chip></span>}
        <small className="app-muted block text-xs">{supplierMethodLabel(payment.payment_method)}{payment.reference ? ` · ${payment.reference}` : ""}</small>
      </div>
    ),
  },
  { label: "Proveedor", sortKey: "supplier", filter: "text", render: (payment) => payment.supplier.name ?? "—" },
  { label: "Cuenta", sortKey: "account", filter: "text", render: (payment) => payment.account_name ?? "—" },
  { label: "Fecha", sortKey: "date", filter: "date", render: (payment) => <span className="whitespace-nowrap">{shortDate(payment.paid_at)}</span> },
  { label: "Neto pagado", num: true, sortKey: "amount", filter: "number", render: (payment) => <span className="whitespace-nowrap">{money(payment.amount, payment.currency)}</span> },
  {
    label: "Detracción", num: true, sortKey: "detraction", filter: "number",
    render: (payment) =>
      payment.detraction_amount ? (
        <div className="whitespace-nowrap">
          {money(payment.detraction_amount)}
          <small className="app-muted block text-xs">const. {payment.detraction_number ?? "—"}</small>
        </div>
      ) : "—",
  },
];

type TopFilters = { search: string; status: string };

function withTopFilters(params: URLSearchParams, top: TopFilters) {
  if (top.search) params.set("search", top.search);
  if (top.status) params.set("status", top.status);
  return params;
}

export default function Payables() {
  const { toast, toasts } = useToasts();
  const [exporting, setExporting] = useState(false);

  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [reload, setReload] = useState(0);
  const [sort, setSort] = useState<SortState>(null);
  const columnFilters = useServerColumnFilters(() => setPage(1));
  const { selection, selectedRows, clear: clearSelection } = useRowSelection(payableKey);

  const [data, setData] = useState<PaginatedResponse<Payable> | null>(null);
  const [summary, setSummary] = useState<PayableSummary | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const [openInvoice, setOpenInvoice] = useState<string | null>(null);
  const [paying, setPaying] = useState(false);

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, 350);
    return () => window.clearTimeout(timer);
  }, [searchInput]);

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setLoading(true);
      const params = withTopFilters(new URLSearchParams({ per_page: String(perPage), page: String(page) }), { search, status });
      applySort(applyFilters(params, columnFilters.param), sort);

      try {
        const response = await apiFetch<PaginatedResponse<Payable>>(`/v1/payables?${params}`);
        if (!cancelled) {
          setData(response);
          setError("");
        }
      } catch {
        if (!cancelled) setError("No se pudieron cargar las cuentas por pagar.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [page, perPage, search, status, columnFilters.param, sort, reload]);

  // Indicadores, vencimientos y saldos bancarios: después del listado.
  useEffect(() => {
    let cancelled = false;
    apiFetchLater<PayableSummary>("/v1/payables/summary")
      .then((next) => { if (!cancelled) setSummary(next); })
      .catch(() => undefined);
    return () => { cancelled = true; };
  }, [reload]);

  // Para pagar cuentan solo las marcadas que tienen saldo.
  const toPay = selectedRows.filter(isOpen);
  const suppliers = new Set(toPay.map((payable) => payable.supplier.id));
  const currencies = new Set(toPay.map((payable) => payable.currency));
  const blocked = suppliers.size > 1 ? "Selecciona cuentas de un mismo proveedor por pago." : currencies.size > 1 ? "Selecciona cuentas de una misma moneda por pago." : null;

  const columns: Column<Payable>[] = [
    {
      label: "Comprobante", sortKey: "document", filter: "text",
      render: (payable) => (
        <div className="min-w-[160px]">
          <button
            type="button" className={`${LINK} whitespace-nowrap tabular-nums`}
            onClick={(event) => {
              event.stopPropagation();
              setOpenInvoice(payable.supplier_invoice.id);
            }}
          >
            {payable.supplier_invoice.doc_number ?? "—"}
          </button>
          <small className="app-muted block text-xs">{payable.supplier_invoice.description ?? ""}</small>
        </div>
      ),
    },
    { label: "Proveedor", sortKey: "supplier", filter: "text", render: (payable) => payable.supplier.name ?? "—" },
    {
      label: "Vence", sortKey: "due", filter: "date",
      render: (payable) => (
        <span className="inline-flex flex-wrap items-center gap-1 whitespace-nowrap">
          {shortDate(payable.due_date)}
          {payable.overdue && <Chip tone="danger">Vencida</Chip>}
        </span>
      ),
    },
    { label: "Original", num: true, sortKey: "original", filter: "number", render: (payable) => <span className="whitespace-nowrap">{money(payable.original_amount, payable.currency)}</span> },
    {
      label: "Detracción", num: true, sortKey: "detraction", filter: "number",
      render: (payable) =>
        payable.detraction_amount ? (
          <div className="whitespace-nowrap">
            {money(payable.detraction_amount)}
            {payable.detraction_deposited && <small className="app-muted block text-xs">depositada</small>}
          </div>
        ) : "—",
    },
    {
      label: "Saldo", num: true, sortKey: "balance", filter: "number",
      render: (payable) => <strong className="whitespace-nowrap font-semibold">{money(payable.balance_amount, payable.currency)}</strong>,
    },
    {
      label: "Estado", sortKey: "status", filter: { top: "Estado" },
      render: (payable) => <Badge tone={stateOf(PAYABLE_STATES, payable.status)[1]}>{stateOf(PAYABLE_STATES, payable.status)[0]}</Badge>,
    },
  ];

  /** Exporta las filas marcadas o, sin `picked`, todas las del filtro y búsqueda actuales. */
  async function runExport(format: "xlsx" | "csv", picked?: Payable[]) {
    setExporting(true);
    try {
      const rows = picked ?? (await fetchAllPages<Payable>("/v1/payables", applySort(columnFilters.apply(withTopFilters(new URLSearchParams(), { search, status })), sort)));
      // La API comprueba el permiso de exportar y deja la exportación en auditoría.
      await recordExport("payables", format, rows.length, Boolean(picked));
      if (format === "xlsx") await exportXlsx("cuentas-por-pagar", "Cuentas por pagar", EXPORT_COLUMNS, rows);
      else exportCsv("cuentas-por-pagar", EXPORT_COLUMNS, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el listado.", "danger");
    } finally {
      setExporting(false);
    }
  }

  const buckets = summary?.buckets ?? BUCKET_LABELS.map((label, index) => ({ key: String(index), label, amount: 0 }));
  const highest = Math.max(1, ...buckets.map((bucket) => bucket.amount));

  return (
    <Screen>
      <PageHead title="Cuentas por pagar y pagos a proveedores" />

      <Kpis>
        <Kpi label="Saldo por pagar" value={summary ? kMoney(summary.outstanding) : "—"} sub={summary ? `${summary.open_count} ${summary.open_count === 1 ? "documento" : "documentos"}` : undefined} icon={<Wallet size={22} />} />
        <Kpi label="Vencido" value={summary ? kMoney(summary.overdue) : "—"} icon={<TriangleAlert size={22} />} tone="danger" />
        <Kpi label="Vence en 7 días" value={summary ? kMoney(summary.due_soon) : "—"} sub={summary ? `${summary.due_soon_count} ${summary.due_soon_count === 1 ? "documento" : "documentos"}` : undefined} icon={<Clock size={22} />} tone="warning" />
        <Kpi label="Detracciones por depositar" value={summary ? kMoney(summary.detraction_pending) : "—"} icon={<ShieldCheck size={22} />} />
      </Kpis>
      {summary?.missing_rate && <Notice tone="warn">Hay saldo en dólares y ningún tipo de cambio registrado: ese saldo no entra en estos totales.</Notice>}

      <div className="grid grid-cols-1 gap-x-4 lg:grid-cols-2">
        <Card title="Vencimientos">
          <div className="grid gap-2.5" aria-busy={!summary}>
            {buckets.map((bucket, index) => (
              <div key={bucket.key} className="grid grid-cols-[84px_minmax(0,1fr)_auto] items-center gap-2.5 text-[12.5px]">
                <span className="app-muted">{bucket.label}</span>
                <span className="h-2.5 overflow-hidden rounded-full bg-[var(--app-surface-muted)]">
                  <span className={`block h-full rounded-full transition-[width] duration-300 ${BAR_TONES[index]}`} style={{ width: `${(bucket.amount / highest) * 100}%` }} />
                </span>
                <strong className="min-w-[84px] text-right font-semibold tabular-nums">{summary ? kMoney(bucket.amount) : "—"}</strong>
              </div>
            ))}
          </div>
        </Card>
        <Card title="Saldos bancarios disponibles">
          {!summary ? (
            <div className="h-16 animate-pulse rounded bg-[var(--app-surface-muted)]" aria-busy="true" />
          ) : summary.accounts === null ? (
            <p className="app-muted flex items-center gap-1.5">
              <Lock size={14} className="shrink-0" aria-hidden="true" />
              {deniedText("treasury.view")}
            </p>
          ) : !summary.accounts.length ? (
            <p className="app-muted">Aún no hay cuentas de banco ni cajas. Se registran en Bancos y caja.</p>
          ) : (
            <ul className="grid">
              {summary.accounts.map((account) => (
                <li key={account.id} className="flex items-baseline justify-between gap-3 border-b border-[color:var(--app-border)] py-1.5 last:border-b-0">
                  <span className="min-w-0">{account.name}</span>
                  <strong className="whitespace-nowrap font-semibold tabular-nums">{money(account.balance, account.currency)}</strong>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>

      {toPay.length > 0 && (
        <div className="mb-3.5 flex flex-wrap items-center gap-2 rounded-xl border border-[color:var(--p-brand-line)] bg-[var(--p-brand-soft)] px-3.5 py-2.5 text-[13px] text-[color:var(--p-brand-soft-text)]">
          <span className="mr-auto">
            <strong className="font-semibold">{toPay.length}</strong> {toPay.length === 1 ? "seleccionada" : "seleccionadas"} con saldo
            {currencies.size === 1 && <> · {money(toPay.reduce((sum, payable) => sum + payable.balance_amount, 0), toPay[0].currency)}</>}
            {" · "}
            {suppliers.size > 1 ? <span className="text-[color:var(--p-danger-text)]">proveedores distintos</span> : toPay[0].supplier.name}
            {currencies.size > 1 && <span className="text-[color:var(--p-danger-text)]"> · monedas distintas</span>}
          </span>
          <Btn size="sm" onClick={() => setPaying(true)} permission="payables.pay" disabled={Boolean(blocked)} title={blocked ?? undefined}>
            <Wallet size={14} />
            <span>Pagar seleccionadas</span>
          </Btn>
          <Btn size="sm" variant="ghost" onClick={clearSelection}>Limpiar</Btn>
        </div>
      )}

      <Filters>
        <SearchInput value={searchInput} onChange={setSearchInput} placeholder="Buscar proveedor o comprobante" />
        <FilterSelect
          label="Estado"
          value={status}
          onChange={(value) => {
            setStatus(value);
            setPage(1);
          }}
          options={STATE_FILTER}
        />
        <ColumnFilterBar
          filters={columnFilters}
          hasOtherFilters={Boolean(searchInput || status)}
          onClearAll={() => {
            setSearchInput("");
            setSearch("");
            setStatus("");
            setPage(1);
          }}
        />
        <ScopeNote resource="payables" />
      </Filters>

      {error ? (
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
      ) : (
        <Card flush>
          <SelectionBar permission="payables.export" count={selectedRows.length} busy={exporting} onExport={(format) => runExport(format, selectedRows)} onClear={clearSelection} />
          <DataTable
            tableId="payables" sort={sort} onSort={(next) => { setSort(next); setPage(1); }} refreshing={loading}
            onFilter={(column) => addColumnFilter(columnFilters, column)}
            cols={columns}
            rows={data?.data ?? []}
            rowKey={payableKey}
            selection={selection}
            // Como en el prototipo: la cuenta se ve en la ficha de su comprobante, con sus movimientos.
            onRowClick={(payable) => setOpenInvoice(payable.supplier_invoice.id)}
            loading={loading && !data}
            empty="No hay registros con estos filtros."
          />
          {data && (
            <Pager
              loading={loading}
              page={data.current_page}
              lastPage={data.last_page}
              total={data.total}
              perPage={data.per_page}
              onPage={setPage}
              onPerPage={(size) => {
                setPerPage(size);
                setPage(1);
              }}
              left={<ExportButtons permission="payables.export" total={data.total} busy={exporting} onExport={(format) => runExport(format)} />}
            />
          )}
        </Card>
      )}

      <SupplierPayments version={reload} toast={toast} onChanged={() => setReload((n) => n + 1)} onInvoice={setOpenInvoice} />

      {openInvoice && <SupplierInvoiceModal id={openInvoice} toast={toast} onClose={() => setOpenInvoice(null)} onChanged={() => setReload((n) => n + 1)} />}
      {paying && toPay.length > 0 && (
        <PaySuppliersModal
          payables={toPay}
          onClose={() => setPaying(false)}
          onPaid={(payment) => {
            setPaying(false);
            clearSelection();
            toast("Pago registrado", "success", payment.payment_number);
            setReload((n) => n + 1);
          }}
        />
      )}
      {toasts}
    </Screen>
  );
}

/** Tarjeta "Pagos a proveedores": los pagos registrados, del más reciente al más antiguo. */
function SupplierPayments({
  version, toast, onChanged, onInvoice,
}: { version: number; toast: (text: string, tone?: "success" | "danger" | "warning", detail?: string) => void; onChanged: () => void; onInvoice: (id: string) => void }) {
  const [openPayment, setOpenPayment] = useState<string | null>(null);
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [sort, setSort] = useState<SortState>(null);
  const columnFilters = useServerColumnFilters(() => setPage(1));
  const [data, setData] = useState<PaginatedResponse<SupplierPayment> | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [retry, setRetry] = useState(0);
  const [exporting, setExporting] = useState(false);

  // Tabla secundaria de la pantalla: se pide después del listado de cuentas por pagar.
  useEffect(() => {
    let cancelled = false;

    async function load() {
      setLoading(true);
      const params = applySort(applyFilters(new URLSearchParams({ per_page: String(perPage), page: String(page) }), columnFilters.param), sort);
      try {
        const response = await apiFetchLater<PaginatedResponse<SupplierPayment>>(`/v1/supplier-payments?${params}`);
        if (!cancelled) {
          setData(response);
          setError("");
        }
      } catch {
        if (!cancelled) setError("No se pudieron cargar los pagos a proveedores.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [page, perPage, columnFilters.param, sort, version, retry]);

  async function runExport(format: "xlsx" | "csv") {
    setExporting(true);
    try {
      const rows = await fetchAllPages<SupplierPayment>("/v1/supplier-payments", applySort(columnFilters.apply(new URLSearchParams()), sort));
      await recordExport("payables", format, rows.length);
      if (format === "xlsx") await exportXlsx("pagos-a-proveedores", "Pagos a proveedores", PAYMENT_EXPORT_COLUMNS, rows);
      else exportCsv("pagos-a-proveedores", PAYMENT_EXPORT_COLUMNS, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el listado.", "danger");
    } finally {
      setExporting(false);
    }
  }

  return (
    <Card title="Pagos a proveedores" flush>
      <div className="px-5 pt-3">
        <Filters>
          <ColumnFilterBar filters={columnFilters} hasOtherFilters={false} onClearAll={() => setPage(1)} />
        </Filters>
      </div>
      {error ? (
        <div className="px-5 pb-4"><LoadError text={error} onRetry={() => setRetry((n) => n + 1)} /></div>
      ) : (
        <>
          <DataTable
            tableId="supplier-payments" sort={sort} onSort={(next) => { setSort(next); setPage(1); }} refreshing={loading}
            onFilter={(column) => addColumnFilter(columnFilters, column)}
            cols={PAYMENT_COLUMNS}
            rows={data?.data ?? []}
            rowKey={paymentKey}
            onRowClick={(payment) => setOpenPayment(payment.id)}
            loading={loading && !data}
            empty="Aún no se registran pagos a proveedores."
          />
          {data && (
            <Pager
              loading={loading}
              page={data.current_page}
              lastPage={data.last_page}
              total={data.total}
              perPage={data.per_page}
              onPage={setPage}
              onPerPage={(size) => {
                setPerPage(size);
                setPage(1);
              }}
              left={<ExportButtons permission="payables.export" total={data.total} busy={exporting} onExport={runExport} />}
            />
          )}
        </>
      )}
      {openPayment && (
        <SupplierPaymentModal
          id={openPayment} toast={toast} onClose={() => setOpenPayment(null)} onChanged={onChanged}
          onInvoice={(invoiceId) => { setOpenPayment(null); onInvoice(invoiceId); }}
        />
      )}
    </Card>
  );
}
