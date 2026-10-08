"use client";

import { useEffect, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { ApiError, apiFetch } from "@/lib/api";
import { customerDocument, customerName, excelTone, shortDate, stateOf } from "@/lib/commercial";
import { exportCsv, exportXlsx, fetchAllPages, type ExportColumn } from "@/lib/export-table";
import {
  Badge, Btn, Card, Chip, DataTable, ExportButtons, Field, FilterSelect, Filters, INPUT, LinkBtn, LoadError, Modal, PAGE_SIZES,
  PageHead, Pager, Screen, SearchInput, SelectionBar, useRowSelection, useToasts, type Column, type Tone,
  applySort, type SortState,
} from "@/components/ui/proto";
import type { PaginatedResponse } from "@/types/customer";
import type { PortalRequest, PortalRequestStatus } from "@/types/commercial";

const REQUEST_STATES: [PortalRequestStatus, string, Tone][] = [
  ["SUBMITTED", "Recibida", "info"],
  ["IN_REVIEW", "En revisión", "warning"],
  ["QUOTED", "Cotizada", "success"],
  ["REJECTED", "Rechazada", "danger"],
];

/** Con CRM la solicitud se vuelve una oportunidad; sin él se cotiza directo en el ERP. */
const routeOf = (request: PortalRequest): [string, "accent" | "neutral"] =>
  request.crm_opportunity_id ? ["CRM → Oportunidad", "accent"] : ["ERP directo", "neutral"];

const isOpen = (request: PortalRequest) => request.status === "SUBMITTED" || request.status === "IN_REVIEW";

const EXPORT_COLUMNS: ExportColumn<PortalRequest>[] = [
  { label: "Solicitud", value: (request) => request.request_number ?? "", width: 18, kind: "center" },
  { label: "Fecha", value: (request) => shortDate(request.created_at), width: 15, kind: "center" },
  { label: "Cliente", value: (request) => customerName(request.customer), width: 36 },
  { label: "Documento", value: (request) => customerDocument(request.customer), width: 20 },
  { label: "Servicio", value: (request) => request.service?.name ?? "", width: 30 },
  { label: "Descripción", value: (request) => request.description || request.subject, width: 50 },
  { label: "Ruta", value: (request) => routeOf(request)[0], width: 20, kind: "center" },
  {
    label: "Estado",
    value: (request) => stateOf(REQUEST_STATES, request.status)[0],
    width: 14,
    kind: "status",
    tone: (request) => excelTone(stateOf(REQUEST_STATES, request.status)[1]),
  },
  { label: "Cotización", value: (request) => request.quotation?.quotation_number ?? "", width: 18, kind: "center" },
  { label: "Motivo de rechazo", value: (request) => request.resolution_note ?? "", width: 40 },
];

const requestKey = (request: PortalRequest) => request.id;

function firstError(err: unknown, fallback: string) {
  if (err instanceof ApiError) return (err.errors && Object.values(err.errors)[0]?.[0]) || err.message;
  return fallback;
}

export default function PortalRequestsList() {
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
  const { selection, selectedRows, clear: clearSelection } = useRowSelection(requestKey);

  const [data, setData] = useState<PaginatedResponse<PortalRequest> | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const [busy, setBusy] = useState(false);
  const [rejecting, setRejecting] = useState<PortalRequest | null>(null);
  const [reason, setReason] = useState("");
  const [reasonError, setReasonError] = useState("");

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
      const params = new URLSearchParams({ per_page: String(perPage), page: String(page) });
      if (search) params.set("search", search);
      if (status) params.set("status", status);
      applySort(params, sort);

      try {
        const response = await apiFetch<PaginatedResponse<PortalRequest>>(`/v1/portal-requests?${params}`);
        if (!cancelled) {
          setData(response);
          setError("");
        }
      } catch {
        if (!cancelled) setError("No se pudieron cargar las solicitudes.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [page, perPage, search, status, sort, reload]);

  /** Toma la solicitud (si aún está recibida) y abre una cotización con su cliente y su servicio. */
  async function quote(request: PortalRequest) {
    setBusy(true);
    try {
      if (request.status === "SUBMITTED") {
        await apiFetch(`/v1/portal-requests/${request.id}/take`, { method: "POST" });
        toast("Solicitud tomada");
      }
      const params = new URLSearchParams({ c: request.customer_id, r: request.id });
      if (request.service_id) params.set("s", request.service_id);
      router.push(`/quotations/new?${params}`);
    } catch (err) {
      toast(firstError(err, "No se pudo tomar la solicitud."), "danger");
      setBusy(false);
    }
  }

  async function reject() {
    if (!rejecting) return;
    const text = reason.trim();
    if (!text) {
      setReasonError("Debes indicar un motivo.");
      return;
    }
    setBusy(true);
    try {
      await apiFetch(`/v1/portal-requests/${rejecting.id}/reject`, { method: "POST", body: JSON.stringify({ reason: text }) });
      toast("Rechazar: listo");
      setRejecting(null);
      setReload((n) => n + 1);
    } catch (err) {
      toast(firstError(err, "No se pudo rechazar la solicitud."), "danger");
    } finally {
      setBusy(false);
    }
  }

  const columns = useMemo<Column<PortalRequest>[]>(() => [
    {
      label: "Solicitud", sortKey: "number",
      render: (request) => (
        <div className="min-w-[180px] max-w-[340px]">
          <strong className="font-semibold">{request.request_number ?? "—"}</strong>
          <small className="app-muted block text-xs">{request.description || request.subject}</small>
        </div>
      ),
    },
    { label: "Cliente", sortKey: "customer", render: (request) => customerName(request.customer) },
    { label: "Servicio", sortKey: "service", render: (request) => request.service?.name ?? "—" },
    {
      label: "Ruta",
      render: (request) => {
        const [label, tone] = routeOf(request);
        return <Chip tone={tone}>{label}</Chip>;
      },
    },
    {
      label: "Estado", sortKey: "status",
      render: (request) => {
        const [label, tone] = stateOf(REQUEST_STATES, request.status);
        return (
          <>
            <Badge tone={tone}>{label}</Badge>
            {request.status === "REJECTED" && request.resolution_note && (
              <small className="app-muted mt-1 block max-w-[220px] text-xs">{request.resolution_note}</small>
            )}
          </>
        );
      },
    },
    {
      label: "",
      render: (request) => (
        <div className="flex flex-wrap items-center gap-1.5 sm:flex-nowrap sm:justify-end">
          {request.crm_opportunity_id ? (
            // Aún sin función: el pipeline de CRM no está construido.
            <Btn variant="ghost" size="sm" onClick={() => toast("El pipeline de CRM aún no está disponible.", "warning")}>
              Ver oportunidad
            </Btn>
          ) : isOpen(request) ? (
            <>
              <Btn variant="secondary" size="sm" onClick={() => quote(request)} permission="quotations.manage" disabled={busy}>
                Cotizar
              </Btn>
              <Btn
                variant="danger-ghost"
                size="sm"
                onClick={() => {
                  setReason("");
                  setReasonError("");
                  setRejecting(request);
                }}
                permission="portal.manage"
                disabled={busy}
              >
                Rechazar
              </Btn>
            </>
          ) : request.quotation ? (
            <LinkBtn href={`/quotations/${request.quotation.id}`} variant="ghost" size="sm">Ver cotización</LinkBtn>
          ) : null}
        </div>
      ),
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps -- quote solo usa router y toast, que no cambian
  ], [busy]);

  /** Exporta las filas marcadas o, sin `picked`, todas las del filtro y búsqueda actuales. */
  async function runExport(format: "xlsx" | "csv", picked?: PortalRequest[]) {
    setExporting(true);
    try {
      const params = new URLSearchParams();
      if (search) params.set("search", search);
      if (status) params.set("status", status);
      const rows = picked ?? (await fetchAllPages<PortalRequest>("/v1/portal-requests", applySort(params, sort)));
      if (format === "xlsx") await exportXlsx("solicitudes-portal", "Solicitudes del portal", EXPORT_COLUMNS, rows);
      else exportCsv("solicitudes-portal", EXPORT_COLUMNS, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el listado.", "danger");
    } finally {
      setExporting(false);
    }
  }

  return (
    <Screen>
      <PageHead title="Solicitudes del portal" />

      <Filters>
        <SearchInput value={searchInput} onChange={setSearchInput} placeholder="Buscar…" />
        <FilterSelect
          label="Estado"
          value={status}
          onChange={(value) => {
            setStatus(value);
            setPage(1);
          }}
          options={REQUEST_STATES.map(([key, label]) => [key, label])}
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
              tableId="portal-requests" sort={sort} onSort={(next) => { setSort(next); setPage(1); }} refreshing={loading}
              cols={columns}
              rows={data?.data ?? []}
              rowKey={requestKey}
              selection={selection}
              loading={loading && !data}
              empty={search || status ? "No hay registros con estos filtros." : "Sin solicitudes."}
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

      {rejecting && (
        <Modal
          title="Rechazar"
          size="sm"
          onClose={() => setRejecting(null)}
          footer={
            <>
              <Btn variant="ghost" onClick={() => setRejecting(null)}>Volver</Btn>
              <Btn variant="danger" disabled={busy} onClick={reject}>Rechazar</Btn>
            </>
          }
        >
          <p className="text-[15px]">Esta transición requiere una justificación.</p>
          <div className="mt-3">
            <Field label="Motivo" required error={reasonError}>
              <textarea
                className={INPUT}
                rows={3}
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                placeholder="Queda registrado en auditoría"
                autoFocus
              />
            </Field>
          </div>
        </Modal>
      )}
      {toasts}
    </Screen>
  );
}
