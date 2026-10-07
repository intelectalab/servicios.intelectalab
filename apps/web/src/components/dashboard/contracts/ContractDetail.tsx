"use client";

import { useEffect, useState, type FormEvent } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ArrowLeft, Check, FileText, Settings } from "lucide-react";
import { ApiError, apiFetch } from "@/lib/api";
import { customerName, historyLabel, money, stateOf, takeFlash } from "@/lib/commercial";
import { CONTRACT_STATES, SLA_LEVELS, billingOf, downloadContractPdf, slaLabel, validity } from "@/lib/contracts";
import {
  Badge, Btn, Card, Chip, DataTable, Field, INPUT, Kv, LoadError, Modal, PageHead, Screen, StateTrack, TD, TH, Timeline, Who,
  useToasts, type Column, type Tone,
} from "@/components/ui/proto";
import { SortableBoard } from "@/components/ui/sortable-cards";
import type { Contract, ContractedService } from "@/types/commercial";

// Transiciones que piden confirmación; "reason" indica que el motivo es obligatorio.
type Confirm = { path: string; title: string; text: string; confirmLabel: string; done: string; danger?: boolean; reason?: boolean };

const CONFIRMS: Record<"suspend" | "reactivate" | "terminate" | "expire", Confirm> = {
  suspend: {
    path: "suspend", title: "Suspender contrato", confirmLabel: "Suspender", done: "Contrato suspendido", danger: true, reason: true,
    text: "El contrato deja de estar vigente hasta que se reactive. Se conserva el historial.",
  },
  reactivate: {
    path: "reactivate", title: "Reactivar contrato", confirmLabel: "Reactivar", done: "Contrato vigente",
    text: "El contrato vuelve a estar vigente.",
  },
  terminate: {
    path: "terminate", title: "Terminar contrato", confirmLabel: "Terminar", done: "Contrato terminado", danger: true, reason: true,
    text: "El contrato queda terminado y ya no se podrá reactivar. Se conserva el historial.",
  },
  expire: {
    path: "expire", title: "Marcar vencido", confirmLabel: "Marcar vencido", done: "Contrato vencido",
    text: "El contrato queda vencido y ya no admite cambios de estado.",
  },
};

const SERVICE_STATUS: Record<string, [string, Tone]> = {
  PENDING: ["Pendiente", "neutral"], ACTIVE: ["Activo", "success"], SUSPENDED: ["Suspendido", "warning"],
  COMPLETED: ["Completado", "info"], CANCELLED: ["Cancelado", "muted"],
};

const ORDER_STATUS: Record<string, [string, Tone]> = {
  DRAFT: ["Borrador", "neutral"], CONFIRMED: ["Confirmada", "info"], IN_PROGRESS: ["En ejecución", "warning"],
  COMPLETED: ["Completada", "success"], CANCELLED: ["Cancelada", "muted"],
};

// Estado en el que cada movimiento deja el contrato, para cuando la auditoría no lo guardó.
const ACTION_STATE: Record<string, string> = { "contract.generate": "DRAFT", "contract.activate": "ACTIVE" };

const SERVICE_COLUMNS: Column<ContractedService>[] = [
  {
    label: "Servicio",
    render: (service) => (
      <>
        <strong className="font-semibold">{service.service_name}</strong>
        <small className="app-muted block text-xs">{service.service_code}{service.recurring ? " · recurrente" : ""}</small>
      </>
    ),
  },
  { label: "Cant.", num: true, render: (service) => Number(service.quantity) },
  {
    label: "Estado",
    render: (service) => <Badge tone={SERVICE_STATUS[service.status]?.[1] ?? "neutral"}>{SERVICE_STATUS[service.status]?.[0] ?? service.status}</Badge>,
  },
];

function firstError(err: unknown, fallback: string) {
  if (err instanceof ApiError) return (err.errors && Object.values(err.errors)[0]?.[0]) || err.message;
  return fallback;
}

const dateTime = (value: string) => new Date(value).toLocaleString("es-PE", { dateStyle: "medium", timeStyle: "short" });

