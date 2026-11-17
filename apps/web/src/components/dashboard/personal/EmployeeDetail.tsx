"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ArrowLeft, Pencil, RotateCcw, UserMinus } from "lucide-react";
import { ApiError, apiFetch, apiFetchLater } from "@/lib/api";
import { shortDate, stateOf } from "@/lib/commercial";
import { dateTime, firstError } from "@/lib/crm";
import { EMPLOYEE_STATES, HR_MASTERED_NOTE, LEAVE_STATES, hrDateTime, hrHistoryLabel, isMastered, money, periodLabel } from "@/lib/personal";
import { Badge, Btn, Card, Chip, DataTable, Kv, LoadError, PageHead, Screen, Timeline, useToasts, type Column } from "@/components/ui/proto";
import { SortableBoard } from "@/components/ui/sortable-cards";
import { EmployeeFormModal, TerminateEmployeeModal, ifMatch } from "@/components/dashboard/personal/EmployeeModals";
import type { HrEmployeeDetail, HrEmployeeOptions, HrHistoryEntry, HrMode } from "@/types/personal";

const LINK = "text-[color:var(--p-brand-text)] hover:underline";

type Leave = HrEmployeeDetail["leaves"][number];
type Hours = HrEmployeeDetail["hours"][number];

/** Ficha del trabajador: datos, ausencias, horas aprobadas e historial. */
export default function EmployeeDetail() {
  const { id } = useParams<{ id: string }>();
  const { toast, toasts } = useToasts();
  const [employee, setEmployee] = useState<HrEmployeeDetail | null>(null);
  const [mode, setMode] = useState<HrMode | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [busy, setBusy] = useState(false);
  const [modal, setModal] = useState<"edit" | "terminate" | null>(null);

  useEffect(() => {
    let cancelled = false;
    apiFetch<HrEmployeeDetail>(`/v1/hr/employees/${id}`)
      .then((next) => {
        if (cancelled) return;
        setEmployee(next);
        setError("");
      })
      .catch((err) => {
        if (!cancelled) setError(err instanceof ApiError && err.status === 404 ? "El trabajador no existe." : "No se pudo cargar el trabajador.");
      });
    return () => { cancelled = true; };
  }, [id, reload]);

  useEffect(() => {
    let cancelled = false;
    apiFetchLater<HrEmployeeOptions>("/v1/hr/employees/options").then((next) => { if (!cancelled) setMode(next.mode); }).catch(() => undefined);
    return () => { cancelled = true; };
  }, []);

  async function reinstate() {
    if (!employee) return;
    setBusy(true);
    try {
      await apiFetch(`/v1/hr/employees/${employee.id}/reinstate`, { method: "POST", headers: ifMatch(employee.lock_version) });
      toast("Cese deshecho: el trabajador vuelve a estar activo");
      setReload((n) => n + 1);
    } catch (err) {
      toast(firstError(err, "No se pudo deshacer el cese."), "danger");
      if (err instanceof ApiError && err.status === 409) setReload((n) => n + 1);
    } finally {
      setBusy(false);
    }
  }

  const back = (
    <Link href="/hr/employees" className="app-muted mb-3 inline-flex items-center gap-1.5 text-[13px] hover:text-[var(--app-text)]">
      <ArrowLeft size={15} />
      Trabajadores y puestos
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

  if (!employee) {
    return (
      <Screen>
        {back}
        <Card><div className="h-48 animate-pulse rounded bg-[var(--app-surface-muted)]" aria-busy="true" /></Card>
        {toasts}
      </Screen>
    );
  }

  const mastered = isMastered(employee.source, mode);
  const [statusLabel, statusTone] = stateOf(EMPLOYEE_STATES, employee.status);
  const history = (employee.history ?? []) as HrHistoryEntry[];
  const terminated = employee.status === "TERMINATED";

  const leaveCols: Column<Leave>[] = [
    { label: "Tipo", render: (leave) => <span className="inline-flex flex-wrap items-center gap-1">{leave.type}{leave.source === "HR_SAAS" && <Chip tone="accent">RRHH</Chip>}</span> },
    { label: "Desde", render: (leave) => <span className="whitespace-nowrap">{shortDate(leave.date_from)}</span> },
    { label: "Hasta", render: (leave) => <span className="whitespace-nowrap">{shortDate(leave.date_to)}</span> },
    { label: "Días", num: true, render: (leave) => leave.days },
    { label: "Estado", render: (leave) => <Badge tone={stateOf(LEAVE_STATES, leave.status)[1]}>{stateOf(LEAVE_STATES, leave.status)[0]}</Badge> },
  ];
  const hourCols: Column<Hours>[] = [
    { label: "Periodo", render: (row) => <span className="capitalize">{periodLabel(row.period)}</span> },
    { label: "Horas", num: true, render: (row) => Number(row.hours).toLocaleString("es-PE", { maximumFractionDigits: 2 }) },
    { label: "Costo", num: true, render: (row) => money(Number(row.cost), employee.currency) },
  ];

  return (
    <Screen>
      {back}
      <PageHead
        showTitle
        title={employee.name}
        sub={
          <span className="inline-flex flex-wrap items-center gap-1.5">
            <Badge tone={statusTone}>{statusLabel}</Badge>
            {employee.on_leave && <Badge tone="warning">{employee.on_leave.type} hasta {shortDate(employee.on_leave.date_to)}</Badge>}
            {employee.source === "HR_SAAS" && <Chip tone="accent">RRHH</Chip>}
            <span>{[employee.code, employee.position?.name, employee.branch?.name].filter(Boolean).join(" · ")}</span>
          </span>
        }
        actions={
          <>
            <Btn onClick={() => setModal("edit")} permission="employees.manage" disabled={busy}>
              <Pencil size={16} />
              <span>Editar</span>
            </Btn>
            {terminated ? (
              <Btn variant="secondary" onClick={reinstate} permission="employees.manage" disabled={busy || mastered} title={mastered ? HR_MASTERED_NOTE : undefined}>
                <RotateCcw size={16} />
                <span>Deshacer cese</span>
              </Btn>
            ) : (
              <Btn variant="danger-ghost" onClick={() => setModal("terminate")} permission="employees.manage" disabled={busy || mastered} title={mastered ? HR_MASTERED_NOTE : undefined}>
                <UserMinus size={16} />
                <span>Registrar cese</span>
              </Btn>
            )}
          </>
        }
      />

      <SortableBoard
        storageKey="erp.cards.hr-employee-detail"
        className="grid grid-cols-1 gap-x-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]"
        zones={[
          { className: "min-w-0 lg:min-h-24", cards: ["data", "hours"] },
          { className: "min-w-0 lg:min-h-24", cards: ["leaves", "history"] },
        ]}
        cards={[
          {
            id: "data",
            node: (
              <Card title="Datos del trabajador">
                {mastered && <p className="app-muted mb-2 text-[12.5px]">{HR_MASTERED_NOTE}: aquí solo cambias el usuario vinculado y la sede.</p>}
                <Kv
                  rows={[
                    ["Persona", <Link key="party" href={`/actors/${employee.party_id}`} className={LINK}>{employee.name}</Link>],
                    ["Documento", employee.document_number ? `${employee.document_type ?? ""} ${employee.document_number}`.trim() : "—"],
                    ["Código", employee.code ?? "—"],
                    ["Puesto", employee.position?.name ?? "—"],
                    ["Sede", employee.branch?.name ?? "—"],
                    ["Usuario", employee.user ? `${employee.user.name} · ${employee.user.email}` : "Sin usuario"],
                    ["Ingreso", shortDate(employee.hire_date)],
                    ["Contrato", employee.contract_label ?? "—"],
                    ["Jornada semanal", `${employee.weekly_hours.toLocaleString("es-PE")} h`],
                    ["Costo por hora", employee.hourly_cost == null ? <Chip key="cost" tone="warning">Sin costo</Chip> : money(employee.hourly_cost, employee.currency)],
                    ["Código en planilla", employee.payroll_external_id ?? "—"],
                    ["Origen", employee.source === "HR_SAAS" ? `SaaS de RRHH${employee.synced_at ? ` · sincronizado ${hrDateTime(employee.synced_at)}` : ""}` : "ERP"],
                    terminated ? ["Cese", shortDate(employee.termination_date)] : null,
                    terminated ? ["Motivo del cese", employee.termination_reason ?? "—"] : null,
                  ]}
                />
              </Card>
            ),
          },
          {
            id: "hours",
            node: (
              <Card title="Horas aprobadas por mes" flush>
                {employee.user ? (
                  <DataTable cols={hourCols} rows={employee.hours} rowKey={(row) => row.period} dense empty="Sin horas aprobadas este mes ni el anterior." />
                ) : (
                  <p className="app-muted px-5 pb-[18px] pt-4">Sin usuario vinculado: sus horas no se registran.</p>
                )}
              </Card>
            ),
          },
          {
            id: "leaves",
            node: (
              <Card title="Ausencias recientes" flush actions={<Link href={`/hr/leaves?employee=${employee.id}`} className={`${LINK} text-[12.5px] font-medium`}>Ver todas</Link>}>
                <DataTable cols={leaveCols} rows={employee.leaves} rowKey={(leave) => leave.id} dense empty="Sin ausencias registradas." />
              </Card>
            ),
          },
          {
            id: "history",
            node: (
              <Card title="Historial">
                <Timeline
                  items={history.map((entry, index) => ({
                    key: `${entry.at}-${index}`,
                    head: <span className="font-medium">{hrHistoryLabel(entry.action)}</span>,
                    meta: `${entry.user ?? "Sistema"} · ${dateTime(entry.at)}`,
                    note: entry.metadata?.note ?? entry.metadata?.reason,
                  }))}
                />
              </Card>
            ),
          },
        ]}
      />

      {modal === "edit" && (
        <EmployeeFormModal
          employee={employee}
          toast={toast}
          onClose={() => {
            setModal(null);
            setReload((n) => n + 1);
          }}
          onSaved={() => {
            setModal(null);
            toast("Trabajador actualizado");
            setReload((n) => n + 1);
          }}
        />
      )}
      {modal === "terminate" && (
        <TerminateEmployeeModal
          employee={employee}
          toast={toast}
          onClose={() => setModal(null)}
          onDone={() => {
            setModal(null);
            toast("Cese registrado", "success", "Si tenía órdenes de trabajo abiertas, reasígnalas.");
            setReload((n) => n + 1);
          }}
        />
      )}
      {toasts}
    </Screen>
  );
}
