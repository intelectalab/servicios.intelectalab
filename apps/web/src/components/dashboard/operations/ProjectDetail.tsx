"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ArrowLeft, Check, Clock, FileText, Lock, Pencil, Plus, Upload } from "lucide-react";
import { ApiError, apiFetch, apiFetchLater } from "@/lib/api";
import { customerName, money, shortDate, stateOf, takeFlash } from "@/lib/commercial";
import { dateTime, firstError } from "@/lib/crm";
import {
  DELIVERABLE_STATES, PROJECT_MOVES, PROJECT_OPEN, PROJECT_STATES, TASK_MOVES, TASK_STATES,
  fileSize, hoursText, projectHistoryLabel, projectPeriod, type ProjectMove,
} from "@/lib/operations";
import { useAccess } from "@/lib/access-context";
import {
  Badge, Btn, Card, Kpi, Kpis, Kv, LoadError, Notice, PageHead, Screen, StateTrack, TD, TH, Tabs, Timeline, Who, deniedText, useToasts,
} from "@/components/ui/proto";
import { SortableBoard } from "@/components/ui/sortable-cards";
import { Kanban, type KanbanColumn, MoveLocked } from "@/components/ui/kanban";
import { PickSelect } from "@/components/ui/pick-select";
import { ReasonModal } from "@/components/dashboard/crm/CrmModals";
import { CostEntriesList, Locked, TimeEntriesList } from "@/components/dashboard/operations/Entries";
import {
  CostFormModal, DeliverableFormModal, ProjectFormModal, TaskFormModal, TimeEntryFormModal,
} from "@/components/dashboard/operations/ProjectModals";
import { DeliverableDocumentModal } from "@/components/dashboard/operations/DeliverableDocumentModal";
import { Margin } from "@/components/dashboard/operations/ProjectsList";
import { PriorityChip } from "@/components/dashboard/operations/WorkOrdersList";
import type {
  Deliverable, ProjectDetail as Project, ProjectOptions, ProjectTask, TaskStatus, TimeEntryOptions,
} from "@/types/operations";

const LINK = "text-[color:var(--p-brand-text)] hover:underline";

// Aire entre la tabla y el borde de su tarjeta (en teléfono las filas ya son tarjetas con su margen).
// Con ese aire, la línea bajo la última fila queda suelta: se quita.
const TABLE_PAD = "sm:px-2.5 sm:pb-2.5 sm:[&_tbody_tr:last-child>td]:border-b-0";

// Las canceladas no tienen columna: se listan debajo del tablero.
const TASK_COLUMNS: KanbanColumn[] = [
  { id: "TODO", label: "Por hacer", tone: "neutral" },
  { id: "IN_PROGRESS", label: "En curso", tone: "accent" },
  { id: "BLOCKED", label: "Bloqueadas", tone: "warning" },
  { id: "DONE", label: "Hechas", tone: "success" },
];

type Tab = "tareas" | "horas" | "costos" | "entregables" | "resumen";

/** Acción que pide un motivo antes de ejecutarse. */
type Reasoned = { title: string; text: string; label: string; confirm: string; run: (reason: string) => Promise<boolean> };

