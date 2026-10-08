"use client";

import { useEffect, useMemo, useRef, useState, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { CheckCircle, Plus, Trash2, Upload } from "lucide-react";
import { ApiError, apiFetch } from "@/lib/api";
import { useAccess } from "@/lib/access-context";
import { excelTone, stateOf, takeFlash, setFlash } from "@/lib/commercial";
import { ACTOR_ENTITY, ACTOR_STATES, ROLE_LABELS, actorDocument, actorRelation, kindLabel, rolesText } from "@/lib/actors";
import { exportCsv, exportXlsx, fetchAllPages, type ExportColumn } from "@/lib/export-table";
import { ColumnFilterBar, openTopFilter, useServerColumnFilters } from "@/components/dashboard/ColumnFilters";
import {
  Btn, Card, Empty, ExportButtons, Field, FilterSelect, Filters, INPUT, LoadError, Modal, PAGE_SIZES, PageHead, Pager, Screen,
  SearchInput, useToasts,
  applySort, type SortState,
} from "@/components/ui/proto";
import { PickSelect } from "@/components/ui/pick-select";
import { ActorsTable } from "./ActorsTable";
import { ActorForm } from "./ActorForm";
import { ImportModal } from "@/components/dashboard/ImportModal";
import type { PaginatedResponse } from "@/types/customer";
import type { Actor, ActorKind } from "@/types/actor";
import type { CustomFieldDefinition } from "@/types/custom-field";

type Values = Record<string, Record<string, string>>;
type FieldType = CustomFieldDefinition["field_type"];

const FIELD_TYPES: [FieldType, string][] = [["text", "Texto"], ["number", "Número"], ["date", "Fecha"], ["boolean", "Sí / No"]];
/** Valores de las columnas personalizadas de cada actor; llegan con el listado, sin pedirlos fila por fila. */
function customValuesOf(actors: Actor[]): Values {
  return Object.fromEntries(actors.map((actor) => [
    actor.id,
    Object.fromEntries(Object.entries(actor.custom_values ?? {}).map(([fieldId, value]) => [fieldId, value ?? ""])),
  ]));
}

const errorText = (err: unknown, fallback: string) =>
  err instanceof ApiError ? (err.errors && Object.values(err.errors)[0]?.[0]) || err.message : fallback;

export default function ActorsList() {
  const router = useRouter();
  const { can } = useAccess();
  const { toast, toasts } = useToasts();
  const editable = can("core.manage");

  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [role, setRole] = useState("");
  const [kind, setKind] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [reload, setReload] = useState(0);
  const [sort, setSort] = useState<SortState>(null);

  const [data, setData] = useState<PaginatedResponse<Actor> | null>(null);
  const [definitions, setDefinitions] = useState<CustomFieldDefinition[]>([]);
  const [customValues, setCustomValues] = useState<Values>({});
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const [selectedIds, setSelectedIds] = useState<string[]>([]);
  const [busy, setBusy] = useState(false);
  const [exporting, setExporting] = useState(false);
  const [creating, setCreating] = useState<ActorKind | null>(null);
  const [importing, setImporting] = useState(false);
  const [bulk, setBulk] = useState<{ type: "activate" | "deactivate"; ids: string[] } | null>(null);
  const [fieldModal, setFieldModal] = useState(false);
  const [fieldForm, setFieldForm] = useState<{ label: string; field_type: FieldType }>({ label: "", field_type: "text" });
  const [fieldError, setFieldError] = useState("");
  const [fieldToDelete, setFieldToDelete] = useState<CustomFieldDefinition | null>(null);
  const filtersRef = useRef<HTMLDivElement>(null);

  const actors = useMemo(() => data?.data ?? [], [data]);

  // Filtros por columna (los que se agregan desde los tres puntos de cada cabecera): los aplica la API.
  const columnFilters = useServerColumnFilters(() => setPage(1));
  const { activeFilters, handleAddFilter } = columnFilters;

  useEffect(() => {
    const flash = takeFlash();
    if (flash) toast(flash);
  }, [toast]);

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, 350);
    return () => window.clearTimeout(timer);
  }, [searchInput]);

  const queryParams = () => {
    const params = new URLSearchParams();
    if (search) params.set("search", search);
    if (role) params.set("role", role);
    if (kind) params.set("kind", kind);
    if (status) params.set("status", status);
    return applySort(columnFilters.apply(params), sort);
  };

  // Las columnas personalizadas no cambian al paginar, ordenar o filtrar: se piden solo al entrar o al recargar.
  useEffect(() => {
    let cancelled = false;
    apiFetch<CustomFieldDefinition[]>(`/v1/custom-fields/definitions?entity_type=${ACTOR_ENTITY}`)
      .then((fields) => { if (!cancelled) setDefinitions(fields); })
      .catch(() => { if (!cancelled) setDefinitions([]); });
    return () => { cancelled = true; };
  }, [reload]);

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setLoading(true);
      const params = queryParams();
      params.set("per_page", String(perPage));
      params.set("page", String(page));

      try {
        const response = await apiFetch<PaginatedResponse<Actor>>(`/v1/parties?${params}`);
        if (cancelled) return;
        setData(response);
        setCustomValues(customValuesOf(response.data));
        setSelectedIds([]);
        setError("");
      } catch {
        if (!cancelled) setError("No se pudo cargar el listado.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps -- queryParams solo lee los filtros de la lista
  }, [page, perPage, search, role, kind, status, columnFilters.param, sort, reload]);

  // Rol y Estado ya se filtran arriba: desde su columna se abre ese desplegable en vez de crear
  // un segundo filtro del mismo dato, que podría contradecirlo.
  function addColumnFilter(columnId: string, label: string, type: "text" | "number" | "select" | "date") {
    const top = columnId === "roles" ? "Rol" : columnId === "status" ? "Estado" : null;
    if (!top) {
      handleAddFilter(columnId, label, type);
      return;
    }
    openTopFilter(top);
  }

  /** Quita todo lo que filtra el listado: buscador, Rol, Tipo, Estado y los filtros por columna. */
  function clearAllFilters() {
    setSearchInput("");
    setSearch("");
    setRole("");
    setKind("");
    setStatus("");
    setPage(1);
  }

  const refresh = () => setReload((n) => n + 1);
  const changeFilter = (setter: (value: string) => void) => (value: string) => {
    setter(value);
    setPage(1);
  };

  const replaceActor = (actor: Actor) =>
    setData((current) => current && { ...current, data: current.data.map((item) => (item.id === actor.id ? { ...item, ...actor } : item)) });

  // --- Acciones masivas -----------------------------------------------------------

  const selected = actors.filter((actor) => selectedIds.includes(actor.id));
  const askBulk = (type: "activate" | "deactivate") => {
    const ids = selected.filter((actor) => actor.status === (type === "activate" ? "INACTIVE" : "ACTIVE")).map((actor) => actor.id);
    if (ids.length) setBulk({ type, ids });
  };

  async function runBulk() {
    if (!bulk) return;
    setBusy(true);
    try {
      await apiFetch(`/v1/parties/bulk-${bulk.type}`, { method: "POST", body: JSON.stringify({ ids: bulk.ids }) });
      toast(bulk.type === "activate" ? "Registros activados" : "Registros inactivados");
      setBulk(null);
      refresh();
    } catch (err) {
      toast(errorText(err, "No se pudo completar la acción."), "danger");
    } finally {
      setBusy(false);
    }
  }

  // --- Columnas personalizadas ------------------------------------------------------

  async function createField(event: FormEvent) {
    event.preventDefault();
    const label = fieldForm.label.trim();
    if (!label) {
      setFieldError("Este dato es obligatorio.");
      return;
    }
    setBusy(true);
    try {
      const field_key = label.toLowerCase().normalize("NFD").replace(/[^\w\s]/g, "").trim().replace(/\s+/g, "_");
      await apiFetch("/v1/custom-fields/definitions", {
        method: "POST",
        body: JSON.stringify({ entity_type: ACTOR_ENTITY, field_key, label, field_type: fieldForm.field_type }),
      });
      setFieldModal(false);
      setFieldForm({ label: "", field_type: "text" });
      toast("Columna agregada");
      refresh();
    } catch (err) {
      setFieldError(errorText(err, "No se pudo crear la columna."));
    } finally {
      setBusy(false);
    }
  }

  async function deleteField() {
    if (!fieldToDelete) return;
    setBusy(true);
    try {
      await apiFetch(`/v1/custom-fields/definitions/${fieldToDelete.id}`, { method: "DELETE" });
      setFieldToDelete(null);
      toast("Columna eliminada");
      refresh();
    } catch (err) {
      toast(errorText(err, "No se pudo eliminar la columna."), "danger");
    } finally {
      setBusy(false);
    }
  }

  // --- Exportar -----------------------------------------------------------------------

  /** Exporta las filas marcadas o, sin `picked`, todas las del filtro y búsqueda actuales. */
  async function runExport(format: "xlsx" | "csv", picked?: Actor[]) {
    setExporting(true);
    try {
      const rows = picked ?? (await fetchAllPages<Actor>("/v1/parties", queryParams()));
      // Lo editado en la celda durante esta visita manda sobre lo que llegó con el listado.
      const values: Values = { ...customValuesOf(rows), ...customValues };
      const columns: ExportColumn<Actor>[] = [
        { label: "Tipo", value: (actor) => kindLabel(actor), width: 15, kind: "center" },
        { label: "Nombre", value: (actor) => actor.name, width: 34 },
        { label: "Razón social", value: (actor) => actor.organization?.legal_name ?? "", width: 34 },
        { label: "Documento", value: (actor) => actorDocument(actor), width: 20, kind: "center" },
        { label: "Roles", value: (actor) => rolesText(actor), width: 26 },
        { label: "Sector / relación", value: (actor) => actorRelation(actor).replace(/^—$/, ""), width: 26 },
        { label: "Correo", value: (actor) => actor.email ?? "", width: 28 },
        { label: "Teléfono", value: (actor) => actor.phone ?? "", width: 16 },
        ...definitions.map((definition): ExportColumn<Actor> => ({
          label: definition.label,
          value: (actor) => {
            const value = values[actor.id]?.[definition.id] ?? "";
            return value === "true" ? "Sí" : value === "false" ? "No" : value;
          },
          width: 20,
        })),
        {
          label: "Estado",
          value: (actor) => stateOf(ACTOR_STATES, actor.status)[0],
          width: 14,
          kind: "status",
          tone: (actor) => excelTone(stateOf(ACTOR_STATES, actor.status)[1]),
        },
      ];
      if (format === "xlsx") await exportXlsx("clientes-y-actores", "Clientes y actores", columns, rows);
      else exportCsv("clientes-y-actores", columns, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el listado.", "danger");
    } finally {
      setExporting(false);
    }
  }

  const hasServerFilters = Boolean(search || role || kind || status);

  return (
    <Screen>
      <PageHead
        title="Clientes y actores"
        actions={
          <>
            <Btn onClick={() => setCreating("ORGANIZATION")} permission="core.manage">
              <Plus size={16} />
              <span>Nueva organización</span>
            </Btn>
            <Btn variant="secondary" onClick={() => setCreating("PERSON")} permission="core.manage">
              <Plus size={16} />
              <span>Nueva persona</span>
            </Btn>
            <Btn variant="ghost" onClick={() => setImporting(true)} permission="core.manage">
              <Upload size={16} />
              <span>Importar</span>
            </Btn>
          </>
        }
      />

      <div ref={filtersRef}>
        <Filters>
          <SearchInput value={searchInput} onChange={setSearchInput} placeholder="Buscar por nombre, RUC, DNI o correo" />
          <FilterSelect label="Rol" value={role} onChange={changeFilter(setRole)} options={Object.entries(ROLE_LABELS)} />
          <FilterSelect label="Tipo" value={kind} onChange={changeFilter(setKind)} options={[["ORGANIZATION", "Organización"], ["PERSON", "Persona"]]} />
          <FilterSelect label="Estado" value={status} onChange={changeFilter(setStatus)} options={ACTOR_STATES.map(([key, label]) => [key, label])} />
          <ColumnFilterBar filters={columnFilters} hasOtherFilters={Boolean(hasServerFilters || searchInput)} onClearAll={clearAllFilters} />
        </Filters>
      </div>

      <Card flush>
        {error ? (
          <LoadError text={error} onRetry={refresh} />
        ) : (
          <>
            {selectedIds.length > 0 && (
              <div className="flex flex-wrap items-center gap-2 border-b border-[color:var(--p-brand-line)] bg-[var(--p-brand-soft)] px-3.5 py-2 text-[13px] text-[color:var(--p-brand-soft-text)]">
                <span className="grid h-6 min-w-6 place-items-center rounded-full bg-[#1B8FC9] px-1.5 text-xs font-semibold text-[#fff]">{selectedIds.length}</span>
                <span className="mr-auto font-medium">{selectedIds.length === 1 ? "fila seleccionada" : "filas seleccionadas"}</span>
                <Btn size="sm" disabled={exporting} onClick={() => runExport("xlsx", selected)}>
                  <Upload size={14} />
                  <span>{exporting ? "Exportando…" : "Exportar selección a Excel"}</span>
                </Btn>
                <Btn variant="secondary" size="sm" disabled={exporting} onClick={() => runExport("csv", selected)}>CSV</Btn>
                {selected.some((actor) => actor.status === "INACTIVE") && (
                  <Btn variant="secondary" size="sm" permission="core.manage" disabled={busy} onClick={() => askBulk("activate")}>
                    <CheckCircle size={14} />
                    <span>Activar</span>
                  </Btn>
                )}
                {selected.some((actor) => actor.status === "ACTIVE") && (
                  <Btn variant="danger-ghost" size="sm" permission="core.manage" disabled={busy} onClick={() => askBulk("deactivate")}>
                    <Trash2 size={14} />
                    <span>Desactivar</span>
                  </Btn>
                )}
                <button type="button" onClick={() => setSelectedIds([])} className="cursor-pointer px-1.5 text-xs font-medium underline-offset-2 hover:underline">
                  Quitar selección
                </button>
              </div>
            )}

            {loading && !data ? (
              <div className="grid gap-3 p-5" aria-busy="true">
                {Array.from({ length: 5 }).map((_, index) => <div key={index} className="h-9 animate-pulse rounded bg-[var(--app-surface-muted)]" />)}
              </div>
            ) : actors.length === 0 ? (
              <Empty text={hasServerFilters || activeFilters.length ? "No hay registros con estos filtros." : "Sin registros."} />
            ) : (
              <div
                data-table-scroll
                aria-busy={loading}
                className={`proto-tbl-wrap isolate max-h-[calc(100vh-290px)] overflow-auto transition-opacity duration-150 max-sm:max-h-none ${loading ? "pointer-events-none opacity-50" : ""}`}
              >
                <ActorsTable
                  sort={sort}
                  onSort={(next) => {
                    setSort(next);
                    setPage(1);
                  }}
                  actors={actors}
                  customFieldDefinitions={definitions}
                  customValues={customValues}
                  onCustomValue={(actorId, fieldId, value) =>
                    setCustomValues((current) => ({ ...current, [actorId]: { ...(current[actorId] ?? {}), [fieldId]: value } }))}
                  selectedIds={selectedIds}
                  editable={editable}
                  onToggleSelection={(id) => setSelectedIds((current) => (current.includes(id) ? current.filter((item) => item !== id) : [...current, id]))}
                  onSelectAll={setSelectedIds}
                  onOpen={(id) => router.push(`/actors/${id}`)}
                  onActorChange={replaceActor}
                  onAddCustomField={() => {
                    setFieldError("");
                    setFieldModal(true);
                  }}
                  onRequestDeleteCustomField={setFieldToDelete}
                  onAddFilter={addColumnFilter}
                  onError={(message) => toast(message, "danger")}
                />
              </div>
            )}

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
                left={<ExportButtons total={data.total} busy={exporting} onExport={(format) => runExport(format)} />}
              />
            )}
          </>
        )}
      </Card>

      {importing && (
        <ImportModal
          title="Importar clientes y actores"
          endpoint="/v1/parties"
          templateName="plantilla_importacion_clientes_y_actores.xlsx"
          hint="Una fila por organización o persona, con sus roles. Si el documento ya existe no se duplica: se le agregan los roles que falten. Las columnas que agregues al final se vuelven columnas personalizadas."
          onClose={() => setImporting(false)}
          onImported={refresh}
        />
      )}

      {creating && (
        <ActorForm
          kind={creating}
          customFields={definitions}
          onClose={() => setCreating(null)}
          onSaved={(actor, message) => {
            setFlash(message);
            router.push(`/actors/${actor.id}`);
          }}
          onOpenExisting={(id, message) => {
            setFlash(message ?? "Se reutiliza la identidad existente");
            router.push(`/actors/${id}`);
          }}
        />
      )}

      {bulk && (
        <Modal
          title={bulk.type === "activate" ? "Activar registros" : "Desactivar registros"}
          size="sm"
          onClose={() => setBulk(null)}
          footer={
            <>
              <Btn variant="ghost" onClick={() => setBulk(null)}>Volver</Btn>
              <Btn variant={bulk.type === "activate" ? "primary" : "danger"} disabled={busy} onClick={runBulk}>
                {bulk.type === "activate" ? "Activar" : "Desactivar"}
              </Btn>
            </>
          }
        >
          <p className="text-[15px]">
            {bulk.type === "activate"
              ? `Se activarán ${bulk.ids.length} registro(s) con todos sus roles.`
              : `Se inactivarán ${bulk.ids.length} registro(s) con todos sus roles (baja lógica). Se conserva su historial.`}
          </p>
        </Modal>
      )}

      {fieldModal && (
        <Modal title="Agregar columna nueva" size="sm" onClose={() => setFieldModal(false)}>
          <form onSubmit={createField}>
            <div className="grid gap-3">
              <Field label="Nombre de la columna" required error={fieldError}>
                <input className={INPUT} value={fieldForm.label} onChange={(e) => setFieldForm({ ...fieldForm, label: e.target.value })} placeholder="Ej. Instagram" autoFocus />
              </Field>
              <Field label="Tipo de dato">
                <PickSelect
                  className={INPUT}
                  value={fieldForm.field_type}
                  onChange={(value) => setFieldForm({ ...fieldForm, field_type: value as FieldType })}
                  options={FIELD_TYPES}
                />
              </Field>
            </div>
            <p className="app-muted mt-3 text-[12.5px]">La columna aparece para todos los registros de esta tabla. Se llena al crear o editar una organización o persona, o desde la celda.</p>
            <div className="mt-4 flex justify-end gap-2">
              <Btn variant="ghost" onClick={() => setFieldModal(false)}>Cancelar</Btn>
              <Btn type="submit" disabled={busy}>{busy ? "Guardando…" : "Agregar columna"}</Btn>
            </div>
          </form>
        </Modal>
      )}

      {fieldToDelete && (
        <Modal
          title="Eliminar columna"
          size="sm"
          onClose={() => setFieldToDelete(null)}
          footer={
            <>
              <Btn variant="ghost" onClick={() => setFieldToDelete(null)}>Volver</Btn>
              <Btn variant="danger" disabled={busy} onClick={deleteField}>Eliminar columna</Btn>
            </>
          }
        >
          <p className="text-[15px]">Se eliminará la columna «{fieldToDelete.label}» y los valores que tenga en todos los registros. No se puede deshacer.</p>
        </Modal>
      )}
      {toasts}
    </Screen>
  );
}
