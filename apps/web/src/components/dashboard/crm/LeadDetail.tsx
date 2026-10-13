"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { ArrowLeft, Filter as Funnel, Pencil } from "lucide-react";
import { ApiError, apiFetch, apiFetchLater } from "@/lib/api";
import { setFlash, shortDate, stateOf } from "@/lib/commercial";
import { LEAD_OPEN, LEAD_STATES, crmHistoryLabel, dateTime, firstError, leadName } from "@/lib/crm";
import {
  Badge, Btn, Card, Kv, LinkBtn, LoadError, Notice, PageHead, Screen, StateTrack, Timeline, Who, useToasts, type BtnVariant,
} from "@/components/ui/proto";
import { SortableBoard } from "@/components/ui/sortable-cards";
import { ActivitiesCard, ScoreChip } from "@/components/dashboard/crm/Activities";
import { LeadFormModal, QualifyLeadModal, ReasonModal } from "@/components/dashboard/crm/CrmModals";
import type { LeadDetail as Lead, LeadOptions, LeadStatus } from "@/types/crm";

// "reason" indica que la transición pide un motivo antes de ejecutarse.
type Transition = { from: LeadStatus[]; path: string; label: string; done: string; variant: BtnVariant; reason?: boolean };

const TRANSITIONS: Transition[] = [
  { from: ["NEW"], path: "contact", label: "Marcar contactado", done: "Lead actualizado", variant: "secondary" },
  { from: ["NEW", "CONTACTED"], path: "qualify", label: "Calificar", done: "Lead actualizado", variant: "secondary" },
  { from: ["NEW", "CONTACTED", "QUALIFIED"], path: "disqualify", label: "Descartar", done: "Lead descartado", variant: "danger-ghost", reason: true },
];

const LINK = "text-[color:var(--p-brand-text)] hover:underline";

