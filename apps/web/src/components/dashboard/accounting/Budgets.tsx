"use client";

import { useEffect, useState } from "react";
import { Boxes, ChartColumn, Coins, Pencil, Plus, TrendingUp } from "lucide-react";
import { apiFetch, apiFetchLater } from "@/lib/api";
import { money, shortDate, stateOf } from "@/lib/commercial";
import { BUDGET_STATES, CENTER_KINDS, kfmt } from "@/lib/accounting";
import { firstError } from "@/lib/crm";
import { exportCsv, exportXlsx, recordExport, type ExportColumn } from "@/lib/export-table";
import {
  Badge, Btn, Card, Chip, ExportButtons, Field, Filters, FormGrid, INPUT, Kpi, Kpis, LoadError, Modal, Notice, PageHead, Screen, useToasts,
} from "@/components/ui/proto";
import { PickSelect } from "@/components/ui/pick-select";
import { ReasonModal } from "@/components/dashboard/crm/CrmModals";
import { ScopeNote } from "@/components/dashboard/ScopeNote";
import type { BudgetExecution, BudgetLineRow, BudgetOptions, CenterBudget, CenterExecution, CostCenterKind, CostCenterRow } from "@/types/accounting";

type Toast = ReturnType<typeof useToasts>["toast"];

const TH = "app-muted whitespace-nowrap border-b border-[color:var(--app-border)] px-3.5 pb-2.5 pt-3 text-left text-[10.5px] font-semibold uppercase tracking-[.06em]";
const TD = "border-b border-[color:var(--app-border)] px-3.5 py-2.5 align-middle";
const NUM = "text-right tabular-nums whitespace-nowrap";

const CENTER_EXPORT: ExportColumn<CenterExecution>[] = [
  { label: "Código", value: (c) => c.code, width: 12, kind: "center" },
  { label: "Centro de costo", value: (c) => c.name, width: 32 },
  { label: "Tipo", value: (c) => (c.kind === "REVENUE" ? "Centro de ingresos" : "Centro de gastos"), width: 18 },
  { label: "Responsable", value: (c) => c.manager ?? "", width: 24 },
  { label: "Sede", value: (c) => c.branch ?? "", width: 20 },
  { label: "Presupuesto anual", value: (c) => c.annual, width: 16, kind: "money" },
  { label: "A la fecha", value: (c) => c.ytd, width: 15, kind: "money" },
  { label: "Ejecutado", value: (c) => c.actual, width: 15, kind: "money" },
  { label: "Comprometido", value: (c) => (c.committed === null ? "" : c.committed), width: 15, kind: "number" },
  { label: "Avance %", value: (c) => c.pct, width: 11, kind: "number" },
];

const LINE_EXPORT: ExportColumn<BudgetLineRow>[] = [
  { label: "Cuenta", value: (l) => l.account_code, width: 12, kind: "center" },
  { label: "Nombre", value: (l) => l.account_name, width: 40 },
  { label: "Presupuesto anual", value: (l) => l.annual, width: 16, kind: "money" },
  { label: "Presupuesto a la fecha", value: (l) => l.ytd, width: 18, kind: "money" },
  { label: "Ejecutado", value: (l) => l.actual, width: 15, kind: "money" },
  { label: "Avance anual %", value: (l) => (l.pct === null ? "" : l.pct), width: 14, kind: "number" },
];

/** Barra de avance: con los colores de estado solo cuando se pasa o se acerca al tope. */
function Progress({ pct, kind }: { pct: number; kind: CostCenterKind }) {
  const tone = kind === "REVENUE" ? "bg-[var(--p-success)]" : pct > 100 ? "bg-[var(--p-danger-text)]" : pct > 85 ? "bg-[var(--p-warning-text)]" : "bg-[#1B8FC9]";
  const value = Math.max(0, Math.min(100, pct));
  return (
    <span className="block h-1.5 min-w-[110px] overflow-hidden rounded-full bg-[var(--app-surface-muted)]" role="progressbar" aria-valuenow={pct} aria-valuemin={0} aria-valuemax={100}>
      <span className={`block h-full rounded-full ${tone}`} style={{ width: `${value}%` }} />
    </span>
  );
}