export default function ProjectDetail() {
  const { id } = useParams<{ id: string }>();
  const { can, loading: accessLoading } = useAccess();
  const { toast, toasts } = useToasts();

  const [project, setProject] = useState<Project | null>(null);
  const [options, setOptions] = useState<ProjectOptions | null>(null);
  const [timeOptions, setTimeOptions] = useState<TimeEntryOptions | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [entries, setEntries] = useState(0);
  const [busy, setBusy] = useState(false);
  const [tab, setTab] = useState<Tab>("tareas");
  // Contadores de las pestañas Horas y Costos: los da cada tabla al cargar (dentro del alcance de quien mira).
  const [hoursTotal, setHoursTotal] = useState<number | null>(null);
  const [costsTotal, setCostsTotal] = useState<number | null>(null);

  const [editing, setEditing] = useState(false);
  const [taskForm, setTaskForm] = useState<{ task?: ProjectTask } | null>(null);
  const [deliverableForm, setDeliverableForm] = useState<{ deliverable?: Deliverable } | null>(null);
  const [attachingId, setAttachingId] = useState<string | null>(null);
  const [logging, setLogging] = useState(false);
  const [costing, setCosting] = useState(false);
  const [reasoning, setReasoning] = useState<Reasoned | null>(null);

  useEffect(() => {
    const flash = takeFlash();
    if (flash) toast(flash);
  }, [toast]);

  useEffect(() => {
    let cancelled = false;
    apiFetch<Project>(`/v1/projects/${id}`)
      .then((next) => {
        if (cancelled) return;
        setProject(next);
        setError("");
      })
      .catch((err) => {
        if (!cancelled) setError(err instanceof ApiError && err.status === 404 ? "El proyecto no existe." : "No se pudo cargar el proyecto.");
      });
    return () => { cancelled = true; };
  }, [id, reload]);

  // Personas para el responsable y las tareas: después de la ficha.
  useEffect(() => {
    let cancelled = false;
    apiFetchLater<ProjectOptions>("/v1/projects/options")
      .then((next) => { if (!cancelled) setOptions(next); })
      .catch(() => undefined);
    return () => { cancelled = true; };
  }, []);

  /** Ejecuta una acción sobre el proyecto y refresca la ficha con lo que devuelve la API. */
  async function act(path: string, method: "POST" | "PATCH", body: unknown, done: string): Promise<boolean> {
    setBusy(true);
    try {
      const updated = await apiFetch<Project>(`/v1/projects/${id}/${path}`, { method, body: body ? JSON.stringify(body) : undefined });
      setProject(updated);
      toast(done);
      return true;
    } catch (err) {
      toast(firstError(err, "No se pudo completar la acción."), "danger");
      // Otra persona lo cambió: se muestra la versión vigente.
      if (err instanceof ApiError && err.status === 409) setReload((n) => n + 1);
      return false;
    } finally {
      setBusy(false);
    }
  }

  function move(transition: ProjectMove) {
    if (!project) return;
    if (!transition.reason) {
      act(transition.path, "POST", null, transition.done);
      return;
    }
    setReasoning({
      title: `${transition.label} proyecto`, text: `${project.code} · ${project.name}`, label: transition.reason, confirm: transition.label,
      run: (reason) => act(transition.path, "POST", { reason }, transition.done),
    });
  }

  /** Mover una tarea a otra columna (arrastrando o con "Mover a…"). */
  function moveTask(task: ProjectTask, target: string) {
    if (target === task.status) return;
    const label = (state: string) => stateOf(TASK_STATES, state)[0];
    const from = TASK_MOVES[target as Exclude<TaskStatus, "TODO">];

    if (!from?.includes(task.status)) {
      toast(`Transición no permitida: ${label(task.status)} → ${label(target)}`, "danger");
      return;
    }
    const send = (reason?: string) => act(`tasks/${task.id}/move`, "POST", { status: target, reason }, "Tarea actualizada");
    if (target === "BLOCKED") setReasoning({ title: "Bloquear tarea", text: task.title, label: "Motivo del bloqueo", confirm: "Bloquear", run: send });
    else send();
  }

  /** Descarga el archivo de un entregable con un enlace temporal que da la API. */
  async function download(deliverable: Deliverable) {
    try {
      const { url } = await apiFetch<{ url: string }>(`/v1/projects/${id}/deliverables/${deliverable.id}/document-url`);
      window.location.assign(url);
    } catch (err) {
      toast(firstError(err, "No se pudo descargar el documento."), "danger");
    }
  }

  function openLogging() {
    setLogging(true);
    if (!timeOptions) apiFetch<TimeEntryOptions>("/v1/time-entries/options").then(setTimeOptions).catch(() => setTimeOptions({ projects: [], work_orders: [] }));
  }

  /** Tras registrar o revisar horas o costos: se refrescan las tablas y los totales del proyecto. */
  function entriesChanged() {
    setEntries((n) => n + 1);
    setReload((n) => n + 1);
  }

  const back = (
    <Link href="/projects" className="app-muted mb-3 inline-flex items-center gap-1.5 text-[13px] hover:text-[var(--app-text)]">
      <ArrowLeft size={15} />
      Proyectos
    </Link>
  );

  if (error) {
    return (
      <Screen>
        {back}
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
        {toasts}
      </Screen>
    );
  }

  if (!project) {
    return (
      <Screen>
        {back}
        <Card><div className="h-48 animate-pulse rounded bg-[var(--app-surface-muted)]" aria-busy="true" /></Card>
        {toasts}
      </Screen>
    );
  }

  const status = project.status;
  const open = PROJECT_OPEN.includes(status);
  const running = status === "IN_PROGRESS";
  const metrics = project.metrics;
  const history = project.history ?? [];
  const visited = history.flatMap((entry) => (entry.metadata.to ? [entry.metadata.to] : [])).concat("DRAFT");
  const available = PROJECT_MOVES.filter((transition) => transition.from.includes(status));
  // Las tareas tienen su propio permiso: quien ejecuta las trabaja sin poder pausar el proyecto.
  const canManage = !accessLoading && can("tasks.manage");
  const closedNote = open ? undefined : "Proyecto cerrado";

  const boardTasks = project.tasks.filter((task) => task.status !== "CANCELLED");
  const cancelledTasks = project.tasks.filter((task) => task.status === "CANCELLED");
  const taskTargets = (task: ProjectTask): [string, string][] =>
    (Object.entries(TASK_MOVES) as [TaskStatus, TaskStatus[]][]).filter(([, from]) => from.includes(task.status)).map(([to]) => [to, stateOf(TASK_STATES, to)[0]]);

  const deliverables = project.deliverables;
  const attaching = deliverables?.find((item) => item.id === attachingId) ?? null;

  return (
    <Screen>
      {back}
      <PageHead
        showTitle
        title={project.name}
        sub={[project.code, customerName(project.customer), project.manager ? `Responsable ${project.manager.name}` : null].filter(Boolean).join(" · ")}
        actions={
          <>
            <Btn variant="secondary" onClick={() => setEditing(true)} permission="projects.manage" disabled={!open || busy} title={closedNote}>
              <Pencil size={16} />
              <span>Editar</span>
            </Btn>
            <Btn variant="secondary" onClick={openLogging} permission="time_entries.create" disabled={!open} title={closedNote}>
              <Clock size={16} />
              <span>Registrar horas</span>
            </Btn>
          </>
        }
      />

      <Kpis row>
        <Kpi label="Avance" value={`${metrics.progress}%`} />
        <Kpi label="Tareas" value={`${metrics.done}/${metrics.tasks}`} />
        <Kpi label="Horas aprobadas" value={hoursText(metrics.hours)} sub={metrics.pending_hours ? `${hoursText(metrics.pending_hours)} por aprobar` : undefined} />
        <Kpi label="Costo real" value={money(metrics.cost)} tone="warning" />
        <Kpi label="Margen" value={money(metrics.margin)} sub={`${metrics.margin_pct}% del ingreso`} tone={metrics.margin < 0 ? "danger" : "success"} />
      </Kpis>

      <Tabs
        value={tab}
        onChange={(next) => setTab(next as Tab)}
        tabs={[
          ["tareas", "Tareas", boardTasks.length],
          ["horas", "Horas", hoursTotal],
          ["costos", "Costos", costsTotal],
          ["entregables", "Entregables", deliverables?.length ?? null],
          ["resumen", "Resumen y estados", null],
        ]}
      />

      {tab === "tareas" && (
        <>
          <Kanban
            narrow
            columns={TASK_COLUMNS}
            cards={boardTasks}
            cardKey={(task) => task.id}
            columnOf={(task) => task.status}
            canDrag={(task) => canManage && running && task.status !== "DONE"}
            onDrop={moveTask}
            renderCard={(task) => {
              const editable = canManage && open && task.status !== "DONE";
              return (
                <>
                  <div className="flex items-start justify-between gap-1.5">
                    <strong className="font-semibold leading-snug">{task.title}</strong>
                    {editable && (
                      <button
                        type="button"
                        aria-label={`Editar tarea ${task.title}`}
                        title="Editar tarea"
                        onClick={() => setTaskForm({ task })}
                        className="app-muted -mr-1 -mt-0.5 grid h-6 w-6 shrink-0 cursor-pointer place-items-center rounded hover:bg-[var(--app-surface-muted)] hover:text-[var(--app-text)]"
                      >
                        <Pencil size={13} />
                      </button>
                    )}
                    {!canManage && !accessLoading && open && task.status !== "DONE" && (
                      <span title={deniedText("tasks.manage")} aria-label={deniedText("tasks.manage")} className="app-muted -mr-1 -mt-0.5 grid h-6 w-6 shrink-0 cursor-not-allowed place-items-center opacity-60">
                        <Lock size={12} aria-hidden="true" />
                      </span>
                    )}
                  </div>
                  <div className="app-muted flex flex-wrap items-center justify-between gap-1.5 text-xs">
                    <PriorityChip priority={task.priority} />
                    <span>{task.estimate_hours} h est.</span>
                  </div>
                  {task.required_check && (
                    <label className="flex cursor-pointer items-start gap-1.5 text-[12.5px]" title={editable ? undefined : "La tarea ya no se cambia"}>
                      <input
                        type="checkbox"
                        checked={task.required_check_done}
                        disabled={!editable || busy}
                        onChange={(event) => act(`tasks/${task.id}/check`, "PATCH", { done: event.target.checked }, "Control actualizado")}
                        className="mt-0.5 h-3.5 w-3.5 cursor-pointer accent-[#1B8FC9] disabled:cursor-not-allowed"
                      />
                      <span>{task.required_check} <em className="not-italic text-[color:var(--p-danger-text)]" title="Obligatorio">*</em></span>
                    </label>
                  )}
                  {task.status === "BLOCKED" && task.blocked_reason && <small className="text-xs text-[color:var(--p-warning-text)]">{task.blocked_reason}</small>}
                  <div className="app-muted flex flex-wrap items-center justify-between gap-1.5 text-xs">
                    <Who name={task.assignee?.name} />
                    {task.due_at && <span>vence {shortDate(task.due_at)}</span>}
                  </div>
                  {canManage && running && taskTargets(task).length > 0 && (
                    <PickSelect
                      className="app-muted flex h-[30px] w-full cursor-pointer items-center justify-between rounded-md border border-[color:var(--app-border)] bg-[var(--app-surface-muted)] px-2 text-xs"
                      ariaLabel={`Mover tarea ${task.title}`}
                      value=""
                      onChange={(target) => moveTask(task, target)}
                      options={[["", "Mover a…"], ...taskTargets(task)]}
                      disabled={busy}
                    />
                  )}
                  {!canManage && running && taskTargets(task).length > 0 && <MoveLocked permission="tasks.manage" />}
                </>
              );
            }}
          />
          <div className="mb-[18px] flex flex-wrap items-center gap-x-3 gap-y-1.5">
            <Btn variant="secondary" onClick={() => setTaskForm({})} permission="tasks.manage" disabled={!open || busy} title={closedNote}>
              <Plus size={16} />
              <span>Nueva tarea</span>
            </Btn>
            <span className="app-muted text-[12.5px]">
              {running ? "Arrastra una tarea para cambiar su estado." : open ? "Las tareas se mueven con el proyecto en ejecución." : "Proyecto cerrado."}
            </span>
            {cancelledTasks.length > 0 && (
              <span className="app-muted w-full text-xs">Canceladas: {cancelledTasks.map((task) => task.title).join(" · ")}</span>
            )}
          </div>
        </>
      )}

      {/* Las dos tablas quedan montadas: dan el contador de su pestaña y conservan página y orden al volver. */}
      <div hidden={tab !== "horas"}>
        <Card
          title="Horas del proyecto"
          flush
          actions={
            <Btn size="sm" variant="secondary" onClick={openLogging} permission="time_entries.create" disabled={!open} title={closedNote}>
              <Clock size={14} />
              <span>Registrar horas</span>
            </Btn>
          }
        >
          <div className={TABLE_PAD}>
            <TimeEntriesList fixed={{ project_id: project.id }} tableId="project-hours" version={entries} onChanged={entriesChanged} onTotal={setHoursTotal} toast={toast} empty="Sin horas registradas." />
          </div>
        </Card>
      </div>

      <div hidden={tab !== "costos"}>
        <Card
          title="Costos del proyecto"
          flush
          actions={
            <Btn size="sm" variant="secondary" onClick={() => setCosting(true)} permission="cost_entries.create" disabled={!open} title={closedNote}>
              <Plus size={14} />
              <span>Registrar costo</span>
            </Btn>
          }
        >
          <div className={TABLE_PAD}>
            <CostEntriesList fixed={{ project_id: project.id }} tableId="project-costs" version={entries} onChanged={entriesChanged} onTotal={setCostsTotal} toast={toast} empty="Sin costos registrados." />
          </div>
        </Card>
      </div>

      {tab === "entregables" && (
        <Card
          title="Entregables"
          flush
          actions={deliverables !== null && (
            <Btn size="sm" variant="secondary" onClick={() => setDeliverableForm({})} permission="deliverables.manage" disabled={!open || busy} title={closedNote}>
              <Plus size={14} />
              <span>Nuevo entregable</span>
            </Btn>
          )}
        >
          {deliverables === null ? (
            <Locked permission="deliverables.view" />
          ) : (
            <>
              {deliverables.length ? (
                <div className={`proto-tbl-wrap overflow-x-auto ${TABLE_PAD}`}>
                  <table className="proto-tbl w-full border-collapse text-[13px]">
                    <thead>
                      <tr>
                        <th className={TH}>Entregable</th>
                        <th className={TH}>Documento</th>
                        <th className={TH}>Estado</th>
                        <th className={TH}>Acciones</th>
                      </tr>
                    </thead>
                    <tbody>
                      {deliverables.map((deliverable) => {
                        const editable = deliverable.status === "DRAFT" || deliverable.status === "RETURNED";
                        const send = (path: string, done: string, body?: unknown) => act(`deliverables/${deliverable.id}/${path}`, "POST", body ?? null, done);
                        return (
                          <tr key={deliverable.id}>
                            <td className={TD} data-label="Entregable">
                              <strong className="font-semibold">{deliverable.name}</strong>
                              {deliverable.due_date && <small className="app-muted block text-xs">vence {shortDate(deliverable.due_date)}</small>}
                              {deliverable.status === "RETURNED" && deliverable.review_note && (
                                <small className="block text-xs text-[color:var(--p-warning-text)]">Devuelto: {deliverable.review_note}</small>
                              )}
                            </td>
                            <td className={TD} data-label="Documento">
                              {deliverable.document ? (
                                <div className="min-w-[160px]">
                                  <button type="button" onClick={() => download(deliverable)} title="Descargar" className={`${LINK} inline-flex cursor-pointer items-start gap-1.5 text-left [overflow-wrap:anywhere]`}>
                                    <FileText size={14} className="mt-0.5 shrink-0" />
                                    <span>{deliverable.document.title}</span>
                                  </button>
                                  <small className="app-muted block text-xs [overflow-wrap:anywhere]">
                                    v{deliverable.document.version} · {deliverable.document.file_name} · {fileSize(deliverable.document.size_bytes)}
                                    {deliverable.document.visibility === "PORTAL" ? " · visible en el portal" : ""}
                                  </small>
                                </div>
                              ) : deliverable.document_ref ? (
                                /^https?:\/\//i.test(deliverable.document_ref)
                                  ? <a href={deliverable.document_ref} target="_blank" rel="noopener noreferrer" className={`${LINK} [overflow-wrap:anywhere]`}>{deliverable.document_ref}</a>
                                  : <span className="[overflow-wrap:anywhere]">{deliverable.document_ref}</span>
                              ) : editable ? (
                                <Btn size="sm" variant="ghost" permission="deliverables.manage" disabled={!open || busy} title={closedNote} onClick={() => setAttachingId(deliverable.id)}>
                                  <Upload size={13} />
                                  <span>Adjuntar</span>
                                </Btn>
                              ) : <span className="app-muted">Sin documento</span>}
                            </td>
                            <td className={TD} data-label="Estado">
                              <Badge tone={stateOf(DELIVERABLE_STATES, deliverable.status)[1]}>{stateOf(DELIVERABLE_STATES, deliverable.status)[0]}</Badge>
                            </td>
                            <td className={TD} data-label="Acciones">
                              <div className="flex flex-wrap gap-1.5">
                                {editable && (
                                  <>
                                    <Btn size="sm" variant="ghost" permission="deliverables.manage" disabled={!open || busy} onClick={() => setDeliverableForm({ deliverable })}>
                                      Editar
                                    </Btn>
                                    {deliverable.document_ref && (
                                      <Btn size="sm" variant="ghost" permission="deliverables.manage" disabled={!open || busy} onClick={() => setAttachingId(deliverable.id)}>
                                        {deliverable.document ? "Nueva versión" : "Adjuntar archivo"}
                                      </Btn>
                                    )}
                                    <Btn
                                      size="sm" variant="secondary" permission="deliverables.manage"
                                      disabled={!open || busy || !deliverable.document_ref}
                                      title={deliverable.document_ref ? undefined : "Adjunta el documento antes de enviarlo a revisión"}
                                      onClick={() => send("submit", "Entregable enviado a revisión")}
                                    >
                                      Enviar a revisión
                                    </Btn>
                                  </>
                                )}
                                {deliverable.status === "IN_REVIEW" && (
                                  <>
                                    <Btn size="sm" variant="secondary" permission="deliverables.approve" disabled={busy} onClick={() => send("approve", "Entregable aprobado")}>Aprobar</Btn>
                                    <Btn
                                      size="sm" variant="danger-ghost" permission="deliverables.approve" disabled={busy}
                                      onClick={() => setReasoning({
                                        title: "Devolver entregable", text: deliverable.name, label: "Qué falta o qué corregir", confirm: "Devolver",
                                        run: (reason) => send("return", "Entregable devuelto", { reason }),
                                      })}
                                    >
                                      Devolver
                                    </Btn>
                                  </>
                                )}
                                {deliverable.status === "APPROVED" && (
                                  <Btn size="sm" variant="secondary" permission="deliverables.manage" disabled={busy} onClick={() => send("deliver", "Entregable entregado")}>
                                    <Check size={13} />
                                    <span>Entregar al cliente</span>
                                  </Btn>
                                )}
                                {deliverable.status === "DELIVERED" && deliverable.delivered_at && (
                                  <span className="app-muted text-xs">Entregado el {dateTime(deliverable.delivered_at)}</span>
                                )}
                              </div>
                            </td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              ) : (
                <p className="app-muted px-5 pb-5">Sin entregables.</p>
              )}
            </>
          )}
        </Card>
      )}

      {tab === "resumen" && (
        <SortableBoard
          storageKey="erp.cards.project-summary"
          className="grid grid-cols-1 gap-x-4 lg:grid-cols-2"
          zones={[
            { className: "min-w-0 lg:min-h-24", cards: ["states"] },
            { className: "min-w-0 lg:min-h-24", cards: ["budget"] },
          ]}
          cards={[
            {
              id: "states",
              node: (
                <Card title="Estados">
                  <div className="grid gap-3">
                    <StateTrack states={PROJECT_STATES} current={status} visited={visited} />
                    {status === "ON_HOLD" && project.hold_reason && <Notice tone="warn"><strong className="font-semibold">En pausa:</strong> {project.hold_reason}</Notice>}
                    {status === "CANCELLED" && project.cancel_reason && <Notice tone="warn"><strong className="font-semibold">Cancelado:</strong> {project.cancel_reason}</Notice>}
                    {available.length ? (
                      <div className="flex flex-wrap gap-1.5">
                        {available.map((transition) => (
                          <Btn
                            key={transition.path}
                            variant={transition.reason ? "danger-ghost" : "secondary"}
                            onClick={() => move(transition)}
                            permission={transition.permission}
                            disabled={busy}
                          >
                            {transition.label}
                          </Btn>
                        ))}
                      </div>
                    ) : (
                      <p className="app-muted text-[12.5px]">Sin transiciones disponibles desde este estado.</p>
                    )}
                  </div>
                  <Timeline
                    items={history.map((entry, index) => {
                      const to = entry.metadata.to;
                      return {
                        key: `${entry.at}-${index}`,
                        head: to ? (
                          <>
                            <Badge tone={stateOf(PROJECT_STATES, to)[1]}>{stateOf(PROJECT_STATES, to)[0]}</Badge>
                            {entry.metadata.from && entry.metadata.from !== to && (
                              <span className="app-muted text-[12.5px]">desde {stateOf(PROJECT_STATES, entry.metadata.from)[0]}</span>
                            )}
                          </>
                        ) : (
                          <span className="font-medium">{projectHistoryLabel(entry)}</span>
                        ),
                        meta: `${entry.user ?? "Sistema"} · ${dateTime(entry.at)}`,
                        note: entry.metadata.note,
                      };
                    })}
                  />
                </Card>
              ),
            },
            {
              id: "budget",
              node: (
                <Card title="Presupuesto vs real">
                  <Kv
                    rows={[
                      ["Cliente", customerName(project.customer)],
                      [
                        "Contrato",
                        project.contract ? <Link key="contract" href={`/contracts/${project.contract.id}`} className={LINK}>{project.contract.contract_number}</Link> : "—",
                      ],
                      ["Vigencia", projectPeriod(project)],
                      ["Ingreso atribuible", money(project.budget_revenue)],
                      ["Costo presupuestado", money(project.budget_cost)],
                      ["Horas presupuestadas", hoursText(project.budget_hours)],
                      ["Horas aprobadas", `${hoursText(metrics.hours)}${metrics.pending_hours ? ` (${hoursText(metrics.pending_hours)} por aprobar)` : ""}`],
                      ["Costo de horas", money(metrics.labor_cost)],
                      ["Otros costos", money(metrics.other_cost)],
                      ["Margen", <strong key="margin" className="font-semibold"><Margin project={project} /></strong>],
                    ]}
                  />
                  <p className="app-muted text-xs">Margen = ingreso atribuible − costo real (horas y costos aprobados).</p>
                </Card>
              ),
            },
          ]}
        />
      )}

      {editing && (
        <ProjectFormModal
          project={project}
          options={options}
          toast={toast}
          onClose={() => setEditing(false)}
          onSaved={(updated) => {
            setProject(updated);
            setEditing(false);
          }}
        />
      )}
      {taskForm && (
        <TaskFormModal
          projectId={project.id}
          task={taskForm.task}
          people={options?.people ?? null}
          toast={toast}
          onClose={() => setTaskForm(null)}
          onSaved={(updated) => {
            setProject(updated);
            setTaskForm(null);
          }}
        />
      )}
      {deliverableForm && (
        <DeliverableFormModal
          projectId={project.id}
          deliverable={deliverableForm.deliverable}
          toast={toast}
          onClose={() => setDeliverableForm(null)}
          onSaved={(updated) => {
            setProject(updated);
            setDeliverableForm(null);
          }}
        />
      )}
      {attaching && (
        <DeliverableDocumentModal
          projectId={project.id}
          deliverable={attaching}
          toast={toast}
          onClose={() => setAttachingId(null)}
          onUploaded={setProject}
        />
      )}
      {logging && (
        <TimeEntryFormModal
          options={timeOptions}
          projectId={project.id}
          toast={toast}
          onClose={() => setLogging(false)}
          onSaved={() => {
            setLogging(false);
            entriesChanged();
          }}
        />
      )}
      {costing && (
        <CostFormModal
          projectId={project.id}
          toast={toast}
          onClose={() => setCosting(false)}
          onSaved={() => {
            setCosting(false);
            entriesChanged();
          }}
        />
      )}
      {reasoning && (
        <ReasonModal
          title={reasoning.title}
          text={reasoning.text}
          label={reasoning.label}
          confirmLabel={reasoning.confirm}
          busy={busy}
          onClose={() => setReasoning(null)}
          onConfirm={async (reason) => {
            if (await reasoning.run(reason)) setReasoning(null);
          }}
        />
      )}
      {toasts}
    </Screen>
  );
}
