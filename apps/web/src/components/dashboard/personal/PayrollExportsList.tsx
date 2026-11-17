"use client";

import { useEffect, useMemo, useState } from "react";
import { Eye, FileSpreadsheet } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { stateOf } from "@/lib/commercial";
import { fieldErrors, firstError } from "@/lib/crm";
import { exportCsv, exportXlsx, fetchAllPages, recordExport } from "@/lib/export-table";
import { PAYROLL_STATES, isoDay, minutesLabel } from "@/lib/personal";
import {
  Badge, Btn, Card, Chip, DataTable, ExportButtons, Field, FilterSelect, Filters, INPUT, LoadError, Notice, PAGE_SIZES, PageHead, Pager, Screen,
  SelectionBar, useRowSelection, useToasts, type Column,
} from "@/components/ui/proto";
import {
  PAYROLL_EXPORT_COLUMNS, PayrollActions, PayrollDetailModal, periodTitle, usePayrollActions,
} from "@/components/dashboard/personal/PayrollModals";
import { when } from "@/components/dashboard/personal/HrSyncShared";
import type { HrMode, PayrollExport, PayrollPreview } from "@/types/personal";

type ListResponse = {
  data: PayrollExport[]; current_page: number; last_page: number; per_page: number; total: number;
  mode: HrMode; suggested_period: string;
};

const rowKey = (row: PayrollExport) => row.id;

/** Los últimos 24 meses hasta el actual, del más reciente al más antiguo ("2026-11"). */
function recentPeriods(): string[] {
  const now = new Date();
  return Array.from({ length: 24 }, (_, index) => isoDay(new Date(now.getFullYear(), now.getMonth() - index, 1)).slice(0, 7));
}

function withFilters(params: URLSearchParams, period: string, status: string) {
  if (period) params.set("period", period);
  if (status) params.set("status", status);
  return params;
}

