"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { FileText, Upload } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { excelTone, stateOf } from "@/lib/commercial";
import { dateTime } from "@/lib/crm";
import { fileSize } from "@/lib/operations";
import { DOCUMENT_STATES, VISIBILITIES, antivirusText, shortSha } from "@/lib/documents";
import { exportCsv, exportXlsx, fetchAllPages, recordExport, type ExportColumn } from "@/lib/export-table";
import {
  Btn, Card, DataTable, ExportButtons, FilterSelect, Filters, LoadError, PAGE_SIZES, PageHead, Pager, Screen, SearchInput, SelectionBar,
  applySort, useRowSelection, useToasts, type Column, type SortState,
} from "@/components/ui/proto";
import { ScopeNote } from "@/components/dashboard/ScopeNote";
import { ColumnFilterBar, addColumnFilter, applyFilters, useServerColumnFilters } from "@/components/dashboard/ColumnFilters";
import { DocumentDetailModal, DocumentStatusBadge, DocumentUploadModal, LinkChips, VisibilityChip } from "@/components/dashboard/documents/DocumentModals";
import type { PaginatedResponse } from "@/types/customer";
import type { DocumentRow } from "@/types/documents";

const CATEGORIES = ["Contrato", "Entregable", "Evidencia", "Proyecto", "Cliente", "Proveedor", "Servidor", "Dominio", "Hosting", "Cloud", "Otros"];
const documentKey = (document: DocumentRow) => document.id;

const EXPORT_COLUMNS: ExportColumn<DocumentRow>[] = [
  { label: "Documento", value: (doc) => doc.title, width: 36 },
  { label: "Archivo", value: (doc) => doc.file?.name ?? "", width: 32 },
  { label: "Tamaño", value: (doc) => (doc.file ? fileSize(doc.file.size) : ""), width: 12, kind: "center" },
  { label: "Categoría", value: (doc) => doc.category, width: 16 },
  { label: "Vinculado a", value: (doc) => doc.links.map((link) => link.label).join(" · "), width: 40 },
  { label: "Versión", value: (doc) => `v${doc.version}`, width: 10, kind: "center" },
  { label: "SHA-256", value: (doc) => doc.file?.sha256 ?? "", width: 30 },
  { label: "Antivirus", value: (doc) => (doc.file ? antivirusText(doc.file.antivirus, doc.file.engine)[0] : ""), width: 20 },
  { label: "Visibilidad", value: (doc) => (doc.visibility === "PORTAL" ? "Visible en portal" : "Interno"), width: 18, kind: "center" },
  {
    label: "Estado", value: (doc) => stateOf(DOCUMENT_STATES, doc.status)[0], width: 16, kind: "status",
    tone: (doc) => excelTone(stateOf(DOCUMENT_STATES, doc.status)[1]),
  },
  { label: "Subido por", value: (doc) => doc.creator ?? "", width: 24 },
  { label: "Actualizado", value: (doc) => dateTime(doc.updated_at), width: 22, kind: "center" },
];

type TopFilters = { search: string; category: string; status: string; visibility: string };

function withTopFilters(params: URLSearchParams, top: TopFilters) {
  if (top.search) params.set("search", top.search);
  if (top.category) params.set("category", top.category);
  if (top.status) params.set("status", top.status);
  if (top.visibility) params.set("visibility", top.visibility);
  return params;
}

