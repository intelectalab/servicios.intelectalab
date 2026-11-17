"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { CalendarCheck, CalendarClock, CalendarDays, Hourglass, Plus } from "lucide-react";
import { apiFetchLater } from "@/lib/api";
import { Btn, Kpi, Kpis, Notice, PageHead, Screen, Tabs, useToasts } from "@/components/ui/proto";
import LeaveRequestsList from "@/components/dashboard/personal/LeaveRequestsList";
import LeaveCalendar from "@/components/dashboard/personal/LeaveCalendar";
import LeaveTypesList from "@/components/dashboard/personal/LeaveTypesList";
import { LeaveDetailModal, LeaveFormModal, leaveMastered, useLeaveActions, type LeaveConflicts } from "@/components/dashboard/personal/LeaveModals";
import type { LeaveKpis, LeaveList, LeaveOptions, LeaveRequest } from "@/types/personal";

type Tab = "requests" | "calendar" | "types";

const LINK = "font-medium underline underline-offset-2";

/** Personal → Vacaciones y ausencias: bandeja de solicitudes, calendario del equipo y tipos. */
export default function LeavesScreen() {
  const { toast, toasts } = useToasts();
  const [tab, setTab] = useState<Tab>("requests");
  const [options, setOptions] = useState<LeaveOptions | null>(null);
  const [optionsReload, setOptionsReload] = useState(0);
  const [kpis, setKpis] = useState<LeaveKpis | null>(null);
  const [reload, setReload] = useState(0);
  const [creating, setCreating] = useState(false);
  const [open, setOpen] = useState<LeaveRequest | null>(null);
  const [conflicts, setConflicts] = useState<LeaveConflicts | null>(null);
  // Desde la ficha de un trabajador: sus ausencias (?employee=).
  const [employeeFilter, setEmployeeFilter] = useState("");

  const { busy, start, actionModal } = useLeaveActions(
    toast,
    (next) => {
      setOpen((current) => (current && current.id === next.id ? next : current));
      setReload((n) => n + 1);
    },
    setConflicts,
  );

  useEffect(() => {
    async function fromLink() {
      const params = new URLSearchParams(window.location.search);
      const employee = params.get("employee");
      if (employee) setEmployeeFilter(employee);
      const tabParam = params.get("tab");
      if (tabParam === "calendar" || tabParam === "types") setTab(tabParam);
    }
    fromLink();
  }, []);

  useEffect(() => {
    let cancelled = false;
    apiFetchLater<LeaveOptions>("/v1/hr/leaves/options").then((next) => { if (!cancelled) setOptions(next); }).catch(() => undefined);
    return () => { cancelled = true; };
  }, [optionsReload]);

  // En Calendario y Tipos los indicadores se piden aparte (en Solicitudes vienen con el listado).
  useEffect(() => {
    if (tab === "requests") return;
    let cancelled = false;
    apiFetchLater<LeaveList>("/v1/hr/leaves?per_page=1").then((next) => { if (!cancelled) setKpis(next.kpis); }).catch(() => undefined);
    return () => { cancelled = true; };
  }, [tab, reload]);

  const employeeName = options?.employees.find((e) => e.id === employeeFilter)?.name;

  return (
    <Screen>
      <PageHead
        title="Vacaciones y ausencias"
        actions={
          <Btn onClick={() => setCreating(true)} permission="leaves.create">
            <Plus size={16} />
            <span>Nueva solicitud</span>
          </Btn>
        }
      />
      <Tabs
        tabs={[["requests", "Solicitudes", kpis ? kpis.pending : null], ["calendar", "Calendario", null], ["types", "Tipos de ausencia", null]]}
        value={tab}
        onChange={(value) => setTab(value as Tab)}
      />

      {conflicts && (
        <Notice tone="warn">
          <p>
            <strong className="font-semibold">{conflicts.employee}</strong> tiene trabajo agendado en esas fechas:{" "}
            {conflicts.items.map((item, index) => (
              <span key={`${item.type}-${item.id}`}>
                {index > 0 && ", "}
                <Link href={item.type === "work_order" ? `/work-orders/${item.id}` : "/agenda"} className={LINK}>{item.label}</Link>
              </span>
            ))}
            . Reasígnalo.
          </p>
          <button type="button" onClick={() => setConflicts(null)} className="mt-1 cursor-pointer text-[12.5px] font-medium underline-offset-2 hover:underline">
            Entendido
          </button>
        </Notice>
      )}

      {tab !== "types" && (
        <Kpis>
          <Kpi label="Por aprobar" value={kpis ? kpis.pending : "—"} icon={<Hourglass size={22} />} tone="warning" />
          <Kpi label="Ausentes hoy" value={kpis ? kpis.absent_today : "—"} icon={<CalendarClock size={22} />} />
          <Kpi label="Próximas (30 días)" value={kpis ? kpis.upcoming : "—"} icon={<CalendarDays size={22} />} />
          <Kpi label="Días aprobados este año" value={kpis ? kpis.days_this_year : "—"} icon={<CalendarCheck size={22} />} tone="success" />
        </Kpis>
      )}

      {tab === "requests" && (
        <LeaveRequestsList
          toast={toast}
          options={options}
          reload={reload}
          busy={busy}
          onAction={start}
          onOpen={setOpen}
          onKpis={setKpis}
          employee={employeeFilter ? { id: employeeFilter, name: employeeName } : null}
          onClearEmployee={() => {
            setEmployeeFilter("");
            window.history.replaceState(null, "", "/hr/leaves");
          }}
        />
      )}
      {tab === "calendar" && <LeaveCalendar reload={reload} onOpen={setOpen} />}
      {tab === "types" && (
        <LeaveTypesList toast={toast} onChanged={() => setOptionsReload((n) => n + 1)} />
      )}

      {creating && (
        <LeaveFormModal
          options={options}
          toast={toast}
          onClose={() => setCreating(false)}
          onSaved={(leave) => {
            setCreating(false);
            toast(leave.status === "APPROVED" ? "Ausencia registrada y aprobada" : "Solicitud registrada · queda por aprobar");
            setReload((n) => n + 1);
          }}
        />
      )}
      {open && (
        <LeaveDetailModal
          leave={open}
          mastered={leaveMastered(open, options)}
          busy={busy}
          onAction={(action) => start(open, action)}
          onClose={() => setOpen(null)}
        />
      )}
      {actionModal}
      {toasts}
    </Screen>
  );
}
