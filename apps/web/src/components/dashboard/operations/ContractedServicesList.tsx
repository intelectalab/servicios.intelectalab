"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Plus } from "lucide-react";
import { ApiError, apiFetch } from "@/lib/api";
import { FREQUENCIES, customerName, excelTone, money, setFlash, shortDate, stateOf } from "@/lib/commercial";
import { dateTime, firstError } from "@/lib/crm";
import { CONTRACTED_MOVES, CONTRACTED_STATES, WORK_ORDER_STATES, billingOf, contractedPeriod } from "@/lib/operations";
import { exportCsv, exportXlsx, recordExport, fetchAllPages, type ExportColumn } from "@/lib/export-table";
import {
  Badge, Btn, Card, DataTable, ExportButtons, FilterSelect, Filters, Kv, LoadError, Modal, Notice, PAGE_SIZES, PageHead, Pager, Screen,
  SearchInput, SelectionBar, StateTrack, TD, TH, Timeline, Who, useRowSelection, useToasts, type Column,
  applySort, type SortState,
} from "@/components/ui/proto";
import { ScopeNote } from "@/components/dashboard/ScopeNote";
import { ColumnFilterBar, addColumnFilter, applyFilters, useServerColumnFilters } from "@/components/dashboard/ColumnFilters";
import { ReasonModal } from "@/components/dashboard/crm/CrmModals";
import { ProjectFormModal } from "@/components/dashboard/operations/ProjectModals";
import { WorkOrderFormModal } from "@/components/dashboard/operations/WorkOrderModals";
import type { PaginatedResponse } from "@/types/customer";
import type { ContractedService, ContractedServiceDetail, ProjectOptions, WorkOrderOptions } from "@/types/operations";

type Move = (typeof CONTRACTED_MOVES)[number];
type Toast = (text: string, tone?: "success" | "danger" | "warning", detail?: string) => void;

const LINK = "text-[color:var(--p-brand-text)] hover:underline";
const EMPTY_OPTIONS: WorkOrderOptions = { technicians: [], templates: [], contracted_services: [] };

const serviceKey = (service: ContractedService) => service.id;

const agreed = (service: Pick<ContractedService, "price" | "quantity">) => `${money(service.price)} × ${service.quantity}`;

const EXPORT_COLUMNS: ExportColumn<ContractedService>[] = [
  { label: "Servicio", value: (service) => service.service?.name ?? "", width: 36 },
  { label: "Código", value: (service) => service.service?.code ?? "", width: 16, kind: "center" },
  { label: "Cliente", value: (service) => customerName(service.customer), width: 34 },
  { label: "Contrato", value: (service) => service.contract?.contract_number ?? "", width: 18, kind: "center" },
  { label: "Precio", value: (service) => service.price, width: 14, kind: "money" },
  { label: "Cantidad", value: (service) => service.quantity, width: 11, kind: "number" },
  { label: "Facturación", value: (service) => billingOf(service.service), width: 22 },
  { label: "Inicio", value: (service) => (service.start_date ? shortDate(service.start_date) : ""), width: 15, kind: "center" },
  { label: "Fin", value: (service) => (service.end_date ? shortDate(service.end_date) : ""), width: 15, kind: "center" },
  { label: "Órdenes de trabajo", value: (service) => service.work_orders_count, width: 18, kind: "number" },
  { label: "Responsable", value: (service) => service.owner?.name ?? "", width: 24 },
  {
    label: "Estado",
    value: (service) => stateOf(CONTRACTED_STATES, service.status)[0],
    width: 16,
    kind: "status",
    tone: (service) => excelTone(stateOf(CONTRACTED_STATES, service.status)[1]),
  },
  { label: "Motivo", value: (service) => service.status_reason ?? "", width: 32 },
];

type TopFilters = { search: string; status: string };

function withTopFilters(params: URLSearchParams, top: TopFilters) {
  if (top.search) params.set("search", top.search);
  if (top.status) params.set("status", top.status);
  return params;
}

