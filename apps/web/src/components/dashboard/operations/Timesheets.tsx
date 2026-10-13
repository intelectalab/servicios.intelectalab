"use client";

import { useEffect, useState } from "react";
import { CalendarClock, Clock, Coins, ListChecks } from "lucide-react";
import { apiFetch, apiFetchLater } from "@/lib/api";
import { hoursText } from "@/lib/operations";
import { useAccess } from "@/lib/access-context";
import { Btn, Card, Kpi, Kpis, PageHead, Screen, Tabs, useToasts } from "@/components/ui/proto";
import { CostEntriesList, Locked, TimeEntriesList } from "@/components/dashboard/operations/Entries";
import { TimeEntryFormModal } from "@/components/dashboard/operations/ProjectModals";
import type { TimeEntryOptions, TimeSummary } from "@/types/operations";

type Tab = "mine" | "approve" | "costs" | "all" | "all-costs";


/** Horas y costos: registrar las horas propias y aprobar las de los demás y los costos. */
export default function Timesheets() {
  const { can, loading: accessLoading } = useAccess();
  const { toast, toasts } = useToasts();

  const [tab, setTab] = useState<Tab>("mine");
  const [summary, setSummary] = useState<TimeSummary | null>(null);
  const [options, setOptions] = useState<TimeEntryOptions | null>(null);
  const [version, setVersion] = useState(0);
  const [logging, setLogging] = useState(false);

  // Indicadores: después del listado.
  useEffect(() => {
    let cancelled = false;
    apiFetchLater<TimeSummary>("/v1/time-entries/summary")
      .then((next) => { if (!cancelled) setSummary(next); })
      .catch(() => undefined);
    return () => { cancelled = true; };
  }, [version]);

  function openLogging() {
    setLogging(true);
    if (!options) apiFetch<TimeEntryOptions>("/v1/time-entries/options").then(setOptions).catch(() => setOptions({ projects: [], work_orders: [] }));
  }

  const changed = () => setVersion((n) => n + 1);
  const shown = (value: number | string | null | undefined) => (summary ? value ?? "—" : "—");
  const allowed = (permission: string) => accessLoading || can(permission);

  return (
    <Screen>
      <PageHead
        title="Horas y costos"
        actions={
          <Btn onClick={openLogging} permission="time_entries.create">
            <Clock size={16} />
            <span>Registrar horas</span>
          </Btn>
        }
      />

      <Kpis>
        <Kpi label="Esta semana" value={shown(summary ? hoursText(summary.week_hours) : undefined)} sub="Tus horas de los últimos 7 días" icon={<CalendarClock size={24} />} />
        <Kpi label="Mis registros" value={shown(summary?.mine)} icon={<Clock size={24} />} />
        <Kpi label="Horas por aprobar" value={shown(summary?.to_approve)} icon={<ListChecks size={24} />} tone="warning" />
        <Kpi label="Costos por aprobar" value={shown(summary?.costs_to_approve)} icon={<Coins size={24} />} tone="warning" />
      </Kpis>

      <Tabs
        value={tab}
        onChange={(next) => setTab(next as Tab)}
        tabs={[
          ["mine", "Mis horas", summary?.mine ?? null],
          ["approve", "Horas por aprobar", summary?.to_approve ?? null],
          ["costs", "Costos por aprobar", summary?.costs_to_approve ?? null],
          ["all", "Todas las horas", null],
          ["all-costs", "Todos los costos", null],
        ]}
      />

      {tab === "mine" && (
        <TimeEntriesList full fixed={{ mine: "1" }} tableId="timesheets-mine" version={version} onChanged={changed} toast={toast} empty="No registraste horas todavía." />
      )}
      {tab === "approve" && (
        allowed("time_entries.approve") ? (
          <>
            <TimeEntriesList full fixed={{ others: "1", status: "SUBMITTED" }} tableId="timesheets-approve" version={version} onChanged={changed} toast={toast} empty="No hay horas pendientes de aprobación." />
            <p className="app-muted text-xs">No puedes aprobar tus propias horas. Solo las horas aprobadas cuentan para el costo de un proyecto.</p>
          </>
        ) : (
          <Card flush><Locked permission="time_entries.approve" /></Card>
        )
      )}
      {tab === "costs" && (
        allowed("cost_entries.view") ? (
          <CostEntriesList full fixed={{ status: "SUBMITTED" }} tableId="timesheets-costs" version={version} onChanged={changed} toast={toast} empty="Sin costos pendientes." />
        ) : (
          <Card flush><Locked permission="cost_entries.view" /></Card>
        )
      )}
      {tab === "all" && (
        <TimeEntriesList full fixed={{}} tableId="timesheets-all" version={version} onChanged={changed} toast={toast} empty="No hay registros con estos filtros." />
      )}
      {tab === "all-costs" && (
        allowed("cost_entries.view") ? (
          <CostEntriesList full fixed={{}} tableId="timesheets-all-costs" version={version} onChanged={changed} toast={toast} empty="No hay registros con estos filtros." />
        ) : (
          <Card flush><Locked permission="cost_entries.view" /></Card>
        )
      )}

      {logging && (
        <TimeEntryFormModal
          options={options}
          toast={toast}
          onClose={() => setLogging(false)}
          onSaved={() => {
            setLogging(false);
            changed();
          }}
        />
      )}
      {toasts}
    </Screen>
  );
}
