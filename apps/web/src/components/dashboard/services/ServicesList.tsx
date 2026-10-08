"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Plus, Upload } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { BILLING_MODES, SERVICE_STATES, billingLabel, excelTone, money, stateOf } from "@/lib/commercial";
import { exportCsv, exportXlsx, fetchAllPages, type ExportColumn } from "@/lib/export-table";
import {
  Badge, Btn, Card, Chip, DataTable, ExportButtons, FilterSelect, Filters, LoadError, PAGE_SIZES, PageHead, Pager, Screen,
  SearchInput, SelectionBar, useRowSelection, useToasts, type Column,
  applySort, type SortState,
} from "@/components/ui/proto";
import ServiceFormModal from "./ServiceFormModal";
import { ImportModal } from "@/components/dashboard/ImportModal";
import type { PaginatedResponse } from "@/types/customer";
import type { Service, ServiceCategory } from "@/types/commercial";

const warranty = (service: Service) => (service.warranty_months ? `${service.warranty_months} meses` : "—");

const COLUMNS: Column<Service>[] = [
  {
    label: "Servicio", sortKey: "name",
    render: (service) => (
      <div className="min-w-[180px]">
        <strong className="font-semibold">{service.name}</strong>
        <small className="app-muted block font-mono text-[11.5px]">{service.code}</small>
      </div>
    ),
  },
  { label: "Categoría", sortKey: "category", render: (service) => service.category?.name ?? "—" },
  {
    label: "Modalidad", sortKey: "billing_mode",
    render: (service) => <Chip tone={service.billing_mode === "RECURRING" ? "accent" : "neutral"}>{billingLabel(service)}</Chip>,
  },
  {
    label: "Precio vigente", sortKey: "price",
    num: true,
    render: (service) =>
      service.current_price ? (
        <>
          {money(service.current_price.amount, service.current_price.currency)}
          {service.unit && <small className="app-muted block text-xs">/ {service.unit}</small>}
        </>
      ) : (
        <span className="app-muted">Sin precio</span>
      ),
  },
  { label: "Garantía", sortKey: "warranty", render: warranty },
  {
    label: "Estado", sortKey: "status",
    render: (service) => {
      const [label, tone] = stateOf(SERVICE_STATES, service.status);
      return <Badge tone={tone}>{label}</Badge>;
    },
  },
];

const EXPORT_COLUMNS: ExportColumn<Service>[] = [
  { label: "Código", value: (service) => service.code, width: 14, kind: "center" },
  { label: "Servicio", value: (service) => service.name, width: 36 },
  { label: "Categoría", value: (service) => service.category?.name ?? "", width: 22 },
  { label: "Modalidad", value: billingLabel, width: 24, kind: "center" },
  { label: "Unidad", value: (service) => service.unit ?? "", width: 14, kind: "center" },
  {
    label: "Precio vigente (sin IGV)",
    value: (service) => (service.current_price ? Number(service.current_price.amount) : ""),
    width: 20,
    kind: "money",
  },
  { label: "Garantía (meses)", value: (service) => service.warranty_months || "", width: 14, kind: "number" },
  {
    label: "Estado",
    value: (service) => stateOf(SERVICE_STATES, service.status)[0],
    width: 14,
    kind: "status",
    tone: (service) => excelTone(stateOf(SERVICE_STATES, service.status)[1]),
  },
];

const serviceKey = (service: Service) => service.id;

