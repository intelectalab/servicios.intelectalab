"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { Send, Wallet } from "lucide-react";
import { ApiError, apiFetch, apiFetchLater } from "@/lib/api";
import { customerName, excelTone, money, shortDate, stateOf } from "@/lib/commercial";
import { dateTime } from "@/lib/crm";
import { AGING, MOVEMENT_LABELS, RECEIVABLE_STATES, WITHHOLDING_STATES, bucketLabel } from "@/lib/finance";
import { useAccess } from "@/lib/access-context";
import { exportCsv, exportXlsx, recordExport, fetchAllPages, type ExportColumn } from "@/lib/export-table";
import {
  Badge, Btn, Card, Chip, DataTable, ExportButtons, FilterSelect, Filters, Kv, LoadError, Modal, Notice, PAGE_SIZES, PageHead, Pager, Screen,
  SearchInput, SelectionBar, useRowSelection, useToasts, type Column,
  applySort, type SortState,
} from "@/components/ui/proto";
import { ScopeNote } from "@/components/dashboard/ScopeNote";
import { ColumnFilterBar, addColumnFilter, applyFilters, useServerColumnFilters } from "@/components/dashboard/ColumnFilters";
import { PaymentFormModal } from "@/components/dashboard/finance/PaymentModals";
import type { PaginatedResponse } from "@/types/customer";
import type { Receivable, ReceivableDetail, ReceivableSummary } from "@/types/finance";

const LINK = "text-[color:var(--p-brand-text)] hover:underline";

const receivableKey = (receivable: Receivable) => receivable.id;

// "Vencida" no es un estado: se calcula por la fecha. El filtro la ofrece junto a los estados, como el prototipo.
const STATE_FILTER: [string, string][] = [["OPEN", "Pendiente"], ["PARTIAL", "Parcial"], ["OVERDUE", "Vencida"], ["PAID", "Pagada"], ["CANCELLED", "Anulada"]];

// Color de cada tramo: al día, recién vencido y vencido de más tiempo.
const BAR_TONES = ["bg-[var(--p-success)]", "bg-[var(--p-warning-text)]", "bg-[var(--p-danger-text)]", "bg-[var(--p-danger-text)]", "bg-[var(--p-danger-text)]"];

const EXPORT_COLUMNS: ExportColumn<Receivable>[] = [
  { label: "Comprobante", value: (receivable) => receivable.invoice_number ?? "", width: 20, kind: "center" },
  { label: "Cliente", value: (receivable) => customerName(receivable.customer), width: 34 },
  { label: "Vence", value: (receivable) => (receivable.due_date ? shortDate(receivable.due_date) : ""), width: 15, kind: "center" },
  { label: "Días de atraso", value: (receivable) => receivable.days_overdue, width: 14, kind: "number" },
  { label: "Antigüedad", value: (receivable) => bucketLabel(receivable.bucket), width: 16, kind: "center" },
  { label: "Moneda", value: (receivable) => receivable.currency, width: 10, kind: "center" },
  { label: "Original", value: (receivable) => receivable.original_amount, width: 14, kind: "money", currency: (receivable) => receivable.currency },
  { label: "Detracción / retención (S/)", value: (receivable) => receivable.withholding?.amount ?? "", width: 22, kind: "number" },
  {
    label: "Constancia", width: 20, kind: "center",
    value: (receivable) => (receivable.withholding ? `${receivable.withholding.type === "DETRACTION" ? "Detracción" : "Retención"} · ${stateOf(WITHHOLDING_STATES, receivable.withholding.status)[0]}` : ""),
  },
  { label: "Saldo", value: (receivable) => receivable.balance_amount, width: 14, kind: "money", currency: (receivable) => receivable.currency },
  {
    label: "Estado",
    value: (receivable) => (receivable.overdue ? "Vencida" : stateOf(RECEIVABLE_STATES, receivable.status)[0]),
    width: 15,
    kind: "status",
    tone: (receivable) => (receivable.overdue ? "danger" : excelTone(stateOf(RECEIVABLE_STATES, receivable.status)[1])),
  },
];

type TopFilters = { search: string; status: string; bucket: string };

function withTopFilters(params: URLSearchParams, top: TopFilters) {
  if (top.search) params.set("search", top.search);
  if (top.status) params.set("status", top.status);
  if (top.bucket) params.set("bucket", top.bucket);
  return params;
}

