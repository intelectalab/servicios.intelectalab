"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { AlertCircle, ArrowLeft, Check, FileText, Lock, Pencil, Users } from "lucide-react";
import { ApiError, apiFetch, apiFetchLater } from "@/lib/api";
import { QUOTATION_STATES, customerName, money, shortDate, stateOf, takeFlash } from "@/lib/commercial";
import { OPPORTUNITY_STATES, crmHistoryLabel, dateTime, firstError } from "@/lib/crm";
import { useAccess } from "@/lib/access-context";
import {
  Badge, Btn, Card, DataTable, Kv, LinkBtn, LoadError, PageHead, Screen, Timeline, Who, deniedText, useDenied, useToasts, type Column,
} from "@/components/ui/proto";
import { SortableBoard } from "@/components/ui/sortable-cards";
import { ActivitiesCard } from "@/components/dashboard/crm/Activities";
import { CloseOpportunityModal, ConvertOpportunityModal, OpportunityFormModal } from "@/components/dashboard/crm/CrmModals";
import type { OpportunityDetail as Opportunity, OpportunityOptions } from "@/types/crm";

const LINK = "text-[color:var(--p-brand-text)] hover:underline";

type QuotationRow = Opportunity["quotations"][number];

const QUOTATION_COLUMNS: Column<QuotationRow>[] = [
  { label: "Número", render: (row) => <strong className="font-semibold">{row.quotation_number}</strong> },
  { label: "Total", num: true, render: (row) => money(row.total, row.currency) },
  {
    label: "Estado",
    render: (row) => {
      const [label, tone] = stateOf(QUOTATION_STATES, row.status);
      return <Badge tone={tone}>{label}</Badge>;
    },
  },
];

const STEP = "grid min-w-0 flex-[1_1_120px] rounded-[7px] border px-2.5 py-2 text-left font-medium transition-colors";