export default function DocumentsList() {
  const router = useRouter();
  const { toast, toasts } = useToasts();
  const [exporting, setExporting] = useState(false);

  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [category, setCategory] = useState("");
  const [status, setStatus] = useState("");
  const [visibility, setVisibility] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [reload, setReload] = useState(0);
  const [sort, setSort] = useState<SortState>(null);
  const columnFilters = useServerColumnFilters(() => setPage(1));
  const { selection, selectedRows, clear: clearSelection } = useRowSelection(documentKey);

  const [data, setData] = useState<PaginatedResponse<DocumentRow> | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [uploading, setUploading] = useState(false);
  const [open, setOpen] = useState<string | null>(null);

  // La ficha se abre también desde un enlace (?document=).
  useEffect(() => {
    async function fromLink() {
      const id = new URLSearchParams(window.location.search).get("document");
      if (id) setOpen(id);
    }
    fromLink();
  }, []);

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
      const params = withTopFilters(new URLSearchParams({ per_page: String(perPage), page: String(page) }), { search, category, status, visibility });
      applySort(applyFilters(params, columnFilters.param), sort);
      try {
        const response = await apiFetch<PaginatedResponse<DocumentRow>>(`/v1/documents?${params}`);
        if (!cancelled) {
          setData(response);
          setError("");
        }
      } catch {
        if (!cancelled) setError("No se pudieron cargar los documentos.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [page, perPage, search, category, status, visibility, columnFilters.param, sort, reload]);

  async function runExport(format: "xlsx" | "csv", picked?: DocumentRow[]) {
    setExporting(true);
    try {
      const rows = picked ?? (await fetchAllPages<DocumentRow>("/v1/documents", applySort(columnFilters.apply(withTopFilters(new URLSearchParams(), { search, category, status, visibility })), sort)));
      await recordExport("documents", format, rows.length, Boolean(picked));
      if (format === "xlsx") await exportXlsx("documentos", "Documentos", EXPORT_COLUMNS, rows);
      else exportCsv("documentos", EXPORT_COLUMNS, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el listado.", "danger");
    } finally {
      setExporting(false);
    }
  }

  const cols: Column<DocumentRow>[] = [
    {
      label: "Documento", sortKey: "document", filter: "text",
      render: (doc) => (
        <div className="flex min-w-[150px] max-w-[300px] items-start gap-2">
          <FileText size={17} className="mt-0.5 shrink-0 text-[color:var(--p-brand)]" aria-hidden="true" />
          <div className="min-w-0">
            <strong className="font-semibold">{doc.title}</strong>
            <small className="app-muted block text-xs [overflow-wrap:anywhere]">{doc.file ? `${doc.file.name} · ${fileSize(doc.file.size)}` : "Sin archivo"}</small>
          </div>
        </div>
      ),
    },
    { label: "Categoría", sortKey: "category", filter: { top: "Categoría" }, render: (doc) => doc.category },
    { label: "Vinculado a", render: (doc) => <div className="min-w-[110px] max-w-[190px]"><LinkChips links={doc.links} stop /></div> },
    { label: "Versión", sortKey: "version", render: (doc) => `v${doc.version}` },
    {
      label: "Integridad",
      render: (doc) => (doc.file ? <code className="text-[11.5px]" title={doc.file.sha256}>{shortSha(doc.file.sha256, 8)}</code> : <span className="app-muted">—</span>),
    },
    { label: "Visibilidad", sortKey: "visibility", filter: { top: "Visibilidad" }, render: (doc) => <VisibilityChip visibility={doc.visibility} /> },
    { label: "Estado", sortKey: "status", filter: { top: "Estado" }, render: (doc) => <DocumentStatusBadge status={doc.status} /> },
  ];

  const rows = data?.data ?? [];

  return (
    <Screen>
      <PageHead
        title="Documentos"
        actions={
          <Btn onClick={() => setUploading(true)} permission="documents.create">
            <Upload size={16} />
            <span>Subir documento</span>
          </Btn>
        }
      />

      <Filters>
        <SearchInput value={searchInput} onChange={setSearchInput} placeholder="Buscar título o archivo" />
        <FilterSelect label="Categoría" value={category} onChange={(value) => { setCategory(value); setPage(1); }} options={CATEGORIES.map((item) => [item, item])} />
        <FilterSelect label="Estado" value={status} onChange={(value) => { setStatus(value); setPage(1); }} options={DOCUMENT_STATES.map(([key, label]) => [key, label])} />
        <FilterSelect label="Visibilidad" value={visibility} onChange={(value) => { setVisibility(value); setPage(1); }} options={VISIBILITIES.map(([key, label]) => [key, label])} />
        <ColumnFilterBar
          filters={columnFilters}
          hasOtherFilters={Boolean(searchInput || category || status || visibility)}
          onClearAll={() => {
            setSearchInput("");
            setSearch("");
            setCategory("");
            setStatus("");
            setVisibility("");
            setPage(1);
          }}
        />
        <ScopeNote resource="documents" />
      </Filters>

      {error ? (
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
      ) : (
        <Card flush>
          <SelectionBar permission="documents.export" count={selectedRows.length} busy={exporting} onExport={(format) => runExport(format, selectedRows)} onClear={clearSelection} />
          <DataTable
            tableId="documents" sort={sort} onSort={(next) => { setSort(next); setPage(1); }} refreshing={loading}
            onFilter={(column) => addColumnFilter(columnFilters, column)}
            cols={cols}
            rows={rows}
            rowKey={documentKey}
            selection={selection}
            onRowClick={(doc) => setOpen(doc.id)}
            loading={loading && !data}
            empty="No hay documentos con estos filtros."
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
              left={<ExportButtons permission="documents.export" total={data.total} busy={exporting} onExport={(format) => runExport(format)} />}
            />
          )}
        </Card>
      )}

      {uploading && (
        <DocumentUploadModal
          toast={toast}
          onClose={() => setUploading(false)}
          onUploaded={() => setReload((n) => n + 1)}
        />
      )}
      {open && (
        <DocumentDetailModal
          documentId={open}
          toast={toast}
          onClose={() => {
            setOpen(null);
            if (window.location.search.includes("document=")) router.replace("/documents");
          }}
          onChanged={() => setReload((n) => n + 1)}
        />
      )}
      {toasts}
    </Screen>
  );
}
