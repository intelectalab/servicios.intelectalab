"use client";

import { useEffect, useState, type FormEvent } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { ArrowLeft, Check, FileSignature, FileText, Send, Settings, Upload } from "lucide-react";
import { ApiError, apiFetch } from "@/lib/api";
import { customerName, historyLabel, money, setFlash, shortDate, stateOf, takeFlash } from "@/lib/commercial";
import { CONTRACT_STATES, SLA_LEVELS, billingOf, downloadContractPdf, slaLabel, validity } from "@/lib/contracts";
import { ORDER_STATES } from "@/lib/orders";
import {
  Badge, Btn, Card, Chip, DataTable, Field, INPUT, Kv, LoadError, Modal, Notice, PageHead, Screen, StateTrack, TD, TH, Timeline, Who,
  useToasts, type Column, type Tone,
} from "@/components/ui/proto";
import { SortableBoard } from "@/components/ui/sortable-cards";
import type { Contract, ContractedService, RecurringInstance, SignatureRequest } from "@/types/commercial";
import { CompanySignModal, SendSignatureModal, SignatureSummary } from "./ContractSignature";
import { DocumentDetailModal, DocumentStatusBadge, DocumentUploadModal, useRecordDocuments } from "@/components/dashboard/documents/DocumentModals";

// Transiciones que piden confirmación; "reason" indica que el motivo es obligatorio.
type Confirm = { path: string; title: string; text: string; confirmLabel: string; done: string; danger?: boolean; reason?: boolean };

const CONFIRMS: Record<"suspend" | "reactivate" | "terminate" | "expire", Confirm> = {
  suspend: {
    path: "suspend", title: "Suspender contrato", confirmLabel: "Suspender", done: "Contrato suspendido", danger: true, reason: true,
    text: "El contrato deja de estar vigente hasta que se reactive. Sus servicios contratados activos se suspenden con él. Se conserva el historial.",
  },
  reactivate: {
    path: "reactivate", title: "Reactivar contrato", confirmLabel: "Reactivar", done: "Contrato vigente",
    text: "El contrato vuelve a estar vigente, y con él los servicios contratados que se suspendieron al suspenderlo.",
  },
  terminate: {
    path: "terminate", title: "Terminar contrato", confirmLabel: "Terminar", done: "Contrato terminado", danger: true, reason: true,
    text: "El contrato queda terminado y ya no se podrá reactivar. Sus servicios contratados se cancelan; no se puede terminar si alguno tiene órdenes de trabajo abiertas. Se conserva el historial.",
  },
  expire: {
    path: "expire", title: "Marcar vencido", confirmLabel: "Marcar vencido", done: "Contrato vencido",
    text: "El contrato queda vencido y ya no admite cambios de estado. Sus servicios contratados se dan por completados (los que no empezaron, cancelados); no se puede si alguno tiene órdenes de trabajo abiertas.",
  },
};

const SERVICE_STATUS: Record<string, [string, Tone]> = {
  PENDING: ["Pendiente", "neutral"], ACTIVE: ["Activo", "success"], SUSPENDED: ["Suspendido", "warning"],
  COMPLETED: ["Completado", "info"], CANCELLED: ["Cancelado", "muted"],
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
  {
    label: "OT / proyectos",
    render: (service) => <span className="whitespace-nowrap">{Number(service.work_orders_count)} OT · {Number(service.projects_count)} proy.</span>,
  },
];

const RECURRING_STATUS: Record<string, [string, Tone]> = {
  ACTIVE: ["Activa", "success"], SUSPENDED: ["Suspendida", "warning"], EXPIRED: ["Vencida", "muted"], CANCELLED: ["Cancelada", "muted"],
};