export default function PayrollExportsList() {
  const { toast, toasts } = useToasts();
  const periods = useMemo(() => recentPeriods(), []);

  // Generar envío
  const [period, setPeriod] = useState("");
  const [preview, setPreview] = useState<PayrollPreview | null>(null);
  const [previewing, setPreviewing] = useState(false);
  const [generating, setGenerating] = useState(false);
  const [periodError, setPeriodError] = useState("");

  // Listado
  const [filterPeriod, setFilterPeriod] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [reload, setReload] = useState(0);
  const [data, setData] = useState<ListResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [exporting, setExporting] = useState(false);
  const [open, setOpen] = useState<string | null>(null);
  const { selection, selectedRows, clear: clearSelection } = useRowSelection(rowKey);
  const { busy, start, deliverModal } = usePayrollActions(toast, () => setReload((n) => n + 1));

  useEffect(() => {
    let cancelled = false;
    async function load() {
      setLoading(true);
      const params = withFilters(new URLSearchParams({ per_page: String(perPage), page: String(page) }), filterPeriod, status);
      try {
        const response = await apiFetch<ListResponse>(`/v1/hr/payroll-exports?${params}`);
        if (!cancelled) {
          setData(response);
          setError("");
          // El mes que se propone generar: el anterior al actual (lo dice la API).
          setPeriod((current) => current || response.suggested_period);
        }
      } catch {
        if (!cancelled) setError("No se pudieron cargar los envíos.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [page, perPage, filterPeriod, status, reload]);

  async function loadPreview(target = period) {
    if (!target) return;
    setPreviewing(true);
    setPeriodError("");
    try {
      setPreview(await apiFetch<PayrollPreview>(`/v1/hr/payroll-exports/preview?period=${encodeURIComponent(target)}`));
    } catch (err) {
      setPreview(null);
      const fields = fieldErrors(err);
      if (fields.period) setPeriodError(fields.period);
      else toast(firstError(err, "No se pudo calcular la vista previa."), "danger");
    } finally {
      setPreviewing(false);
    }
  }

  async function generate() {
    if (!preview) return;
    setGenerating(true);
    setPeriodError("");
    try {
      const created = await apiFetch<PayrollExport>("/v1/hr/payroll-exports", { method: "POST", body: JSON.stringify({ period: preview.period }) });
      const [label, tone] = stateOf(PAYROLL_STATES, created.status);
      toast(
        `Versión ${created.version} de ${periodTitle(created.period)} generada · ${label}`,
        tone === "danger" ? "danger" : "success",
        created.provider_response ?? undefined,
      );
      setReload((n) => n + 1);
      loadPreview(preview.period);
    } catch (err) {
      const fields = fieldErrors(err);
      if (fields.period) setPeriodError(fields.period);
      toast(firstError(err, "No se pudo generar el envío."), "danger");
    } finally {
      setGenerating(false);
    }
  }

  async function runExport(format: "xlsx" | "csv", picked?: PayrollExport[]) {
    setExporting(true);
    try {
      const rows = picked ?? (await fetchAllPages<PayrollExport>("/v1/hr/payroll-exports", withFilters(new URLSearchParams(), filterPeriod, status)));
      await recordExport("hr_sync", format, rows.length, Boolean(picked));
      if (format === "xlsx") await exportXlsx("envios-de-horas-a-planilla", "Envíos de horas a planilla", PAYROLL_EXPORT_COLUMNS, rows);
      else exportCsv("envios-de-horas-a-planilla", PAYROLL_EXPORT_COLUMNS, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el listado.", "danger");
    } finally {
      setExporting(false);
    }
  }

  const periodOptions: [string, string][] = periods.map((value) => [value, periodTitle(value)]);

  const cols: Column<PayrollExport>[] = [
    {
      label: "Periodo",
      render: (row) => (
        <div className="whitespace-nowrap">
          <strong className="font-semibold">{periodTitle(row.period)}</strong> <span className="app-muted text-xs">v{row.version}</span>
          <small className="app-muted block text-xs">{row.destination_label}</small>
        </div>
      ),
    },
    {
      label: "Horas",
      render: (row) => (
        <div className="whitespace-nowrap">
          {minutesLabel(row.minutes_total)}
          <small className="app-muted block text-xs">{row.employee_count} {row.employee_count === 1 ? "trabajador" : "trabajadores"}</small>
        </div>
      ),
    },
    {
      label: "Estado",
      render: (row) => {
        const [label, tone] = stateOf(PAYROLL_STATES, row.status);
        return (
          <div className="grid max-w-[240px] gap-0.5">
            <span><Badge tone={tone}>{label}</Badge></span>
            {row.sent_at && <small className="app-muted block text-xs">Enviado {when(row.sent_at)}</small>}
            {row.acknowledged_at && <small className="app-muted block text-xs">Recibido {when(row.acknowledged_at)}</small>}
            {row.provider_response && <small className="app-muted block truncate text-xs" title={row.provider_response}>{row.provider_response}</small>}
          </div>
        );
      },
    },
    {
      label: "Generado",
      render: (row) => (
        <div className="min-w-[120px] text-[12.5px]">
          {row.generated_by ?? "—"}
          <small className="app-muted block text-xs">{when(row.created_at)}</small>
        </div>
      ),
    },
    {
      label: "Acciones",
      render: (row) => (
        <span onClick={(e) => e.stopPropagation()}>
          <PayrollActions item={row} busy={busy} onAction={(action) => start(row, action)} />
        </span>
      ),
    },
  ];

  const rows = data?.data ?? [];
  const toHr = data?.mode === "HR_SAAS_MASTER";

  return (
    <Screen>
      <PageHead title="Envío de horas a planilla" />

      <Card
        title="Generar envío"
        actions={data && <Chip tone={toHr ? "accent" : "neutral"}>{toHr ? "Destino: SaaS de RRHH" : "Destino: archivo para planilla"}</Chip>}
      >
        <div className="flex flex-wrap items-end gap-2.5">
          <div className="min-w-0 flex-[1_1_220px] sm:max-w-[260px]">
            <Field label="Mes" error={periodError}>
              <select
                className={`${INPUT} cursor-pointer`}
                value={period}
                onChange={(e) => { setPeriod(e.target.value); setPreview(null); setPeriodError(""); }}
                disabled={!data}
              >
                {!period && <option value="">Seleccionar…</option>}
                {periodOptions.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
              </select>
            </Field>
          </div>
          <Btn variant="secondary" onClick={() => loadPreview()} disabled={!period || previewing} permission="hr_sync.view" className={periodError ? "mb-[22px]" : ""}>
            <Eye size={15} />
            <span>{previewing ? "Calculando…" : "Vista previa"}</span>
          </Btn>
        </div>

        {preview && preview.period === period && (
          <div className="mt-4 grid gap-3">
            <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-4">
              {([
                ["Destino", preview.destination === "HR_SAAS" ? "SaaS de RRHH" : "Archivo para planilla"],
                ["Trabajadores", String(preview.employee_count)],
                ["Horas", minutesLabel(preview.minutes_total)],
                ["Versión", `v${preview.next_version}`],
              ] as [string, string][]).map(([label, value]) => (
                <div key={label} className="min-w-0 rounded-xl bg-[var(--app-surface-muted)] px-3 py-2.5">
                  <span className="app-muted block text-[10.5px] font-semibold uppercase tracking-[.04em]">{label}</span>
                  <strong className="block text-[16px] font-semibold tabular-nums [overflow-wrap:anywhere]">{value}</strong>
                </div>
              ))}
            </div>
            {(preview.pending_hours > 0 || preview.unlinked.length > 0 || preview.current_month) && (
              <Notice tone="warn">
                <ul className="grid gap-0.5">
                  {preview.current_month && <li>El mes aún no termina: las horas que se aprueben después irán en otra versión.</li>}
                  {preview.pending_hours > 0 && (
                    <li>
                      {preview.pending_hours} {preview.pending_hours === 1 ? "registro de horas aún sin aprobar no entra" : "registros de horas aún sin aprobar no entran"}.
                    </li>
                  )}
                  {preview.unlinked.length > 0 && <li>Sin código en el SaaS de RRHH (no se envían): {preview.unlinked.join(", ")}.</li>}
                </ul>
              </Notice>
            )}
            <div>
              <Btn onClick={generate} disabled={generating} permission="hr_sync.create">
                <FileSpreadsheet size={15} />
                <span>{generating ? "Generando…" : `Generar versión ${preview.next_version}`}</span>
              </Btn>
            </div>
          </div>
        )}
      </Card>

      <Filters>
        <FilterSelect label="Periodo" value={filterPeriod} onChange={(value) => { setFilterPeriod(value); setPage(1); }} options={periodOptions} />
        <FilterSelect label="Estado" value={status} onChange={(value) => { setStatus(value); setPage(1); }} options={PAYROLL_STATES.map(([key, label]) => [key, label])} />
      </Filters>

      {error ? (
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
      ) : (
        <Card flush>
          <SelectionBar permission="hr_sync.export" count={selectedRows.length} busy={exporting} onExport={(format) => runExport(format, selectedRows)} onClear={clearSelection} />
          <DataTable
            tableId="payroll-exports"
            refreshing={loading}
            cols={cols}
            rows={rows}
            rowKey={rowKey}
            selection={selection}
            onRowClick={(row) => setOpen(row.id)}
            loading={loading && !data}
            empty={filterPeriod || status ? "No hay envíos con estos filtros." : "Aún no se ha generado ningún envío."}
          />
          {data && (
            <Pager
              loading={loading}
              page={data.current_page}
              lastPage={data.last_page}
              total={data.total}
              perPage={data.per_page}
              onPage={setPage}
              onPerPage={(size) => { setPerPage(size); setPage(1); }}
              left={<ExportButtons permission="hr_sync.export" total={data.total} busy={exporting} onExport={(format) => runExport(format)} />}
            />
          )}
        </Card>
      )}

      {open && (
        <PayrollDetailModal
          exportId={open}
          toast={toast}
          onClose={() => setOpen(null)}
          onChanged={() => setReload((n) => n + 1)}
        />
      )}
      {deliverModal}
      {toasts}
    </Screen>
  );
}
