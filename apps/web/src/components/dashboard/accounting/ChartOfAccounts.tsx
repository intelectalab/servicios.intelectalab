"use client";

import { useEffect, useMemo, useState } from "react";
import { Plus } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { excelTone, stateOf } from "@/lib/commercial";
import { ACCOUNT_TYPES, PERIOD_STATES } from "@/lib/accounting";
import { firstError } from "@/lib/crm";
import { exportCsv, exportXlsx, recordExport, type ExportColumn } from "@/lib/export-table";
import {
  Badge, Btn, Card, Chip, ExportButtons, Field, FormGrid, INPUT, LoadError, Modal, Notice, PageHead, Screen, SearchInput, Tabs, useToasts,
} from "@/components/ui/proto";
import { PickSelect } from "@/components/ui/pick-select";
import { ReasonModal } from "@/components/dashboard/crm/CrmModals";
import type { AccountType, AccountingAccount, AccountingPeriod, PostingRule } from "@/types/accounting";

type Tab = "PCGE" | "RULES" | "PER";
type Toast = ReturnType<typeof useToasts>["toast"];

const TH = "app-muted whitespace-nowrap border-b border-[color:var(--app-border)] px-3.5 pb-2.5 pt-3 text-left text-[10.5px] font-semibold uppercase tracking-[.06em]";
const TD = "border-b border-[color:var(--app-border)] px-3.5 py-2 align-middle";

const ACCOUNT_EXPORT: ExportColumn<AccountingAccount>[] = [
  { label: "Código", value: (a) => a.code, width: 12, kind: "center" },
  { label: "Cuenta", value: (a) => a.name, width: 50 },
  { label: "Tipo", value: (a) => a.type_label, width: 13 },
  { label: "Naturaleza", value: (a) => (a.nature === "D" ? "Deudora" : "Acreedora"), width: 13 },
  { label: "Imputable", value: (a) => (a.postable ? "Sí" : "Título"), width: 11, kind: "center" },
  { label: "Con movimientos", value: (a) => (a.used ? "Sí" : ""), width: 16, kind: "center" },
  { label: "Activa", value: (a) => (a.active ? "Sí" : "No"), width: 10, kind: "center" },
];

const RULE_EXPORT: ExportColumn<PostingRule>[] = [
  { label: "Evento", value: (r) => r.event, width: 28 },
  { label: "Operación", value: (r) => r.label, width: 40 },
  { label: "Asiento", value: (r) => r.lines, width: 70 },
];

const PERIOD_EXPORT: ExportColumn<AccountingPeriod>[] = [
  { label: "Periodo", value: (p) => p.period, width: 12, kind: "center" },
  { label: "Asientos", value: (p) => p.entries, width: 12, kind: "number" },
  { label: "Movimientos bancarios sin conciliar", value: (p) => p.unreconciled, width: 20, kind: "number" },
  { label: "Estado", value: (p) => stateOf(PERIOD_STATES, p.status)[0], width: 14, kind: "status", tone: (p) => excelTone(stateOf(PERIOD_STATES, p.status)[1]) },
  { label: "Cerró", value: (p) => p.closed_by ?? "", width: 24 },
];

export default function ChartOfAccounts() {
  const { toast, toasts } = useToasts();
  const [tab, setTab] = useState<Tab>("PCGE");

  return (
    <Screen>
      <PageHead title="Plan de cuentas, reglas y periodos" />
      <Tabs tabs={[["PCGE", "Plan de cuentas", null], ["RULES", "Reglas automáticas", null], ["PER", "Periodos", null]]} value={tab} onChange={(value) => setTab(value as Tab)} />
      {tab === "PCGE" && <AccountsTab toast={toast} />}
      {tab === "RULES" && <RulesTab toast={toast} />}
      {tab === "PER" && <PeriodsTab toast={toast} />}
      {toasts}
    </Screen>
  );
}

