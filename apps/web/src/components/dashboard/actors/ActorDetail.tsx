"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { ArrowLeft, FileText, Plus, Settings } from "lucide-react";
import { ApiError, apiFetch } from "@/lib/api";
import { QUOTATION_STATES, historyLabel, money, shortDate, stateOf, takeFlash } from "@/lib/commercial";
import { CONTRACT_STATES } from "@/lib/contracts";
import { ACTOR_STATES, ROLE_LABELS, ROLE_TONES, kindLabel, rolesText } from "@/lib/actors";
import {
  Badge, Btn, Card, Chip, DataTable, Kv, LinkBtn, LoadError, Notice, PageHead, Screen, StateTrack, Tabs, Timeline, useToasts,
  type Column,
} from "@/components/ui/proto";
import { SortableBoard } from "@/components/ui/sortable-cards";
import { ActorForm } from "./ActorForm";
import type { ActorDetail as Actor } from "@/types/actor";

type Row<K extends keyof Actor> = Actor[K] extends (infer T)[] ? T : never;

const CONTACT_TYPES: Record<string, string> = { EMAIL: "Correo", PHONE: "Teléfono", WHATSAPP: "WhatsApp", ADDRESS: "Dirección", OTHER: "Otro" };

const raw = (status: string) => <Badge tone="neutral">{status}</Badge>;

const QUOTATION_COLUMNS: Column<Row<"quotations">>[] = [
  { label: "Número", render: (row) => <strong className="font-semibold">{row.quotation_number}</strong> },
  { label: "Fecha", render: (row) => shortDate(row.created_at) },
  { label: "Total", num: true, render: (row) => money(row.total, row.currency) },
  { label: "Estado", render: (row) => <Badge tone={stateOf(QUOTATION_STATES, row.status)[1]}>{stateOf(QUOTATION_STATES, row.status)[0]}</Badge> },
];

const CONTRACT_COLUMNS: Column<Row<"contracts">>[] = [
  { label: "Número", render: (row) => <strong className="font-semibold">{row.contract_number}</strong> },
  { label: "Vigencia", render: (row) => <span className="whitespace-nowrap">{shortDate(row.start_date)} → {shortDate(row.end_date)}</span> },
  { label: "Valor", num: true, render: (row) => money(row.total_value, row.currency) },
  { label: "Estado", render: (row) => <Badge tone={stateOf(CONTRACT_STATES, row.status)[1]}>{stateOf(CONTRACT_STATES, row.status)[0]}</Badge> },
];

const WORK_ORDER_COLUMNS: Column<Row<"work_orders">>[] = [
  { label: "OT", render: (row) => <><strong className="font-semibold">{row.code}</strong><small className="app-muted block text-xs">{row.description}</small></> },
  { label: "Programada", render: (row) => shortDate(row.scheduled_start) },
  { label: "Técnico", render: (row) => row.technician ?? "—" },
  { label: "Estado", render: (row) => raw(row.status) },
];

const INVOICE_COLUMNS: Column<Row<"invoices">>[] = [
  { label: "Número", render: (row) => <strong className="font-semibold">{row.invoice_number || "Borrador"}</strong> },
  { label: "Emisión", render: (row) => shortDate(row.issue_date) },
  { label: "Total", num: true, render: (row) => money(row.total, row.currency) },
  { label: "Estado", render: (row) => raw(row.status) },
];

const TICKET_COLUMNS: Column<Row<"tickets">>[] = [
  { label: "Ticket", render: (row) => <><strong className="font-semibold">{row.ticket_number}</strong><small className="app-muted block text-xs">{row.subject}</small></> },
  { label: "Prioridad", render: (row) => row.priority },
  { label: "Estado", render: (row) => raw(row.status) },
];

const DOCUMENT_COLUMNS: Column<Row<"documents">>[] = [
  { label: "Documento", render: (row) => <strong className="font-semibold">{row.title}</strong> },
  { label: "Categoría", render: (row) => row.category },
  { label: "Estado", render: (row) => raw(row.status) },
];

type CrmRow = { id: string; label: string; type: string; status: string };