export default function ContractedServicesList() {
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
  const { selection, selectedRows, clear: clearSelection } = useRowSelection(serviceKey);

  const [data, setData] = useState<PaginatedResponse<ContractedService> | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const [openId, setOpenId] = useState<string | null>(null);
  const [reasoning, setReasoning] = useState<{ service: ContractedService; move: Move } | null>(null);
  const [ordering, setOrdering] = useState<ContractedService | null>(null);
  const [options, setOptions] = useState<WorkOrderOptions | null>(null);
  const [planning, setPlanning] = useState<ContractedService | null>(null);
  const [projectOptions, setProjectOptions] = useState<ProjectOptions | null>(null);
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
      const params = withTopFilters(new URLSearchParams({ per_page: String(perPage), page: String(page) }), { search, status });
      applySort(applyFilters(params, columnFilters.param), sort);

      try {
        const response = await apiFetch<PaginatedResponse<ContractedService>>(`/v1/contracted-services?${params}`);
        if (!cancelled) {
          setData(response);
          setError("");
        }
      } catch {
        if (!cancelled) setError("No se pudieron cargar los servicios contratados.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [page, perPage, search, status, columnFilters.param, sort, reload]);

  /** Cambia el estado del servicio; devuelve si salió bien. */
  async function transition(service: ContractedService, move: Move, reason?: string): Promise<boolean> {
    setBusy(true);
    try {
      await apiFetch(`/v1/contracted-services/${service.id}/${move.path}`, { method: "POST", body: reason ? JSON.stringify({ reason }) : undefined });
      toast(move.done);
      setReasoning(null);
      return true;
    } catch (err) {
      toast(firstError(err, "No se pudo completar la acción."), "danger");
      return false;
    } finally {
      setBusy(false);
      // También tras un error: si otra persona lo cambió, se ve la versión vigente.
      setReload((n) => n + 1);
    }
  }

  function start(service: ContractedService, move: Move) {
    if (move.reason) setReasoning({ service, move });
    else transition(service, move);
  }

  /** Abre "Nueva OT" con el servicio ya elegido; las listas del formulario se piden al abrirlo. */
  function newOrder(service: ContractedService) {
    setOrdering(service);
    if (!options) {
      apiFetch<WorkOrderOptions>("/v1/work-orders/options").then(setOptions).catch(() => setOptions(EMPTY_OPTIONS));
    }
  }

  /** Abre "Nuevo proyecto" con el servicio ya elegido; las listas del formulario se piden al abrirlo. */
  function newProject(service: ContractedService) {
    setPlanning(service);
    if (!projectOptions) {
      apiFetch<ProjectOptions>("/v1/projects/options").then(setProjectOptions).catch(() => setProjectOptions({ people: [], contracted_services: [] }));
    }
  }

  const columns: Column<ContractedService>[] = [
    {
      label: "Servicio", sortKey: "service", filter: "text",
      render: (service) => (
        <div className="min-w-[180px]">
          <strong className="font-semibold">{service.service?.name ?? "—"}</strong>
          <small className="app-muted block text-xs">{customerName(service.customer)}</small>
        </div>
      ),
    },
    {
      label: "Contrato", sortKey: "contract", filter: "text",
      render: (service) =>
        service.contract ? (
          <Link href={`/contracts/${service.contract.id}`} className={LINK} onClick={(event) => event.stopPropagation()}>
            {service.contract.contract_number}
          </Link>
        ) : <span className="app-muted">—</span>,
    },
    {
      label: "Precio pactado", sortKey: "price", filter: "number",
      render: (service) => (
        <div className="whitespace-nowrap">
          <span className="tabular-nums">{agreed(service)}</span>
          <small className="app-muted block text-xs">{billingOf(service.service)}</small>
        </div>
      ),
    },
    { label: "Vigencia", sortKey: "start", filter: "date", render: (service) => <span className="sm:whitespace-nowrap">{contractedPeriod(service)}</span> },
    { label: "OT", num: true, render: (service) => service.work_orders_count },
    {
      label: "Estado", sortKey: "status", filter: { top: "Estado" },
      render: (service) => {
        const [label, tone] = stateOf(CONTRACTED_STATES, service.status);
        return <Badge tone={tone}>{label}</Badge>;
      },
    },
    {
      label: "Acciones",
      render: (service) => (
        // Las acciones no abren la ficha de la fila.
        <div className="flex flex-wrap gap-1.5" onClick={(event) => event.stopPropagation()}>
          {CONTRACTED_MOVES.filter((move) => !move.reason && move.from.includes(service.status)).map((move) => (
            <Btn key={move.path} size="sm" variant="ghost" onClick={() => start(service, move)} permission="contracted_services.manage" disabled={busy}>
              {move.label}
            </Btn>
          ))}
          {service.status === "ACTIVE" && (
            <>
              <Btn size="sm" variant="secondary" onClick={() => newOrder(service)} permission="work_orders.create">
                <Plus size={13} />
                <span>Nueva OT</span>
              </Btn>
              <Btn size="sm" variant="ghost" onClick={() => newProject(service)} permission="projects.create">Nuevo proyecto</Btn>
            </>
          )}
        </div>
      ),
    },
  ];

  /** Exporta las filas marcadas o, sin `picked`, todas las del filtro y búsqueda actuales. */
  async function runExport(format: "xlsx" | "csv", picked?: ContractedService[]) {
    setExporting(true);
    try {
      const rows = picked ?? (await fetchAllPages<ContractedService>("/v1/contracted-services", applySort(columnFilters.apply(withTopFilters(new URLSearchParams(), { search, status })), sort)));
      // La API comprueba el permiso de exportar y deja la exportación en auditoría.
      await recordExport("contracted_services", format, rows.length, Boolean(picked));
      if (format === "xlsx") await exportXlsx("servicios-contratados", "Servicios contratados", EXPORT_COLUMNS, rows);
      else exportCsv("servicios-contratados", EXPORT_COLUMNS, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el listado.", "danger");
    } finally {
      setExporting(false);
    }
  }

  const rows = data?.data ?? [];

  return (
    <Screen>
      <PageHead title="Servicios contratados" />

      <Filters>
        <SearchInput value={searchInput} onChange={setSearchInput} placeholder="Buscar cliente o servicio" />
        <FilterSelect
          label="Estado"
          value={status}
          onChange={(value) => {
            setStatus(value);
            setPage(1);
          }}
          options={CONTRACTED_STATES.map(([key, label]) => [key, label])}
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
        <ScopeNote resource="contracted_services" />
      </Filters>

      {error ? (
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
      ) : (
        <Card flush>
          <SelectionBar permission="contracted_services.export" count={selectedRows.length} busy={exporting} onExport={(format) => runExport(format, selectedRows)} onClear={clearSelection} />
          <DataTable
            tableId="contracted-services" sort={sort} onSort={(next) => { setSort(next); setPage(1); }} refreshing={loading}
            onFilter={(column) => addColumnFilter(columnFilters, column)}
            cols={columns}
            rows={rows}
            rowKey={serviceKey}
            selection={selection}
            onRowClick={(service) => setOpenId(service.id)}
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
              left={<ExportButtons permission="contracted_services.export" total={data.total} busy={exporting} onExport={(format) => runExport(format)} />}
            />
          )}
        </Card>
      )}

      {openId && (
        <ContractedServiceModal
          id={openId}
          // Se vuelve a pedir cuando el listado se refresca tras una acción.
          version={reload}
          busy={busy}
          onClose={() => setOpenId(null)}
          onMove={start}
          onNewOrder={newOrder}
          onNewProject={newProject}
        />
      )}
      {reasoning && (
        <ReasonModal
          title={`${reasoning.move.label} servicio`}
          text={`${reasoning.service.service?.name ?? "Servicio"} · ${customerName(reasoning.service.customer)}`}
          label="Motivo"
          confirmLabel={reasoning.move.label}
          busy={busy}
          onClose={() => setReasoning(null)}
          onConfirm={(reason) => transition(reasoning.service, reasoning.move, reason)}
        />
      )}
      {ordering && (
        <WorkOrderFormModal
          options={options}
          contractedServiceId={ordering.id}
          // El aviso de "creada" se da en la ficha a la que se navega; aquí solo los errores.
          toast={((text, tone, detail) => { if (tone && tone !== "success") toast(text, tone, detail); }) as Toast}
          onClose={() => setOrdering(null)}
          onSaved={(order) => {
            setFlash("Orden de trabajo creada");
            router.push(`/work-orders/${order.id}`);
          }}
        />
      )}
      {planning && (
        <ProjectFormModal
          options={projectOptions}
          contractedServiceId={planning.id}
          defaultName={[planning.service?.name, customerName(planning.customer)].filter(Boolean).join(" — ")}
          // El aviso de "creado" se da en la ficha a la que se navega; aquí solo los errores.
          toast={((text, tone, detail) => { if (tone && tone !== "success") toast(text, tone, detail); }) as Toast}
          onClose={() => setPlanning(null)}
          onSaved={(project) => {
            setFlash("Proyecto creado");
            router.push(`/projects/${project.id}`);
          }}
        />
      )}
      {toasts}
    </Screen>
  );
}

/** Ficha de un servicio contratado, sobre el listado: datos, estados, sus órdenes de trabajo e historial. */
function ContractedServiceModal({
  id, version, busy, onClose, onMove, onNewOrder, onNewProject,
}: {
  id: string;
  version: number;
  busy: boolean;
  onClose: () => void;
  onMove: (service: ContractedService, move: Move) => void;
  onNewOrder: (service: ContractedService) => void;
  onNewProject: (service: ContractedService) => void;
}) {
  const [service, setService] = useState<ContractedServiceDetail | null>(null);
  const [error, setError] = useState("");
  const [retry, setRetry] = useState(0);

  useEffect(() => {
    let cancelled = false;
    apiFetch<ContractedServiceDetail>(`/v1/contracted-services/${id}`)
      .then((next) => {
        if (cancelled) return;
        setService(next);
        setError("");
      })
      .catch((err) => {
        if (!cancelled) setError(err instanceof ApiError && err.status === 404 ? "El servicio contratado no existe." : "No se pudo cargar el servicio contratado.");
      });
    return () => { cancelled = true; };
  }, [id, version, retry]);

  const history = service?.history ?? [];
  const visited = history.flatMap((entry) => (entry.metadata.to ? [entry.metadata.to] : [])).concat("PENDING");
  const moves = service ? CONTRACTED_MOVES.filter((move) => move.from.includes(service.status)) : [];
  const instance = service?.recurring_instance;

  return (
    <Modal title="Servicio contratado" size="lg" onClose={onClose} footer={<Btn variant="ghost" onClick={onClose}>Cerrar</Btn>}>
      {error ? (
        <LoadError text={error} onRetry={() => setRetry((n) => n + 1)} />
      ) : !service ? (
        <div className="h-40 animate-pulse rounded bg-[var(--app-surface-muted)]" aria-busy="true" />
      ) : (
        <div className="grid gap-4">
          <div>
            <h4 className="text-[17px] font-semibold leading-tight">{service.service?.name ?? "Servicio"}</h4>
            <p className="app-muted text-[13px]">{[service.service?.code, customerName(service.customer)].filter(Boolean).join(" · ")}</p>
          </div>

          <div className="grid gap-3">
            <StateTrack states={CONTRACTED_STATES} current={service.status} visited={visited} />
            {service.status_reason && <Notice tone="warn"><strong className="font-semibold">Motivo:</strong> {service.status_reason}</Notice>}
            <div className="flex flex-wrap gap-1.5">
              {moves.map((move) => (
                <Btn key={move.path} variant={move.reason ? "danger-ghost" : "secondary"} onClick={() => onMove(service, move)} permission="contracted_services.manage" disabled={busy}>
                  {move.label}
                </Btn>
              ))}
              {service.status === "ACTIVE" && (
                <>
                  <Btn variant="secondary" onClick={() => onNewOrder(service)} permission="work_orders.create">
                    <Plus size={15} />
                    <span>Nueva OT</span>
                  </Btn>
                  <Btn variant="ghost" onClick={() => onNewProject(service)} permission="projects.create">Nuevo proyecto</Btn>
                </>
              )}
              {!moves.length && <p className="app-muted text-[12.5px]">Sin transiciones disponibles desde este estado.</p>}
            </div>
          </div>

          <Kv
            rows={[
              ["Cliente", customerName(service.customer)],
              [
                "Contrato",
                service.contract ? <Link key="contract" href={`/contracts/${service.contract.id}`} className={LINK}>{service.contract.contract_number}</Link> : "—",
              ],
              ["Precio pactado", agreed(service)],
              ["Facturación", billingOf(service.service)],
              ["Vigencia", contractedPeriod(service)],
              ["Responsable", <Who key="owner" name={service.owner?.name} />],
              instance ? [
                "Instancia recurrente",
                `${instance.status === "ACTIVE" ? "Activa" : instance.status === "SUSPENDED" ? "Suspendida" : "Cancelada"} · ${FREQUENCIES[instance.frequency] ?? instance.frequency}${instance.next_cycle_date ? ` · próximo ciclo ${shortDate(instance.next_cycle_date)}` : ""}`,
              ] : null,
            ]}
          />

          <section>
            <h4 className="mb-1 text-[15px] font-semibold">Órdenes de trabajo</h4>
            {service.work_orders.length ? (
              <div className="proto-tbl-wrap overflow-x-auto">
                <table className="proto-tbl w-full border-collapse text-[13px]">
                  <thead>
                    <tr>
                      <th className={TH}>OT</th>
                      <th className={TH}>Programada</th>
                      <th className={TH}>Técnico</th>
                      <th className={TH}>Estado</th>
                    </tr>
                  </thead>
                  <tbody>
                    {service.work_orders.map((order) => (
                      <tr key={order.id}>
                        <td className={TD} data-label="OT">
                          <Link href={`/work-orders/${order.id}`} className={`${LINK} font-semibold`}>{order.code}</Link>
                          <small className="app-muted block text-xs">{order.title}</small>
                        </td>
                        <td className={`${TD} whitespace-nowrap`} data-label="Programada">{order.scheduled_start ? dateTime(order.scheduled_start) : "Sin programar"}</td>
                        <td className={TD} data-label="Técnico"><Who name={order.assignee} /></td>
                        <td className={TD} data-label="Estado"><Badge tone={stateOf(WORK_ORDER_STATES, order.status)[1]}>{stateOf(WORK_ORDER_STATES, order.status)[0]}</Badge></td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : (
              <p className="app-muted">Sin órdenes de trabajo.</p>
            )}
          </section>

          <section>
            <h4 className="mb-1 text-[15px] font-semibold">Historial</h4>
            <Timeline
              items={history.map((entry, index) => {
                const to = entry.metadata.to;
                return {
                  key: `${entry.at}-${index}`,
                  head: to ? (
                    <>
                      <Badge tone={stateOf(CONTRACTED_STATES, to)[1]}>{stateOf(CONTRACTED_STATES, to)[0]}</Badge>
                      {entry.metadata.from && entry.metadata.from !== to && (
                        <span className="app-muted text-[12.5px]">desde {stateOf(CONTRACTED_STATES, entry.metadata.from)[0]}</span>
                      )}
                    </>
                  ) : (
                    <span className="font-medium">{entry.action}</span>
                  ),
                  meta: `${entry.user ?? "Sistema"} · ${dateTime(entry.at)}`,
                  note: entry.metadata.note,
                };
              })}
            />
          </section>
        </div>
      )}
    </Modal>
  );
}
