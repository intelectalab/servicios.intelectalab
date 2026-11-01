"use client";

import { useEffect, useState } from "react";
import { ChevronRight, Filter, Pencil, Play, Plus, Sparkles, Zap } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { excelTone, stateOf } from "@/lib/commercial";
import { dateTime, firstError } from "@/lib/crm";
import { useAccess } from "@/lib/access-context";
import { RUN_STATES, TEMPLATES, conditionText, type WorkflowRule, type WorkflowRun, type WorkflowsData } from "@/lib/workflows";
import { exportCsv, exportXlsx, fetchAllPages, recordExport, type ExportColumn } from "@/lib/export-table";
import {
  Badge, Btn, Card, Chip, DataTable, ExportButtons, FilterSelect, Filters, LoadError, PAGE_SIZES, PageHead, Pager, Screen,
  applySort, deniedText, useToasts, type Column, type SortState,
} from "@/components/ui/proto";
import { ColumnFilterBar, addColumnFilter, applyFilters, useServerColumnFilters } from "@/components/dashboard/ColumnFilters";
import { WorkflowModal, type WorkflowDraft } from "@/components/dashboard/workflows/WorkflowModal";
import type { PaginatedResponse } from "@/types/customer";

const runKey = (run: WorkflowRun) => run.id;

const EXPORT_COLUMNS: ExportColumn<WorkflowRun>[] = [
  { label: "Workflow", value: (r) => r.workflow, width: 34 },
  { label: "Disparador", value: (r) => r.trigger_label, width: 24 },
  { label: "Registro", value: (r) => r.record ?? "", width: 34 },
  { label: "Condición", value: (r) => (r.matched === null ? "" : r.matched ? "Cumplida" : "No cumplida"), width: 14, kind: "center" },
  { label: "Estado", value: (r) => stateOf(RUN_STATES, r.status)[0], width: 14, kind: "status", tone: (r) => excelTone(stateOf(RUN_STATES, r.status)[1]) },
  { label: "Resultado", value: (r) => r.result ?? "", width: 44 },
  { label: "Fecha", value: (r) => dateTime(r.at), width: 22, kind: "center" },
];

/** Interruptor de activa/inactiva; sin permiso queda deshabilitado con su motivo. */
function Switch({ on, disabled, title, onChange, label }: { on: boolean; disabled: boolean; title?: string; onChange: (next: boolean) => void; label: string }) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={on}
      aria-label={label}
      title={title ?? (on ? "Activo" : "Inactivo")}
      disabled={disabled}
      onClick={() => onChange(!on)}
      className={`relative h-6 w-11 shrink-0 cursor-pointer rounded-full transition-colors disabled:cursor-not-allowed disabled:opacity-50 ${on ? "bg-[var(--p-accent,#1B8FC9)]" : "bg-[var(--app-border)]"}`}
    >
      <span className={`absolute top-0.5 h-5 w-5 rounded-full bg-[#fff] shadow transition-[left] ${on ? "left-[22px]" : "left-0.5"}`} />
    </button>
  );
}

function Node({ icon, step, children, tone }: { icon: React.ReactNode; step: string; children: React.ReactNode; tone: string }) {
  return (
    <span className={`inline-flex min-w-0 max-w-full items-start gap-1.5 rounded-lg px-2.5 py-1.5 text-[12.5px] ${tone}`}>
      <span className="mt-0.5 shrink-0">{icon}</span>
      <span className="min-w-0">
        <small className="block text-[10px] font-semibold uppercase tracking-[.05em] opacity-80">{step}</small>
        <b className="font-semibold [overflow-wrap:anywhere]">{children}</b>
      </span>
    </span>
  );
}