function EditModal({ contract, onClose, onSaved }: { contract: Contract; onClose: () => void; onSaved: (contract: Contract) => void }) {
  const [start, setStart] = useState(contract.start_date?.slice(0, 10) ?? "");
  const [end, setEnd] = useState(contract.end_date?.slice(0, 10) ?? "");
  const [sla, setSla] = useState(contract.sla_level);
  const [terms, setTerms] = useState(contract.terms ?? "");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    const found: Record<string, string> = {};
    if (!start) found.start_date = "Indica la fecha de inicio.";
    if (!end) found.end_date = "Indica la fecha de fin.";
    else if (start && end <= start) found.end_date = "El fin debe ser posterior al inicio.";
    setErrors(found);
    if (Object.keys(found).length) return;

    setSaving(true);
    try {
      const saved = await apiFetch<Contract>(`/v1/contracts/${contract.id}`, {
        method: "PUT",
        body: JSON.stringify({ start_date: start, end_date: end, sla_level: sla, terms: terms.trim() || null }),
      });
      onSaved(saved);
    } catch (err) {
      if (err instanceof ApiError && err.errors) {
        const [field, messages] = Object.entries(err.errors)[0];
        setErrors({ [field]: messages[0] });
      } else {
        setErrors({ form: firstError(err, "No se pudo guardar el contrato.") });
      }
      setSaving(false);
    }
  }

  return (
    <Modal title="Editar contrato" size="sm" onClose={onClose}>
      <form onSubmit={submit}>
        <div className="grid gap-3">
          <Field label="Inicio" required error={errors.start_date}>
            <input className={INPUT} type="date" value={start} onChange={(e) => setStart(e.target.value)} autoFocus />
          </Field>
          <Field label="Fin" required error={errors.end_date}>
            <input className={INPUT} type="date" value={end} onChange={(e) => setEnd(e.target.value)} />
          </Field>
          <Field label="Nivel de servicio (SLA)" error={errors.sla_level}>
            <select className={INPUT} value={sla} onChange={(e) => setSla(e.target.value)}>
              {Object.entries(SLA_LEVELS).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
            </select>
          </Field>
          <Field label="Condiciones" error={errors.terms}>
            <textarea className={INPUT} rows={3} value={terms} onChange={(e) => setTerms(e.target.value)} />
          </Field>
        </div>
        <p className="app-muted mt-3 text-[12.5px]">Las líneas son el snapshot de la cotización y no se editan. Solo se edita en borrador.</p>
        {(errors.form || errors.status) && <p className="mt-2 text-[12.5px] text-[#D93A40]">{errors.form || errors.status}</p>}
        <div className="mt-4 flex justify-end gap-2">
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn type="submit" disabled={saving}>{saving ? "Guardando…" : "Guardar"}</Btn>
        </div>
      </form>
    </Modal>
  );
}

