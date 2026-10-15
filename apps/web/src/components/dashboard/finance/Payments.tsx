"use client";

import { useEffect, useState, type ReactNode } from "react";
import Link from "next/link";
import { FileText, Plus } from "lucide-react";
import { ApiError, apiFetch, apiFetchLater } from "@/lib/api";
import { customerName, excelTone, money, shortDate, stateOf } from "@/lib/commercial";
import { dateTime, firstError } from "@/lib/crm";
import { PAYMENT_FILTER_STATES, PAYMENT_LIVE, PAYMENT_STATES, SOURCE_LABELS, downloadPaymentPdf, financeHistoryLabel, methodLabel } from "@/lib/finance";
import { useAccess } from "@/lib/access-context";
import { exportCsv, exportXlsx, recordExport, fetchAllPages, type ExportColumn } from "@/lib/export-table";
import {
  Badge, Btn, Card, Chip, DataTable, ExportButtons, FilterSelect, Filters, Kv, LoadError, Modal, PAGE_SIZES, PageHead, Pager, Screen,
  SearchInput, SelectionBar, TD, TH, Timeline, Who, useRowSelection, useToasts, type Column,
  applySort, type SortState,
} from "@/components/ui/proto";
import { ScopeNote } from "@/components/dashboard/ScopeNote";
import { ColumnFilterBar, addColumnFilter, applyFilters, useServerColumnFilters } from "@/components/dashboard/ColumnFilters";
import { AllocatePaymentModal, PaymentFormModal, RefundPaymentModal } from "@/components/dashboard/finance/PaymentModals";
import type { PaginatedResponse } from "@/types/customer";
import type { Payment, PaymentDetail, Refund } from "@/types/finance";

const LINK = "text-[color:var(--p-brand-text)] hover:underline";

const paymentKey = (payment: Payment) => payment.id;

const canAllocate = (payment: Payment) => payment.status === "CONFIRMED" || payment.status === "PARTIALLY_ALLOCATED";
const canReconcile = (payment: Payment) => !payment.reconciled && PAYMENT_LIVE.includes(payment.status);
const canRefund = (payment: Payment) => PAYMENT_LIVE.includes(payment.status);

const EXPORT_COLUMNS: ExportColumn<Payment>[] = [
  { label: "Recibo", value: (payment) => payment.payment_number, width: 18, kind: "center" },
  { label: "Cliente", value: (payment) => customerName(payment.customer), width: 34 },
  { label: "Fecha", value: (payment) => (payment.paid_at ? shortDate(payment.paid_at) : ""), width: 15, kind: "center" },
  { label: "Método", value: (payment) => methodLabel(payment.payment_method), width: 16 },
  { label: "N.º de operación", value: (payment) => payment.reference ?? "", width: 20 },
  { label: "Origen", value: (payment) => SOURCE_LABELS[payment.source] ?? payment.source, width: 16 },
  { label: "Moneda", value: (payment) => payment.currency, width: 10, kind: "center" },
  { label: "Monto", value: (payment) => payment.amount, width: 14, kind: "money", currency: (payment) => payment.currency },
  { label: "Aplicado", value: (payment) => payment.allocated, width: 14, kind: "money", currency: (payment) => payment.currency },
  { label: "Reembolsado", value: (payment) => payment.refunded_amount, width: 14, kind: "money", currency: (payment) => payment.currency },
  { label: "Cuenta", value: (payment) => payment.account_name ?? "", width: 24 },
  {
    label: "Estado",
    value: (payment) => stateOf(PAYMENT_STATES, payment.status)[0],
    width: 17,
    kind: "status",
    tone: (payment) => excelTone(stateOf(PAYMENT_STATES, payment.status)[1]),
  },
  { label: "Conciliación", value: (payment) => (payment.reconciled ? "Conciliado" : "Por conciliar"), width: 15, kind: "center" },
];

type TopFilters = { search: string; status: string; reconciled: string };

function withTopFilters(params: URLSearchParams, top: TopFilters) {
  if (top.search) params.set("search", top.search);
  if (top.status) params.set("status", top.status);
  if (top.reconciled) params.set("reconciled", top.reconciled);
  return params;
}