export default function Receivables() {
  const { can } = useAccess();
  const { toast, toasts } = useToasts();
  const [exporting, setExporting] = useState(false);

  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [bucket, setBucket] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [reload, setReload] = useState(0);
  const [sort, setSort] = useState<SortState>(null);
  const columnFilters = useServerColumnFilters(() => setPage(1));
  const { selection, selectedRows, clear: clearSelection } = useRowSelection(receivableKey);

  const [data, setData] = useState<PaginatedResponse<Receivable> | null>(null);
  const [summary, setSummary] = useState<ReceivableSummary | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const [openId, setOpenId] = useState<string | null>(null);
  const [paying, setPaying] = useState(false);

  const seesInvoices = can("billing.view");

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
      const params = withTopFilters(new URLSearchParams({ per_page: String(perPage), page: String(page) }), { search, status, bucket });
      applySort(applyFilters(params, columnFilters.param), sort);

      try {
        const response = await apiFetch<PaginatedResponse<Receivable>>(`/v1/receivables?${params}`);
        if (!cancelled) {
          setData(response);
          setError("");
        }
      } catch {
        if (!cancelled) setError("No se pudieron cargar las cuentas por cobrar.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [page, perPage, search, status, bucket, columnFilters.param, sort, reload]);

  // Antigüedad y resumen: después del listado.
  useEffect(() => {
    let cancelled = false;
    apiFetchLater<ReceivableSummary>("/v1/receivables/summary")
      .then((next) => { if (!cancelled) setSummary(next); })
      .catch(() => undefined);
    return () => { cancelled = true; };
  }, [reload]);

  const columns: Column<Receivable>[] = [
    {
      label: "Comprobante", sortKey: "invoice", filter: "text",
      render: (receivable) =>
        seesInvoices ? (
          <Link href={`/invoices/${receivable.invoice_id}`} className={`${LINK} whitespace-nowrap tabular-nums`} onClick={(event) => event.stopPropagation()}>
            {receivable.invoice_number ?? "—"}
          </Link>
        ) : <span className="whitespace-nowrap tabular-nums">{receivable.invoice_number ?? "—"}</span>,
    },
    { label: "Cliente", sortKey: "customer", filter: "text", render: (receivable) => customerName(receivable.customer) },
    {
      label: "Vence", sortKey: "due", filter: "date",
      render: (receivable) => (
        <div className="whitespace-nowrap">
          {shortDate(receivable.due_date)}
          {receivable.overdue && <small className="block text-xs text-[color:var(--p-danger-text)]">{bucketLabel(receivable.bucket)}</small>}
        </div>
      ),
    },
    { label: "Original", num: true, sortKey: "original", filter: "number", render: (receivable) => money(receivable.original_amount, receivable.currency) },
    {
      label: "Detracción / retención", num: true, sortKey: "withholding", filter: "number",
      render: (receivable) =>
        receivable.withholding ? (
          <div className="whitespace-nowrap">
            {money(receivable.withholding.amount)}
            <small className="mt-0.5 block"><Badge tone={stateOf(WITHHOLDING_STATES, receivable.withholding.status)[1]}>{stateOf(WITHHOLDING_STATES, receivable.withholding.status)[0]}</Badge></small>
          </div>
        ) : "—",
    },
    {
      label: "Saldo", num: true, sortKey: "balance", filter: "number",
      render: (receivable) => <strong className="font-semibold">{money(receivable.balance_amount, receivable.currency)}</strong>,
    },
    {
      label: "Estado", sortKey: "status", filter: { top: "Estado" },
      render: (receivable) => (
        <span className="inline-flex flex-wrap items-center gap-1">
          <Badge tone={stateOf(RECEIVABLE_STATES, receivable.status)[1]}>{stateOf(RECEIVABLE_STATES, receivable.status)[0]}</Badge>
          {receivable.overdue && <Chip tone="danger">Vencida</Chip>}
        </span>
      ),
    },
  ];

  /** Exporta las filas marcadas o, sin `picked`, todas las del filtro y búsqueda actuales. */
  async function runExport(format: "xlsx" | "csv", picked?: Receivable[]) {
    setExporting(true);
    try {
      const rows = picked ?? (await fetchAllPages<Receivable>("/v1/receivables", applySort(columnFilters.apply(withTopFilters(new URLSearchParams(), { search, status, bucket })), sort)));
      // La API comprueba el permiso de exportar y deja la exportación en auditoría.
      await recordExport("receivables", format, rows.length, Boolean(picked));
      if (format === "xlsx") await exportXlsx("cuentas-por-cobrar", "Cuentas por cobrar", EXPORT_COLUMNS, rows);
      else exportCsv("cuentas-por-cobrar", EXPORT_COLUMNS, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el listado.", "danger");
    } finally {
      setExporting(false);
    }
  }

  const highest = Math.max(1, ...(summary?.aging.map((item) => item.amount) ?? []));

  return (
    <Screen>
      <PageHead
        title="Cuentas por cobrar"
        actions={
          <>
            {/* Aún sin función: depende de Notificaciones. */}
            <Btn variant="secondary" onClick={() => toast("Los recordatorios de cobranza aún no están disponibles: llegarán con Notificaciones.", "warning")} permission="receivables.manage">
              <Send size={16} />
              <span>Enviar recordatorios de cobranza</span>
            </Btn>
            <Btn onClick={() => setPaying(true)} permission="payments.create">
              <Wallet size={16} />
              <span>Registrar pago</span>
            </Btn>
          </>
        }
      />

      <div className="grid grid-cols-1 gap-x-4 lg:grid-cols-2">
        <Card title="Antigüedad del saldo">
          <div className="grid gap-2.5" aria-busy={!summary}>
            {(summary?.aging ?? AGING.map(([key, label]) => ({ key, label, amount: 0 }))).map((item, index) => (
              <div key={item.key} className="grid grid-cols-[92px_minmax(0,1fr)_auto] items-center gap-2.5 text-[12.5px]">
                <span className="app-muted">{item.label}</span>
                <span className="h-2.5 overflow-hidden rounded-full bg-[var(--app-surface-muted)]">
                  <span className={`block h-full rounded-full transition-[width] duration-300 ${BAR_TONES[index]}`} style={{ width: `${(item.amount / highest) * 100}%` }} />
                </span>
                <strong className="min-w-[84px] text-right font-semibold tabular-nums">{summary ? money(item.amount) : "—"}</strong>
              </div>
            ))}
          </div>
        </Card>
        <Card title="Resumen">
          <Kv
            rows={[
              ["Saldo total pendiente (en soles)", <strong key="outstanding" className="font-semibold tabular-nums">{summary ? money(summary.outstanding) : "—"}</strong>],
              ["Vencido", <strong key="overdue" className="font-semibold tabular-nums text-[color:var(--p-danger-text)]">{summary ? money(summary.overdue) : "—"}</strong>],
              ["Documentos abiertos", summary?.open_count ?? "—"],
              ["Clientes con deuda vencida", summary?.overdue_customers ?? "—"],
            ]}
          />
          {summary?.missing_rate && <Notice tone="warn">Hay saldo en dólares y ningún tipo de cambio registrado: ese saldo no entra en estos totales.</Notice>}
        </Card>
      </div>

      <Filters>
        <SearchInput value={searchInput} onChange={setSearchInput} placeholder="Buscar cliente o comprobante" />
        <FilterSelect
          label="Estado"
          value={status}
          onChange={(value) => {
            setStatus(value);
            setPage(1);
          }}
          options={STATE_FILTER}
        />
        <FilterSelect
          label="Antigüedad"
          value={bucket}
          onChange={(value) => {
            setBucket(value);
            setPage(1);
          }}
          options={AGING}
        />
        <ColumnFilterBar
          filters={columnFilters}
          hasOtherFilters={Boolean(searchInput || status || bucket)}
          onClearAll={() => {
            setSearchInput("");
            setSearch("");
            setStatus("");
            setBucket("");
            setPage(1);
          }}
        />
        <ScopeNote resource="receivables" />
      </Filters>

      {error ? (
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
      ) : (
        <Card flush>
          <SelectionBar permission="receivables.export" count={selectedRows.length} busy={exporting} onExport={(format) => runExport(format, selectedRows)} onClear={clearSelection} />
          <DataTable
            tableId="receivables" sort={sort} onSort={(next) => { setSort(next); setPage(1); }} refreshing={loading}
            onFilter={(column) => addColumnFilter(columnFilters, column)}
            cols={columns}
            rows={data?.data ?? []}
            rowKey={receivableKey}
            selection={selection}
            onRowClick={(receivable) => setOpenId(receivable.id)}
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
              left={<ExportButtons permission="receivables.export" total={data.total} busy={exporting} onExport={(format) => runExport(format)} />}
            />
          )}
        </Card>
      )}

      {openId && <ReceivableModal id={openId} version={reload} seesInvoices={seesInvoices} onClose={() => setOpenId(null)} />}
      {paying && (
        <PaymentFormModal
          onClose={() => setPaying(false)}
          onSaved={(payment) => {
            setPaying(false);
            toast(payment.allocated > 0 ? "Pago registrado y aplicado" : "Pago registrado", "success", payment.payment_number);
            setReload((n) => n + 1);
          }}
        />
      )}
      {toasts}
    </Screen>
  );
}

/** Ficha de una cuenta por cobrar, sobre el listado: de dónde sale su saldo. */
function ReceivableModal({ id, version, seesInvoices, onClose }: { id: string; version: number; seesInvoices: boolean; onClose: () => void }) {
  const [receivable, setReceivable] = useState<ReceivableDetail | null>(null);
  const [error, setError] = useState("");
  const [retry, setRetry] = useState(0);

  useEffect(() => {
    let cancelled = false;
    apiFetch<ReceivableDetail>(`/v1/receivables/${id}`)
      .then((next) => {
        if (cancelled) return;
        setReceivable(next);
        setError("");
      })
      .catch((err) => {
        if (!cancelled) setError(err instanceof ApiError && err.status === 404 ? "La cuenta por cobrar no existe." : "No se pudo cargar la cuenta por cobrar.");
      });
    return () => { cancelled = true; };
  }, [id, version, retry]);

  return (
    <Modal title="Cuenta por cobrar" onClose={onClose} footer={<Btn variant="ghost" onClick={onClose}>Cerrar</Btn>}>
      {error ? (
        <LoadError text={error} onRetry={() => setRetry((n) => n + 1)} />
      ) : !receivable ? (
        <div className="h-40 animate-pulse rounded bg-[var(--app-surface-muted)]" aria-busy="true" />
      ) : (
        <div className="grid gap-4">
          <div>
            <h4 className="text-[17px] font-semibold leading-tight tabular-nums">{receivable.invoice_number ?? "Comprobante"}</h4>
            <p className="app-muted text-[13px]">{customerName(receivable.customer)}</p>
          </div>
          <Kv
            rows={[
              [
                "Comprobante",
                seesInvoices
                  ? <Link key="invoice" href={`/invoices/${receivable.invoice_id}`} className={LINK}>{receivable.invoice_number ?? "—"}</Link>
                  : receivable.invoice_number ?? "—",
              ],
              [
                "Vence",
                <span key="due" className="inline-flex flex-wrap items-center gap-1.5">
                  {shortDate(receivable.due_date)}
                  {receivable.overdue && <Chip tone="danger">Vencida · {receivable.days_overdue} {receivable.days_overdue === 1 ? "día" : "días"}</Chip>}
                </span>,
              ],
              ["Original", money(receivable.original_amount, receivable.currency)],
              receivable.withholding ? [
                receivable.withholding.type === "DETRACTION" ? "Detracción" : "Retención",
                <span key="withholding" className="inline-flex flex-wrap items-center gap-1.5">
                  {money(receivable.withholding.amount)}
                  <Badge tone={stateOf(WITHHOLDING_STATES, receivable.withholding.status)[1]}>{stateOf(WITHHOLDING_STATES, receivable.withholding.status)[0]}</Badge>
                </span>,
              ] : null,
              ["Saldo", <strong key="balance" className="font-semibold tabular-nums">{money(receivable.balance_amount, receivable.currency)}</strong>],
              ["Estado", <Badge key="status" tone={stateOf(RECEIVABLE_STATES, receivable.status)[1]}>{stateOf(RECEIVABLE_STATES, receivable.status)[0]}</Badge>],
            ]}
          />
          <section>
            <h4 className="mb-1 text-[15px] font-semibold">Movimientos</h4>
            <ul className="grid">
              {receivable.movements.map((movement) => (
                <li key={movement.id} className="flex flex-wrap items-baseline gap-x-2 border-b border-[color:var(--app-border)] py-1.5 last:border-b-0">
                  <span className="min-w-0 flex-1">{MOVEMENT_LABELS[movement.movement_type] ?? movement.movement_type} · {movement.reference ?? "—"}</span>
                  <strong className={`font-semibold tabular-nums ${movement.amount < 0 ? "text-[color:var(--p-success-text)]" : ""}`}>{money(movement.amount, receivable.currency)}</strong>
                  <small className="app-muted w-full text-xs">{dateTime(movement.at)}</small>
                </li>
              ))}
            </ul>
          </section>
        </div>
      )}
    </Modal>
  );
}
