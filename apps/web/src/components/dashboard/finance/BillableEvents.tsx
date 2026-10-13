"use client";

import { useEffect, useState, type FormEvent } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Plus, Upload } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { customerName, excelTone, money, setFlash, shortDate, stateOf } from "@/lib/commercial";
import { dateTime, fieldErrors, firstError } from "@/lib/crm";
import { EVENT_SOURCES, EVENT_STATES, sourceLabel } from "@/lib/finance";
import { exportCsv, exportXlsx, recordExport, fetchAllPages, type ExportColumn } from "@/lib/export-table";
import {
  Badge, Btn, Card, DataTable, ExportButtons, Field, FilterSelect, Filters, FormGrid, INPUT, Kv, LoadError, Modal, Notice, PAGE_SIZES, PageHead, Pager, Screen,
  SearchInput, useRowSelection, useToasts, type Column,
  applySort, type SortState,
} from "@/components/ui/proto";
import { PickSelect } from "@/components/ui/pick-select";
import { ScopeNote } from "@/components/dashboard/ScopeNote";
import { ColumnFilterBar, addColumnFilter, applyFilters, useServerColumnFilters } from "@/components/dashboard/ColumnFilters";
import { ReasonModal } from "@/components/dashboard/crm/CrmModals";
import type { PaginatedResponse } from "@/types/customer";
import type { BillableEvent, BillableEventOptions, Currency, InvoiceDetail } from "@/types/finance";

const LINK = "text-[color:var(--p-brand-text)] hover:underline";
const IGV = 1.18;

const eventKey = (event: BillableEvent) => event.id;

const EXPORT_COLUMNS: ExportColumn<BillableEvent>[] = [
  { label: "Evento", value: (event) => event.description, width: 44 },
  { label: "Origen", value: (event) => sourceLabel(event.source_type), width: 20 },
  { label: "Cliente", value: (event) => customerName(event.customer), width: 34 },
  { label: "Contrato", value: (event) => event.contract?.contract_number ?? "", width: 18, kind: "center" },
  { label: "Fecha", value: (event) => shortDate(event.created_at), width: 15, kind: "center" },
  { label: "Moneda", value: (event) => event.currency, width: 10, kind: "center" },
  { label: "Base", value: (event) => event.base, width: 14, kind: "money", currency: (event) => event.currency },
  { label: "Total c/IGV", value: (event) => event.amount, width: 14, kind: "money", currency: (event) => event.currency },
  {
    label: "Estado",
    value: (event) => stateOf(EVENT_STATES, event.status)[0],
    width: 16,
    kind: "status",
    tone: (event) => excelTone(stateOf(EVENT_STATES, event.status)[1]),
  },
  { label: "Comprobante", value: (event) => event.invoice?.invoice_number ?? (event.invoice ? "Borrador" : ""), width: 18, kind: "center" },
  { label: "Motivo de anulación", value: (event) => event.cancel_reason ?? "", width: 32 },
];

type TopFilters = { search: string; status: string; source: string };

function withTopFilters(params: URLSearchParams, top: TopFilters) {
  if (top.search) params.set("search", top.search);
  if (top.status) params.set("status", top.status);
  if (top.source) params.set("source", top.source);
  return params;
}

