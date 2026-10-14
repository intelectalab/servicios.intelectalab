"use client";

import { useEffect, useState } from "react";
import { ArrowDown, ArrowUp, Camera, Copy, ListChecks, Pencil, Plus, Power, TriangleAlert, Upload, Wrench, X } from "lucide-react";
import { ApiError, apiFetch, apiFetchLater } from "@/lib/api";
import { excelTone, shortDate } from "@/lib/commercial";
import { dateTime, fieldErrors, firstError } from "@/lib/crm";
import { exportCsv, exportXlsx, recordExport, fetchAllPages, type ExportColumn } from "@/lib/export-table";
import {
  Badge, Btn, Card, Chip, DataTable, ExportButtons, Field, FilterSelect, Filters, FormGrid, INPUT, Kpi, Kpis, Kv, LoadError, Modal, Notice,
  PAGE_SIZES, PageHead, Pager, Screen, SearchInput, SelectionBar, TD, TH, Timeline, useRowSelection, useToasts, type Column, type Tone,
  applySort, type SortState,
} from "@/components/ui/proto";
import { PickSelect } from "@/components/ui/pick-select";
import { ImportModal } from "@/components/dashboard/ImportModal";
import { ColumnFilterBar, addColumnFilter, applyFilters, useServerColumnFilters } from "@/components/dashboard/ColumnFilters";
import type { PaginatedResponse } from "@/types/customer";
import type {
  ChecklistTemplate, ChecklistTemplateDetail, ChecklistTemplateItem, ChecklistTemplateOptions, ChecklistTemplateSummary,
} from "@/types/operations";

type Toast = (text: string, tone?: "success" | "danger" | "warning", detail?: string) => void;

const STATUS: Record<"ACTIVE" | "INACTIVE", [string, Tone]> = { ACTIVE: ["Activa", "success"], INACTIVE: ["Inactiva", "muted"] };
const statusOf = (template: Pick<ChecklistTemplate, "active">) => STATUS[template.active ? "ACTIVE" : "INACTIVE"];

// Textos del historial de la plantilla (los mismos de Auditoría).
const ACTIONS: Record<string, string> = {
  "checklist.create": "Plantilla creada",
  "checklist.update": "Plantilla editada",
  "checklist.duplicate": "Plantilla creada como copia",
  "checklist.import": "Plantilla importada",
  "checklist.activate": "Plantilla reactivada",
  "checklist.deactivate": "Plantilla desactivada",
};

const templateKey = (template: ChecklistTemplate) => template.id;

const EXPORT_COLUMNS: ExportColumn<ChecklistTemplate>[] = [
  { label: "Plantilla", value: (template) => template.name, width: 34 },
  { label: "Descripción", value: (template) => template.description ?? "", width: 36 },
  { label: "Servicio", value: (template) => template.service?.name ?? "", width: 30 },
  { label: "Código de servicio", value: (template) => template.service?.code ?? "", width: 18, kind: "center" },
  { label: "Ítems", value: (template) => template.items_count, width: 10, kind: "number" },
  { label: "Obligatorios", value: (template) => template.required_count, width: 13, kind: "number" },
  { label: "Con evidencia", value: (template) => template.evidence_count, width: 14, kind: "number" },
  { label: "Órdenes de trabajo", value: (template) => template.work_orders_count, width: 18, kind: "number" },
  { label: "Actualizada", value: (template) => (template.updated_at ? shortDate(template.updated_at) : ""), width: 15, kind: "center" },
  { label: "Estado", value: (template) => statusOf(template)[0], width: 13, kind: "status", tone: (template) => excelTone(statusOf(template)[1]) },
];

const ITEM_EXPORT_COLUMNS: ExportColumn<ChecklistTemplateItem>[] = [
  { label: "#", value: (item) => item.position, width: 6, kind: "number" },
  { label: "Ítem", value: (item) => item.label, width: 50 },
  { label: "Obligatorio", value: (item) => (item.required ? "Sí" : "No"), width: 13, kind: "center" },
  { label: "Evidencia obligatoria", value: (item) => (item.evidence_required ? "Sí" : "No"), width: 20, kind: "center" },
];

type TopFilters = { search: string; status: string; service: string };