export default function Payments() {
  const { toast, toasts } = useToasts();
  const [exporting, setExporting] = useState(false);

  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [reconciled, setReconciled] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [reload, setReload] = useState(0);
  const [sort, setSort] = useState<SortState>(null);
  const columnFilters = useServerColumnFilters(() => setPage(1));
  const { selection, selectedRows, clear: clearSelection } = useRowSelection(paymentKey);

  const [data, setData] = useState<PaginatedResponse<Payment> | null>(null);
  const [refunds, setRefunds] = useState<Refund[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const [openId, setOpenId] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);
  const [allocating, setAllocating] = useState<Payment | null>(null);
  const [refunding, setRefunding] = useState<Payment | null>(null);
  const [busy, setBusy] = useState(false);

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
      const params = withTopFilters(new URLSearchParams({ per_page: String(perPage), page: String(page) }), { search, status, reconciled });
      applySort(applyFilters(params, columnFilters.param), sort);

      try {
        const response = await apiFetch<PaginatedResponse<Payment>>(`/v1/payments?${params}`);
        if (!cancelled) {
          setData(response);
          setError("");
        }
      } catch {
        if (!cancelled) setError("No se pudieron cargar los pagos.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [page, perPage, search, status, reconciled, columnFilters.param, sort, reload]);

  // Los reembolsos: después del listado.
  useEffect(() => {
    let cancelled = false;
    apiFetchLater<Refund[]>("/v1/payments/refunds")
      .then((next) => { if (!cancelled) setRefunds(next); })
      .catch(() => undefined);
    return () => { cancelled = true; };
  }, [reload]);

  async function reconcile(payment: Payment) {
    setBusy(true);
    try {
      await apiFetch(`/v1/payments/${payment.id}/reconcile`, { method: "POST" });
      toast("Pago conciliado con el extracto bancario");
    } catch (err) {
      toast(firstError(err, "No se pudo conciliar el pago."), "danger");
    } finally {
      setBusy(false);
      // También tras un error: si otra persona lo cambió, se ve la versión vigente.
      setReload((n) => n + 1);
    }
  }

  /** Recibo en PDF: necesita la ficha (a qué cuentas se aplicó). */
  async function receipt(payment: Payment) {
    toast("Generando PDF…");
    try {
      await downloadPaymentPdf(await apiFetch<PaymentDetail>(`/v1/payments/${payment.id}`));
    } catch {
      toast("No se pudo generar el recibo", "danger");
    }
  }

  const actions = (payment: Payment, size: "sm" | "md") => (
    <>
      <Btn size={size} variant="ghost" onClick={() => receipt(payment)}>
        <FileText size={size === "sm" ? 13 : 16} />
        <span>Recibo</span>
      </Btn>
      {canAllocate(payment) && <Btn size={size} variant="secondary" onClick={() => setAllocating(payment)} permission="payments.manage" disabled={busy}>Aplicar</Btn>}
      {canReconcile(payment) && <Btn size={size} variant="ghost" onClick={() => reconcile(payment)} permission="payments.approve" disabled={busy}>Conciliar</Btn>}
      {canRefund(payment) && <Btn size={size} variant="danger-ghost" onClick={() => setRefunding(payment)} permission="payments.approve" disabled={busy}>Reembolsar</Btn>}
    </>
  );

  const columns: Column<Payment>[] = [
    {
      label: "Recibo", sortKey: "payment", filter: "text",
      render: (payment) => (
        // Sin ancho mínimo fijo: el detalle (método · operación · origen) baja de línea para que la tabla quepa a 1366 px.
        <div className="min-w-[120px]">
          <strong className="whitespace-nowrap font-semibold tabular-nums">{payment.payment_number}</strong>
          <small className="app-muted block text-xs">
            {[methodLabel(payment.payment_method), payment.reference, SOURCE_LABELS[payment.source] ?? payment.source].filter(Boolean).join(" · ")}
          </small>
        </div>
      ),
    },
    { label: "Cliente", sortKey: "customer", filter: "text", render: (payment) => customerName(payment.customer) },
    { label: "Fecha", sortKey: "date", filter: "date", render: (payment) => <span className="whitespace-nowrap">{shortDate(payment.paid_at)}</span> },
    {
      label: "Monto", num: true, sortKey: "amount", filter: "number",
      render: (payment) => (
        <div>
          <span className="whitespace-nowrap">{money(payment.amount, payment.currency)}</span>
          {/* El importe no se parte; "reembolsado" puede bajar a otra línea. */}
          {payment.refunded_amount > 0 && (
            <small className="block whitespace-normal text-xs text-[color:var(--p-warning-text)]">
              <span className="whitespace-nowrap">− {money(payment.refunded_amount, payment.currency)}</span> reembolsado
            </small>
          )}
        </div>
      ),
    },
    { label: "Aplicado", num: true, sortKey: "allocated", filter: "number", render: (payment) => money(payment.allocated, payment.currency) },
    { label: "Cuenta", sortKey: "account", filter: "text", render: (payment) => <small className="text-xs">{payment.account_name ?? "—"}</small> },
    {
      label: "Estado", sortKey: "status", filter: { top: "Estado" },
      render: (payment) => (
        <span className="inline-flex flex-wrap items-center gap-1">
          <Badge tone={stateOf(PAYMENT_STATES, payment.status)[1]}>{stateOf(PAYMENT_STATES, payment.status)[0]}</Badge>
          {payment.reconciled && <Chip tone="success">Conciliado</Chip>}
        </span>
      ),
    },
    {
      label: "Acciones",
      // Las acciones no abren la ficha de la fila.
      render: (payment) => <div className="flex flex-wrap gap-1.5" onClick={(event) => event.stopPropagation()}>{actions(payment, "sm")}</div>,
    },
  ];

  /** Exporta las filas marcadas o, sin `picked`, todas las del filtro y búsqueda actuales. */
  async function runExport(format: "xlsx" | "csv", picked?: Payment[]) {
    setExporting(true);
    try {
      const rows = picked ?? (await fetchAllPages<Payment>("/v1/payments", applySort(columnFilters.apply(withTopFilters(new URLSearchParams(), { search, status, reconciled })), sort)));
      // La API comprueba el permiso de exportar y deja la exportación en auditoría.
      await recordExport("payments", format, rows.length, Boolean(picked));
      if (format === "xlsx") await exportXlsx("pagos", "Pagos", EXPORT_COLUMNS, rows);
      else exportCsv("pagos", EXPORT_COLUMNS, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el listado.", "danger");
    } finally {
      setExporting(false);
    }
  }

  const refresh = (text: string) => {
    toast(text);
    setReload((n) => n + 1);
  };

  return (
    <Screen>
      <PageHead
        title="Pagos"
        actions={
          <Btn onClick={() => setCreating(true)} permission="payments.create">
            <Plus size={16} />
            <span>Registrar pago</span>
          </Btn>
        }
      />

      <Filters>
        <SearchInput value={searchInput} onChange={setSearchInput} placeholder="Buscar recibo, cliente u operación" />
        <FilterSelect
          label="Estado"
          value={status}
          onChange={(value) => {
            setStatus(value);
            setPage(1);
          }}
          options={PAYMENT_FILTER_STATES.map(([key, label]) => [key, label])}
        />
        <FilterSelect
          label="Conciliación"
          value={reconciled}
          onChange={(value) => {
            setReconciled(value);
            setPage(1);
          }}
          options={[["true", "Conciliado"], ["false", "Por conciliar"]]}
        />
        <ColumnFilterBar
          filters={columnFilters}
          hasOtherFilters={Boolean(searchInput || status || reconciled)}
          onClearAll={() => {
            setSearchInput("");
            setSearch("");
            setStatus("");
            setReconciled("");
            setPage(1);
          }}
        />
        <ScopeNote resource="payments" />
      </Filters>

      {error ? (
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
      ) : (
        <Card flush>
          <SelectionBar permission="payments.export" count={selectedRows.length} busy={exporting} onExport={(format) => runExport(format, selectedRows)} onClear={clearSelection} />
          <DataTable
            tableId="payments" sort={sort} onSort={(next) => { setSort(next); setPage(1); }} refreshing={loading}
            onFilter={(column) => addColumnFilter(columnFilters, column)}
            cols={columns}
            rows={data?.data ?? []}
            rowKey={paymentKey}
            selection={selection}
            onRowClick={(payment) => setOpenId(payment.id)}
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
              left={<ExportButtons permission="payments.export" total={data.total} busy={exporting} onExport={(format) => runExport(format)} />}
            />
          )}
        </Card>
      )}

      {refunds.length > 0 && (
        <Card title="Reembolsos">
          <div className="proto-tbl-wrap -mx-1 overflow-x-auto">
            <table className="proto-tbl w-full border-collapse text-[13px]">
              <thead>
                <tr>
                  <th className={TH}>Pago original</th>
                  <th className={`${TH} text-right`}>Monto</th>
                  <th className={TH}>Motivo</th>
                  <th className={TH}>Por</th>
                  <th className={TH}>Fecha</th>
                </tr>
              </thead>
              <tbody>
                {refunds.map((refund) => (
                  <tr key={refund.id}>
                    <td className={TD} data-label="Pago original">
                      <button type="button" className={`${LINK} cursor-pointer font-semibold tabular-nums`} onClick={() => refund.payment_id && setOpenId(refund.payment_id)}>
                        {refund.payment_number}
                      </button>
                    </td>
                    <td className={`${TD} whitespace-nowrap text-right tabular-nums`} data-label="Monto">{money(refund.amount, refund.currency)}</td>
                    <td className={TD} data-label="Motivo">{refund.reason}</td>
                    <td className={TD} data-label="Por"><Who name={refund.by} /></td>
                    <td className={`${TD} whitespace-nowrap`} data-label="Fecha">{refund.at ? dateTime(refund.at) : "—"}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      {openId && (
        <PaymentModal
          id={openId}
          // Se vuelve a pedir cuando el listado se refresca tras una acción.
          version={reload}
          onClose={() => setOpenId(null)}
          actions={(payment) => actions(payment, "md")}
        />
      )}
      {creating && (
        <PaymentFormModal
          onClose={() => setCreating(false)}
          onSaved={(payment) => {
            setCreating(false);
            setPage(1);
            refresh(payment.allocated > 0 ? "Pago registrado y aplicado" : "Pago registrado");
          }}
        />
      )}
      {allocating && (
        <AllocatePaymentModal
          payment={allocating}
          onClose={() => setAllocating(null)}
          onError={(text) => toast(text, "danger")}
          onDone={() => {
            setAllocating(null);
            refresh("Pago aplicado");
          }}
        />
      )}
      {refunding && (
        <RefundPaymentModal
          payment={refunding}
          onClose={() => setRefunding(null)}
          onError={(text) => toast(text, "danger")}
          onDone={() => {
            setRefunding(null);
            refresh("Reembolso registrado");
          }}
        />
      )}
      {toasts}
    </Screen>
  );
}

/** Ficha de un cobro, sobre el listado: datos, a qué cuentas se aplicó, reembolsos e historial. */
function PaymentModal({
  id, version, onClose, actions,
}: { id: string; version: number; onClose: () => void; actions: (payment: Payment) => ReactNode }) {
  const { can } = useAccess();
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
      .catch((err) => {
        if (!cancelled) setError(err instanceof ApiError && err.status === 404 ? "El pago no existe." : "No se pudo cargar el pago.");
      });
    return () => { cancelled = true; };
  }, [id, version, retry]);

  return (
    <Modal title="Pago" size="lg" onClose={onClose} footer={<Btn variant="ghost" onClick={onClose}>Cerrar</Btn>}>
      {error ? (
        <LoadError text={error} onRetry={() => setRetry((n) => n + 1)} />
      ) : !payment ? (
        <div className="h-40 animate-pulse rounded bg-[var(--app-surface-muted)]" aria-busy="true" />
      ) : (
        <div className="grid gap-4">
          <div>
            <h4 className="text-[17px] font-semibold leading-tight tabular-nums">{payment.payment_number} · {money(payment.amount, payment.currency)}</h4>
            <p className="app-muted text-[13px]">{customerName(payment.customer)}</p>
          </div>

          <div className="flex flex-wrap items-center gap-1.5">
            <Badge tone={stateOf(PAYMENT_STATES, payment.status)[1]}>{stateOf(PAYMENT_STATES, payment.status)[0]}</Badge>
            {payment.reconciled && <Chip tone="success">Conciliado</Chip>}
          </div>
          <div className="flex flex-wrap gap-1.5">{actions(payment)}</div>

          <Kv
            rows={[
              ["Fecha", payment.paid_at ? shortDate(payment.paid_at) : "—"],
              ["Método", methodLabel(payment.payment_method)],
              ["N.º de operación", payment.reference || "—"],
              ["Cuenta de destino", payment.account_name ?? "—"],
              ["Origen", SOURCE_LABELS[payment.source] ?? payment.source],
              payment.exchange_rate ? ["Tipo de cambio", payment.exchange_rate.toFixed(3)] : null,
              ["Aplicado", money(payment.allocated, payment.currency)],
              payment.refunded_amount > 0 ? ["Reembolsado", money(payment.refunded_amount, payment.currency)] : null,
              ["Sin aplicar", <strong key="free" className="font-semibold tabular-nums">{money(payment.free, payment.currency)}</strong>],
              payment.reconciled_at ? ["Conciliado el", dateTime(payment.reconciled_at)] : null,
              ["Registrado por", <Who key="creator" name={payment.creator} />],
            ]}
          />

          <section>
            <h4 className="mb-1 text-[15px] font-semibold">Aplicaciones</h4>
            {payment.allocations.length ? (
              <ul className="grid">
                {payment.allocations.map((allocation) => (
                  <li key={allocation.receivable_id} className="flex flex-wrap items-baseline gap-x-2 border-b border-[color:var(--app-border)] py-1.5 last:border-b-0">
                    <span className="min-w-0 flex-1">
                      {can("billing.view")
                        ? <Link href={`/invoices/${allocation.invoice_id}`} className={`${LINK} font-semibold`}>{allocation.invoice_number ?? "—"}</Link>
                        : <strong className="font-semibold">{allocation.invoice_number ?? "—"}</strong>}
                      <small className="app-muted ml-2 text-xs">{dateTime(allocation.at)}</small>
                    </span>
                    <strong className="font-semibold tabular-nums">{money(allocation.amount, payment.currency)}</strong>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="app-muted">Sin aplicar: el saldo está como anticipo del cliente.</p>
            )}
          </section>

          {payment.refunds.length > 0 && (
            <section>
              <h4 className="mb-1 text-[15px] font-semibold">Reembolsos</h4>
              <ul className="grid">
                {payment.refunds.map((refund) => (
                  <li key={refund.id} className="border-b border-[color:var(--app-border)] py-1.5 last:border-b-0">
                    <strong className="font-semibold tabular-nums">{money(refund.amount, payment.currency)}</strong> · {refund.reason}
                    <small className="app-muted block text-xs">{refund.by ?? "—"} · {refund.at ? dateTime(refund.at) : "—"}</small>
                  </li>
                ))}
              </ul>
            </section>
          )}

          <section>
            <h4 className="mb-1 text-[15px] font-semibold">Historial</h4>
            <Timeline
              items={payment.history.map((entry, index) => {
                const to = entry.metadata.to;
                return {
                  key: `${entry.at}-${index}`,
                  head: (
                    <>
                      <span className="font-medium">{financeHistoryLabel(entry)}</span>
                      {to && <Badge tone={stateOf(PAYMENT_STATES, to)[1]}>{stateOf(PAYMENT_STATES, to)[0]}</Badge>}
                    </>
                  ),
                  meta: `${entry.user ?? "Sistema"} · ${dateTime(entry.at)}`,
                  note: entry.metadata.note,
                };
              })}
            />
          </section>
        </div>
      )}
    </Modal>
  );
}