export default function BillableEvents() {
  const router = useRouter();
  const { toast, toasts } = useToasts();
  const [exporting, setExporting] = useState(false);

  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [source, setSource] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [reload, setReload] = useState(0);
  const [sort, setSort] = useState<SortState>(null);
  const columnFilters = useServerColumnFilters(() => setPage(1));
  const { selection, selectedRows, clear: clearSelection } = useRowSelection(eventKey);

  const [data, setData] = useState<PaginatedResponse<BillableEvent> | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const [open, setOpen] = useState<BillableEvent | null>(null);
  const [cancelling, setCancelling] = useState<BillableEvent | null>(null);
  const [creating, setCreating] = useState(false);
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
      const params = withTopFilters(new URLSearchParams({ per_page: String(perPage), page: String(page) }), { search, status, source });
      applySort(applyFilters(params, columnFilters.param), sort);

      try {
        const response = await apiFetch<PaginatedResponse<BillableEvent>>(`/v1/billable-events?${params}`);
        if (!cancelled) {
          setData(response);
          setError("");
        }
      } catch {
        if (!cancelled) setError("No se pudieron cargar los eventos facturables.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [page, perPage, search, status, source, columnFilters.param, sort, reload]);

  // Qué impide facturar lo seleccionado: se factura a un cliente, en una moneda, y solo lo pendiente.
  const customers = new Set(selectedRows.map((event) => event.customer?.id));
  const currencies = new Set(selectedRows.map((event) => event.currency));
  const blocked = selectedRows.some((event) => event.status !== "PENDING") ? "Solo se facturan eventos por facturar: quita de la selección los facturados o anulados."
    : customers.size > 1 ? "Todos los eventos deben ser del mismo cliente."
    : currencies.size > 1 ? "Todos los eventos deben estar en la misma moneda." : undefined;

  /** Genera el borrador con los eventos marcados y abre su ficha. */
  async function invoice(type: "INVOICE" | "RECEIPT") {
    setBusy(true);
    try {
      const draft = await apiFetch<InvoiceDetail>("/v1/invoices/from-events", {
        method: "POST",
        body: JSON.stringify({ event_ids: selectedRows.map((event) => event.id), type }),
      });
      setFlash("Comprobante en borrador creado");
      router.push(`/invoices/${draft.id}`);
    } catch (err) {
      toast(firstError(err, "No se pudo generar el comprobante."), "danger");
      // Si otra persona facturó alguno, se ve su estado vigente.
      setReload((n) => n + 1);
      setBusy(false);
    }
  }

  async function cancel(event: BillableEvent, reason: string) {
    setBusy(true);
    try {
      await apiFetch(`/v1/billable-events/${event.id}/cancel`, { method: "POST", body: JSON.stringify({ reason }) });
      toast("Evento anulado");
      setCancelling(null);
      setOpen(null);
      clearSelection();
    } catch (err) {
      toast(firstError(err, "No se pudo anular el evento."), "danger");
    } finally {
      setBusy(false);
      setReload((n) => n + 1);
    }
  }

  const columns: Column<BillableEvent>[] = [
    {
      label: "Evento", sortKey: "event", filter: "text",
      render: (event) => (
        <div className="min-w-[200px]">
          <strong className="font-semibold">{event.description}</strong>
          <small className="app-muted block text-xs">
            {sourceLabel(event.source_type)} · clave <code className="rounded bg-[var(--app-surface-muted)] px-1">{event.idempotency_key}</code>
          </small>
        </div>
      ),
    },
    { label: "Cliente", sortKey: "customer", filter: "text", render: (event) => customerName(event.customer) },
    { label: "Fecha", sortKey: "date", filter: "date", render: (event) => <span className="whitespace-nowrap">{shortDate(event.created_at)}</span> },
    { label: "Base", num: true, sortKey: "base", filter: "number", render: (event) => money(event.base, event.currency) },
    { label: "Total c/IGV", num: true, sortKey: "total", filter: "number", render: (event) => money(event.amount, event.currency) },
    {
      label: "Estado", sortKey: "status", filter: { top: "Estado" },
      render: (event) => {
        const [label, tone] = stateOf(EVENT_STATES, event.status);
        return (
          <div>
            <Badge tone={tone}>{label}</Badge>
            {event.invoice && (
              <small className="block text-xs">
                <Link href={`/invoices/${event.invoice.id}`} className={LINK} onClick={(click) => click.stopPropagation()}>
                  {event.invoice.invoice_number ?? "borrador"}
                </Link>
              </small>
            )}
          </div>
        );
      },
    },
  ];

  /** Exporta las filas marcadas o, sin `picked`, todas las del filtro y búsqueda actuales. */
  async function runExport(format: "xlsx" | "csv", picked?: BillableEvent[]) {
    setExporting(true);
    try {
      const rows = picked ?? (await fetchAllPages<BillableEvent>("/v1/billable-events", applySort(columnFilters.apply(withTopFilters(new URLSearchParams(), { search, status, source })), sort)));
      // La API comprueba el permiso de exportar y deja la exportación en auditoría.
      await recordExport("billing", format, rows.length, Boolean(picked));
      if (format === "xlsx") await exportXlsx("eventos-facturables", "Eventos facturables", EXPORT_COLUMNS, rows);
      else exportCsv("eventos-facturables", EXPORT_COLUMNS, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el listado.", "danger");
    } finally {
      setExporting(false);
    }
  }

  const rows = data?.data ?? [];
  const count = selectedRows.length;
  const selectedTotal = selectedRows.reduce((sum, event) => sum + event.amount, 0);

  return (
    <Screen>
      <PageHead
        title="Eventos facturables"
        actions={
          <Btn onClick={() => setCreating(true)} permission="billing.create">
            <Plus size={16} />
            <span>Nuevo evento</span>
          </Btn>
        }
      />

      <Filters>
        <SearchInput value={searchInput} onChange={setSearchInput} placeholder="Buscar descripción o cliente" />
        <FilterSelect
          label="Estado"
          value={status}
          onChange={(value) => {
            setStatus(value);
            setPage(1);
          }}
          options={EVENT_STATES.map(([key, label]) => [key, label])}
        />
        <FilterSelect
          label="Origen"
          value={source}
          onChange={(value) => {
            setSource(value);
            setPage(1);
          }}
          options={EVENT_SOURCES}
        />
        <ColumnFilterBar
          filters={columnFilters}
          hasOtherFilters={Boolean(searchInput || status || source)}
          onClearAll={() => {
            setSearchInput("");
            setSearch("");
            setStatus("");
            setSource("");
            setPage(1);
          }}
        />
        <ScopeNote resource="billing" />
      </Filters>

      {error ? (
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
      ) : (
        <Card flush>
          {count > 0 && (
            // La barra del prototipo: lo seleccionado, su total, su cliente y qué comprobante generar.
            <div className="flex flex-wrap items-center gap-2 border-b border-[color:var(--p-brand-line)] bg-[var(--p-brand-soft)] px-3.5 py-2 text-[13px] text-[color:var(--p-brand-soft-text)]">
              <span className="grid h-6 min-w-6 place-items-center rounded-full bg-[#1B8FC9] px-1.5 text-xs font-semibold text-[#fff]">{count}</span>
              <span className="mr-auto font-medium">
                {count === 1 ? "seleccionado" : "seleccionados"}
                {currencies.size === 1 && <> · {money(selectedTotal, selectedRows[0].currency)}</>}
                {" · "}
                {customers.size > 1 ? <span className="text-[color:var(--p-danger-text)]">clientes distintos</span> : customerName(selectedRows[0].customer)}
              </span>
              <Btn size="sm" disabled={busy || Boolean(blocked)} title={blocked} onClick={() => invoice("INVOICE")} permission="billing.create">Generar factura</Btn>
              <Btn variant="secondary" size="sm" disabled={busy || Boolean(blocked)} title={blocked} onClick={() => invoice("RECEIPT")} permission="billing.create">Generar boleta</Btn>
              <Btn variant="secondary" size="sm" disabled={exporting} onClick={() => runExport("xlsx", selectedRows)} permission="billing.export">
                <Upload size={14} />
                <span>{exporting ? "Exportando…" : "Exportar selección a Excel"}</span>
              </Btn>
              <Btn variant="secondary" size="sm" disabled={exporting} onClick={() => runExport("csv", selectedRows)} permission="billing.export">CSV</Btn>
              <button type="button" onClick={clearSelection} className="cursor-pointer px-1.5 text-xs font-medium underline-offset-2 hover:underline">
                Limpiar
              </button>
            </div>
          )}
          <DataTable
            tableId="billable-events" sort={sort} onSort={(next) => { setSort(next); setPage(1); }} refreshing={loading}
            onFilter={(column) => addColumnFilter(columnFilters, column)}
            cols={columns}
            rows={rows}
            rowKey={eventKey}
            selection={selection}
            onRowClick={setOpen}
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
              left={<ExportButtons permission="billing.export" total={data.total} busy={exporting} onExport={(format) => runExport(format)} />}
            />
          )}
        </Card>
      )}

      {open && (
        <Modal
          title="Evento facturable"
          onClose={() => setOpen(null)}
          footer={
            <>
              {open.status === "PENDING" && (
                <Btn variant="danger-ghost" onClick={() => setCancelling(open)} permission="billing.manage" disabled={busy}>Anular evento</Btn>
              )}
              <Btn variant="ghost" onClick={() => setOpen(null)}>Cerrar</Btn>
            </>
          }
        >
          <h4 className="text-[17px] font-semibold leading-tight">{open.description}</h4>
          <p className="app-muted mb-3 text-[13px]">{customerName(open.customer)}</p>
          {open.status === "CANCELLED" && open.cancel_reason && <Notice tone="warn"><strong className="font-semibold">Anulado:</strong> {open.cancel_reason}</Notice>}
          <Kv
            rows={[
              ["Estado", <Badge key="status" tone={stateOf(EVENT_STATES, open.status)[1]}>{stateOf(EVENT_STATES, open.status)[0]}</Badge>],
              ["Origen", sourceLabel(open.source_type)],
              ["Clave", <code key="key" className="rounded bg-[var(--app-surface-muted)] px-1 text-[12.5px]">{open.idempotency_key}</code>],
              ["Contrato", open.contract ? <Link key="contract" href={`/contracts/${open.contract.id}`} className={LINK}>{open.contract.contract_number}</Link> : "—"],
              ["Registrado", dateTime(open.created_at)],
              ["Base", money(open.base, open.currency)],
              ["Total c/IGV", <strong key="total" className="font-semibold">{money(open.amount, open.currency)}</strong>],
              [
                "Comprobante",
                open.invoice ? <Link key="invoice" href={`/invoices/${open.invoice.id}`} className={LINK}>{open.invoice.invoice_number ?? "Borrador"}</Link> : "Sin facturar",
              ],
            ]}
          />
        </Modal>
      )}
      {cancelling && (
        <ReasonModal
          title="Anular evento facturable"
          text={`${cancelling.description} · ${money(cancelling.amount, cancelling.currency)}`}
          label="Motivo"
          confirmLabel="Anular"
          busy={busy}
          onClose={() => setCancelling(null)}
          onConfirm={(reason) => cancel(cancelling, reason)}
        />
      )}
      {creating && (
        <EventFormModal
          onClose={() => setCreating(false)}
          onSaved={() => {
            setCreating(false);
            toast("Evento facturable registrado");
            setPage(1);
            setReload((n) => n + 1);
          }}
        />
      )}
      {toasts}
    </Screen>
  );
}

/** Evento registrado a mano: lo que se va a cobrar y no nace de una orden de trabajo. */
function EventFormModal({ onClose, onSaved }: { onClose: () => void; onSaved: () => void }) {
  const [options, setOptions] = useState<BillableEventOptions | null>(null);
  const [customerId, setCustomerId] = useState("");
  const [contractId, setContractId] = useState("");
  const [description, setDescription] = useState("");
  const [base, setBase] = useState("");
  const [currency, setCurrency] = useState<Currency>("PEN");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  // Las listas del formulario se piden al abrirlo.
  useEffect(() => {
    let cancelled = false;
    apiFetch<BillableEventOptions>("/v1/billable-events/options")
      .then((next) => { if (!cancelled) setOptions(next); })
      .catch(() => { if (!cancelled) setOptions({ customers: [], contracts: [] }); });
    return () => { cancelled = true; };
  }, []);

  const contracts = options?.contracts.filter((contract) => contract.customer_id === customerId) ?? [];
  const contract = contracts.find((item) => item.id === contractId);
  const amount = Number(base);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setSaving(true);
    setError("");
    setErrors({});
    try {
      await apiFetch("/v1/billable-events", {
        method: "POST",
        body: JSON.stringify({ customer_id: customerId, contract_id: contractId || null, description: description.trim(), base: amount, currency }),
      });
      onSaved();
    } catch (err) {
      const fields = fieldErrors(err);
      setErrors(fields);
      if (!Object.keys(fields).length) setError(firstError(err, "No se pudo registrar el evento."));
      setSaving(false);
    }
  }

  return (
    <Modal
      title="Nuevo evento facturable"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn type="submit" form="event-form" disabled={saving || !options} permission="billing.create">{saving ? "Guardando…" : "Registrar"}</Btn>
        </>
      }
    >
      <form id="event-form" onSubmit={submit}>
        {error && <Notice tone="warn">{error}</Notice>}
        <FormGrid>
          <Field label="Cliente" required span={3} error={errors.customer_id}>
            <PickSelect
              className={INPUT} ariaLabel="Cliente" searchable disabled={!options} value={customerId}
              onChange={(value) => {
                setCustomerId(value);
                setContractId("");
                const customer = options?.customers.find((item) => item.id === value);
                if (customer) setCurrency(customer.currency);
              }}
              options={[["", options ? "Seleccionar…" : "Cargando…"], ...(options?.customers ?? []).map((customer): [string, string] => [customer.id, customer.tax_id ? `${customer.name} · ${customer.tax_id}` : customer.name])]}
            />
          </Field>
          <Field label="Contrato" span={3} error={errors.contract_id} hint={customerId && !contracts.length ? "El cliente no tiene contratos vigentes a tu alcance." : "Opcional. El evento toma su moneda y su sede."}>
            <PickSelect
              className={INPUT} ariaLabel="Contrato" disabled={!customerId || !contracts.length} value={contractId}
              onChange={(value) => {
                setContractId(value);
                const chosen = contracts.find((item) => item.id === value);
                if (chosen) setCurrency(chosen.currency);
              }}
              options={[["", "Sin contrato"], ...contracts.map((item): [string, string] => [item.id, item.contract_number])]}
            />
          </Field>
          <Field label="Descripción" required span={3} error={errors.description} hint="Así saldrá en la línea del comprobante.">
            <input className={INPUT} value={description} onChange={(e) => setDescription(e.target.value)} maxLength={500} required />
          </Field>
          <Field label="Importe sin IGV" required error={errors.base}>
            <input className={INPUT} type="number" min="0.01" step="0.01" inputMode="decimal" value={base} onChange={(e) => setBase(e.target.value)} required />
          </Field>
          <Field label="Moneda" error={errors.currency} hint={contract ? "La del contrato." : undefined}>
            <PickSelect
              className={INPUT} ariaLabel="Moneda" disabled={Boolean(contract)} value={currency} onChange={(value) => setCurrency(value as Currency)}
              options={[["PEN", "Soles"], ["USD", "Dólares"]]}
            />
          </Field>
          <Field label="Total c/IGV">
            <output className={`${INPUT} flex items-center bg-[var(--app-surface-muted)] font-semibold tabular-nums`}>{amount > 0 ? money(Math.round(amount * IGV * 100) / 100, currency) : "—"}</output>
          </Field>
        </FormGrid>
      </form>
    </Modal>
  );
}