function withTopFilters(params: URLSearchParams, top: TopFilters) {
  if (top.search) params.set("search", top.search);
  if (top.status) params.set("status", top.status);
  if (top.service) params.set("service_id", top.service);
  return params;
}

const itemsText = (template: Pick<ChecklistTemplate, "items_count" | "required_count" | "evidence_count">) =>
  [`${template.required_count} ${template.required_count === 1 ? "obligatorio" : "obligatorios"}`, template.evidence_count ? `${template.evidence_count} con evidencia` : null]
    .filter(Boolean).join(" · ");

export default function ChecklistTemplates() {
  const { toast, toasts } = useToasts();
  const [exporting, setExporting] = useState(false);

  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [service, setService] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [reload, setReload] = useState(0);
  const [sort, setSort] = useState<SortState>(null);
  const columnFilters = useServerColumnFilters(() => setPage(1));
  const { selection, selectedRows, clear: clearSelection } = useRowSelection(templateKey);

  const [data, setData] = useState<PaginatedResponse<ChecklistTemplate> | null>(null);
  const [summary, setSummary] = useState<ChecklistTemplateSummary | null>(null);
  const [options, setOptions] = useState<ChecklistTemplateOptions | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const [openId, setOpenId] = useState<string | null>(null);
  const [editing, setEditing] = useState<ChecklistTemplateDetail | "new" | null>(null);
  const [importing, setImporting] = useState(false);

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
      const params = withTopFilters(new URLSearchParams({ per_page: String(perPage), page: String(page) }), { search, status, service });
      applySort(applyFilters(params, columnFilters.param), sort);

      try {
        const response = await apiFetch<PaginatedResponse<ChecklistTemplate>>(`/v1/checklists?${params}`);
        if (!cancelled) {
          setData(response);
          setError("");
        }
      } catch {
        if (!cancelled) setError("No se pudieron cargar las plantillas de checklist.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [page, perPage, search, status, service, columnFilters.param, sort, reload]);

  // Indicadores y servicios del filtro: después del listado.
  useEffect(() => {
    let cancelled = false;
    apiFetchLater<ChecklistTemplateSummary>("/v1/checklists/summary").then((next) => { if (!cancelled) setSummary(next); }).catch(() => undefined);
    return () => { cancelled = true; };
  }, [reload]);
  useEffect(() => {
    apiFetchLater<ChecklistTemplateOptions>("/v1/checklists/options").then(setOptions).catch(() => setOptions({ services: [] }));
  }, []);

  const columns: Column<ChecklistTemplate>[] = [
    {
      label: "Plantilla", sortKey: "name", filter: "text",
      render: (template) => (
        <div className="min-w-[200px]">
          <strong className="font-semibold">{template.name}</strong>
          {template.description && <small className="app-muted block text-xs">{template.description}</small>}
        </div>
      ),
    },
    {
      label: "Servicio", sortKey: "service", filter: "text",
      render: (template) => (template.service ? (
        <div className="min-w-[140px]">
          {template.service.name}
          <small className="app-muted block text-xs">{template.service.code}</small>
        </div>
      ) : <span className="app-muted">Cualquiera</span>),
    },
    {
      label: "Ítems", num: true, sortKey: "items", filter: "number",
      render: (template) => (
        <div className="whitespace-nowrap">
          <strong className="font-semibold">{template.items_count}</strong>
          <small className="app-muted block text-xs">{itemsText(template)}</small>
        </div>
      ),
    },
    { label: "Órdenes de trabajo", num: true, sortKey: "work_orders", render: (template) => template.work_orders_count },
    { label: "Actualizada", sortKey: "updated", filter: "date", render: (template) => <span className="whitespace-nowrap">{template.updated_at ? shortDate(template.updated_at) : "—"}</span> },
    {
      label: "Estado", sortKey: "status", filter: { top: "Estado" },
      render: (template) => <Badge tone={statusOf(template)[1]}>{statusOf(template)[0]}</Badge>,
    },
  ];

  /** Exporta las filas marcadas o, sin `picked`, todas las del filtro y búsqueda actuales. */
  async function runExport(format: "xlsx" | "csv", picked?: ChecklistTemplate[]) {
    setExporting(true);
    try {
      const rows = picked ?? (await fetchAllPages<ChecklistTemplate>("/v1/checklists", applySort(columnFilters.apply(withTopFilters(new URLSearchParams(), { search, status, service })), sort)));
      // La API comprueba el permiso de exportar y deja la exportación en auditoría.
      await recordExport("checklists", format, rows.length, Boolean(picked));
      if (format === "xlsx") await exportXlsx("plantillas-de-checklist", "Plantillas de checklist", EXPORT_COLUMNS, rows);
      else exportCsv("plantillas-de-checklist", EXPORT_COLUMNS, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el listado.", "danger");
    } finally {
      setExporting(false);
    }
  }

  const serviceOptions: [string, string][] = [["", "Servicio: todos"], ...(options?.services ?? []).map((item): [string, string] => [item.id, `${item.name} · ${item.code}`])];

  return (
    <Screen>
      <PageHead
        title="Plantillas de checklist"
        actions={
          <>
            <Btn variant="secondary" onClick={() => setImporting(true)} permission="checklists.manage">
              <Upload size={15} />
              <span>Importar</span>
            </Btn>
            <Btn onClick={() => setEditing("new")} permission="checklists.manage">
              <Plus size={16} />
              <span>Nueva plantilla</span>
            </Btn>
          </>
        }
      />

      <Kpis>
        <Kpi
          label="Plantillas activas" value={summary ? summary.active : "—"} icon={<ListChecks size={22} />}
          sub={summary ? `${summary.inactive} ${summary.inactive === 1 ? "inactiva" : "inactivas"} · ${summary.with_service} con servicio` : undefined}
        />
        <Kpi label="Ítems obligatorios" value={summary ? summary.required_items : "—"} sub="En las plantillas activas" icon={<TriangleAlert size={22} />} tone="warning" />
        <Kpi label="Con evidencia obligatoria" value={summary ? summary.with_evidence : "—"} sub="Plantillas que piden foto" icon={<Camera size={22} />} />
        <Kpi label="OT abiertas con checklist" value={summary ? summary.open_work_orders : "—"} sub="A tu alcance" icon={<Wrench size={22} />} tone="success" />
      </Kpis>

      <Filters>
        <SearchInput value={searchInput} onChange={setSearchInput} placeholder="Buscar plantilla, ítem o servicio" />
        <FilterSelect
          label="Estado"
          value={status}
          onChange={(value) => {
            setStatus(value);
            setPage(1);
          }}
          options={[["ACTIVE", "Activa"], ["INACTIVE", "Inactiva"]]}
        />
        <div className="flex-[1_1_180px] sm:w-[260px] sm:flex-none">
          <PickSelect
            className={`${INPUT} h-10`} ariaLabel="Servicio" searchable={serviceOptions.length > 10} value={service}
            onChange={(value) => {
              setService(value);
              setPage(1);
            }}
            options={serviceOptions}
          />
        </div>
        <ColumnFilterBar
          filters={columnFilters}
          hasOtherFilters={Boolean(searchInput || status || service)}
          onClearAll={() => {
            setSearchInput("");
            setSearch("");
            setStatus("");
            setService("");
            setPage(1);
          }}
        />
      </Filters>

      {error ? (
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
      ) : (
        <Card flush>
          <SelectionBar permission="checklists.export" count={selectedRows.length} busy={exporting} onExport={(format) => runExport(format, selectedRows)} onClear={clearSelection} />
          <DataTable
            tableId="checklist-templates" sort={sort} onSort={(next) => { setSort(next); setPage(1); }} refreshing={loading}
            onFilter={(column) => addColumnFilter(columnFilters, column)}
            cols={columns}
            rows={data?.data ?? []}
            rowKey={templateKey}
            selection={selection}
            onRowClick={(template) => setOpenId(template.id)}
            loading={loading && !data}
            empty="No hay plantillas con estos filtros."
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
              left={<ExportButtons permission="checklists.export" total={data.total} busy={exporting} onExport={(format) => runExport(format)} />}
            />
          )}
        </Card>
      )}

      {openId && (
        <ChecklistTemplateModal
          id={openId}
          version={reload}
          toast={toast}
          onClose={() => setOpenId(null)}
          onEdit={(template) => setEditing(template)}
          onChanged={(next) => {
            if (next) setOpenId(next);
            setReload((n) => n + 1);
          }}
        />
      )}
      {editing && (
        <ChecklistTemplateFormModal
          template={editing === "new" ? undefined : editing}
          options={options}
          onClose={() => setEditing(null)}
          onSaved={(saved) => {
            toast(editing === "new" ? "Plantilla creada" : "Plantilla actualizada", "success", saved.name);
            setEditing(null);
            setOpenId(saved.id);
            setReload((n) => n + 1);
          }}
        />
      )}
      {importing && (
        <ImportModal
          title="Importar plantillas de checklist"
          endpoint="/v1/checklists"
          templateName="plantilla_checklists.xlsx"
          hint="Una fila por ítem; las filas con el mismo nombre de plantilla forman una plantilla. Las que ya existen no se importan."
          onClose={() => setImporting(false)}
          onImported={() => setReload((n) => n + 1)}
        />
      )}
      {toasts}
    </Screen>
  );
}

/** Ficha de una plantilla, sobre el listado: datos, ítems (exportables), uso e historial. */
function ChecklistTemplateModal({
  id, version, toast, onClose, onEdit, onChanged,
}: {
  id: string;
  version: number;
  toast: Toast;
  onClose: () => void;
  onEdit: (template: ChecklistTemplateDetail) => void;
  /** Cambió algo; con id, la ficha pasa a esa plantilla (la copia). */
  onChanged: (openId?: string) => void;
}) {
  const [template, setTemplate] = useState<ChecklistTemplateDetail | null>(null);
  const [error, setError] = useState("");
  const [retry, setRetry] = useState(0);
  const [busy, setBusy] = useState(false);
  const [exporting, setExporting] = useState(false);

  useEffect(() => {
    let cancelled = false;
    apiFetch<ChecklistTemplateDetail>(`/v1/checklists/${id}`)
      .then((next) => {
        if (cancelled) return;
        setTemplate(next);
        setError("");
      })
      .catch((err) => {
        if (!cancelled) setError(err instanceof ApiError && err.status === 404 ? "La plantilla no existe." : "No se pudo cargar la plantilla.");
      });
    return () => { cancelled = true; };
  }, [id, version, retry]);

  async function act(path: "duplicate" | "activate" | "deactivate", done: string) {
    setBusy(true);
    try {
      const next = await apiFetch<ChecklistTemplateDetail>(`/v1/checklists/${id}/${path}`, { method: "POST" });
      toast(done, "success", path === "duplicate" ? next.name : undefined);
      if (path === "duplicate") onChanged(next.id);
      else {
        setTemplate(next);
        onChanged();
      }
    } catch (err) {
      toast(firstError(err, "No se pudo completar la acción."), "danger");
      setRetry((n) => n + 1);
    } finally {
      setBusy(false);
    }
  }

  async function exportItems(format: "xlsx" | "csv") {
    if (!template) return;
    setExporting(true);
    try {
      await recordExport("checklists", format, template.items.length);
      const name = `checklist-${template.name.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "") || "plantilla"}`;
      if (format === "xlsx") await exportXlsx(name, template.name, ITEM_EXPORT_COLUMNS, template.items);
      else exportCsv(name, ITEM_EXPORT_COLUMNS, template.items);
      toast(`${template.items.length} ${template.items.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar la tabla.", "danger");
    } finally {
      setExporting(false);
    }
  }

  return (
    <Modal title="Plantilla de checklist" size="lg" onClose={onClose} footer={<Btn variant="ghost" onClick={onClose}>Cerrar</Btn>}>
      {error ? (
        <LoadError text={error} onRetry={() => setRetry((n) => n + 1)} />
      ) : !template ? (
        <div className="h-40 animate-pulse rounded bg-[var(--app-surface-muted)]" aria-busy="true" />
      ) : (
        <div className="grid gap-4">
          <div className="flex flex-wrap items-start justify-between gap-2">
            <div className="min-w-0">
              <h4 className="text-[17px] font-semibold leading-tight">{template.name}</h4>
              {template.description && <p className="app-muted text-[13px]">{template.description}</p>}
            </div>
            <Badge tone={statusOf(template)[1]}>{statusOf(template)[0]}</Badge>
          </div>

          <div className="flex flex-wrap gap-1.5">
            <Btn variant="secondary" onClick={() => onEdit(template)} permission="checklists.manage" disabled={busy}>
              <Pencil size={14} />
              <span>Editar</span>
            </Btn>
            <Btn variant="secondary" onClick={() => act("duplicate", "Plantilla duplicada")} permission="checklists.manage" disabled={busy}>
              <Copy size={14} />
              <span>Duplicar</span>
            </Btn>
            {template.active ? (
              <Btn variant="danger-ghost" onClick={() => act("deactivate", "Plantilla desactivada")} permission="checklists.manage" disabled={busy}>
                <Power size={14} />
                <span>Desactivar</span>
              </Btn>
            ) : (
              <Btn variant="secondary" onClick={() => act("activate", "Plantilla reactivada")} permission="checklists.manage" disabled={busy}>
                <Power size={14} />
                <span>Reactivar</span>
              </Btn>
            )}
          </div>
          {!template.active && <Notice tone="warn">Inactiva: no se ofrece al crear órdenes de trabajo. Las que ya la usan no cambian.</Notice>}

          <Kv
            rows={[
              ["Servicio asociado", template.service ? `${template.service.name} · ${template.service.code}` : <span key="any" className="app-muted">Ninguno: se elige a mano en cualquier OT</span>],
              ["Ítems", `${template.items_count} · ${itemsText(template)}`],
              [
                "Uso",
                `Copiada en ${template.work_orders_count} ${template.work_orders_count === 1 ? "orden de trabajo" : "órdenes de trabajo"}${template.open_work_orders ? ` (${template.open_work_orders} ${template.open_work_orders === 1 ? "abierta" : "abiertas"})` : ""}`,
              ],
              ["Creó", template.creator ?? "—"],
              ["Actualizada", template.updated_at ? dateTime(template.updated_at) : "—"],
            ]}
          />
          <p className="app-muted -mt-2 text-[12.5px]">Cambiar la plantilla no modifica las órdenes de trabajo que ya la usan: cada una guarda su copia.</p>

          <section>
            <h4 className="mb-1 text-[15px] font-semibold">Ítems</h4>
            <div className="proto-tbl-wrap overflow-x-auto">
              <table className="proto-tbl w-full border-collapse text-[13px]">
                <thead>
                  <tr>
                    <th className={`${TH} w-10`}>#</th>
                    <th className={TH}>Ítem</th>
                    <th className={TH}>Obligatorio</th>
                    <th className={TH}>Evidencia</th>
                  </tr>
                </thead>
                <tbody>
                  {template.items.map((item) => (
                    <tr key={item.id}>
                      <td className={`${TD} tabular-nums`} data-label="#">{item.position}</td>
                      <td className={TD} data-label="Ítem">{item.label}</td>
                      <td className={TD} data-label="Obligatorio">{item.required ? <Chip tone="warning">Obligatorio</Chip> : <span className="app-muted">Opcional</span>}</td>
                      <td className={TD} data-label="Evidencia">{item.evidence_required ? <Chip tone="info">Foto obligatoria</Chip> : <span className="app-muted">—</span>}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="flex justify-start pt-3">
              <ExportButtons permission="checklists.export" total={template.items.length} busy={exporting} onExport={exportItems} />
            </div>
          </section>

          <section>
            <h4 className="mb-1 text-[15px] font-semibold">Historial</h4>
            <Timeline
              items={template.history.map((entry, index) => ({
                key: `${entry.at}-${index}`,
                head: <span className="font-medium">{ACTIONS[entry.action] ?? entry.action}</span>,
                meta: `${entry.user ?? "Sistema"} · ${dateTime(entry.at)}`,
                note: entry.metadata.note,
              }))}
            />
          </section>
        </div>
      )}
    </Modal>
  );
}

type DraftItem = { key: number; label: string; required: boolean; evidence_required: boolean };

let draftKey = 0;
const draft = (item?: Partial<DraftItem>): DraftItem => ({ key: ++draftKey, label: "", required: false, evidence_required: false, ...item });

/** Alta o edición de una plantilla: nombre, servicio, descripción e ítems en orden. */
function ChecklistTemplateFormModal({
  template, options, onClose, onSaved,
}: {
  template?: ChecklistTemplateDetail;
  options: ChecklistTemplateOptions | null;
  onClose: () => void;
  onSaved: (template: ChecklistTemplateDetail) => void;
}) {
  const [name, setName] = useState(template?.name ?? "");
  const [description, setDescription] = useState(template?.description ?? "");
  const [serviceId, setServiceId] = useState(template?.service?.id ?? "");
  const [items, setItems] = useState<DraftItem[]>(() => (template?.items.length ? template.items.map((item) => draft(item)) : [draft()]));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  const update = (key: number, patch: Partial<DraftItem>) => setItems((list) => list.map((item) => (item.key === key ? { ...item, ...patch } : item)));
  const move = (index: number, step: -1 | 1) => setItems((list) => {
    const next = [...list];
    [next[index], next[index + step]] = [next[index + step], next[index]];
    return next;
  });

  async function save() {
    const found: Record<string, string> = {};
    if (!name.trim()) found.name = "Indica el nombre de la plantilla.";
    const filled = items.filter((item) => item.label.trim());
    if (!filled.length) found.items = "Agrega al menos un ítem.";
    setErrors(found);
    if (Object.keys(found).length) return;

    setSaving(true);
    setError("");
    try {
      const saved = await apiFetch<ChecklistTemplateDetail>(template ? `/v1/checklists/${template.id}` : "/v1/checklists", {
        method: template ? "PUT" : "POST",
        body: JSON.stringify({
          name: name.trim(),
          description: description.trim() || null,
          service_id: serviceId || null,
          items: filled.map((item) => ({ label: item.label.trim(), required: item.required, evidence_required: item.evidence_required })),
        }),
      });
      onSaved(saved);
    } catch (err) {
      const fields = fieldErrors(err);
      // Los errores de un ítem llegan como "items.3.label": se muestran en su fila (sobre las filas con texto).
      setErrors(fields);
      if (!Object.keys(fields).some((key) => ["name", "service_id", "items"].includes(key) || key.startsWith("items."))) setError(firstError(err, "No se pudo guardar la plantilla."));
      setSaving(false);
    }
  }

  const services: [string, string][] = [["", "Ninguno"], ...(options?.services ?? []).map((item): [string, string] => [item.id, `${item.name} · ${item.code}${item.active ? "" : " (inactivo)"}`])];
  // Índice de cada fila entre las que tienen texto: así llegan sus errores desde la API.
  const sentIndex = new Map<number, number>();
  items.filter((item) => item.label.trim()).forEach((item, index) => sentIndex.set(item.key, index));

  return (
    <Modal
      title={template ? `Editar ${template.name}` : "Nueva plantilla de checklist"}
      size="lg"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn onClick={save} disabled={saving} permission="checklists.manage">{saving ? "Guardando…" : "Guardar"}</Btn>
        </>
      }
    >
      {error && <Notice tone="warn">{error}</Notice>}
      {template && template.work_orders_count > 0 && (
        <Notice>
          {template.work_orders_count === 1
            ? "La orden de trabajo que ya usa esta plantilla conserva su checklist; el cambio vale para las nuevas."
            : `Las ${template.work_orders_count} órdenes de trabajo que ya usan esta plantilla conservan su checklist; el cambio vale para las nuevas.`}
        </Notice>
      )}
      <FormGrid>
        <Field label="Nombre" required span={2} error={errors.name}>
          <input className={INPUT} value={name} onChange={(e) => setName(e.target.value)} maxLength={255} autoFocus />
        </Field>
        <Field label="Servicio asociado" error={errors.service_id} hint="Se propone al crear la OT de ese servicio.">
          <PickSelect className={INPUT} ariaLabel="Servicio asociado" searchable={services.length > 8} disabled={!options} value={serviceId} onChange={setServiceId} options={services} />
        </Field>
        <Field label="Descripción" span={3} error={errors.description}>
          <input className={INPUT} value={description} onChange={(e) => setDescription(e.target.value)} maxLength={2000} />
        </Field>
      </FormGrid>

      <div className="mt-4">
        <div className="mb-1.5 flex items-baseline justify-between gap-2">
          <h4 className="text-[15px] font-semibold">Ítems <em className="not-italic text-[color:var(--p-danger-text)]">*</em></h4>
          <span className="app-muted text-xs">{items.filter((item) => item.label.trim()).length} de 100</span>
        </div>
        {errors.items && <p className="mb-1.5 text-xs text-[color:var(--p-danger-text)]">{errors.items}</p>}
        <ol className="grid gap-2">
          {items.map((item, index) => {
            const rowError = sentIndex.has(item.key) ? errors[`items.${sentIndex.get(item.key)}.label`] : undefined;
            return (
              <li key={item.key} className="rounded-xl border border-[color:var(--app-border)] p-2.5">
                <div className="flex items-start gap-2">
                  <span className="app-muted mt-2.5 w-5 shrink-0 text-right text-xs tabular-nums">{index + 1}</span>
                  <div className="min-w-0 flex-1">
                    <input
                      className={INPUT} value={item.label} maxLength={500} placeholder="Qué se revisa o se hace"
                      aria-label={`Ítem ${index + 1}`}
                      onChange={(e) => update(item.key, { label: e.target.value })}
                      onKeyDown={(e) => {
                        // Enter en el último ítem agrega otro, como en una lista.
                        if (e.key === "Enter" && index === items.length - 1 && item.label.trim() && items.length < 100) {
                          e.preventDefault();
                          setItems((list) => [...list, draft()]);
                        }
                      }}
                    />
                    {rowError && <small className="text-xs text-[color:var(--p-danger-text)]">{rowError}</small>}
                    <div className="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-[12.5px]">
                      <label className="relative inline-flex cursor-pointer items-center gap-1.5">
                        <input type="checkbox" checked={item.required} onChange={(e) => update(item.key, { required: e.target.checked })} className="h-4 w-4 cursor-pointer accent-[#1B8FC9]" />
                        Obligatorio
                      </label>
                      <label className="relative inline-flex cursor-pointer items-center gap-1.5" title="No se marca sin su foto o archivo, también en la App del técnico.">
                        <input type="checkbox" checked={item.evidence_required} onChange={(e) => update(item.key, { evidence_required: e.target.checked })} className="h-4 w-4 cursor-pointer accent-[#1B8FC9]" />
                        Evidencia obligatoria
                      </label>
                    </div>
                  </div>
                  <div className="flex shrink-0 items-center gap-0.5">
                    <button type="button" onClick={() => move(index, -1)} disabled={index === 0} aria-label={`Subir ítem ${index + 1}`} title="Subir"
                      className="app-muted grid h-8 w-8 cursor-pointer place-items-center rounded-md hover:bg-[var(--app-surface-muted)] hover:text-[var(--app-text)] disabled:cursor-not-allowed disabled:opacity-35">
                      <ArrowUp size={15} />
                    </button>
                    <button type="button" onClick={() => move(index, 1)} disabled={index === items.length - 1} aria-label={`Bajar ítem ${index + 1}`} title="Bajar"
                      className="app-muted grid h-8 w-8 cursor-pointer place-items-center rounded-md hover:bg-[var(--app-surface-muted)] hover:text-[var(--app-text)] disabled:cursor-not-allowed disabled:opacity-35">
                      <ArrowDown size={15} />
                    </button>
                    <button type="button" onClick={() => setItems((list) => (list.length > 1 ? list.filter((other) => other.key !== item.key) : [draft()]))}
                      aria-label={`Quitar ítem ${index + 1}`} title="Quitar"
                      className="grid h-8 w-8 cursor-pointer place-items-center rounded-md text-[color:var(--p-danger-text)] hover:bg-[var(--p-danger-soft)]">
                      <X size={15} />
                    </button>
                  </div>
                </div>
              </li>
            );
          })}
        </ol>
        <Btn variant="secondary" size="sm" className="mt-2" onClick={() => setItems((list) => [...list, draft()])} disabled={items.length >= 100}>
          <Plus size={14} />
          <span>Agregar ítem</span>
        </Btn>
      </div>
    </Modal>
  );
}