export default function LeadDetail() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const { toast, toasts } = useToasts();

  const [lead, setLead] = useState<Lead | null>(null);
  const [options, setOptions] = useState<Pick<LeadOptions, "sources" | "campaigns">>({ sources: [], campaigns: [] });
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [busy, setBusy] = useState(false);
  const [editing, setEditing] = useState(false);
  const [qualifying, setQualifying] = useState(false);
  const [discarding, setDiscarding] = useState<Transition | null>(null);

  useEffect(() => {
    let cancelled = false;
    apiFetch<Lead>(`/v1/crm/leads/${id}`)
      .then((data) => {
        if (cancelled) return;
        setLead(data);
        setError("");
      })
      .catch((err) => {
        if (!cancelled) setError(err instanceof ApiError && err.status === 404 ? "Lead no encontrado." : "No se pudo cargar el lead.");
      });
    return () => { cancelled = true; };
  }, [id, reload]);

  // Las fuentes solo hacen falta para editar.
  useEffect(() => {
    let cancelled = false;
    apiFetchLater<LeadOptions>("/v1/crm/leads/options").then((next) => { if (!cancelled) setOptions({ sources: next.sources, campaigns: next.campaigns ?? [] }); }).catch(() => undefined);
    return () => { cancelled = true; };
  }, []);

  /** Cambia el estado del lead; la API devuelve la ficha ya actualizada. */
  async function run(transition: Transition, reason?: string) {
    setBusy(true);
    try {
      const updated = await apiFetch<Lead>(`/v1/crm/leads/${id}/${transition.path}`, {
        method: "POST",
        body: reason ? JSON.stringify({ reason }) : undefined,
      });
      setLead(updated);
      toast(transition.done);
      setDiscarding(null);
    } catch (err) {
      toast(firstError(err, "No se pudo completar la acción."), "danger");
    } finally {
      setBusy(false);
    }
  }

  const back = (
    <Link href="/crm/leads" className="mb-3 inline-flex items-center gap-1.5 text-[13px] font-medium text-[color:var(--p-brand-text)] hover:underline">
      <ArrowLeft size={15} /> Leads
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

  if (!lead) {
    return (
      <Screen>
        {back}
        <Card><div className="h-48 animate-pulse rounded bg-[var(--app-surface-muted)]" aria-busy="true" /></Card>
        {toasts}
      </Screen>
    );
  }

  const open = LEAD_OPEN.includes(lead.status);
  const history = lead.history ?? [];
  const visited = history.flatMap((entry) => (entry.metadata.to ? [entry.metadata.to] : [])).concat("NEW");
  const available = TRANSITIONS.filter((transition) => transition.from.includes(lead.status));

  return (
    <Screen>
      {back}
      <PageHead
        showTitle
        title={leadName(lead)}
        sub={[lead.lead_number, lead.organization_name ? lead.person_name : null, lead.source?.name].filter(Boolean).join(" · ")}
        actions={
          open ? (
            <>
              <Btn variant="secondary" onClick={() => setEditing(true)} permission="leads.manage">
                <Pencil size={15} />
                <span>Editar</span>
              </Btn>
              <Btn onClick={() => setQualifying(true)} permission="opportunities.manage">
                <Funnel size={16} />
                <span>Calificar y crear oportunidad</span>
              </Btn>
            </>
          ) : lead.opportunity ? (
            <LinkBtn href={`/crm/opportunities/${lead.opportunity.id}`} variant="secondary" permission="opportunities.view">
              Ver oportunidad {lead.opportunity.opportunity_number}
            </LinkBtn>
          ) : null
        }
      />

      {lead.core_party && (
        <Notice>
          <strong className="font-semibold">Esta empresa ya existe en CORE: {lead.core_party.name}</strong>
          <p className="mt-0.5">El CRM no crea un cliente paralelo. Al convertir la oportunidad se reutilizará esta misma identidad.</p>
          <div className="mt-2">
            <LinkBtn href={`/actors/${lead.core_party.id}`} variant="secondary" size="sm" permission="core.view">Abrir vista 360</LinkBtn>
          </div>
        </Notice>
      )}

      <SortableBoard
        storageKey="erp.cards.crm-lead-detail"
        className="grid grid-cols-1 gap-x-4 lg:grid-cols-2"
        zones={[
          { className: "min-w-0 lg:min-h-24", cards: ["data"] },
          { className: "min-w-0 lg:min-h-24", cards: ["cycle", "activities"] },
        ]}
        cards={[
          {
            id: "data",
            node: (
              <Card title="Datos del prospecto">
                <Kv
                  rows={[
                    ["Empresa", lead.organization_name || "—"],
                    ["Contacto", lead.person_name || "—"],
                    ["Cargo", lead.position || "—"],
                    ["Correo", lead.email || "—"],
                    ["Teléfono", lead.phone || "—"],
                    ["Interés", lead.interest || "—"],
                    ["Fuente", lead.source?.name ?? "—"],
                    lead.campaign ? ["Campaña", lead.campaign.name] : null,
                    [
                      "Puntaje automático",
                      <div key="score">
                        <ScoreChip score={lead.score} grade={lead.score_grade} hits={lead.score_hits} />
                        <small className="app-muted block text-xs">{lead.score_hits?.length ? lead.score_hits.join(" · ") : "Sin criterios cumplidos"}</small>
                        <Link href="/crm/scoring" className={`${LINK} text-xs`}>Ver reglas</Link>
                      </div>,
                    ],
                    ["Responsable", <Who key="owner" name={lead.owner?.name} />],
                    ["Creado", shortDate(lead.created_at)],
                    lead.opportunity
                      ? [
                          "Oportunidad",
                          <Link key="opportunity" href={`/crm/opportunities/${lead.opportunity.id}`} className={LINK}>
                            {lead.opportunity.opportunity_number} · {lead.opportunity.name}
                          </Link>,
                        ]
                      : null,
                    lead.discard_reason ? ["Motivo de descarte", lead.discard_reason] : null,
                  ]}
                />
              </Card>
            ),
          },
          {
            id: "activities",
            // Las interacciones registradas cuentan para el puntaje: al cambiar, la ficha se refresca.
            node: <ActivitiesCard relation={{ type: "lead", id: lead.id }} toast={toast} onChanged={() => setReload((n) => n + 1)} />,
          },
          {
            id: "cycle",
            node: (
              <Card title="Ciclo del lead">
                <div className="grid gap-3">
                  <StateTrack states={LEAD_STATES} current={lead.status} visited={visited} />
                  {available.length ? (
                    <div className="flex flex-wrap gap-1.5">
                      {available.map((transition) => (
                        <Btn
                          key={transition.path}
                          variant={transition.variant}
                          onClick={() => (transition.reason ? setDiscarding(transition) : run(transition))}
                          permission="leads.manage"
                          disabled={busy}
                        >
                          {transition.label}
                        </Btn>
                      ))}
                    </div>
                  ) : (
                    <p className="app-muted text-[12.5px]">Sin transiciones disponibles desde este estado.</p>
                  )}
                  <Timeline
                    items={history.map((entry, index) => {
                      const to = entry.metadata.to;
                      return {
                        key: `${entry.at}-${index}`,
                        head: to ? (
                          <>
                            <Badge tone={stateOf(LEAD_STATES, to)[1]}>{stateOf(LEAD_STATES, to)[0]}</Badge>
                            {entry.metadata.from && entry.metadata.from !== to && (
                              <span className="app-muted text-[12.5px]">desde {stateOf(LEAD_STATES, entry.metadata.from)[0]}</span>
                            )}
                          </>
                        ) : (
                          <span className="font-medium">{crmHistoryLabel(entry)}</span>
                        ),
                        meta: `${entry.user ?? "Sistema"} · ${dateTime(entry.at)}`,
                        note: entry.metadata.note,
                      };
                    })}
                  />
                </div>
              </Card>
            ),
          },
        ]}
      />

      {editing && (
        <LeadFormModal
          lead={lead}
          sources={options.sources}
          campaigns={options.campaigns}
          toast={toast}
          onClose={() => setEditing(false)}
          onSaved={(saved) => {
            setLead(saved);
            setEditing(false);
          }}
        />
      )}
      {qualifying && (
        <QualifyLeadModal
          lead={lead}
          toast={toast}
          onClose={() => setQualifying(false)}
          onCreated={(opportunityId) => {
            setFlash("Oportunidad creada");
            router.push(`/crm/opportunities/${opportunityId}`);
          }}
        />
      )}
      {discarding && (
        <ReasonModal
          title="Descartar lead"
          text={leadName(lead)}
          label="Motivo de descarte"
          confirmLabel="Descartar"
          busy={busy}
          onClose={() => setDiscarding(null)}
          onConfirm={(reason) => run(discarding, reason)}
        />
      )}
      {toasts}
    </Screen>
  );
}