export default function Budgets() {
  const { toast, toasts } = useToasts();
  const [year, setYear] = useState(() => new Date().getFullYear());
  const [data, setData] = useState<BudgetExecution | null>(null);
  const [options, setOptions] = useState<BudgetOptions | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [selected, setSelected] = useState("");
  const [detail, setDetail] = useState<CenterBudget | null>(null);
  const [busy, setBusy] = useState(false);
  const [reopening, setReopening] = useState(false);
  const [closingBudget, setClosingBudget] = useState(false);
  const [lineFor, setLineFor] = useState<{ account: string; annual: number } | null>(null);
  const [centerForm, setCenterForm] = useState<CostCenterRow | "new" | null>(null);
  const [exporting, setExporting] = useState(false);

  useEffect(() => {
    let cancelled = false;
    async function load() {
      setLoading(true);
      try {
        const next = await apiFetch<BudgetExecution>(`/v1/budgets/execution?year=${year}`);
        if (!cancelled) {
          setData(next);
          setError("");
        }
      } catch {
        if (!cancelled) setError("No se pudo cargar la ejecución del presupuesto.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [year, reload]);

  useEffect(() => {
    let cancelled = false;
    apiFetchLater<BudgetOptions>("/v1/budgets/options")
      .then((next) => { if (!cancelled) setOptions(next); })
      .catch(() => undefined);
    return () => { cancelled = true; };
  }, [reload]);

  useEffect(() => {
    if (!selected) return;
    let cancelled = false;
    apiFetch<CenterBudget>(`/v1/cost-centers/${selected}/budget?year=${year}`)
      .then((next) => { if (!cancelled) setDetail(next); })
      .catch(() => { if (!cancelled) { setSelected(""); toast("No se pudo cargar el detalle del centro de costo.", "danger"); } });
    return () => { cancelled = true; };
  }, [selected, year, reload, toast]);

  const budget = data?.budget ?? null;
  const draft = budget?.status === "DRAFT";

  async function act(path: string, body: object, success: string) {
    setBusy(true);
    try {
      await apiFetch(path, { method: "POST", body: JSON.stringify(body) });
      toast(success);
      setReopening(false);
      setReload((n) => n + 1);
    } catch (err) {
      toast(firstError(err, "No se pudo completar la acción."), "danger");
    } finally {
      setBusy(false);
    }
  }

  async function runExport<T>(format: "xlsx" | "csv", name: string, title: string, columns: ExportColumn<T>[], rows: T[]) {
    setExporting(true);
    try {
      await recordExport("budgets", format, rows.length);
      if (format === "xlsx") await exportXlsx(name, title, columns, rows);
      else exportCsv(name, columns, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar.", "danger");
    } finally {
      setExporting(false);
    }
  }

  const years = [...new Set([...(options?.years ?? []), year])].sort((a, b) => b - a);

  return (
    <Screen>
      <PageHead
        title="Centros de costo y presupuestos"
        actions={
          <Btn variant="secondary" permission="budgets.manage" onClick={() => setCenterForm("new")}>
            <Plus size={16} />
            <span>Nuevo centro de costo</span>
          </Btn>
        }
      />

      <Filters>
        <div className="flex-[1_1_140px] sm:w-[150px] sm:flex-none">
          <PickSelect className={`${INPUT} h-10`} ariaLabel="Año" value={String(year)} onChange={(value) => { setYear(Number(value)); setSelected(""); }} options={years.map((y): [string, string] => [String(y), `Año ${y}`])} />
        </div>
        <ScopeNote resource="budgets" />
      </Filters>

      {data && (
        budget ? (
          <Notice tone={budget.status === "APPROVED" ? "info" : "warn"}>
            <div>
              <strong className="font-semibold">{budget.name}</strong> · <Badge tone={stateOf(BUDGET_STATES, budget.status)[1]}>{stateOf(BUDGET_STATES, budget.status)[0]}</Badge>
              {budget.approved_by && budget.status === "APPROVED" ? ` · aprobado por ${budget.approved_by} el ${shortDate(budget.approved_at)}` : ""}
              {draft && budget.reason ? ` · reabierto para ajuste: ${budget.reason}` : ""}
            </div>
            <div className="mt-2 flex flex-wrap gap-2">
              {draft ? (
                <Btn size="sm" permission="budgets.approve" disabled={busy} onClick={() => act(`/v1/budgets/${budget.id}/approve`, {}, "Presupuesto aprobado")}>Aprobar presupuesto</Btn>
              ) : budget.status === "APPROVED" ? (
                <>
                  <Btn size="sm" variant="ghost" permission="budgets.manage" onClick={() => setReopening(true)}>Reabrir para ajuste</Btn>
                  {/* Como el prototipo: el presupuesto aprobado de un año terminado se cierra. */}
                  <Btn
                    size="sm" variant="secondary" permission="budgets.approve" disabled={busy || year >= new Date().getFullYear()}
                    title={year >= new Date().getFullYear() ? `El ejercicio ${year} aún no termina` : undefined}
                    onClick={() => setClosingBudget(true)}
                  >
                    Cerrar ejercicio
                  </Btn>
                </>
              ) : (
                <span className="text-[12.5px]">Ejercicio cerrado: el presupuesto queda solo para consulta. Se reabre si Contabilidad reabre el ejercicio {year}.</span>
              )}
            </div>
          </Notice>
        ) : (
          <Notice tone="warn">
            <div>Aún no hay presupuesto {year}. Sin presupuesto aprobado, las órdenes de compra no se controlan contra un disponible.</div>
            <div className="mt-2">
              <Btn size="sm" permission="budgets.manage" disabled={busy} onClick={() => act("/v1/budgets", { year }, `Presupuesto ${year} creado`)}>Crear presupuesto {year}</Btn>
            </div>
          </Notice>
        )
      )}

      <Kpis>
        <Kpi label="Presupuesto de gastos" value={data ? kfmt(data.kpis.cost_budget) : "—"} icon={<Coins size={22} />} />
        <Kpi label="Gasto ejecutado" value={data ? kfmt(data.kpis.cost_actual) : "—"} icon={<ChartColumn size={22} />} />
        <Kpi label="Comprometido en OC" value={data ? kfmt(data.kpis.committed) : "—"} icon={<Boxes size={22} />} />
        <Kpi label="Ingresos vs meta anual" value={data?.kpis.revenue_pct == null ? "—" : `${data.kpis.revenue_pct} %`} icon={<TrendingUp size={22} />} />
      </Kpis>

      {selected && detail && detail.center.id === selected && (
        <Card
          title={`Detalle · ${detail.center.code} ${detail.center.name}`}
          flush
          actions={
            <>
              {draft && <Btn size="sm" variant="secondary" permission="budgets.manage" onClick={() => setLineFor({ account: "", annual: 0 })}>Agregar cuenta</Btn>}
              <Btn size="sm" variant="ghost" permission="budgets.manage" onClick={() => setCenterForm(detail.center)}><Pencil size={13} /><span>Editar centro</span></Btn>
              <Btn size="sm" variant="ghost" onClick={() => setSelected("")}>Cerrar</Btn>
            </>
          }
        >
          <div className="overflow-x-auto px-1 pt-1">
            <table className="proto-tbl w-full text-[13px]">
              <thead>
                <tr>
                  <th className={TH}>Cuenta</th><th className={`${TH} text-right`}>Presupuesto anual</th><th className={`${TH} text-right`}>Presupuesto a la fecha</th>
                  <th className={`${TH} text-right`}>Ejecutado</th><th className={TH}>Avance anual</th><th className={TH}><span className="sr-only">Acción</span></th>
                </tr>
              </thead>
              <tbody>
                {detail.lines.length === 0 && <tr><td colSpan={6} className={`${TD} app-muted text-center`}>{budget ? "Este centro aún no tiene cuentas presupuestadas." : `Crea el presupuesto ${year} para asignarle montos.`}</td></tr>}
                {detail.lines.map((line) => (
                  <tr key={line.account_code}>
                    <td className={TD} data-label="Cuenta">
                      <strong className="font-semibold tabular-nums">{line.account_code}</strong> {line.account_name}
                      {!line.budgeted && <small className="app-muted block text-xs">Con ejecución, sin presupuesto</small>}
                    </td>
                    <td className={`${TD} ${NUM}`} data-label="Anual">{money(line.annual)}</td>
                    <td className={`${TD} ${NUM}`} data-label="A la fecha">{money(line.ytd)}</td>
                    <td className={`${TD} ${NUM}`} data-label="Ejecutado">{money(line.actual)}</td>
                    <td className={TD} data-label="Avance">
                      {line.pct === null ? <span className="app-muted">—</span> : (
                        <div className="min-w-[110px]"><Progress pct={line.pct} kind={detail.center.kind} /><small className="text-xs">{line.pct} %</small></div>
                      )}
                    </td>
                    <td className={`${TD} text-right`} data-label="">
                      {draft && <Btn size="sm" variant="ghost" permission="budgets.manage" onClick={() => setLineFor({ account: line.account_code, annual: line.annual })}>Ajustar</Btn>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="border-t border-[color:var(--app-border)] px-3.5 py-3">
            <ExportButtons
              permission="budgets.export" total={detail.lines.length} busy={exporting}
              onExport={(format) => runExport(format, `presupuesto-${detail.center.code}-${year}`, `Presupuesto ${year} · ${detail.center.code} ${detail.center.name}`, LINE_EXPORT, detail.lines)}
            />
          </div>
        </Card>
      )}

      <Card title="Ejecución por centro de costo" flush>
        {error ? <LoadError text={error} onRetry={() => setReload((n) => n + 1)} /> : !data ? (
          <div className="m-5 h-32 animate-pulse rounded bg-[var(--app-surface-muted)]" aria-busy="true" />
        ) : (
          <>
            <div className={`overflow-x-auto px-1 pt-1 ${loading ? "opacity-60" : ""}`}>
              <table className="proto-tbl w-full text-[13px]">
                <thead>
                  <tr>
                    <th className={TH}>Centro de costo</th><th className={`${TH} text-right`}>Presupuesto anual</th><th className={`${TH} text-right`}>A la fecha</th>
                    <th className={`${TH} text-right`}>Ejecutado</th><th className={`${TH} text-right`}>Comprometido</th><th className={TH}>Avance</th>
                  </tr>
                </thead>
                <tbody>
                  {data.centers.length === 0 && <tr><td colSpan={6} className={`${TD} app-muted text-center`}>No hay centros de costo.</td></tr>}
                  {data.centers.map((center) => (
                    <tr
                      key={center.id} tabIndex={0} role="button" aria-label={`Ver el detalle de ${center.code}`}
                      onClick={() => setSelected(center.id)} onKeyDown={(event) => { if (event.key === "Enter") setSelected(center.id); }}
                      className={`cursor-pointer hover:bg-[var(--app-surface-muted)] ${center.id === selected ? "bg-[var(--p-brand-soft)]" : ""}`}
                    >
                      <td className={TD} data-label="Centro de costo">
                        <strong className="font-semibold">{center.code}</strong> {center.name}
                        {!center.active && <span className="ml-1.5"><Chip tone="muted">Inactivo</Chip></span>}
                        <small className="app-muted block text-xs">
                          {center.kind === "REVENUE" ? "Centro de ingresos" : "Centro de gastos"} · responsable {center.manager ?? "—"}{center.branch ? ` · ${center.branch}` : ""}
                        </small>
                      </td>
                      <td className={`${TD} ${NUM}`} data-label="Anual">{money(center.annual)}</td>
                      <td className={`${TD} ${NUM}`} data-label="A la fecha">{money(center.ytd)}</td>
                      <td className={`${TD} ${NUM}`} data-label="Ejecutado">{money(center.actual)}</td>
                      <td className={`${TD} ${NUM}`} data-label="Comprometido">{center.committed === null ? "—" : money(center.committed)}</td>
                      <td className={TD} data-label="Avance">
                        <div className="min-w-[110px]">
                          <Progress pct={center.pct} kind={center.kind} />
                          <small className="text-xs">{center.pct} % {center.over_ytd && <Chip tone="warning">Sobre lo previsto a la fecha</Chip>}</small>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="border-t border-[color:var(--app-border)] px-3.5 py-3">
              <ExportButtons permission="budgets.export" total={data.centers.length} busy={exporting} onExport={(format) => runExport(format, `ejecucion-presupuesto-${year}`, `Ejecución del presupuesto ${year}`, CENTER_EXPORT, data.centers)} />
            </div>
          </>
        )}
      </Card>

      {reopening && budget && (
        <ReasonModal
          title="Reabrir para ajuste" text="El presupuesto vuelve a borrador para ajustar sus montos; mientras tanto las órdenes de compra no se controlan contra él."
          label="Motivo del ajuste" confirmLabel="Reabrir" busy={busy}
          onClose={() => setReopening(false)} onConfirm={(reason) => act(`/v1/budgets/${budget.id}/reopen`, { reason }, "Presupuesto reabierto para ajuste")}
        />
      )}
      {closingBudget && budget && (
        <Modal
          title={`Cerrar ejercicio ${year}`} size="sm" onClose={() => setClosingBudget(false)}
          footer={
            <>
              <Btn variant="ghost" onClick={() => setClosingBudget(false)}>Volver</Btn>
              <Btn disabled={busy} permission="budgets.approve" onClick={async () => { await act(`/v1/budgets/${budget.id}/close`, {}, "Presupuesto cerrado"); setClosingBudget(false); }}>Cerrar ejercicio</Btn>
            </>
          }
        >
          <p className="text-[15px]">{budget.name} queda cerrado: ya no se ajusta ni se reabre para ajuste.</p>
          <p className="app-muted mt-2 text-[13px]">El asiento de cierre del año lo registra Contabilidad al cerrar el ejercicio en Plan de cuentas y periodos.</p>
        </Modal>
      )}
      {lineFor && budget && detail && detail.center.id === selected && (
        <LineModal
          budgetId={budget.id} center={detail.center} initial={lineFor} options={options}
          onClose={() => setLineFor(null)}
          onSaved={() => { setLineFor(null); toast("Presupuesto ajustado"); setReload((n) => n + 1); }}
        />
      )}
      {centerForm && (
        <CenterModal
          center={centerForm === "new" ? null : centerForm} options={options} toast={toast}
          onClose={() => setCenterForm(null)}
          onSaved={(id) => { setCenterForm(null); setReload((n) => n + 1); setSelected(id); }}
        />
      )}
      {toasts}
    </Screen>
  );
}

function LineModal({
  budgetId, center, initial, options, onClose, onSaved,
}: { budgetId: string; center: CostCenterRow; initial: { account: string; annual: number }; options: BudgetOptions | null; onClose: () => void; onSaved: () => void }) {
  const [account, setAccount] = useState(initial.account);
  const [annual, setAnnual] = useState(initial.annual ? String(initial.annual) : "");
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  async function save() {
    setSaving(true);
    setError("");
    try {
      await apiFetch(`/v1/budgets/${budgetId}/lines`, { method: "PUT", body: JSON.stringify({ cost_center_id: center.id, account_code: account, annual: Number(annual) }) });
      onSaved();
    } catch (err) {
      setError(firstError(err, "No se pudo guardar la línea."));
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      title="Línea de presupuesto" size="sm" onClose={onClose}
      footer={<><Btn variant="ghost" onClick={onClose}>Cancelar</Btn><Btn permission="budgets.manage" disabled={saving || !account || annual === ""} onClick={save}>{saving ? "Guardando…" : "Guardar"}</Btn></>}
    >
      {error && <Notice tone="warn">{error}</Notice>}
      <p className="app-muted mb-3 text-[13px]">{center.code} · {center.name}</p>
      <div className="grid gap-3">
        <div>
          <span className="mb-[5px] block text-[12.5px] font-medium">Cuenta <em className="not-italic text-[color:var(--p-danger-text)]">*</em></span>
          <PickSelect
            className={INPUT} ariaLabel="Cuenta" searchable disabled={Boolean(initial.account)} value={account} onChange={setAccount}
            options={[["", "Selecciona la cuenta"], ...(options?.accounts ?? []).map((a): [string, string] => [a.code, `${a.code} · ${a.name}`])]}
          />
        </div>
        <Field label="Monto anual (S/)" required hint="Se distribuye en 12 meses iguales.">
          <input type="number" min="0" step="0.01" className={INPUT} value={annual} onChange={(event) => setAnnual(event.target.value)} autoFocus />
        </Field>
      </div>
    </Modal>
  );
}

function CenterModal({
  center, options, toast, onClose, onSaved,
}: { center: CostCenterRow | null; options: BudgetOptions | null; toast: Toast; onClose: () => void; onSaved: (id: string) => void }) {
  const [code, setCode] = useState(center?.code ?? "");
  const [name, setName] = useState(center?.name ?? "");
  const [kind, setKind] = useState<CostCenterKind>(center?.kind ?? "COST");
  const [manager, setManager] = useState(center?.manager_user_id ?? "");
  const [branch, setBranch] = useState(center?.branch_id ?? "");
  const [active, setActive] = useState(center?.active ?? true);
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  async function save() {
    setSaving(true);
    setError("");
    const body = { name: name.trim(), kind, manager_user_id: manager || null, branch_id: branch || null };
    try {
      if (center) {
        await apiFetch(`/v1/cost-centers/${center.id}`, { method: "PUT", body: JSON.stringify({ ...body, active }) });
        toast("Centro de costo actualizado", "success", `${center.code} · ${body.name}`);
        onSaved(center.id);
      } else {
        const created = await apiFetch<{ id: string; code: string }>("/v1/cost-centers", { method: "POST", body: JSON.stringify({ ...body, code: code.trim() }) });
        toast("Centro de costo creado", "success", created.code);
        onSaved(created.id);
      }
    } catch (err) {
      setError(firstError(err, "No se pudo guardar el centro de costo."));
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      title={center ? `Centro de costo ${center.code}` : "Nuevo centro de costo"} size="sm" onClose={onClose}
      footer={<><Btn variant="ghost" onClick={onClose}>Cancelar</Btn><Btn permission="budgets.manage" disabled={saving} onClick={save}>{saving ? "Guardando…" : center ? "Guardar" : "Crear"}</Btn></>}
    >
      {error && <Notice tone="warn">{error}</Notice>}
      <FormGrid>
        <Field label="Código" required hint={center ? "El código no cambia." : undefined}>
          <input className={INPUT} value={code} onChange={(event) => setCode(event.target.value.toUpperCase())} disabled={Boolean(center)} maxLength={30} placeholder="CC-OPS" />
        </Field>
        <Field label="Nombre" required span={2}>
          <input className={INPUT} value={name} onChange={(event) => setName(event.target.value)} maxLength={150} />
        </Field>
        <div>
          <span className="mb-[5px] block text-[12.5px] font-medium">Tipo</span>
          <PickSelect className={INPUT} ariaLabel="Tipo" value={kind} onChange={(value) => setKind(value as CostCenterKind)} options={CENTER_KINDS} />
        </div>
        <div className="md:col-span-2">
          <span className="mb-[5px] block text-[12.5px] font-medium">Responsable</span>
          <PickSelect className={INPUT} ariaLabel="Responsable" searchable value={manager} onChange={setManager} options={[["", "—"], ...(options?.users ?? []).map((u): [string, string] => [u.id, u.name])]} />
        </div>
        <div className="md:col-span-2">
          <span className="mb-[5px] block text-[12.5px] font-medium">Sede</span>
          <PickSelect className={INPUT} ariaLabel="Sede" value={branch} onChange={setBranch} options={[["", "—"], ...(options?.branches ?? []).map((b): [string, string] => [b.id, b.name])]} />
        </div>
        {center && (
          <label className="relative flex items-center gap-2 self-end pb-2 text-[13px]">
            <input type="checkbox" className="h-4 w-4 accent-[#1B8FC9]" checked={active} onChange={(event) => setActive(event.target.checked)} />
            Activo
          </label>
        )}
      </FormGrid>
      <p className="app-muted mt-3 text-[12.5px]">La sede decide quién lo ve con el alcance «Sede»; las ventas de la sede van a su centro de ingresos.</p>
    </Modal>
  );
}