const CRM_COLUMNS: Column<CrmRow>[] = [
  { label: "Registro", render: (row) => <strong className="font-semibold">{row.label}</strong> },
  { label: "Tipo", render: (row) => row.type },
  { label: "Estado", render: (row) => raw(row.status) },
];

const dateTime = (value: string) => new Date(value).toLocaleString("es-PE", { dateStyle: "medium", timeStyle: "short" });

function firstError(err: unknown, fallback: string) {
  if (err instanceof ApiError) return (err.errors && Object.values(err.errors)[0]?.[0]) || err.message;
  return fallback;
}

/** Tabla de una pestaña de la vista 360, dentro de su tarjeta. */
function Listing<T extends { id: string }>({
  title, cols, rows, onRowClick,
}: { title: string; cols: Column<T>[]; rows: T[]; onRowClick?: (row: T) => void }) {
  return (
    <Card title={title} flush>
      <div className="pt-3 sm:px-1.5 sm:pb-2.5">
        <DataTable cols={cols} rows={rows} rowKey={(row) => row.id} onRowClick={onRowClick} dense />
      </div>
    </Card>
  );
}

export default function ActorDetail() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const { toast, toasts } = useToasts();

  const [actor, setActor] = useState<Actor | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [tab, setTab] = useState("resumen");
  const [editing, setEditing] = useState(false);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    const flash = takeFlash();
    if (flash) toast(flash);
  }, [toast]);

  useEffect(() => {
    let cancelled = false;
    apiFetch<Actor>(`/v1/parties/${id}`)
      .then((data) => {
        if (cancelled) return;
        setActor(data);
        setError("");
      })
      .catch((err) => {
        if (!cancelled) setError(err instanceof ApiError && err.status === 404 ? "Registro no encontrado" : "No se pudo cargar el registro.");
      });
    return () => { cancelled = true; };
  }, [id, reload]);

  async function setStatus(to: "ACTIVE" | "INACTIVE", label: string) {
    setBusy(true);
    try {
      setActor(await apiFetch<Actor>(`/v1/parties/${id}/${to === "ACTIVE" ? "activate" : "deactivate"}`, { method: "POST" }));
      toast(`${label}: listo`);
    } catch (err) {
      toast(firstError(err, "No se pudo completar la acción."), "danger");
    } finally {
      setBusy(false);
    }
  }

  const back = (
    <Link href="/actors" className="mb-3 inline-flex items-center gap-1.5 text-[13px] font-medium text-[#1679AB] hover:underline">
      <ArrowLeft size={15} /> Clientes y actores
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

  if (!actor) {
    return (
      <Screen>
        {back}
        <Card><div className="h-48 animate-pulse rounded bg-[var(--app-surface-muted)]" aria-busy="true" /></Card>
        {toasts}
      </Screen>
    );
  }

  const isOrg = actor.kind === "ORGANIZATION";
  const isCustomer = actor.roles.includes("CUSTOMER");
  const visited = actor.history.flatMap((entry) => (entry.metadata.to ? [entry.metadata.to] : [])).concat("ACTIVE");
  const crmRows: CrmRow[] = [
    ...actor.opportunities.map((row) => ({ id: row.id, label: row.name, type: "Oportunidad", status: row.status })),
    ...actor.leads.map((row) => ({ id: row.id, label: row.person_name || row.organization_name || "—", type: "Lead", status: row.status })),
  ];

  const tabs: [string, string, number | null][] = [
    ["resumen", "Resumen", null],
    ["comercial", "Comercial", actor.quotations.length + actor.contracts.length],
    ["operaciones", "Operaciones", actor.work_orders.length],
    ["finanzas", "Finanzas", actor.invoices.length],
    ["soporte", "Soporte", actor.tickets.length],
    ["documentos", "Documentos", actor.documents.length],
    ["crm", "CRM", crmRows.length],
  ];

  return (
    <Screen>
      {back}
      <PageHead
        showTitle
        title={actor.name}
        sub={`${kindLabel(actor)} · ${rolesText(actor) || "sin roles"} · vista 360`}
        actions={
          <>
            <Btn variant="secondary" onClick={() => setEditing(true)} permission="core.manage" disabled={busy}>
              <Settings size={16} />
              <span>Editar</span>
            </Btn>
            {isCustomer && (
              // Aún sin función: depende de Finanzas (cuentas por cobrar).
              <Btn variant="ghost" onClick={() => toast("El estado de cuenta aún no está disponible.", "warning")}>
                <FileText size={16} />
                <span>Estado de cuenta</span>
              </Btn>
            )}
            {isCustomer && actor.customer_id && (
              <LinkBtn href={`/quotations/new?c=${actor.customer_id}`} permission="quotations.manage">
                <Plus size={16} />
                <span>Nueva cotización</span>
              </LinkBtn>
            )}
          </>
        }
      />

      <Tabs tabs={tabs} value={tab} onChange={setTab} />

      {tab === "resumen" && (
        <SortableBoard
          storageKey="erp.cards.actor-detail"
          className="grid grid-cols-1 gap-x-4 lg:grid-cols-2"
          zones={[
            { className: "min-w-0 lg:min-h-24", cards: ["identity", "history"] },
            { className: "min-w-0 lg:min-h-24", cards: ["contacts", "terms", "finance"] },
          ]}
          cards={[
            {
              id: "identity",
              node: (
                <Card title="Identidad canónica">
                  <Kv
                    rows={[
                      ["Tipo", kindLabel(actor)],
                      isOrg ? ["Razón social", actor.organization?.legal_name ?? "—"] : ["Nombre", actor.name],
                      isOrg && actor.organization?.trade_name ? ["Nombre comercial", actor.organization.trade_name] : null,
                      isOrg
                        ? ["RUC", <code key="ruc">{actor.organization?.tax_identifier || "—"}</code>]
                        : ["Documento", `${actor.person?.document_type ?? ""} ${actor.person?.document_number ?? ""}`.trim() || "—"],
                      [
                        "Roles",
                        <span key="roles" className="inline-flex flex-wrap gap-1">
                          {actor.roles.length ? actor.roles.map((role) => {
                            const off = actor.inactive_roles.includes(role);
                            return (
                              <span key={role} title={off ? `${ROLE_LABELS[role]} inactivo` : undefined} className={off ? "opacity-50" : ""}>
                                <Chip tone={off ? "neutral" : ROLE_TONES[role]}>{ROLE_LABELS[role]}</Chip>
                              </span>
                            );
                          }) : "—"}
                        </span>,
                      ],
                      ["Correo", actor.email || "—"],
                      ["Teléfono", actor.phone || "—"],
                      ["Dirección", actor.address || "—"],
                      isOrg && actor.sector ? ["Sector", actor.sector] : null,
                      actor.sla_level
                        ? ["Nivel de SLA", <Chip key="sla" tone={actor.sla_level === "PREMIUM" ? "accent" : "neutral"}>{actor.sla_level === "PREMIUM" ? "Premium" : "Estándar"}</Chip>]
                        : null,
                      actor.employee_id ? ["Código de empleado", actor.employee_code || "—"] : null,
                      actor.employee_id ? ["Cargo", actor.position || "—"] : null,
                      actor.user_name ? ["Usuario", actor.user_name] : null,
                      ["Alta", shortDate(actor.created_at)],
                    ]}
                  />
                  <div className="mt-3 grid gap-3">
                    <StateTrack states={ACTOR_STATES} current={actor.status} visited={visited} />
                    <div className="flex flex-wrap gap-1.5">
                      {actor.status === "ACTIVE" ? (
                        <Btn variant="danger-ghost" onClick={() => setStatus("INACTIVE", "Inactivar (baja lógica)")} permission="core.manage" disabled={busy}>
                          Inactivar (baja lógica)
                        </Btn>
                      ) : (
                        <Btn variant="secondary" onClick={() => setStatus("ACTIVE", "Reactivar")} permission="core.manage" disabled={busy}>
                          Reactivar
                        </Btn>
                      )}
                    </div>
                  </div>
                </Card>
              ),
            },
            {
              id: "contacts",
              node: (
                <Card title="Contactos">
                  {actor.contacts.length ? (
                    <ul>
                      {actor.contacts.map((contact) => (
                        <li key={contact.id} className="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-[color:var(--app-border)] py-[7px] last:border-b-0">
                          <strong className="font-semibold">{contact.contact_name || CONTACT_TYPES[contact.contact_type] || contact.contact_type}</strong>
                          {contact.contact_name && <span>· {CONTACT_TYPES[contact.contact_type] ?? contact.contact_type}</span>}
                          {contact.is_primary && <Chip tone="neutral">Principal</Chip>}
                          <small className="app-muted w-full text-xs">{contact.country_code ? `${contact.country_code} ` : ""}{contact.value}</small>
                        </li>
                      ))}
                    </ul>
                  ) : (
                    <p className="app-muted">Sin contactos registrados.</p>
                  )}
                </Card>
              ),
            },
            ...(actor.customer ? [{
              id: "terms",
              node: (
                <Card title="Condiciones comerciales (cliente)">
                  <Kv
                    rows={[
                      ["Días de crédito", actor.customer.payment_terms_days == null ? "—" : `${actor.customer.payment_terms_days} días`],
                      ["Límite de crédito", actor.customer.credit_limit == null ? "—" : money(actor.customer.credit_limit, actor.customer.preferred_currency)],
                      ["Moneda", actor.customer.preferred_currency === "USD" ? "USD ($)" : "PEN (S/)"],
                    ]}
                  />
                </Card>
              ),
            }] : []),
            ...(actor.finance ? [{
              id: "finance",
              node: (
                <Card title="Situación financiera">
                  <Kv
                    rows={[
                      ["Saldo pendiente", money(actor.finance.outstanding)],
                      ["Vencido", <span key="overdue" className={actor.finance.overdue ? "text-[#D93A40]" : ""}>{money(actor.finance.overdue)}</span>],
                      ["Documentos abiertos", actor.finance.open_documents],
                    ]}
                  />
                </Card>
              ),
            }] : []),
            {
              id: "history",
              node: (
                <Card title="Historial">
                  <Timeline
                    items={actor.history.map((entry, index) => {
                      const to = entry.metadata.to;
                      return {
                        key: `${entry.at}-${index}`,
                        head: to ? (
                          <>
                            <Badge tone={stateOf(ACTOR_STATES, to)[1]}>{stateOf(ACTOR_STATES, to)[0]}</Badge>
                            {entry.metadata.from && entry.metadata.from !== to && (
                              <span className="app-muted text-[12.5px]">desde {stateOf(ACTOR_STATES, entry.metadata.from)[0]}</span>
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
      )}

      {tab === "resumen" && actor.roles.includes("SUPPLIER") && isCustomer && (
        <Notice>
          <strong className="font-semibold">Una sola identidad, dos roles</strong>
          <p>{isOrg ? "Esta organización" : "Esta persona"} es cliente y proveedor a la vez sin duplicarse.</p>
        </Notice>
      )}

      {tab === "comercial" && (
        <>
          <Listing title="Cotizaciones" cols={QUOTATION_COLUMNS} rows={actor.quotations} onRowClick={(row) => router.push(`/quotations/${row.id}`)} />
          <Listing title="Contratos" cols={CONTRACT_COLUMNS} rows={actor.contracts} onRowClick={(row) => router.push(`/contracts/${row.id}`)} />
        </>
      )}
      {tab === "operaciones" && <Listing title="Órdenes de trabajo" cols={WORK_ORDER_COLUMNS} rows={actor.work_orders} />}
      {tab === "finanzas" && <Listing title="Comprobantes" cols={INVOICE_COLUMNS} rows={actor.invoices} />}
      {tab === "soporte" && <Listing title="Tickets" cols={TICKET_COLUMNS} rows={actor.tickets} />}
      {tab === "documentos" && <Listing title="Documentos vinculados" cols={DOCUMENT_COLUMNS} rows={actor.documents} />}
      {tab === "crm" && <Listing title="Leads y oportunidades vinculados" cols={CRM_COLUMNS} rows={crmRows} />}

      {editing && (
        <ActorForm
          kind={actor.kind}
          actor={actor}
          onClose={() => setEditing(false)}
          onSaved={(saved, message) => {
            setActor(saved);
            setEditing(false);
            toast(message);
          }}
          onOpenExisting={(otherId) => router.push(`/actors/${otherId}`)}
        />
      )}
      {toasts}
    </Screen>
  );
}
