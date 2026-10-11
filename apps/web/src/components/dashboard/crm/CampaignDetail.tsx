"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ArrowLeft, Calculator, Check, Coins, Filter as Funnel, Pencil, UserCheck, UserPlus, Users } from "lucide-react";
import { ApiError, apiFetch } from "@/lib/api";
import { money, stateOf, takeFlash } from "@/lib/commercial";
import { CAMPAIGN_OPEN, CAMPAIGN_STATES, LEAD_STATES, crmHistoryLabel, dateTime, fieldErrors, firstError } from "@/lib/crm";
import {
  Badge, Btn, Card, Chip, DataTable, Kpi, Kpis, Kv, LoadError, Notice, PageHead, Screen, SearchSelect, StateTrack, Timeline, Who, useToasts,
  type BtnVariant, type Column,
} from "@/components/ui/proto";
import { SortableBoard } from "@/components/ui/sortable-cards";
import { CampaignFormModal } from "@/components/dashboard/crm/CampaignModals";
import { CAMPAIGN_MOVES, campaignPeriod } from "@/components/dashboard/crm/CampaignsList";
import { ReasonModal } from "@/components/dashboard/crm/CrmModals";
import type { CampaignDetail as Campaign, CampaignMember, CampaignStatus } from "@/types/crm";

type Target = Exclude<CampaignStatus, "DRAFT">;

const TARGETS: [Target, BtnVariant][] = [["ACTIVE", "primary"], ["COMPLETED", "secondary"], ["CANCELLED", "danger-ghost"]];

const LINK = "text-[color:var(--p-brand-text)] hover:underline";

