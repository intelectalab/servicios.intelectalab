"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Play } from "lucide-react";
import { apiFetch, apiFetchLater } from "@/lib/api";
import { customerName, excelTone, money, shortDate, stateOf } from "@/lib/commercial";
import { firstError } from "@/lib/crm";
import { RECURRING_STATES, frequencyLabel } from "@/lib/finance";
import { useAccess } from "@/lib/access-context";
import { exportCsv, exportXlsx, recordExport, fetchAllPages, type ExportColumn } from "@/lib/export-table";
import {
  Badge, Btn, Card, Chip, DataTable, ExportButtons, FilterSelect, Filters, Kv, LoadError, Modal, Notice, PAGE_SIZES, PageHead, Pager, Screen,
  SearchInput, SelectionBar, useRowSelection, useToasts, type Column,
  applySort, type SortState,
} from "@/components/ui/proto";
import { ScopeNote } from "@/components/dashboard/ScopeNote";
import { ColumnFilterBar, addColumnFilter, applyFilters, useServerColumnFilters } from "@/components/dashboard/ColumnFilters";
import type { PaginatedResponse } from "@/types/customer";
import type { CycleRunSummary, RecurringInstance, RecurringSummary } from "@/types/finance";

const LINK = "text-[color:var(--p-brand-text)] hover:underline";

const instanceKey = (instance: RecurringInstance) => instance.id;

const COLUMNS: Column<RecurringInstance>[] = [
  {
    label: "Servicio", sortKey: "service", filter: "text",
    render: (instance) => (
      <div className="min-w-[180px]">
        <strong className="font-semibold">{instance.service_name ?? "—"}</strong>
        <small className="app-muted block text-xs">{customerName(instance.customer)}</small>
      </div>
    ),
  },
  { label: "Frecuencia", sortKey: "frequency", render: (instance) => frequencyLabel(instance.frequency, instance.interval_value) },
  {
    label: "Precio snapshot", num: true, sortKey: "amount", filter: "number",
    render: (instance) => (
      <div className="whitespace-nowrap">
        {money(instance.amount, instance.currency)}
        <small className="app-muted block text-xs">c/IGV por ciclo</small>
      </div>
    ),
  },
  {
    label: "Próximo ciclo", sortKey: "next", filter: "date",
    render: (instance) => (
      <span className="inline-flex flex-wrap items-center gap-1.5 whitespace-nowrap">
        {shortDate(instance.next_cycle_date)}
        {instance.due && <Chip tone="warning">Vencido</Chip>}
      </span>
    ),
  },
  {
    label: "Fin de vigencia", sortKey: "end", filter: "date",
    render: (instance) => (
      <span className="inline-flex flex-wrap items-center gap-1.5 whitespace-nowrap">
        {shortDate(instance.end_date)}
        {instance.renewal_due && <Chip tone="warning">Por renovar</Chip>}
      </span>
    ),
  },
  { label: "Ciclos", num: true, sortKey: "cycles", filter: "number", render: (instance) => instance.cycles_count },
  {
    label: "Estado", sortKey: "status", filter: { top: "Estado" },
    render: (instance) => <Badge tone={stateOf(RECURRING_STATES, instance.status)[1]}>{stateOf(RECURRING_STATES, instance.status)[0]}</Badge>,
  },
];

const EXPORT_COLUMNS: ExportColumn<RecurringInstance>[] = [
  { label: "Servicio", value: (instance) => instance.service_name ?? "", width: 32 },
  { label: "Cliente", value: (instance) => customerName(instance.customer), width: 34 },
  { label: "Frecuencia", value: (instance) => frequencyLabel(instance.frequency, instance.interval_value), width: 16, kind: "center" },
  { label: "Moneda", value: (instance) => instance.currency, width: 10, kind: "center" },
  { label: "Precio unitario (sin IGV)", value: (instance) => instance.unit_price, width: 20, kind: "money", currency: (instance) => instance.currency },
  { label: "Cantidad", value: (instance) => instance.quantity, width: 11, kind: "number" },
  { label: "Monto por ciclo (c/IGV)", value: (instance) => instance.amount, width: 20, kind: "money", currency: (instance) => instance.currency },
  { label: "Próximo ciclo", value: (instance) => (instance.next_cycle_date ? shortDate(instance.next_cycle_date) : ""), width: 15, kind: "center" },
  { label: "Inicio de vigencia", value: (instance) => (instance.start_date ? shortDate(instance.start_date) : ""), width: 16, kind: "center" },
  { label: "Fin de vigencia", value: (instance) => (instance.end_date ? shortDate(instance.end_date) : ""), width: 16, kind: "center" },
  { label: "Ciclos", value: (instance) => instance.cycles_count, width: 10, kind: "number" },
  {
    label: "Estado", value: (instance) => stateOf(RECURRING_STATES, instance.status)[0], width: 15, kind: "status",
    tone: (instance) => excelTone(stateOf(RECURRING_STATES, instance.status)[1]),
  },
];

type TopFilters = { search: string; status: string };

function withTopFilters(params: URLSearchParams, top: TopFilters) {
  if (top.search) params.set("search", top.search);
  if (top.status) params.set("status", top.status);
  return params;
}