function useExport(toast: Toast) {
  const [busy, setBusy] = useState(false);
  async function run<T>(format: "xlsx" | "csv", name: string, title: string, columns: ExportColumn<T>[], rows: T[]) {
    setBusy(true);
    try {
      await recordExport("accounting", format, rows.length);
      if (format === "xlsx") await exportXlsx(name, title, columns, rows);
      else exportCsv(name, columns, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar.", "danger");
    } finally {
      setBusy(false);
    }
  }
  return { busy, run };
}

function AccountsTab({ toast }: { toast: Toast }) {
  const [accounts, setAccounts] = useState<AccountingAccount[] | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [search, setSearch] = useState("");
  const [editing, setEditing] = useState<AccountingAccount | "new" | null>(null);
  const exporter = useExport(toast);

  useEffect(() => {
    let cancelled = false;
    apiFetch<AccountingAccount[]>("/v1/accounting-accounts")
      .then((next) => { if (!cancelled) { setAccounts(next); setError(""); } })
      .catch(() => { if (!cancelled) setError("No se pudo cargar el plan de cuentas."); });
    return () => { cancelled = true; };
  }, [reload]);

  const plain = (value: string) => value.normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase();
  const rows = useMemo(() => {
    const needle = plain(search.trim());
    return (accounts ?? []).filter((a) => !needle || a.code.startsWith(needle) || plain(a.name).includes(needle));
  }, [accounts, search]);

  return (
    <>
      <Card
        title="Plan Contable General Empresarial (subconjunto)"
        flush
        actions={<Btn size="sm" variant="secondary" permission="accounting.manage" onClick={() => setEditing("new")}><Plus size={14} /><span>Nueva cuenta</span></Btn>}
      >
        <div className="px-5 pb-3 pt-3">
          <div className="max-w-[420px]"><SearchInput value={search} onChange={setSearch} placeholder="Buscar código o cuenta" /></div>
        </div>
        {error ? <LoadError text={error} onRetry={() => setReload((n) => n + 1)} /> : !accounts ? (
          <div className="m-5 h-40 animate-pulse rounded bg-[var(--app-surface-muted)]" aria-busy="true" />
        ) : (
          <>
            <div className="overflow-x-auto px-1">
              <table className="proto-tbl w-full text-[13px]">
                <thead>
                  <tr>
                    <th className={TH}>Código</th><th className={TH}>Cuenta</th><th className={TH}>Tipo</th><th className={TH}>Naturaleza</th><th className={TH}>Imputable</th><th className={TH}>Con movimientos</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.length === 0 && <tr><td colSpan={6} className={`${TD} app-muted text-center`}>No hay cuentas con esa búsqueda.</td></tr>}
                  {rows.map((account) => (
                    <tr key={account.id} className="cursor-pointer hover:bg-[var(--app-surface-muted)]" onClick={() => setEditing(account)}>
                      <td className={TD} data-label="Código">
                        <strong className="font-semibold tabular-nums" style={{ paddingLeft: `${Math.max(0, account.code.length - 2) * 8}px` }}>{account.code}</strong>
                      </td>
                      <td className={TD} data-label="Cuenta">
                        {account.postable ? account.name : <strong className="font-semibold">{account.name}</strong>}
                        {!account.active && <span className="ml-1.5"><Chip tone="muted">Inactiva</Chip></span>}
                      </td>
                      <td className={TD} data-label="Tipo">{account.type_label}</td>
                      <td className={TD} data-label="Naturaleza">{account.nature === "D" ? "Deudora" : "Acreedora"}</td>
                      <td className={TD} data-label="Imputable">{account.postable ? <Chip tone="success">Sí</Chip> : <Chip>Título</Chip>}</td>
                      <td className={TD} data-label="Con movimientos">{account.used ? <Chip tone="info">Sí</Chip> : ""}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="border-t border-[color:var(--app-border)] px-3.5 py-3">
              <ExportButtons permission="accounting.export" total={rows.length} busy={exporter.busy} onExport={(format) => exporter.run(format, "plan-de-cuentas", "Plan de cuentas", ACCOUNT_EXPORT, rows)} />
            </div>
          </>
        )}
      </Card>
      {editing && (
        <AccountModal
          account={editing === "new" ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={(account, created) => {
            setEditing(null);
            toast(created ? "Cuenta creada" : "Cuenta actualizada", "success", `${account.code} · ${account.name}`);
            setReload((n) => n + 1);
          }}
        />
      )}
    </>
  );
}

/** Alta de una cuenta o cambio de nombre y estado de una existente (su código y su tipo no cambian). */
function AccountModal({ account, onClose, onSaved }: { account: AccountingAccount | null; onClose: () => void; onSaved: (account: AccountingAccount, created: boolean) => void }) {
  const [code, setCode] = useState(account?.code ?? "");
  const [name, setName] = useState(account?.name ?? "");
  const [type, setType] = useState<AccountType>(account?.type ?? "EXPENSE");
  const [postable, setPostable] = useState(account?.postable ?? true);
  const [active, setActive] = useState(account?.active ?? true);
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  async function save() {
    setSaving(true);
    setError("");
    try {
      const saved = account
        ? await apiFetch<AccountingAccount>(`/v1/accounting-accounts/${account.id}`, { method: "PUT", body: JSON.stringify({ name: name.trim(), active }) })
        : await apiFetch<AccountingAccount>("/v1/accounting-accounts", { method: "POST", body: JSON.stringify({ code: code.trim(), name: name.trim(), account_type: type, postable }) });
      onSaved(saved, !account);
    } catch (err) {
      setError(firstError(err, "No se pudo guardar la cuenta."));
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      title={account ? `Cuenta ${account.code}` : "Nueva cuenta"}
      size="sm"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn permission="accounting.manage" disabled={saving} onClick={save}>{saving ? "Guardando…" : account ? "Guardar" : "Crear"}</Btn>
        </>
      }
    >
      {error && <Notice tone="warn">{error}</Notice>}
      <FormGrid>
        <Field label="Código" required hint={account ? "El código no cambia." : "Solo dígitos, como en el PCGE (p. ej. 6395)."}>
          <input className={INPUT} value={code} onChange={(event) => setCode(event.target.value.replace(/\D/g, ""))} disabled={Boolean(account)} maxLength={10} inputMode="numeric" />
        </Field>
        <Field label="Nombre" required span={2}>
          <input className={INPUT} value={name} onChange={(event) => setName(event.target.value)} maxLength={255} />
        </Field>
        <div className="md:col-span-2">
          <span className="mb-[5px] block text-[12.5px] font-medium">Tipo</span>
          <PickSelect className={INPUT} ariaLabel="Tipo" disabled={Boolean(account)} value={type} onChange={(value) => setType(value as AccountType)} options={ACCOUNT_TYPES} />
        </div>
        {account ? (
          <label className="relative flex items-center gap-2 self-end pb-2 text-[13px]">
            <input type="checkbox" className="h-4 w-4 accent-[#1B8FC9]" checked={active} onChange={(event) => setActive(event.target.checked)} />
            Activa
          </label>
        ) : (
          <label className="relative flex items-center gap-2 self-end pb-2 text-[13px]">
            <input type="checkbox" className="h-4 w-4 accent-[#1B8FC9]" checked={postable} onChange={(event) => setPostable(event.target.checked)} />
            Imputable
          </label>
        )}
      </FormGrid>
      <p className="app-muted mt-3 text-[12.5px]">
        {account
          ? "Una cuenta inactiva no recibe asientos nuevos. Las cuentas que usan las reglas automáticas no se desactivan."
          : "Cuelga de la cuenta cuyo código es el inicio del suyo; si esa cuenta era imputable y no tiene movimientos, pasa a ser título."}
      </p>
    </Modal>
  );
}

function RulesTab({ toast }: { toast: Toast }) {
  const [rules, setRules] = useState<PostingRule[] | null>(null);
  const [error, setError] = useState(false);
  const exporter = useExport(toast);

  useEffect(() => {
    apiFetch<PostingRule[]>("/v1/accounting-rules").then(setRules).catch(() => setError(true));
  }, []);

  return (
    <Card title="Reglas de contabilización automática" flush>
      {error ? <LoadError text="No se pudieron cargar las reglas." onRetry={() => window.location.reload()} /> : !rules ? (
        <div className="m-5 h-32 animate-pulse rounded bg-[var(--app-surface-muted)]" aria-busy="true" />
      ) : (
        <>
          <div className="overflow-x-auto px-1 pt-1">
            <table className="proto-tbl w-full text-[13px]">
              <thead><tr><th className={TH}>Evento</th><th className={TH}>Operación</th><th className={TH}>Asiento</th></tr></thead>
              <tbody>
                {rules.map((rule) => (
                  <tr key={rule.event}>
                    <td className={TD} data-label="Evento"><code className="rounded bg-[var(--app-surface-muted)] px-1.5 py-0.5 text-[12px]">{rule.event}</code></td>
                    <td className={TD} data-label="Operación">{rule.label}</td>
                    <td className={TD} data-label="Asiento"><small className="text-xs">{rule.lines}</small></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <p className="app-muted px-5 pt-3 text-[12.5px]">
            Cada regla se ejecuta en la misma operación que registra el documento y es idempotente por documento de origen (un documento genera un único asiento).
          </p>
          <div className="mt-2 border-t border-[color:var(--app-border)] px-3.5 py-3">
            <ExportButtons permission="accounting.export" total={rules.length} busy={exporter.busy} onExport={(format) => exporter.run(format, "reglas-de-asientos", "Reglas de contabilización automática", RULE_EXPORT, rules)} />
          </div>
        </>
      )}
    </Card>
  );
}

function PeriodsTab({ toast }: { toast: Toast }) {
  const [periods, setPeriods] = useState<AccountingPeriod[] | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [closing, setClosing] = useState<AccountingPeriod | null>(null);
  const [reopening, setReopening] = useState<AccountingPeriod | null>(null);
  const [busy, setBusy] = useState(false);
  const exporter = useExport(toast);

  useEffect(() => {
    let cancelled = false;
    apiFetch<AccountingPeriod[]>("/v1/accounting-periods")
      .then((next) => { if (!cancelled) { setPeriods(next); setError(""); } })
      .catch(() => { if (!cancelled) setError("No se pudieron cargar los periodos."); });
    return () => { cancelled = true; };
  }, [reload]);

  async function change(period: AccountingPeriod, action: "close" | "reopen", reason?: string) {
    setBusy(true);
    try {
      await apiFetch(`/v1/accounting-periods/${period.period}/${action}`, { method: "POST", body: JSON.stringify(reason ? { reason } : {}) });
      toast("Periodo actualizado", "success", `${period.period} · ${action === "close" ? "cerrado" : "reabierto"}`);
      setClosing(null);
      setReopening(null);
      setReload((n) => n + 1);
    } catch (err) {
      toast(firstError(err, "No se pudo actualizar el periodo."), "danger");
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <Card title="Periodos contables" flush>
        {error ? <LoadError text={error} onRetry={() => setReload((n) => n + 1)} /> : !periods ? (
          <div className="m-5 h-32 animate-pulse rounded bg-[var(--app-surface-muted)]" aria-busy="true" />
        ) : (
          <>
            <div className="overflow-x-auto px-1 pt-1">
              <table className="proto-tbl w-full text-[13px]">
                <thead>
                  <tr>
                    <th className={TH}>Periodo</th><th className={`${TH} text-right`}>Asientos</th><th className={`${TH} text-right`}>Movimientos bancarios sin conciliar</th><th className={TH}>Estado</th><th className={TH}><span className="sr-only">Acción</span></th>
                  </tr>
                </thead>
                <tbody>
                  {periods.map((period) => (
                    <tr key={period.id}>
                      <td className={TD} data-label="Periodo"><strong className="font-semibold tabular-nums">{period.period}</strong></td>
                      <td className={`${TD} text-right tabular-nums`} data-label="Asientos">{period.entries}</td>
                      <td className={`${TD} text-right tabular-nums`} data-label="Sin conciliar">{period.unreconciled}</td>
                      <td className={TD} data-label="Estado">
                        <Badge tone={stateOf(PERIOD_STATES, period.status)[1]}>{stateOf(PERIOD_STATES, period.status)[0]}</Badge>
                        {period.status === "CLOSED" && period.closed_by && <small className="app-muted block text-xs">por {period.closed_by}</small>}
                      </td>
                      <td className={`${TD} text-right`} data-label="">
                        {period.status === "OPEN" ? (
                          <Btn
                            size="sm" variant="secondary" permission="accounting.approve" disabled={!period.ended}
                            title={period.ended ? undefined : "Solo se cierra un periodo que ya terminó"} onClick={() => setClosing(period)}
                          >
                            Cerrar periodo
                          </Btn>
                        ) : (
                          <Btn size="sm" variant="ghost" permission="accounting.approve" onClick={() => setReopening(period)}>Reabrir</Btn>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <p className="app-muted px-5 pt-3 text-[12.5px]">
              Un periodo cerrado no admite asientos: los documentos con fecha en un periodo cerrado se contabilizan en la fecha actual. Cerrar exige tener la conciliación bancaria del periodo al día.
            </p>
            <div className="mt-2 border-t border-[color:var(--app-border)] px-3.5 py-3">
              <ExportButtons permission="accounting.export" total={periods.length} busy={exporter.busy} onExport={(format) => exporter.run(format, "periodos-contables", "Periodos contables", PERIOD_EXPORT, periods)} />
            </div>
          </>
        )}
      </Card>
      {closing && (
        <Modal
          title={`Cerrar periodo ${closing.period}`}
          size="sm"
          onClose={() => setClosing(null)}
          footer={
            <>
              <Btn variant="ghost" onClick={() => setClosing(null)}>Volver</Btn>
              <Btn disabled={busy} onClick={() => change(closing, "close")}>Cerrar</Btn>
            </>
          }
        >
          <p className="text-[15px]">No se podrán registrar asientos con fecha en este periodo.</p>
        </Modal>
      )}
      {reopening && (
        <ReasonModal
          title={`Reabrir periodo ${reopening.period}`}
          text="Reabrir un periodo queda auditado."
          label="Motivo"
          confirmLabel="Reabrir"
          busy={busy}
          onClose={() => setReopening(null)}
          onConfirm={(reason) => change(reopening, "reopen", reason)}
        />
      )}
    </>
  );
}