export default function CampaignDetail() {
  const { id } = useParams<{ id: string }>();
  const { toast, toasts } = useToasts();

  const [campaign, setCampaign] = useState<Campaign | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [busy, setBusy] = useState(false);
  const [editing, setEditing] = useState(false);
  const [cancelling, setCancelling] = useState(false);

  const [leadId, setLeadId] = useState("");
  const [consent, setConsent] = useState(false);
  const [memberErrors, setMemberErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    const flash = takeFlash();
    if (flash) toast(flash);
  }, [toast]);

  useEffect(() => {
    let cancelled = false;
    apiFetch<Campaign>(`/v1/crm/campaigns/${id}`)
      .then((next) => {
        if (cancelled) return;
        setCampaign(next);
        setError("");
      })
      .catch((err) => {
        if (!cancelled) setError(err instanceof ApiError && err.status === 404 ? "La campaña no existe." : "No se pudo cargar la campaña.");
      });
    return () => { cancelled = true; };
  }, [id, reload]);

  /** Ejecuta una acción sobre la campaña y refresca la ficha con lo que devuelve la API. */
  async function act(path: string, body: unknown, done: string): Promise<boolean> {
    setBusy(true);
    try {
      const updated = await apiFetch<Campaign>(`/v1/crm/campaigns/${id}/${path}`, { method: "POST", body: body ? JSON.stringify(body) : undefined });
      setCampaign(updated);
      toast(done);
      return true;
    } catch (err) {
      setMemberErrors(fieldErrors(err));
      toast(firstError(err, "No se pudo completar la acción."), "danger");
      // Otra persona la cambió: se muestra la versión vigente.
      if (err instanceof ApiError && err.status === 409) setReload((n) => n + 1);
      return false;
    } finally {
      setBusy(false);
    }
  }

  async function addMember() {
    if (!leadId) {
      setMemberErrors({ lead_id: "Elige el lead que quieres agregar." });
      return;
    }
    setMemberErrors({});
    if (await act("members", { lead_id: leadId, consent }, "Miembro agregado")) {
      setLeadId("");
      setConsent(false);
    }
  }

  const back = (
    <Link href="/crm/campaigns" className="app-muted mb-3 inline-flex items-center gap-1.5 text-[13px] hover:text-[var(--app-text)]">
      <ArrowLeft size={15} />
      Campañas
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

  if (!campaign) {
    return (
      <Screen>
        {back}
        <Card><div className="h-48 animate-pulse rounded bg-[var(--app-surface-muted)]" aria-busy="true" /></Card>
        {toasts}
      </Screen>
    );
  }

  const open = CAMPAIGN_OPEN.includes(campaign.status);
  const history = campaign.history ?? [];
  const visited = history.flatMap((entry) => (entry.metadata.to ? [entry.metadata.to] : [])).concat("DRAFT");
  const available = TARGETS.filter(([target]) => CAMPAIGN_MOVES[target].from.includes(campaign.status));
  const metrics = campaign.metrics;

  const memberColumns: Column<CampaignMember>[] = [
    {
      label: "Lead",
      render: (member) => (
        <div>
          <Link href={`/crm/leads/${member.lead.id}`} className={LINK}>{member.lead.name ?? member.lead.lead_number}</Link>
          <small className="app-muted block text-xs">{[member.lead.lead_number, member.lead.person_name].filter(Boolean).join(" · ")}</small>
        </div>
      ),
    },
    {
      label: "Estado del lead",
      render: (member) => {
        const [label, tone] = stateOf(LEAD_STATES, member.lead.status);
        return <Badge tone={tone}>{label}</Badge>;
      },
    },
    { label: "Consentimiento", render: (member) => (member.consent ? <Chip tone="success">Sí</Chip> : <Chip tone="danger">No</Chip>) },
    { label: "Membresía", render: (member) => (member.status === "EXCLUDED" ? <Chip tone="muted">Excluido</Chip> : <Chip>Miembro</Chip>) },
    {
      label: "",
      render: (member) =>
        member.status !== "EXCLUDED" && open ? (
          <Btn variant="ghost" size="sm" disabled={busy} permission="campaigns.manage" onClick={() => act(`members/${member.id}/exclude`, null, "Miembro excluido")}>
            Excluir
          </Btn>
        ) : null,
    },
  ];

  return (
    <Screen>
      {back}
      <PageHead
        showTitle
        title={campaign.name}
        sub={[campaign.code, campaign.channel, `presupuesto ${money(campaign.budget)}`].filter(Boolean).join(" · ")}
        actions={
          open ? (
            <Btn variant="secondary" onClick={() => setEditing(true)} permission="campaigns.manage">
              <Pencil size={15} />
              <span>Editar</span>
            </Btn>
          ) : null
        }
      />

      <Kpis>
        <Kpi label="Miembros" value={metrics.members} icon={<Users size={24} />} />
        <Kpi label="Calificados" value={metrics.qualified} icon={<UserCheck size={24} />} />
        <Kpi label="Oportunidades" value={metrics.opportunities} icon={<Funnel size={24} />} tone="warning" />
        <Kpi label="Ganadas" value={metrics.won} icon={<Check size={24} />} tone="success" />
        <Kpi label="Ingresos" value={money(metrics.revenue)} icon={<Coins size={24} />} tone="success" />
        <Kpi label="Costo por oportunidad" value={metrics.opportunities ? money(campaign.budget / metrics.opportunities) : "—"} icon={<Calculator size={24} />} />
      </Kpis>

      {campaign.hidden_members > 0 && (
        <Notice>
          Ves los miembros y los resultados que tu rol alcanza. Hay {campaign.hidden_members}{" "}
          {campaign.hidden_members === 1 ? "miembro más que lleva" : "miembros más que llevan"} otras personas.
        </Notice>
      )}

      <SortableBoard
        storageKey="erp.cards.crm-campaign-detail"
        className="grid grid-cols-1 gap-x-4 lg:grid-cols-2"
        zones={[
          { className: "min-w-0 lg:min-h-24", cards: ["data", "add"] },
          { className: "min-w-0 lg:min-h-24", cards: ["state"] },
          { className: "min-w-0 lg:col-span-2", cards: ["members"] },
        ]}
        cards={[
          {
            id: "data",
            node: (
              <Card title="Datos de la campaña">
                <Kv
                  rows={[
                    ["Código", campaign.code],
                    ["Canal", campaign.channel || "—"],
                    ["Vigencia", campaignPeriod(campaign)],
                    ["Presupuesto", money(campaign.budget)],
                    ["Consentimiento", campaign.consent_required ? "Se exige a cada miembro" : "No se exige"],
                    ["Responsable", <Who key="owner" name={campaign.owner?.name} />],
                    campaign.cancel_reason ? ["Motivo de cancelación", campaign.cancel_reason] : null,
                  ]}
                />
              </Card>
            ),
          },
          {
            id: "state",
            node: (
              <Card title="Estado">
                <div className="grid gap-3">
                  <StateTrack states={CAMPAIGN_STATES} current={campaign.status} visited={visited} />
                  {available.length ? (
                    <div className="flex flex-wrap gap-1.5">
                      {available.map(([target, variant]) => (
                        <Btn
                          key={target}
                          variant={variant}
                          disabled={busy}
                          permission="campaigns.manage"
                          onClick={() => (target === "CANCELLED" ? setCancelling(true) : act(CAMPAIGN_MOVES[target].path, null, CAMPAIGN_MOVES[target].done))}
                        >
                          {CAMPAIGN_MOVES[target].label}
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
                            <Badge tone={stateOf(CAMPAIGN_STATES, to)[1]}>{stateOf(CAMPAIGN_STATES, to)[0]}</Badge>
                            {entry.metadata.from && entry.metadata.from !== to && (
                              <span className="app-muted text-[12.5px]">desde {stateOf(CAMPAIGN_STATES, entry.metadata.from)[0]}</span>
                            )}
                          </>
                        ) : (
                          <span className="font-medium">{crmHistoryLabel(entry)}</span>
                        ),
                        meta: `${entry.user ?? "Sistema"} · ${dateTime(entry.at)}`,
                        note: entry.metadata.note ?? (typeof entry.metadata.lead_number === "string" ? entry.metadata.lead_number : null),
                      };
                    })}
                  />
                </div>
              </Card>
            ),
          },
          {
            id: "add",
            node: (
              <Card title="Agregar miembros">
                {!open ? (
                  <p className="app-muted text-[13px]">La campaña está {stateOf(CAMPAIGN_STATES, campaign.status)[0].toLowerCase()}: ya no admite cambios en sus miembros.</p>
                ) : (
                  <div className="grid gap-2.5">
                    <SearchSelect
                      value={leadId}
                      onChange={(value) => {
                        setLeadId(value);
                        setMemberErrors({});
                      }}
                      options={campaign.available_leads.map((lead) => ({
                        value: lead.id,
                        label: lead.name ?? lead.number,
                        hint: [lead.number, lead.person_name].filter(Boolean).join(" · "),
                      }))}
                      placeholder="Elegir lead…"
                      searchPlaceholder="Buscar lead…"
                      empty="No hay leads disponibles para agregar."
                    />
                    {memberErrors.lead_id && <small className="text-xs text-[color:var(--p-danger-text)]">{memberErrors.lead_id}</small>}
                    <label className="flex cursor-pointer items-start gap-2 text-[13px]">
                      <input
                        type="checkbox"
                        checked={consent}
                        onChange={(event) => setConsent(event.target.checked)}
                        className="mt-0.5 h-4 w-4 cursor-pointer rounded border-[color:var(--p-field-border)] accent-[#1B8FC9]"
                      />
                      <span>El lead dio su consentimiento para recibir comunicaciones</span>
                    </label>
                    {memberErrors.consent && <small className="text-xs text-[color:var(--p-danger-text)]">{memberErrors.consent}</small>}
                    <div>
                      <Btn variant="secondary" onClick={addMember} disabled={busy} permission="campaigns.manage">
                        <UserPlus size={15} />
                        <span>Agregar</span>
                      </Btn>
                    </div>
                    {campaign.consent_required && (
                      <p className="app-muted text-xs">Esta campaña exige consentimiento registrado de cada miembro.</p>
                    )}
                  </div>
                )}
              </Card>
            ),
          },
          {
            id: "members",
            node: (
              <Card title="Miembros" flush>
                <div className="pt-3 sm:px-1.5 sm:pb-2.5">
                  <DataTable cols={memberColumns} rows={campaign.members} rowKey={(member) => member.id} dense empty="Sin miembros todavía." />
                </div>
              </Card>
            ),
          },
        ]}
      />

      {editing && (
        <CampaignFormModal
          campaign={campaign}
          toast={toast}
          onClose={() => setEditing(false)}
          onSaved={(saved) => {
            setCampaign(saved);
            setEditing(false);
          }}
        />
      )}
      {cancelling && (
        <ReasonModal
          title="Cancelar campaña"
          text={campaign.name}
          label="Motivo de cancelación"
          confirmLabel="Cancelar campaña"
          busy={busy}
          onClose={() => setCancelling(false)}
          onConfirm={async (reason) => {
            if (await act("cancel", { reason }, "Campaña cancelada")) setCancelling(false);
          }}
        />
      )}
      {toasts}
    </Screen>
  );
}