export default function ServicesList() {
  const router = useRouter();
  const { toast, toasts } = useToasts();

  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [category, setCategory] = useState("");
  const [mode, setMode] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [reload, setReload] = useState(0);
  const [sort, setSort] = useState<SortState>(null);
  const { selection, selectedRows, clear: clearSelection } = useRowSelection(serviceKey);

  const [data, setData] = useState<PaginatedResponse<Service> | null>(null);
  const [categories, setCategories] = useState<ServiceCategory[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [creating, setCreating] = useState(false);
  const [importing, setImporting] = useState(false);
  const [exporting, setExporting] = useState(false);

  // La búsqueda espera a que se deje de escribir.
  useEffect(() => {
    const timer = window.setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, 350);
    return () => window.clearTimeout(timer);
  }, [searchInput]);

  useEffect(() => {
    let cancelled = false;
    apiFetch<ServiceCategory[]>("/v1/service-categories")
      .then((list) => { if (!cancelled) setCategories(list); })
      .catch(() => { /* sin categorías el filtro queda vacío */ });
    return () => { cancelled = true; };
    // También tras importar, que puede crear categorías nuevas.
  }, [reload]);

  function filterParams() {
    const params = new URLSearchParams();
    if (search) params.set("search", search);
    if (category) params.set("category_id", category);
    if (mode) params.set("billing_mode", mode);
    if (status) params.set("status", status);
    return params;
  }

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setLoading(true);
      const params = filterParams();
      params.set("per_page", String(perPage));
      params.set("page", String(page));
      applySort(params, sort);

      try {
        const response = await apiFetch<PaginatedResponse<Service>>(`/v1/services?${params}`);
        if (!cancelled) {
          setData(response);
          setError("");
        }
      } catch {
        if (!cancelled) setError("No se pudo cargar el catálogo de servicios.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
    // filterParams solo lee los filtros que ya están en las dependencias.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [page, perPage, search, category, mode, status, sort, reload]);

  const pick = (setter: (value: string) => void) => (value: string) => {
    setter(value);
    setPage(1);
  };

  /** Exporta las filas marcadas o, sin `rows`, todas las que cumplen los filtros (no solo la página visible). */
  async function runExport(format: "xlsx" | "csv", picked?: Service[]) {
    setExporting(true);
    try {
      const rows = picked ?? (await fetchAllPages<Service>("/v1/services", applySort(filterParams(), sort)));
      if (format === "xlsx") await exportXlsx("catalogo_servicios", "Catálogo de servicios", EXPORT_COLUMNS, rows);
      else exportCsv("catalogo_servicios", EXPORT_COLUMNS, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el listado.", "danger");
    } finally {
      setExporting(false);
    }
  }

  return (
    <Screen>
      <PageHead
        title="Catálogo de servicios"
        actions={
          <>
            <Btn
              onClick={() => setCreating(true)}
              permission="services.manage"
            >
              <Plus size={16} />
              <span>Nuevo servicio</span>
            </Btn>
            <Btn variant="ghost" onClick={() => setImporting(true)} permission="services.manage">
              <Upload size={16} />
              <span>Importar</span>
            </Btn>
          </>
        }
      />

      <Filters>
        <SearchInput value={searchInput} onChange={setSearchInput} placeholder="Buscar servicio" />
        <FilterSelect label="Categoría" value={category} onChange={pick(setCategory)} options={categories.map((c) => [c.id, c.name])} />
        <FilterSelect label="Modalidad" value={mode} onChange={pick(setMode)} options={Object.entries(BILLING_MODES)} />
        <FilterSelect
          label="Estado"
          value={status}
          onChange={pick(setStatus)}
          options={SERVICE_STATES.map(([key, label]) => [key, label])}
        />
      </Filters>

      <Card flush>
        {error ? (
          <LoadError text={error} onRetry={() => setReload((n) => n + 1)} />
        ) : (
          <>
            <SelectionBar
              count={selectedRows.length}
              busy={exporting}
              onExport={(format) => runExport(format, selectedRows)}
              onClear={clearSelection}
            />
            <DataTable
              tableId="services" sort={sort} onSort={(next) => { setSort(next); setPage(1); }} refreshing={loading}
              cols={COLUMNS}
              rows={data?.data ?? []}
              rowKey={serviceKey}
              selection={selection}
              onRowClick={(service) => router.push(`/services/${service.id}`)}
              loading={loading && !data}
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
                left={<ExportButtons total={data.total} busy={exporting} onExport={(format) => runExport(format)} />}
              />
            )}
          </>
        )}
      </Card>

      {importing && (
        <ImportModal
          title="Importar servicios"
          endpoint="/v1/services"
          templateName="plantilla_importacion_servicios.xlsx"
          hint="Una fila por servicio. Entran en borrador, con su categoría (se crea si no existe) y su precio inicial. Un código que ya existe se omite."
          onClose={() => setImporting(false)}
          onImported={() => setReload((n) => n + 1)}
        />
      )}

      {creating && (
        <ServiceFormModal
          categories={categories}
          suggestedCode={`SRV-${String((data?.total ?? 0) + 1).padStart(3, "0")}`}
          onClose={() => setCreating(false)}
          onCategoryCreated={(created) =>
            setCategories((list) => [...list, created].sort((a, b) => a.name.localeCompare(b.name)))
          }
          onSaved={() => {
            setCreating(false);
            toast("Servicio guardado");
            setReload((n) => n + 1);
          }}
        />
      )}
      {toasts}
    </Screen>
  );
}