export default function WorkflowsView() {
  const { can, loading: accessLoading } = useAccess();
  const canManage = !accessLoading && can("workflows.manage");
  const { toast, toasts } = useToasts();
  const [data, setData] = useState<WorkflowsData | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [draft, setDraft] = useState<WorkflowDraft | null>(null);
  const [toggling, setToggling] = useState<string | null>(null);

  // Ejecuciones
  const [runs, setRuns] = useState<PaginatedResponse<WorkflowRun> | null>(null);
  const [runsLoading, setRunsLoading] = useState(true);
  const [runsError, setRunsError] = useState("");
  const [workflowFilter, setWorkflowFilter] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [sort, setSort] = useState<SortState>(null);
  const [exporting, setExporting] = useState(false);
  const columnFilters = useServerColumnFilters(() => setPage(1));

  useEffect(() => {
    let cancelled = false;
    apiFetch<WorkflowsData>("/v1/workflows")
      .then((response) => { if (!cancelled) { setData(response); setError(""); } })
      .catch(() => { if (!cancelled) setError("No se pudieron cargar los workflows."); });
    return () => { cancelled = true; };
  }, [reload]);

  const runParams = (params: URLSearchParams) => {
    if (workflowFilter) params.set("workflow_id", workflowFilter);
    if (statusFilter) params.set("status", statusFilter);
    return params;
  };

  useEffect(() => {
    let cancelled = false;
    async function load() {
      setRunsLoading(true);
      const params = runParams(new URLSearchParams({ per_page: String(perPage), page: String(page) }));
      applySort(applyFilters(params, columnFilters.param), sort);
      try {
        const response = await apiFetch<PaginatedResponse<WorkflowRun>>(`/v1/workflow-runs?${params}`);
        if (!cancelled) { setRuns(response); setRunsError(""); }
      } catch {
        if (!cancelled) setRunsError("No se pudieron cargar las ejecuciones.");
      } finally {
        if (!cancelled) setRunsLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps -- runParams solo lee los dos filtros, que ya están en la lista
  }, [page, perPage, workflowFilter, statusFilter, columnFilters.param, sort, reload]);

  async function toggle(rule: WorkflowRule, active: boolean) {
    setToggling(rule.id);
    try {
      const saved = await apiFetch<WorkflowRule>(`/v1/workflows/${rule.id}/toggle`, { method: "POST", body: JSON.stringify({ active }) });
      setData((current) => current && { ...current, rules: current.rules.map((r) => (r.id === saved.id ? saved : r)) });
      toast(active ? "Workflow activado" : "Workflow desactivado");
    } catch (err) {
      toast(firstError(err, "No se pudo cambiar el workflow."), "danger");
    } finally {
      setToggling(null);
    }
  }

  async function runExport(format: "xlsx" | "csv") {
    setExporting(true);
    try {
      const rows = await fetchAllPages<WorkflowRun>("/v1/workflow-runs", applySort(columnFilters.apply(runParams(new URLSearchParams())), sort));
      await recordExport("workflows", format, rows.length);
      if (format === "xlsx") await exportXlsx("ejecuciones-workflows", "Ejecuciones de workflows", EXPORT_COLUMNS, rows);
      else exportCsv("ejecuciones-workflows", EXPORT_COLUMNS, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar.", "danger");
    } finally {
      setExporting(false);
    }
  }

  /** Abre el formulario con una plantilla; el rol se busca por su código o nombre en la empresa. */
  function applyTemplate(index: number) {
    if (!data) return;
    const template = TEMPLATES[index];
    setDraft({
      name: template.name,
      trigger: template.trigger,
      condition: template.condition,
      actions: template.actions.map(({ roleHint, ...action }) => {
        const role = roleHint ? data.roles.find((r) => r.code === roleHint || r.name.toUpperCase() === roleHint) : undefined;
        return { ...action, role_id: role?.id };
      }),
    });
  }

  const runCols: Column<WorkflowRun>[] = [
    { label: "Workflow", sortKey: "workflow", filter: "text", render: (r) => <span className="font-semibold">{r.workflow}</span> },
    { label: "Disparador", render: (r) => <code className="whitespace-nowrap text-[12px]">{r.trigger_label}</code> },
    { label: "Registro", sortKey: "record", filter: "text", render: (r) => <span className="[overflow-wrap:anywhere]">{r.record ?? "—"}</span> },
    { label: "Condición", render: (r) => (r.matched === null ? <span className="app-muted">—</span> : r.matched ? <Chip tone="success">Cumplida</Chip> : <Chip tone="muted">No cumplida</Chip>) },
    { label: "Estado", sortKey: "status", filter: { top: "Estado" }, render: (r) => { const [label, tone] = stateOf(RUN_STATES, r.status); return <Badge tone={tone}>{label}</Badge>; } },
    { label: "Resultado", sortKey: "result", filter: "text", render: (r) => <span className="app-muted text-[12.5px]">{r.result ?? "—"}</span> },
    { label: "Fecha", sortKey: "date", filter: "date", render: (r) => <span className="whitespace-nowrap">{dateTime(r.at)}</span> },
  ];

  return (
    <Screen>
      <PageHead
        title="Workflows"
        actions={
          <Btn permission="workflows.manage" disabled={!data} onClick={() => data && setDraft({ name: "", trigger: data.catalog.triggers[0]?.key ?? "", condition: null, actions: [] })}>
            <Plus size={16} />
            <span>Nuevo workflow</span>
          </Btn>
        }
      />

      {error ? (
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
      ) : !data ? (
        <Card><p className="app-muted">Cargando workflows…</p></Card>
      ) : (
        <>
          {data.rules.length === 0 && (
            <Card>
              <p className="font-semibold">Todavía no hay workflows.</p>
              <p className="app-muted mt-1 text-[13px]">Crea uno desde cero o empieza con una plantilla.</p>
            </Card>
          )}

          <div className="mb-[18px] grid gap-3 lg:grid-cols-2">
            {data.rules.map((rule) => (
              <section key={rule.id} className={`app-surface rounded-xl p-4 shadow-[0_1px_2px_rgba(16,24,40,.05),0_2px_8px_rgba(16,24,40,.06)] ${rule.active ? "" : "opacity-70"}`}>
                <div className="mb-3 flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <strong className="font-semibold">{rule.name}</strong>
                    {!rule.active && <small className="app-muted ml-2 text-xs">Inactivo</small>}
                  </div>
                  <Switch
                    on={rule.active}
                    label={`Activar ${rule.name}`}
                    disabled={!canManage || toggling === rule.id}
                    title={canManage ? undefined : deniedText("workflows.manage")}
                    onChange={(next) => toggle(rule, next)}
                  />
                </div>
                <div className="flex flex-wrap items-center gap-1.5">
                  <Node icon={<Zap size={13} />} step="Cuando" tone="bg-[var(--p-brand-soft)] text-[color:var(--p-brand-text)]">{rule.trigger_label}</Node>
                  <ChevronRight size={14} className="app-muted shrink-0" aria-hidden="true" />
                  <Node icon={<Filter size={13} />} step="Si" tone="bg-[var(--p-warning-soft)] text-[color:var(--p-warning-text)]">{conditionText(rule.condition, rule.trigger, data.catalog)}</Node>
                  {rule.actions.map((action, index) => (
                    <span key={index} className="contents">
                      <ChevronRight size={14} className="app-muted shrink-0" aria-hidden="true" />
                      <Node icon={<Play size={13} />} step="Entonces" tone="bg-[var(--p-success-soft)] text-[color:var(--p-success-text)]">
                        {data.catalog.actions[action.type]}{action.role ? ` · ${action.role}` : ""}
                      </Node>
                    </span>
                  ))}
                </div>
                <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
                  <p className="app-muted text-[12px]">
                    {rule.runs} {rule.runs === 1 ? "ejecución" : "ejecuciones"}
                    {rule.last_run_at && ` · última ${dateTime(rule.last_run_at)}`}
                    {!rule.module_on && <strong className="text-[color:var(--p-warning-text)]"> · inactivo porque el módulo está apagado</strong>}
                  </p>
                  <div className="flex gap-1.5">
                    <Btn variant="ghost" size="sm" onClick={() => { setWorkflowFilter(rule.id); setPage(1); document.getElementById("workflow-runs")?.scrollIntoView({ behavior: "smooth" }); }}>
                      Ver ejecuciones
                    </Btn>
                    <Btn variant="ghost" size="sm" permission="workflows.manage" onClick={() => setDraft({ id: rule.id, name: rule.name, trigger: rule.trigger, condition: rule.condition, actions: rule.actions })}>
                      <Pencil size={13} />
                      <span>Editar</span>
                    </Btn>
                  </div>
                </div>
              </section>
            ))}
          </div>

          {canManage && (
            <Card title={<span className="inline-flex items-center gap-2"><Sparkles size={16} className="text-[color:var(--p-brand)]" />Plantillas</span>}>
              <div className="grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                {TEMPLATES.map((template, index) => {
                  const trigger = data.catalog.triggers.find((t) => t.key === template.trigger);
                  return (
                    <div key={template.name} className="flex items-center justify-between gap-2 rounded-[10px] border border-[color:var(--app-border)] p-3">
                      <div className="min-w-0">
                        <p className="text-[13px] font-semibold">{template.name}</p>
                        <p className="app-muted text-xs">{trigger?.label}{trigger && !trigger.available ? " · módulo apagado" : ""}</p>
                      </div>
                      <Btn variant="secondary" size="sm" onClick={() => applyTemplate(index)}>Usar</Btn>
                    </div>
                  );
                })}
              </div>
            </Card>
          )}

          <div id="workflow-runs" className="scroll-mt-4">
            <Card title="Últimas ejecuciones" flush>
              <div className="px-5 pt-3">
                <Filters>
                  <FilterSelect label="Workflow" value={workflowFilter} onChange={(value) => { setWorkflowFilter(value); setPage(1); }} options={data.rules.map((r) => [r.id, r.name])} />
                  <FilterSelect label="Estado" value={statusFilter} onChange={(value) => { setStatusFilter(value); setPage(1); }} options={RUN_STATES.map(([key, label]) => [key, label])} />
                  <ColumnFilterBar
                    filters={columnFilters}
                    hasOtherFilters={Boolean(workflowFilter || statusFilter)}
                    onClearAll={() => { setWorkflowFilter(""); setStatusFilter(""); setPage(1); }}
                  />
                </Filters>
              </div>
              {runsError ? (
                <div className="px-5 pb-4"><LoadError text={runsError} onRetry={() => setReload((n) => n + 1)} /></div>
              ) : (
                <>
                  <DataTable
                    tableId="workflow-runs" sort={sort} onSort={(next) => { setSort(next); setPage(1); }} refreshing={runsLoading}
                    onFilter={(column) => addColumnFilter(columnFilters, column)}
                    cols={runCols}
                    rows={runs?.data ?? []}
                    rowKey={runKey}
                    loading={runsLoading && !runs}
                    empty="Aún no hay ejecuciones. Corren solas cuando ocurre el disparador de un workflow activo."
                  />
                  {runs && (
                    <Pager
                      loading={runsLoading}
                      page={runs.current_page}
                      lastPage={runs.last_page}
                      total={runs.total}
                      perPage={runs.per_page}
                      onPage={setPage}
                      onPerPage={(size) => { setPerPage(size); setPage(1); }}
                      left={<ExportButtons permission="workflows.export" total={runs.total} busy={exporting} onExport={runExport} />}
                    />
                  )}
                </>
              )}
            </Card>
          </div>
        </>
      )}

      {draft && data && (
        <WorkflowModal
          data={data}
          draft={draft}
          toast={toast}
          onClose={() => setDraft(null)}
          onSaved={() => setReload((n) => n + 1)}
        />
      )}
      {toasts}
    </Screen>
  );
}