/** Lo que dejó una pasada del proceso de ciclos. */
export function CycleRunModal({ summary, onClose }: { summary: CycleRunSummary; onClose: () => void }) {
  const { can } = useAccess();
  return (
    <Modal title="Resultado del job de ciclos" size="sm" onClose={onClose} footer={<Btn variant="ghost" onClick={onClose}>Cerrar</Btn>}>
      <Kv
        rows={[
          ["Ciclos procesados", <strong key="created" className="font-semibold tabular-nums">{summary.created}</strong>],
          ["Omitidos por idempotencia (ya procesados)", summary.skipped_idem],
          ["Omitidos por estado (suspendida/vencida)", summary.skipped_status],
          ["Fallidos (reintentables con la misma clave)", summary.failed],
        ]}
      />
      {summary.created > 0 ? (
        <p>
          Se {summary.created === 1 ? "generó 1 evento facturable" : `generaron ${summary.created} eventos facturables`}.{" "}
          {can("billing.view") && <Link href="/billable-events" className={LINK}>Ir a facturar</Link>}
        </p>
      ) : (
        <p className="app-muted">No se duplicó ningún ciclo.</p>
      )}
    </Modal>
  );
}

export default function RecurringList() {
  const router = useRouter();
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
  const { selection, selectedRows, clear: clearSelection } = useRowSelection(instanceKey);

  const [data, setData] = useState<PaginatedResponse<RecurringInstance> | null>(null);
  const [summary, setSummary] = useState<RecurringSummary | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const [running, setRunning] = useState(false);
  const [result, setResult] = useState<CycleRunSummary | null>(null);

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
        const response = await apiFetch<PaginatedResponse<RecurringInstance>>(`/v1/recurring?${params}`);
        if (!cancelled) {
          setData(response);
          setError("");
        }
      } catch {
        if (!cancelled) setError("No se pudieron cargar los servicios recurrentes.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [page, perPage, search, status, columnFilters.param, sort, reload]);

  // Ciclos vencidos y avisos de renovación: después del listado.
  useEffect(() => {
    let cancelled = false;
    apiFetchLater<RecurringSummary>("/v1/recurring/summary")
      .then((next) => { if (!cancelled) setSummary(next); })
      .catch(() => undefined);
    return () => { cancelled = true; };
  }, [reload]);

  async function runCycles() {
    setRunning(true);
    try {
      setResult(await apiFetch<CycleRunSummary>("/v1/recurring/run-cycles", { method: "POST" }));
      setReload((n) => n + 1);
    } catch (err) {
      toast(firstError(err, "No se pudieron procesar los ciclos."), "danger");
    } finally {
      setRunning(false);
    }
  }

  /** Exporta las filas marcadas o, sin `picked`, todas las del filtro y búsqueda actuales. */
  async function runExport(format: "xlsx" | "csv", picked?: RecurringInstance[]) {
    setExporting(true);
    try {
      const rows = picked ?? (await fetchAllPages<RecurringInstance>("/v1/recurring", applySort(columnFilters.apply(withTopFilters(new URLSearchParams(), { search, status })), sort)));
      // La API comprueba el permiso de exportar y deja la exportación en auditoría.
      await recordExport("recurring", format, rows.length, Boolean(picked));
      if (format === "xlsx") await exportXlsx("servicios-recurrentes", "Servicios recurrentes", EXPORT_COLUMNS, rows);
      else exportCsv("servicios-recurrentes", EXPORT_COLUMNS, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el listado.", "danger");
    } finally {
      setExporting(false);
    }
  }

  const alerts = summary?.alerts ?? [];

  return (
    <Screen>
      <PageHead
        title="Servicios recurrentes"
        actions={
          <Btn onClick={runCycles} disabled={running} permission="recurring.create">
            <Play size={16} />
            <span>{running ? "Procesando…" : `Ejecutar job de ciclos${summary?.due ? ` (${summary.due} ${summary.due === 1 ? "vencido" : "vencidos"})` : ""}`}</span>
          </Btn>
        }
      />

      {summary && summary.alerts_total > 0 && (
        <Notice tone="warn">
          <strong className="font-semibold">
            {summary.alerts_total} {summary.alerts_total === 1 ? "servicio" : "servicios"} por renovar en {summary.alert_days} días
          </strong>
          <p className="mt-0.5">
            {alerts.map((alert, index) => (
              <span key={alert.id}>
                {index > 0 && " · "}
                <Link href={`/recurring/${alert.id}`} className="underline underline-offset-2 hover:no-underline">
                  {customerName(alert.customer)} · {alert.service_name ?? "—"} (vence {shortDate(alert.end_date)})
                </Link>
              </span>
            ))}
            {summary.alerts_total > alerts.length && ` · y ${summary.alerts_total - alerts.length} más`}
          </p>
        </Notice>
      )}

      <Filters>
        <SearchInput value={searchInput} onChange={setSearchInput} placeholder="Buscar cliente o servicio" />
        <FilterSelect
          label="Estado"
          value={status}
          onChange={(value) => {
            setStatus(value);
            setPage(1);
          }}
          options={RECURRING_STATES.map(([key, label]): [string, string] => [key, label])}
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
        <ScopeNote resource="recurring" />
      </Filters>

      {error ? (
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
      ) : (
        <Card flush>
          <SelectionBar permission="recurring.export" count={selectedRows.length} busy={exporting} onExport={(format) => runExport(format, selectedRows)} onClear={clearSelection} />
          <DataTable
            tableId="recurring" sort={sort} onSort={(next) => { setSort(next); setPage(1); }} refreshing={loading}
            onFilter={(column) => addColumnFilter(columnFilters, column)}
            cols={COLUMNS}
            rows={data?.data ?? []}
            rowKey={instanceKey}
            selection={selection}
            onRowClick={(instance) => router.push(`/recurring/${instance.id}`)}
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
              left={<ExportButtons permission="recurring.export" total={data.total} busy={exporting} onExport={(format) => runExport(format)} />}
            />
          )}
        </Card>
      )}

      {result && <CycleRunModal summary={result} onClose={() => setResult(null)} />}
      {toasts}
    </Screen>
  );
}