export default function ContractDetail() {
  const { id } = useParams<{ id: string }>();
  const { toast, toasts } = useToasts();

  const [contract, setContract] = useState<Contract | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [editing, setEditing] = useState(false);
  const [activating, setActivating] = useState(false);
  const [generate, setGenerate] = useState(true);
  const [confirming, setConfirming] = useState<Confirm | null>(null);
  const [reason, setReason] = useState("");
  const [reasonError, setReasonError] = useState("");
  const [busy, setBusy] = useState(false);
  const [printing, setPrinting] = useState(false);

  useEffect(() => {
    const flash = takeFlash();
    if (flash) toast(flash);
  }, [toast]);

  useEffect(() => {
    let cancelled = false;
    apiFetch<Contract>(`/v1/contracts/${id}`)
      .then((data) => {
        if (cancelled) return;
        setContract(data);
        setError("");
      })
      .catch((err) => {
        if (!cancelled) setError(err instanceof ApiError && err.status === 404 ? "Contrato no encontrado." : "No se pudo cargar el contrato.");
      });
    return () => { cancelled = true; };
  }, [id, reload]);

  /** Ejecuta una acción del contrato; la API devuelve la ficha ya actualizada. */
  async function run(path: string, done: string, body?: Record<string, unknown>, refetch = false) {
    setBusy(true);
    try {
      const updated = await apiFetch<Contract>(`/v1/contracts/${id}/${path}`, { method: "POST", body: body ? JSON.stringify(body) : undefined });
      if (refetch) setReload((n) => n + 1);
      else setContract(updated);
      toast(done);
      setConfirming(null);
      setActivating(false);
    } catch (err) {
      toast(firstError(err, "No se pudo completar la acción."), "danger");
    } finally {
      setBusy(false);
    }
  }

  function ask(confirm: Confirm) {
    setReason("");
    setReasonError("");
    setConfirming(confirm);
  }

  function confirmNow() {
    if (!confirming) return;
    const text = reason.trim();
    if (confirming.reason && !text) {
      setReasonError("Este dato es obligatorio.");
      return;
    }
    run(confirming.path, confirming.done, confirming.reason ? { reason: text } : undefined);
  }

  async function downloadPdf() {
    if (!contract) return;
    setPrinting(true);
    toast("Generando PDF…");
    try {
      await downloadContractPdf(contract);
    } catch {
      toast("No se pudo generar el PDF", "danger");
    } finally {
      setPrinting(false);
    }
  }

  const back = (
    <Link href="/contracts" className="mb-3 inline-flex items-center gap-1.5 text-[13px] font-medium text-[#1679AB] hover:underline">
      <ArrowLeft size={15} /> Contratos
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

  if (!contract) {
    return (
      <Screen>
        {back}
        <Card><div className="h-48 animate-pulse rounded bg-[var(--app-surface-muted)]" aria-busy="true" /></Card>
        {toasts}
      </Screen>
    );
  }

  const status = contract.status;
  const currency = contract.currency;
  const items = contract.items ?? [];
  const services = contract.contracted_services ?? [];
  const orders = contract.orders ?? [];
  const history = contract.history ?? [];
  const visited = history.flatMap((entry) => (entry.metadata.to ? [entry.metadata.to] : [])).concat("DRAFT");
  // Un contrato sin líneas o sin fecha de inicio no se activa.
  const notActivable = !items.length || !contract.start_date ? "El contrato activo requiere líneas y vigencia" : undefined;

  return (
    <Screen>
      {back}
      <PageHead
        showTitle
        title={`Contrato ${contract.contract_number}`}
        sub={`${customerName(contract.customer)} · ${validity(contract)} · SLA ${slaLabel(contract.sla_level)}`}
        actions={
          <>
            {status === "DRAFT" && (
              <>
                <Btn variant="secondary" onClick={() => setEditing(true)} permission="contracts.manage" disabled={busy}>
                  <Settings size={16} />
                  <span>Editar</span>
                </Btn>
                <Btn onClick={() => setActivating(true)} permission="contracts.approve" disabled={busy || Boolean(notActivable)} title={notActivable}>
                  <Check size={16} />
                  <span>Activar contrato</span>
                </Btn>
              </>
            )}
            <Btn variant="ghost" onClick={downloadPdf} disabled={printing} title="Descargar en PDF">
              <FileText size={16} />
              <span>Descargar PDF</span>
            </Btn>
            {status === "ACTIVE" && (
              <Btn
                variant="secondary"
                onClick={() => run("orders", "Orden creada desde el contrato", undefined, true)}
                permission="orders.manage"
                disabled={busy || orders.length > 0}
                title={orders.length ? "Ya tiene orden" : undefined}
              >
                Crear orden
              </Btn>
            )}
          </>
        }
      />

      <SortableBoard
        storageKey="erp.cards.contract-detail"
        className="grid grid-cols-1 gap-x-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]"
        zones={[
          { className: "min-w-0 lg:min-h-24", cards: ["lines", "services"] },
          { className: "min-w-0 lg:min-h-24", cards: ["signature", "states", "related", "history"] },
        ]}
        cards={[
          {
            id: "lines",
            node: (
              <Card title="Líneas contratadas (snapshot copiado de la cotización)">
                <div className="proto-tbl-wrap -mx-3 overflow-x-auto sm:-mx-5">
                  <table className="proto-tbl w-full border-collapse text-[13px]">
                    <thead>
                      <tr>
                        <th className={TH}>Servicio</th>
                        <th className={TH}>Facturación</th>
                        <th className={`${TH} text-right`}>Cant.</th>
                        <th className={`${TH} text-right`}>P. unit.</th>
                        <th className={`${TH} text-right`}>Total</th>
                      </tr>
                    </thead>
                    <tbody>
                      {items.map((item) => {
                        const [billing, tone] = billingOf(item);
                        return (
                          <tr key={item.id}>
                            <td className={TD} data-label="Servicio">
                              <strong className="font-semibold">{item.description}</strong>
                              {item.service_snapshot?.code && <small className="app-muted block text-xs">{item.service_snapshot.code}</small>}
                            </td>
                            <td className={TD} data-label="Facturación"><Chip tone={tone}>{billing}</Chip></td>
                            <td className={`${TD} whitespace-nowrap text-right tabular-nums`} data-label="Cant.">{Number(item.quantity)}</td>
                            <td className={`${TD} whitespace-nowrap text-right tabular-nums`} data-label="P. unit.">{money(item.unit_price, currency)}</td>
                            <td className={`${TD} whitespace-nowrap text-right tabular-nums`} data-label="Total"><strong>{money(item.line_total, currency)}</strong></td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
                <p className="mt-3 text-right"><strong>Valor total: {money(contract.total_value, currency)}</strong></p>
                {contract.terms && <p className="app-muted mt-2 text-[12.5px]">{contract.terms}</p>}
              </Card>
            ),
          },
          {
            id: "services",
            node: (
              <Card title="Servicios contratados (Operaciones)" flush={services.length > 0}>
                {services.length ? (
                  <div className="pt-3 sm:px-1.5 sm:pb-2.5">
                    <DataTable cols={SERVICE_COLUMNS} rows={services} rowKey={(service) => service.id} dense />
                  </div>
                ) : (
                  <p className="app-muted">Se generan al activar el contrato.</p>
                )}
              </Card>
            ),
          },
          {
            id: "signature",
            node: (
              <Card title="Firma electrónica">
                <p className="app-muted">La firma electrónica no es obligatoria en esta empresa.</p>
              </Card>
            ),
          },
          {
            id: "states",
            node: (
              <Card title="Estados">
                <div className="grid gap-3">
                  <StateTrack states={CONTRACT_STATES} current={status} visited={visited} />
                  {status === "DRAFT" || status === "ACTIVE" || status === "SUSPENDED" ? (
                    <div className="flex flex-wrap gap-1.5">
                      {status === "DRAFT" && (
                        <Btn
                          variant="secondary"
                          onClick={() => setActivating(true)}
                          permission="contracts.approve"
                          disabled={busy || Boolean(notActivable)}
                          title={notActivable}
                        >
                          Activar contrato
                        </Btn>
                      )}
                      {status === "ACTIVE" && (
                        <Btn variant="danger-ghost" onClick={() => ask(CONFIRMS.suspend)} permission="contracts.manage" disabled={busy}>
                          Suspender
                        </Btn>
                      )}
                      {status === "SUSPENDED" && (
                        <Btn variant="secondary" onClick={() => ask(CONFIRMS.reactivate)} permission="contracts.manage" disabled={busy}>
                          Reactivar
                        </Btn>
                      )}
                      <>
                        {status !== "DRAFT" && (
                          <Btn variant="danger-ghost" onClick={() => ask(CONFIRMS.terminate)} permission="contracts.manage" disabled={busy}>
                            Terminar
                          </Btn>
                        )}
                        {status === "ACTIVE" && (
                          <Btn variant="secondary" onClick={() => ask(CONFIRMS.expire)} permission="contracts.manage" disabled={busy}>
                            Marcar vencido
                          </Btn>
                        )}
                      </>
                    </div>
                  ) : (
                    <p className="app-muted text-[12.5px]">Sin transiciones disponibles desde este estado.</p>
                  )}
                </div>
              </Card>
            ),
          },
          {
            id: "related",
            node: (
              <Card title="Relacionado">
                <Kv
                  rows={[
                    [
                      "Cotización",
                      contract.quotation ? (
                        <Link key="quotation" href={`/quotations/${contract.quotation.id}`} className="text-[#1679AB] hover:underline">
                          {contract.quotation.quotation_number}
                        </Link>
                      ) : "—",
                    ],
                    [
                      "Órdenes",
                      orders.length ? (
                        <span key="orders" className="inline-flex flex-wrap items-center gap-1.5">
                          {orders.map((order) => (
                            <span key={order.id} className="inline-flex items-center gap-1.5">
                              {order.order_number}
                              <Badge tone={ORDER_STATUS[order.status]?.[1] ?? "neutral"}>{ORDER_STATUS[order.status]?.[0] ?? order.status}</Badge>
                            </span>
                          ))}
                        </span>
                      ) : "—",
                    ],
                    ["Responsable", <Who key="owner" name={contract.creator?.name} />],
                  ]}
                />
              </Card>
            ),
          },
          {
            id: "history",
            node: (
              <Card title="Historial">
                <Timeline
                  items={history.map((entry, index) => {
                    const to = entry.metadata.to ?? ACTION_STATE[entry.action];
                    return {
                      key: `${entry.at}-${index}`,
                      head: to ? (
                        <>
                          <Badge tone={stateOf(CONTRACT_STATES, to)[1]}>{stateOf(CONTRACT_STATES, to)[0]}</Badge>
                          {entry.metadata.from && entry.metadata.from !== to && (
                            <span className="app-muted text-[12.5px]">desde {stateOf(CONTRACT_STATES, entry.metadata.from)[0]}</span>
                          )}
                        </>
                      ) : (
                        <span className="font-medium">{historyLabel(entry)}</span>
                      ),
                      meta: `${entry.user ?? "Sistema"} · ${dateTime(entry.at)}`,
                      note: entry.metadata.note,
                    };
                  })}
                />
              </Card>
            ),
          },
        ]}
      />

      {editing && (
        <EditModal
          contract={contract}
          onClose={() => setEditing(false)}
          onSaved={(saved) => {
            setContract(saved);
            setEditing(false);
            toast("Contrato guardado");
          }}
        />
      )}

      {activating && (
        <Modal
          title="Activar contrato"
          size="sm"
          onClose={() => setActivating(false)}
          footer={
            <>
              <Btn variant="ghost" onClick={() => setActivating(false)}>Cancelar</Btn>
              <Btn disabled={busy} onClick={() => run("activate", "Contrato vigente", { generate_services: generate })}>Activar</Btn>
            </>
          }
        >
          <p className="text-[15px]">El contrato pasará a VIGENTE. Crear un contrato no genera facturas.</p>
          <label className="mt-3 flex cursor-pointer items-start gap-2.5">
            <input
              type="checkbox"
              className="mt-0.5 h-[17px] w-[17px] accent-[#1B8FC9]"
              checked={generate}
              onChange={(e) => setGenerate(e.target.checked)}
            />
            <span>
              Generar servicios contratados para cada línea
              <small className="app-muted block text-[12.5px]">Operaciones los recibe en estado PENDIENTE.</small>
            </span>
          </label>
        </Modal>
      )}

      {confirming && (
        <Modal
          title={confirming.title}
          size="sm"
          onClose={() => setConfirming(null)}
          footer={
            <>
              <Btn variant="ghost" onClick={() => setConfirming(null)}>Volver</Btn>
              <Btn variant={confirming.danger ? "danger" : "primary"} disabled={busy} onClick={confirmNow}>
                {confirming.confirmLabel}
              </Btn>
            </>
          }
        >
          <p className="text-[15px]">{confirming.text}</p>
          {confirming.reason && (
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
          )}
        </Modal>
      )}
      {toasts}
    </Screen>
  );
}