const recurringColumns = (currency: string): Column<RecurringInstance>[] => [
  { label: "Servicio", render: (instance) => instance.service_name },
  { label: "Próximo ciclo", render: (instance) => <span className="whitespace-nowrap">{shortDate(instance.next_cycle_date)}</span> },
  { label: "Monto", num: true, render: (instance) => money(instance.amount, currency) },
  {
    label: "Estado",
    render: (instance) => <Badge tone={RECURRING_STATUS[instance.status]?.[1] ?? "neutral"}>{RECURRING_STATUS[instance.status]?.[0] ?? instance.status}</Badge>,
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
        {(errors.form || errors.status) && <p className="mt-2 text-[12.5px] text-[color:var(--p-danger-text)]">{errors.form || errors.status}</p>}
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
  const router = useRouter();
  const { toast, toasts } = useToasts();

  const [contract, setContract] = useState<Contract | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [editing, setEditing] = useState(false);
  const [activating, setActivating] = useState(false);
  const [generate, setGenerate] = useState(true);
  const [creditReason, setCreditReason] = useState("");
  const [creditError, setCreditError] = useState("");
  const [confirming, setConfirming] = useState<Confirm | null>(null);
  const [reason, setReason] = useState("");
  const [reasonError, setReasonError] = useState("");
  const [busy, setBusy] = useState(false);
  const [printing, setPrinting] = useState(false);
  const [signing, setSigning] = useState<"send" | "company" | null>(null);
  const [cancelling, setCancelling] = useState<SignatureRequest | null>(null);
  const [attaching, setAttaching] = useState(false);
  const [openDocument, setOpenDocument] = useState<string | null>(null);
  const documents = useRecordDocuments("Contract", id);

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
  async function run(path: string, done: string, body?: Record<string, unknown>) {
    setBusy(true);
    try {
      const updated = await apiFetch<Contract>(`/v1/contracts/${id}/${path}`, { method: "POST", body: body ? JSON.stringify(body) : undefined });
      setContract(updated);
      toast(done);
      setConfirming(null);
      setActivating(false);
    } catch (err) {
      toast(firstError(err, "No se pudo completar la acción."), "danger");
    } finally {
      setBusy(false);
    }
  }

  /** Orden desde el contrato vigente; como el prototipo, lleva al listado de órdenes. */
  async function createOrder() {
    setBusy(true);
    try {
      await apiFetch(`/v1/contracts/${id}/orders`, { method: "POST" });
      setFlash("Orden creada desde el contrato");
      router.push("/orders");
    } catch (err) {
      toast(firstError(err, "No se pudo crear la orden."), "danger");
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
    <Link href="/contracts" className="mb-3 inline-flex items-center gap-1.5 text-[13px] font-medium text-[color:var(--p-brand-text)] hover:underline">
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
  const recurring = contract.recurring_instances ?? [];
  const orders = contract.orders ?? [];
  const history = contract.history ?? [];
  const visited = history.flatMap((entry) => (entry.metadata.to ? [entry.metadata.to] : [])).concat("DRAFT");
  const signature = contract.signature ?? null;
  // Se envía a firma un borrador sin solicitud vigente ni firmada.
  const canSend = status === "DRAFT" && !(signature && (signature.status === "PENDING" || signature.status === "SIGNED"));
  const companyPending = signature?.status === "PENDING" && signature.signers.some((signer) => signer.side === "EMPRESA" && signer.status === "PENDING");
  // Un contrato sin líneas o sin fecha de inicio no se activa; si la empresa lo exige, tampoco sin las dos firmas.
  // Enviado a firma, espera las dos firmas o que se anule la solicitud, aunque la empresa no exija la firma.
  const notActivable = !items.length || !contract.start_date
    ? "El contrato activo requiere líneas y vigencia"
    : signature?.status === "PENDING"
      ? "Enviado a firma: se activa cuando firmen el cliente y la empresa, o anula la solicitud"
      : signature?.status === "DECLINED"
        ? "El cliente rechazó la firma: anula la solicitud antes de activar"
        : contract.signature_required && contract.signature_status !== "SIGNED"
      ? "Requiere la firma del cliente y de la empresa"
      : contract.credit?.blocked
      ? "Supera el límite de crédito: lo activa quien tenga el permiso de aprobar sobre el límite"
      : undefined;

  function openActivate() {
    setCreditReason("");
    setCreditError("");
    setActivating(true);
  }

  /** Activar; sobre el límite de crédito pide su motivo. */
  function activate() {
    const why = creditReason.trim();
    if (contract?.credit && !why) {
      setCreditError("Indica por qué se activa aunque supere el límite.");
      return;
    }
    run("activate", "Contrato vigente", { generate_services: generate, ...(contract?.credit ? { credit_reason: why } : {}) });
  }

  /** Anular la solicitud de firma (pendiente o rechazada). */
  async function cancelSignature() {
    if (!cancelling) return;
    setBusy(true);
    try {
      setContract(await apiFetch<Contract>(`/v1/contracts/${id}/signature-requests/${cancelling.id}/cancel`, { method: "POST" }));
      toast("Solicitud anulada");
      setCancelling(null);
    } catch (err) {
      toast(firstError(err, "No se pudo anular la solicitud."), "danger");
    } finally {
      setBusy(false);
    }
  }

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
                {canSend && (
                  <Btn variant="secondary" onClick={() => setSigning("send")} permission="contracts.manage" disabled={busy}>
                    <Send size={16} />
                    <span>Enviar a firma</span>
                  </Btn>
                )}
                {companyPending && (
                  <Btn
                    variant="secondary"
                    onClick={() => setSigning("company")}
                    permission="contracts.approve"
                    disabled={busy || signature?.changed}
                    title={signature?.changed ? "El contrato cambió después de enviarse a firma" : undefined}
                  >
                    <FileSignature size={16} />
                    <span>Firmar por la empresa</span>
                  </Btn>
                )}
                <Btn onClick={openActivate} permission="contracts.approve" disabled={busy || Boolean(notActivable)} title={notActivable}>
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
                onClick={createOrder}
                permission="orders.create"
                disabled={busy || orders.length > 0}
                title={orders.length ? "Ya tiene orden" : undefined}
              >
                Crear orden
              </Btn>
            )}
            <Btn variant="ghost" onClick={() => setAttaching(true)} permission="documents.create">
              <Upload size={16} />
              <span>Adjuntar documento</span>
            </Btn>
          </>
        }
      />

      {contract.credit && (
        <Notice tone="warn">
          {contract.credit.message}{" "}
          {contract.credit.blocked
            ? "La empresa no deja activarlo sobre el límite: debe hacerlo quien tenga el permiso de aprobar sobre el límite de crédito."
            : contract.credit.mode === "BLOCK"
              ? "La empresa bloquea activar sobre el límite, pero tienes el permiso para autorizarlo: se pedirá el motivo."
              : "Al activarlo se pedirá el motivo."}
        </Notice>
      )}

      <SortableBoard
        storageKey="erp.cards.contract-detail"
        className="grid grid-cols-1 gap-x-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]"
        zones={[
          { className: "min-w-0 lg:min-h-24", cards: ["lines", "services", ...(recurring.length ? ["recurring"] : [])] },
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
          ...(recurring.length
            ? [{
                id: "recurring",
                node: (
                  <Card title="Instancias recurrentes" flush>
                    <div className="pt-3 sm:px-1.5 sm:pb-2.5">
                      <DataTable cols={recurringColumns(currency)} rows={recurring} rowKey={(instance) => instance.id} dense />
                    </div>
                  </Card>
                ),
              }]
            : []),
          {
            id: "signature",
            node: (
              <Card title="Firma electrónica">
                <SignatureSummary contract={contract} onCancel={setCancelling} />
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
                          onClick={openActivate}
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
                        <Link key="quotation" href={`/quotations/${contract.quotation.id}`} className="text-[color:var(--p-brand-text)] hover:underline">
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
                              <Link href={`/orders/${order.id}`} className="text-[color:var(--p-brand-text)] hover:underline">{order.order_number}</Link>
                              <Badge tone={stateOf(ORDER_STATES, order.status)[1]}>{stateOf(ORDER_STATES, order.status)[0]}</Badge>
                            </span>
                          ))}
                        </span>
                      ) : "—",
                    ],
                    [
                      "Documentos",
                      documents.rows?.length ? (
                        <span key="documents" className="grid gap-1">
                          {documents.rows.map((document) => (
                            <span key={document.id} className="inline-flex flex-wrap items-center gap-1.5">
                              <button type="button" onClick={() => setOpenDocument(document.id)} className="cursor-pointer text-left text-[color:var(--p-brand-text)] hover:underline">
                                {document.title}
                              </button>
                              <span className="app-muted text-xs">v{document.version}</span>
                              {document.status !== "ACTIVE" && <DocumentStatusBadge status={document.status} />}
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
                      // Los contratos anteriores no guardaron de qué cotización nacieron.
                      note: entry.metadata.note
                        ?? (entry.action === "contract.generate" && contract.quotation ? `Desde ${contract.quotation.quotation_number}` : undefined),
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
              <Btn disabled={busy} onClick={activate}>Activar</Btn>
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
          {contract.credit && (
            <div className="mt-3">
              <Notice tone="warn">{contract.credit.message}</Notice>
              <Field label="Motivo para activar sobre el límite de crédito" required error={creditError}>
                <textarea
                  className={INPUT}
                  rows={2}
                  maxLength={500}
                  value={creditReason}
                  onChange={(e) => setCreditReason(e.target.value)}
                  placeholder="Queda en el historial del contrato"
                />
              </Field>
            </div>
          )}
        </Modal>
      )}

      {signing === "send" && (
        <SendSignatureModal
          contract={contract}
          onClose={() => setSigning(null)}
          onDone={(updated) => { setContract(updated); setSigning(null); toast("Contrato enviado a firma: el cliente lo verá en su portal"); }}
        />
      )}
      {signing === "company" && signature && (
        <CompanySignModal
          contract={contract}
          onClose={() => setSigning(null)}
          onDone={(updated) => {
            setContract(updated);
            setSigning(null);
            toast(updated.signature?.status === "SIGNED" ? "Contrato firmado por ambas partes" : "Firma de la empresa registrada");
          }}
        />
      )}
      {cancelling && (
        <Modal
          title="Anular solicitud de firma"
          size="sm"
          onClose={() => setCancelling(null)}
          footer={<><Btn variant="ghost" onClick={() => setCancelling(null)}>Volver</Btn><Btn variant="danger" disabled={busy} onClick={cancelSignature}>Anular</Btn></>}
        >
          <p className="text-[15px]">Podrás editar el contrato y enviarlo nuevamente a firma.</p>
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
      {attaching && (
        <DocumentUploadModal
          fixedLink={{ type: "Contract", id: contract.id, label: `Contrato ${contract.contract_number}`, customer: true }}
          defaultCategory="Contrato"
          toast={toast}
          onClose={() => setAttaching(false)}
          onUploaded={documents.refresh}
        />
      )}
      {openDocument && (
        <DocumentDetailModal documentId={openDocument} toast={toast} onClose={() => setOpenDocument(null)} onChanged={documents.refresh} />
      )}
      {toasts}
    </Screen>
  );
}
