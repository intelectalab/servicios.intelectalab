"use client";

import { useEffect, useMemo, useRef, useState, type DragEvent } from "react";
import { Check, Download, FileSpreadsheet, Upload } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { APP_HEADERS } from "@/lib/auth";
import { excelTone, stateOf } from "@/lib/commercial";
import { dateTime, firstError } from "@/lib/crm";
import { exportCsv, exportXlsx, fetchAllPages, recordExport, type ExportColumn } from "@/lib/export-table";
import { JOB_STATES, ROW_STATES, type ImportEntity, type ImportJob, type ImportResult, type ImportRow, type ImportsData } from "@/lib/imports";
import {
  Badge, Btn, Card, Chip, DataTable, ExportButtons, Field, FilterSelect, Filters, Kv, LoadError, Modal, Notice, PAGE_SIZES, PageHead,
  Pager, Screen, applySort, useToasts, type Column, type SortState,
} from "@/components/ui/proto";
import { PickSelect } from "@/components/ui/pick-select";
import { ColumnFilterBar, addColumnFilter, applyFilters, useServerColumnFilters } from "@/components/dashboard/ColumnFilters";
import type { PaginatedResponse } from "@/types/customer";

const API_BASE = process.env.NEXT_PUBLIC_API_URL ?? "";

// La sesión viaja en la cookie HttpOnly (credentials: "include"); la API pide este encabezado para escribir.
const authHeaders = (): Record<string, string> => APP_HEADERS;

const jobKey = (job: ImportJob) => job.id;
const omitted = (job: ImportJob) => job.skipped + job.failed;

const EXPORT_COLUMNS: ExportColumn<ImportJob>[] = [
  { label: "Archivo", value: (j) => j.file, width: 36 },
  { label: "Qué se importó", value: (j) => j.entity_label, width: 26 },
  { label: "Usuario", value: (j) => j.user_name ?? "", width: 24 },
  { label: "Filas", value: (j) => j.total, width: 10, kind: "number" },
  { label: "Creados", value: (j) => j.created, width: 10, kind: "number" },
  { label: "Actualizados", value: (j) => j.updated, width: 12, kind: "number" },
  { label: "Omitidos", value: (j) => omitted(j), width: 10, kind: "number" },
  { label: "Estado", value: (j) => stateOf(JOB_STATES, j.status)[0], width: 16, kind: "status", tone: (j) => excelTone(stateOf(JOB_STATES, j.status)[1]) },
  { label: "Fecha", value: (j) => dateTime(j.at), width: 22, kind: "center" },
];

/** Una fila con su resultado; `saved` cuando ya se importó (historial). */
function RowResult({ row, saved = false }: { row: ImportRow; saved?: boolean }) {
  const [label, tone] = ROW_STATES[row.status];
  const text = saved && row.status === "updated" ? "Actualizada" : saved && row.status === "skipped" ? "Omitida" : label;
  return (
    <span className="inline-flex flex-col items-start gap-0.5">
      <Chip tone={tone}>{text}</Chip>
      {row.message && <small className={`text-[12px] ${row.status === "error" ? "text-[color:var(--p-danger-text)]" : "app-muted"}`}>{row.message}</small>}
    </span>
  );
}