export default function OpportunityDetail() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const { can, loading: accessLoading } = useAccess();
  const { toast, toasts } = useToasts();
  const quotationsDenied = useDenied("quotations.view");

  const [opportunity, setOpportunity] = useState<Opportunity | null>(null);
  const [options, setOptions] = useState<OpportunityOptions>({ stages: [], sources: [], owners: [] });
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [busy, setBusy] = useState(false);
  const [editing, setEditing] = useState(false);
  const [closing, setClosing] = useState<"WON" | "LOST" | null>(null);
  const [converting, setConverting] = useState(false);

  const canManage = !accessLoading && can("opportunities.manage");

  useEffect(() => {
    let cancelled = false;
    apiFetch<Opportunity>(`/v1/crm/opportunities/${id}`)
      .then((data) => {
        if (cancelled) return;
        setOpportunity(data);
        setError("");
        // Se llega desde el tablero tras marcarla ganada: el siguiente paso es convertirla.
        if (new URLSearchParams(window.location.search).get("convert") && data.status === "WON" && !data.conversion) {
          setConverting(true);
          window.history.replaceState(null, "", window.location.pathname);
        }
      })
      .catch((err) => {
        if (!cancelled) setError(err instanceof ApiError && err.status === 404 ? "Oportunidad no encontrada." : "No se pudo cargar la oportunidad.");
      });
    return () => { cancelled = true; };
  }, [id, reload]);

  useEffect(() => {
    let cancelled = false;
    apiFetchLater<OpportunityOptions>("/v1/crm/opportunities/options").then((data) => { if (!cancelled) setOptions(data); }).catch(() => undefined);
    const flash = takeFlash();
    if (flash) toast(flash);
    return () => { cancelled = true; };
  }, [toast]);

  async function moveStage(stageId: string) {
    setBusy(true);
    try {
      setOpportunity(await apiFetch<Opportunity>(`/v1/crm/opportunities/${id}/stage`, { method: "POST", body: JSON.stringify({ stage_id: stageId }) }));
      toast("Etapa actualizada");
    } catch (err) {
      toast(firstError(err, "No se pudo cambiar la etapa."), "danger");
    } finally {
      setBusy(false);
    }
  }

  const back = (
    <Link href="/crm/opportunities" className="mb-3 inline-flex items-center gap-1.5 text-[13px] font-medium text-[color:var(--p-brand-text)] hover:underline">
      <ArrowLeft size={15} /> Pipeline
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

  if (!opportunity) {
    return (
      <Screen>
        {back}
        <Card><div className="h-48 animate-pulse rounded bg-[var(--app-surface-muted)]" aria-busy="true" /></Card>
        {toasts}
      </Screen>
    );
  }

  const { status, conversion, lead } = opportunity;
  const open = status === "OPEN";
  const history = opportunity.history ?? [];
  const position = opportunity.stage?.position ?? 0;
  const partyId = conversion?.party_id ?? opportunity.customer?.party_id ?? null;

  return (
    <Screen>
      {back}
      <PageHead
        showTitle
        title={opportunity.name}
        sub={
          <>
            {[opportunity.opportunity_number, money(opportunity.expected_value), opportunity.source?.name].filter(Boolean).join(" · ")}
            {lead && <> · desde <Link href={`/crm/leads/${lead.id}`} className={LINK}>{lead.lead_number}</Link></>}
          </>
        }
        actions={
          <>
            {open && (
              <>
                <Btn variant="secondary" onClick={() => setEditing(true)} permission="opportunities.manage">
                  <Pencil size={15} />
                  <span>Editar</span>
                </Btn>
                <Btn onClick={() => setClosing("WON")} permission="opportunities.manage">
                  <Check size={16} />
                  <span>Marcar ganada</span>
                </Btn>
                <Btn variant="danger-ghost" onClick={() => setClosing("LOST")} permission="opportunities.manage">Marcar perdida</Btn>
              </>
            )}
            {opportunity.customer_id && status !== "LOST" && (
              <LinkBtn href={`/quotations/new?c=${opportunity.customer_id}&o=${opportunity.id}`} variant="secondary" permission="quotations.manage">
                <FileText size={16} />
                <span>Cotizar desde la oportunidad</span>
              </LinkBtn>
            )}
          </>
        }
      />

      {/* Etapas del embudo: la actual resaltada; con permiso, un clic mueve la oportunidad. */}
      <div className="mb-3.5 flex flex-wrap gap-1" role="group" aria-label="Etapas del embudo">
        {options.stages.map((stage) => {
          const current = open && stage.id === opportunity.stage_id;
          const done = status === "WON" || stage.position < position;
          return (
            <button
              key={stage.id}
              type="button"
              disabled={!open || !canManage || busy || current}
              aria-current={current ? "step" : undefined}
              title={open && !accessLoading && !canManage ? deniedText("opportunities.manage") : undefined}
              onClick={() => moveStage(stage.id)}
              className={`${STEP} ${
                current
                  ? "border-[#1B8FC9] bg-[#1B8FC9] text-[#fff]"
                  : done
                    ? "border-transparent bg-[var(--p-brand-soft)] text-[color:var(--p-brand-soft-text)]"
                    : "border-[color:var(--app-border)] bg-[var(--app-surface)]"
              } ${open && canManage && !current ? "cursor-pointer hover:border-[#1B8FC9]" : "cursor-default"}`}
            >
              <span className="truncate">{stage.name}</span>
              <small className={`font-normal ${current ? "opacity-80" : "app-muted"}`}>{stage.probability}%</small>
              {open && !current && !accessLoading && !canManage && <Lock size={12} className="app-muted shrink-0" aria-hidden="true" />}
            </button>
          );
        })}
        <span
          className={`grid flex-[0_1_110px] place-items-center rounded-[7px] px-2.5 py-2 font-semibold ${
            status === "WON"
              ? "bg-[var(--p-success-soft)] text-[color:var(--p-success-text)]"
              : status === "LOST"
                ? "bg-[var(--p-danger-soft)] text-[color:var(--p-danger-text)]"
                : "app-muted border border-dashed border-[color:var(--app-border)]"
          }`}
        >
          {open ? "Cierre" : stateOf(OPPORTUNITY_STATES, status)[0]}
        </span>
      </div>

      {status === "WON" && (conversion ? (
        <div className="mb-3.5 flex items-start gap-2.5 rounded-xl border border-[color:var(--p-success-line)] bg-[var(--p-success-soft)] px-3.5 py-[11px] text-[color:var(--p-success-text)]">
          <Check size={18} className="mt-px shrink-0" />
          <div className="min-w-0">
            <strong className="font-semibold">Convertida a cliente CORE</strong>
            <p className="mt-0.5">
              {conversion.mode === "REUSE" ? "Se reutilizó" : "Se creó"} la identidad{" "}
              {partyId && can("core.view")
                ? <Link href={`/actors/${partyId}`} className="font-semibold underline">{conversion.party_name ?? "del cliente"}</Link>
                : <strong className="font-semibold">{conversion.party_name ?? "del cliente"}</strong>}{" "}
              el {dateTime(conversion.converted_at)}
            </p>
          </div>
        </div>
      ) : (
        <div className="mb-3.5 flex items-start gap-2.5 rounded-xl border border-[color:var(--p-notice-line)] bg-[var(--p-notice-bg)] px-3.5 py-[11px] text-[color:var(--p-notice-text)]">
          <AlertCircle size={18} className="mt-px shrink-0" />
          <div className="min-w-0">
            <strong className="font-semibold">Oportunidad ganada sin convertir</strong>
            <p className="mt-0.5">Convierte para crear o reutilizar el cliente en CORE y continuar con cotización y contrato.</p>
            <div className="mt-2">
              <Btn size="sm" onClick={() => setConverting(true)} permission="opportunities.manage">
                <Users size={14} />
                <span>Convertir a cliente</span>
              </Btn>
            </div>
          </div>
        </div>
      ))}

      <SortableBoard
        storageKey="erp.cards.crm-opportunity-detail"
        className="grid grid-cols-1 gap-x-4 lg:grid-cols-2"
        zones={[
          { className: "min-w-0 lg:min-h-24", cards: ["summary", "quotations"] },
          { className: "min-w-0 lg:min-h-24", cards: ["activities", "history"] },
        ]}
        cards={[
          {
            id: "summary",
            node: (
              <Card title="Resumen">
                <Kv
                  rows={[
                    [
                      "Cliente CORE",
                      opportunity.customer ? (
                        partyId && can("core.view")
                          ? <Link key="customer" href={`/actors/${partyId}`} className={LINK}>{customerName(opportunity.customer)}</Link>
                          : customerName(opportunity.customer)
                      ) : (
                        <span key="customer" className="app-muted">Aún no vinculado (se resuelve al convertir)</span>
                      ),
                    ],
                    ["Monto estimado", money(opportunity.expected_value)],
                    ["Probabilidad", `${Number(opportunity.probability)}%`],
                    ["Cierre esperado", shortDate(opportunity.expected_close_date)],
                    ["Responsable", <Who key="owner" name={opportunity.owner?.name} />],
                    opportunity.won_evidence ? ["Evidencia de cierre", opportunity.won_evidence] : null,
                    opportunity.loss_reason ? ["Motivo de pérdida", opportunity.loss_reason] : null,
                  ]}
                />
              </Card>
            ),
          },
          {
            id: "activities",
            node: <ActivitiesCard relation={{ type: "opportunity", id: opportunity.id }} toast={toast} />,
          },
          {
            id: "quotations",
            node: (
              <Card title="Cotizaciones vinculadas" flush>
                {quotationsDenied ? (
                  <p className="app-muted px-5 pb-5 pt-3 text-[13px]">{quotationsDenied}</p>
                ) : (
                  <div className="pt-3 sm:px-1.5 sm:pb-2.5">
                    <DataTable
                      cols={QUOTATION_COLUMNS}
                      rows={opportunity.quotations}
                      rowKey={(row) => row.id}
                      onRowClick={(row) => router.push(`/quotations/${row.id}`)}
                      empty="Sin cotizaciones. Convierte o vincula el cliente para poder cotizar."
                      dense
                    />
                  </div>
                )}
              </Card>
            ),
          },
          {
            id: "history",
            node: (
              <Card title="Historial">
                <Timeline
                  items={history.map((entry, index) => {
                    const to = entry.metadata.to;
                    return {
                      key: `${entry.at}-${index}`,
                      head: to ? (
                        <>
                          <Badge tone={stateOf(OPPORTUNITY_STATES, to)[1]}>{stateOf(OPPORTUNITY_STATES, to)[0]}</Badge>
                          {entry.metadata.from && entry.metadata.from !== to && (
                            <span className="app-muted text-[12.5px]">desde {stateOf(OPPORTUNITY_STATES, entry.metadata.from)[0]}</span>
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
              </Card>
            ),
          },
        ]}
      />

      {editing && (
        <OpportunityFormModal
          opportunity={opportunity}
          sources={options.sources}
          toast={toast}
          onClose={() => setEditing(false)}
          onSaved={(saved) => {
            setOpportunity(saved);
            setEditing(false);
          }}
        />
      )}
      {closing && (
        <CloseOpportunityModal
          opportunity={opportunity}
          outcome={closing}
          toast={toast}
          onClose={() => setClosing(null)}
          onClosed={(updated) => {
            setOpportunity(updated);
            setClosing(null);
            // Recién ganada: el siguiente paso es convertirla en cliente.
            if (updated.status === "WON" && !updated.conversion) setConverting(true);
          }}
        />
      )}
      {converting && (
        <ConvertOpportunityModal
          opportunity={opportunity}
          toast={toast}
          onClose={() => setConverting(false)}
          onConverted={(updated) => {
            setOpportunity(updated);
            setConverting(false);
            if (can("quotations.manage")) window.setTimeout(() => toast("Siguiente paso: crea la cotización desde la oportunidad"), 400);
          }}
        />
      )}
      {toasts}
    </Screen>
  );
}