export default function ImportsView() {
  const { toast, toasts } = useToasts();
  const [data, setData] = useState<ImportsData | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);

  const [entityKey, setEntityKey] = useState("");
  const [file, setFile] = useState<File | null>(null);
  const [pasted, setPasted] = useState("");
  const [dragging, setDragging] = useState(false);
  const [busy, setBusy] = useState<"template" | "validate" | "import" | null>(null);
  const [fileError, setFileError] = useState("");
  const [preview, setPreview] = useState<ImportResult | null>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  // Historial
  const [jobs, setJobs] = useState<PaginatedResponse<ImportJob> | null>(null);
  const [jobsLoading, setJobsLoading] = useState(true);
  const [jobsError, setJobsError] = useState("");
  const [entityFilter, setEntityFilter] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [sort, setSort] = useState<SortState>(null);
  const [exporting, setExporting] = useState(false);
  const [detail, setDetail] = useState<ImportJob | null>(null);
  const columnFilters = useServerColumnFilters(() => setPage(1));

  useEffect(() => {
    let cancelled = false;
    apiFetch<ImportsData>("/v1/imports")
      .then((response) => {
        if (cancelled) return;
        setData(response);
        setError("");
        setEntityKey((current) => current || response.entities[0]?.key || "");
      })
      .catch(() => { if (!cancelled) setError("No se pudo cargar Importación masiva."); });
    return () => { cancelled = true; };
  }, [reload]);

  const jobParams = (params: URLSearchParams) => {
    if (entityFilter) params.set("entity", entityFilter);
    if (statusFilter) params.set("status", statusFilter);
    return params;
  };

  useEffect(() => {
    let cancelled = false;
    async function load() {
      setJobsLoading(true);
      const params = jobParams(new URLSearchParams({ per_page: String(perPage), page: String(page) }));
      applySort(applyFilters(params, columnFilters.param), sort);
      try {
        const response = await apiFetch<PaginatedResponse<ImportJob>>(`/v1/import-jobs?${params}`);
        if (!cancelled) { setJobs(response); setJobsError(""); }
      } catch {
        if (!cancelled) setJobsError("No se pudo cargar el historial.");
      } finally {
        if (!cancelled) setJobsLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps -- jobParams solo lee los dos filtros, que ya están en la lista
  }, [page, perPage, entityFilter, statusFilter, columnFilters.param, sort, reload]);

  const entity: ImportEntity | undefined = data?.entities.find((e) => e.key === entityKey);

  function reset() {
    setFile(null);
    setPasted("");
    setPreview(null);
    setFileError("");
    if (inputRef.current) inputRef.current.value = "";
  }

  function pick(candidate: File | undefined) {
    if (!candidate) return;
    if (!/\.(xlsx|xls|csv|txt)$/i.test(candidate.name)) {
      setFileError("Formato no admitido. Usa un archivo .xlsx o .csv.");
      return;
    }
    setFile(candidate);
    setPasted("");
    setPreview(null);
    setFileError("");
  }

  /** El archivo elegido o, si se pegó el contenido, ese texto como CSV. */
  function upload(): File | null {
    if (file) return file;
    if (pasted.trim()) return new File([pasted], "contenido-pegado.csv", { type: "text/csv" });
    return null;
  }

  async function downloadTemplate() {
    if (!entity) return;
    setBusy("template");
    setFileError("");
    try {
      const response = await fetch(`${API_BASE}${entity.endpoint}/template`, { headers: authHeaders(), credentials: "include" });
      if (!response.ok) throw new Error();
      const url = URL.createObjectURL(await response.blob());
      const link = document.createElement("a");
      link.href = url;
      link.download = entity.template;
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);
    } catch {
      setFileError("No se pudo descargar la plantilla.");
    } finally {
      setBusy(null);
    }
  }

  async function send(dryRun: boolean) {
    const chosen = upload();
    if (!entity || !chosen) {
      setFileError("Elige un archivo o pega su contenido.");
      return;
    }
    setBusy(dryRun ? "validate" : "import");
    setFileError("");
    try {
      const body = new FormData();
      body.append("file", chosen);
      if (dryRun) body.append("dry_run", "1");
      const response = await fetch(`${API_BASE}${entity.endpoint}/import`, { method: "POST", credentials: "include", headers: { ...authHeaders(), Accept: "application/json" }, body });
      const result = await response.json().catch(() => null);

      if (dryRun) {
        if (!response.ok) {
          setPreview(null);
          setFileError(result?.errors?.file?.[0] ?? result?.message ?? `Error ${response.status} al leer el archivo.`);
          return;
        }
        setPreview(result as ImportResult);
        return;
      }

      setPage(1);
      setReload((n) => n + 1);
      if (!response.ok) {
        setFileError(result?.message ?? `Error ${response.status} al importar.`);
        if (result?.rows) setPreview({ ...(preview as ImportResult), rows: result.rows });
        return;
      }
      toast(result?.message ?? "Importación terminada");
      reset();
    } catch {
      setFileError("No se pudo conectar con el servidor.");
    } finally {
      setBusy(null);
    }
  }

  async function openJob(job: ImportJob) {
    setDetail(job);
    try {
      setDetail(await apiFetch<ImportJob>(`/v1/import-jobs/${job.id}`));
    } catch (err) {
      toast(firstError(err, "No se pudo abrir la importación."), "danger");
      setDetail(null);
    }
  }

  async function runExport(format: "xlsx" | "csv") {
    setExporting(true);
    try {
      const rows = await fetchAllPages<ImportJob>("/v1/import-jobs", applySort(columnFilters.apply(jobParams(new URLSearchParams())), sort));
      await recordExport("imports", format, rows.length);
      if (format === "xlsx") await exportXlsx("historial-importaciones", "Historial de importaciones", EXPORT_COLUMNS, rows);
      else exportCsv("historial-importaciones", EXPORT_COLUMNS, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar.", "danger");
    } finally {
      setExporting(false);
    }
  }

  const counts = useMemo(() => {
    const rows = preview?.rows ?? [];
    return {
      ready: rows.filter((r) => r.status === "created" || r.status === "updated").length,
      skipped: rows.filter((r) => r.status === "skipped").length,
      errors: rows.filter((r) => r.status === "error").length,
    };
  }, [preview]);
  const previewKeys = preview?.rows[0] ? Object.keys(preview.rows[0].values) : [];

  const jobCols: Column<ImportJob>[] = [
    {
      label: "Archivo", sortKey: "file", filter: "text",
      render: (j) => (
        <span className="block min-w-[150px]">
          <strong className="block font-semibold [overflow-wrap:anywhere]">{j.file}</strong>
          <small className="app-muted block text-[12px]">{j.entity_label} · {dateTime(j.at)}</small>
        </span>
      ),
    },
    { label: "Creados", sortKey: "created", filter: "number", render: (j) => <span className="tabular-nums">{j.created}</span> },
    { label: "Omitidos", sortKey: "skipped", filter: "number", render: (j) => <span className="tabular-nums">{omitted(j)}</span> },
    { label: "Estado", sortKey: "status", filter: { top: "Estado" }, render: (j) => { const [label, tone] = stateOf(JOB_STATES, j.status); return <Badge tone={tone}>{label}</Badge>; } },
  ];

  return (
    <Screen>
      <PageHead title="Importación masiva" />

      {error ? (
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
      ) : !data ? (
        <Card><p className="app-muted">Cargando…</p></Card>
      ) : (
        <div className="grid gap-x-[18px] xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
          <div className="min-w-0">
            {data.entities.length === 0 ? (
              <Card>
                <p className="font-semibold">Sin entidades disponibles para tu rol</p>
                <p className="app-muted mt-1 text-[13px]">Para importar necesitas el permiso de crear de lo que vas a importar (clientes, servicios o leads).</p>
              </Card>
            ) : (
              <>
                <Card title="1. Archivo">
                  <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Qué vas a importar">
                      <PickSelect
                        value={entityKey}
                        onChange={(value) => { setEntityKey(value); reset(); }}
                        options={data.entities.map((e) => [e.key, e.label])}
                        ariaLabel="Qué vas a importar"
                        className="h-10 w-full"
                      />
                    </Field>
                  </div>
                  {entity && <Notice><p className="text-[12.5px]">{entity.hint}</p></Notice>}

                  <label
                    onDragOver={(event) => { event.preventDefault(); setDragging(true); }}
                    onDragLeave={() => setDragging(false)}
                    onDrop={(event: DragEvent<HTMLLabelElement>) => { event.preventDefault(); setDragging(false); pick(event.dataTransfer.files?.[0]); }}
                    className={`relative mt-3 flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed px-4 py-6 text-center transition-colors ${
                      dragging ? "border-[#1B8FC9] bg-[var(--p-brand-soft)]" : "border-[color:var(--app-border)] hover:bg-[var(--app-surface-muted)]"
                    }`}
                  >
                    {file ? <FileSpreadsheet size={26} className="mb-2 text-[color:var(--p-brand)]" /> : <Upload size={26} className="app-muted mb-2" />}
                    <span className="font-semibold text-[color:var(--p-brand-text)]">{file ? file.name : "Haz clic para buscar tu archivo o arrástralo aquí"}</span>
                    <span className="app-muted mt-1 text-xs">{file ? "Haz clic para cambiarlo" : "Excel (.xlsx) o CSV separado por ; o , (máximo 5 MB)"}</span>
                    <input ref={inputRef} type="file" accept=".xlsx,.xls,.csv,.txt" className="sr-only" onChange={(event) => pick(event.target.files?.[0])} />
                  </label>

                  <Field label="O pega el contenido (CSV)">
                    <textarea
                      value={pasted}
                      onChange={(event) => { setPasted(event.target.value); if (event.target.value) setFile(null); setPreview(null); }}
                      rows={6}
                      placeholder={entity?.columns.join(";")}
                      className="app-input w-full rounded-lg border border-[color:var(--app-border)] bg-[var(--app-surface)] px-3 py-2 font-mono text-[12.5px]"
                    />
                  </Field>

                  {fileError && <p className="mt-2 text-[12.5px] font-medium text-[color:var(--p-danger-text)]">{fileError}</p>}

                  <div className="mt-3 flex flex-wrap justify-end gap-2">
                    <Btn variant="ghost" onClick={downloadTemplate} disabled={busy !== null || !entity}>
                      <Download size={15} />
                      <span>{busy === "template" ? "Descargando…" : "Descargar plantilla"}</span>
                    </Btn>
                    <Btn permission="imports.create" onClick={() => send(true)} disabled={busy !== null || (!file && !pasted.trim())}>
                      <Check size={15} />
                      <span>{busy === "validate" ? "Validando…" : "Validar"}</span>
                    </Btn>
                  </div>
                </Card>

                {preview && (
                  <Card title="2. Vista previa y validación" flush>
                    <p className="px-5 pt-2 text-[13px]">
                      <strong>{preview.rows.length}</strong> {preview.rows.length === 1 ? "fila" : "filas"} ·{" "}
                      <span className="text-[color:var(--p-success-text)]">{counts.ready} {counts.ready === 1 ? "lista" : "listas"}</span> ·{" "}
                      <span className="text-[color:var(--p-warning-text)]">{counts.skipped} se {counts.skipped === 1 ? "omitirá" : "omitirán"}</span> ·{" "}
                      <span className="text-[color:var(--p-danger-text)]">{counts.errors} con {counts.errors === 1 ? "error" : "errores"}</span>
                    </p>
                    <div className="mt-2 max-h-[420px] overflow-auto">
                      <table className="proto-tbl w-full border-collapse text-[13px]">
                        <thead>
                          <tr className="app-muted text-left text-[12px]">
                            <th className="px-4 py-2 font-medium">Fila</th>
                            {previewKeys.map((key) => <th key={key} className="px-3 py-2 font-medium">{key}</th>)}
                            <th className="px-3 py-2 font-medium">Resultado</th>
                          </tr>
                        </thead>
                        <tbody>
                          {preview.rows.map((row) => (
                            <tr key={row.row} className="border-t border-[color:var(--app-border)] align-top">
                              <td data-label="Fila" className="px-4 py-2 tabular-nums">{row.row}</td>
                              {previewKeys.map((key) => <td key={key} data-label={key} className="px-3 py-2 [overflow-wrap:anywhere]">{row.values[key] || <span className="app-muted">—</span>}</td>)}
                              <td data-label="Resultado" className="px-3 py-2"><RowResult row={row} /></td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                    <div className="flex flex-wrap items-center justify-end gap-2 px-5 py-3">
                      <Btn variant="ghost" onClick={reset} disabled={busy !== null}>Cancelar</Btn>
                      <Btn
                        permission="imports.create"
                        onClick={() => send(false)}
                        disabled={busy !== null || counts.ready === 0}
                        title={counts.ready === 0 ? "No hay filas válidas" : undefined}
                      >
                        <Upload size={15} />
                        <span>{busy === "import" ? "Importando…" : "Importar filas válidas"}</span>
                      </Btn>
                    </div>
                  </Card>
                )}
              </>
            )}
          </div>

          <div className="min-w-0">
            {entity && (
              <Card title="Columnas esperadas">
                <ul className="flex flex-col gap-1.5">
                  {entity.columns.map((column) => (
                    <li key={column} className="flex items-center justify-between gap-2">
                      <code className="text-[12.5px]">{column}</code>
                      {entity.required.includes(column) && <Chip tone="warning">obligatoria</Chip>}
                    </li>
                  ))}
                </ul>
              </Card>
            )}

            <Card title="Historial" flush>
              <div className="px-5 pt-3">
                <Filters>
                  <FilterSelect label="Qué se importó" value={entityFilter} onChange={(value) => { setEntityFilter(value); setPage(1); }} options={Object.entries(data.labels)} />
                  <FilterSelect label="Estado" value={statusFilter} onChange={(value) => { setStatusFilter(value); setPage(1); }} options={JOB_STATES.map(([key, label]) => [key, label])} />
                  <ColumnFilterBar
                    filters={columnFilters}
                    hasOtherFilters={Boolean(entityFilter || statusFilter)}
                    onClearAll={() => { setEntityFilter(""); setStatusFilter(""); setPage(1); }}
                  />
                </Filters>
              </div>
              {jobsError ? (
                <div className="px-5 pb-4"><LoadError text={jobsError} onRetry={() => setReload((n) => n + 1)} /></div>
              ) : (
                <>
                  <DataTable
                    tableId="import-jobs" sort={sort} onSort={(next) => { setSort(next); setPage(1); }} refreshing={jobsLoading}
                    onFilter={(column) => addColumnFilter(columnFilters, column)}
                    cols={jobCols}
                    rows={jobs?.data ?? []}
                    rowKey={jobKey}
                    onRowClick={openJob}
                    loading={jobsLoading && !jobs}
                    empty="Aún no hay importaciones."
                  />
                  {jobs && (
                    <Pager
                      loading={jobsLoading}
                      page={jobs.current_page}
                      lastPage={jobs.last_page}
                      total={jobs.total}
                      perPage={jobs.per_page}
                      onPage={setPage}
                      onPerPage={(size) => { setPerPage(size); setPage(1); }}
                      left={<ExportButtons permission="imports.export" total={jobs.total} busy={exporting} onExport={runExport} />}
                    />
                  )}
                </>
              )}
            </Card>
          </div>
        </div>
      )}

      {detail && (
        <Modal title="Importación" size="lg" onClose={() => setDetail(null)}>
          <Kv rows={[
            ["Archivo", detail.file],
            ["Qué se importó", detail.entity_label],
            ["Usuario", detail.user_name ?? "—"],
            ["Fecha", dateTime(detail.at)],
            ["Estado", <Badge key="s" tone={stateOf(JOB_STATES, detail.status)[1]}>{stateOf(JOB_STATES, detail.status)[0]}</Badge>],
            ["Filas", `${detail.total} · ${detail.created} creados · ${detail.updated} actualizados · ${detail.skipped} omitidos · ${detail.failed} con error`],
          ]} />
          {!detail.details ? (
            <p className="app-muted mt-3">Cargando…</p>
          ) : detail.details.length === 0 ? (
            <p className="app-muted mt-3 text-[13px]">Todas las filas entraron.</p>
          ) : (
            <div className="mt-3 max-h-[360px] overflow-auto">
              <table className="proto-tbl w-full border-collapse text-[13px]">
                <thead>
                  <tr className="app-muted text-left text-[12px]">
                    <th className="px-3 py-2 font-medium">Fila</th>
                    <th className="px-3 py-2 font-medium">Resultado</th>
                  </tr>
                </thead>
                <tbody>
                  {detail.details.map((row, index) => (
                    <tr key={index} className="border-t border-[color:var(--app-border)] align-top">
                      <td data-label="Fila" className="px-3 py-2 tabular-nums">{row.row}</td>
                      <td data-label="Resultado" className="px-3 py-2"><RowResult row={row} saved /></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Modal>
      )}
      {toasts}
    </Screen>
  );
}
