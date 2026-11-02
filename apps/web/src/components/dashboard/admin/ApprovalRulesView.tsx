"use client";

import { useEffect, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { Check, Plus, ShieldAlert, Trash2 } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { money } from "@/lib/commercial";
import { firstError } from "@/lib/crm";
import { useAccess } from "@/lib/access-context";
import { exportCsv, exportXlsx, recordExport, type ExportColumn } from "@/lib/export-table";
import {
  Btn, Card, Chip, DataTable, ExportButtons, Field, INPUT, LoadError, Notice, PageHead, Screen, deniedText, useToasts, type Column,
} from "@/components/ui/proto";
import { PickSelect } from "@/components/ui/pick-select";

type Level = { name: string; role_ids: string[] };
type Rule = { name: string; metric: "total" | "discount"; value: number | string; level: number };
type Pending = {
  id: string; number: string; customer: string; total: number; currency: string; reasons: string[];
  required_level: number; next_level: { level: number; name: string; roles: string[] } | null;
};
type RulesData = {
  levels: Record<"1" | "2", Level>;
  rules: Rule[];
  metrics: Record<string, string>;
  roles: { id: string; name: string }[];
  pending: Pending[];
};

const EXPORT_COLUMNS: ExportColumn<Pending>[] = [
  { label: "Cotización", value: (p) => p.number, width: 18 },
  { label: "Cliente", value: (p) => p.customer, width: 36 },
  { label: "Total", value: (p) => money(p.total, p.currency), width: 16, kind: "money" },
  { label: "Nivel exigido", value: (p) => p.required_level, width: 12, kind: "center" },
  { label: "Le toca", value: (p) => (p.next_level ? `Nivel ${p.next_level.level} · ${p.next_level.name}` : ""), width: 28 },
  { label: "Motivos", value: (p) => p.reasons.join("; "), width: 50 },
];

export default function ApprovalRulesView() {
  const router = useRouter();
  const { can, loading: accessLoading } = useAccess();
  const canManage = !accessLoading && can("approvals.manage");
  const { toast, toasts } = useToasts();
  const [data, setData] = useState<RulesData | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [levels, setLevels] = useState<Record<"1" | "2", Level>>({ 1: { name: "", role_ids: [] }, 2: { name: "", role_ids: [] } });
  const [rules, setRules] = useState<Rule[]>([]);
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState("");
  const [sim, setSim] = useState({ total: "45000", discount: "12" });
  const [exporting, setExporting] = useState(false);

  useEffect(() => {
    let cancelled = false;
    apiFetch<RulesData>("/v1/approval-rules")
      .then((response) => {
        if (cancelled) return;
        setData(response);
        setLevels(response.levels);
        setRules(response.rules);
        setError("");
      })
      .catch(() => { if (!cancelled) setError("No se pudieron cargar las reglas de aprobación."); });
    return () => { cancelled = true; };
  }, [reload]);

  // Simulador con las reglas tal como están en pantalla (aún sin guardar).
  const simulation = useMemo(() => {
    const total = Number(sim.total) || 0;
    const discount = Number(sim.discount) || 0;
    const hits = rules.filter((r) => r.name.trim() && (r.metric === "discount" ? discount : total) > Number(r.value));
    return { level: Math.max(0, ...hits.map((r) => Number(r.level))), reasons: hits.map((r) => `${r.name} (${r.metric === "discount" ? `${discount} %` : money(total)})`) };
  }, [rules, sim]);

  async function save() {
    setSaving(true);
    setSaveError("");
    try {
      // Para quitar una regla, se deja su nombre vacío.
      const kept = rules.filter((r) => r.name.trim()).map((r) => ({ ...r, value: Number(r.value), level: Number(r.level) }));
      const response = await apiFetch<RulesData>("/v1/approval-rules", { method: "PUT", body: JSON.stringify({ levels, rules: kept }) });
      setData(response);
      setLevels(response.levels);
      setRules(response.rules);
      toast("Reglas guardadas");
    } catch (err) {
      setSaveError(firstError(err, "No se pudieron guardar las reglas."));
    } finally {
      setSaving(false);
    }
  }

  async function runExport(format: "xlsx" | "csv") {
    if (!data) return;
    setExporting(true);
    try {
      await recordExport("quotations", format, data.pending.length);
      if (format === "xlsx") await exportXlsx("cotizaciones-por-autorizar", "Cotizaciones esperando autorización", EXPORT_COLUMNS, data.pending);
      else exportCsv("cotizaciones-por-autorizar", EXPORT_COLUMNS, data.pending);
    } catch {
      toast("No se pudo exportar.", "danger");
    } finally {
      setExporting(false);
    }
  }

  const levelName = (level: number) => levels[String(level) as "1" | "2"]?.name || `Nivel ${level}`;
  const roleNames = (ids: string[]) => data?.roles.filter((r) => ids.includes(r.id)).map((r) => r.name).join(" o ") || "nadie";

  const pendingCols: Column<Pending>[] = [
    { label: "Cotización", render: (p) => <span className="whitespace-nowrap font-mono font-semibold text-[color:var(--p-brand-text)]">{p.number}</span> },
    { label: "Cliente", render: (p) => p.customer || "—" },
    { label: "Total", render: (p) => <span className="whitespace-nowrap tabular-nums">{money(p.total, p.currency)}</span> },
    {
      label: "Estado",
      render: (p) => (
        <span className="inline-flex flex-col items-start gap-0.5">
          <Chip tone="info">{p.next_level ? `Le toca nivel ${p.next_level.level}` : "Pendiente"}</Chip>
          <small className="app-muted text-[12px]">{p.reasons.join("; ")}</small>
        </span>
      ),
    },
  ];

  return (
    <Screen>
      <PageHead title="Autorización interna por niveles" />

      {error ? (
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
      ) : !data ? (
        <Card><p className="app-muted">Cargando reglas…</p></Card>
      ) : (
        <div className="grid gap-x-[18px] lg:grid-cols-2">
          <div className="min-w-0">
            <Card title="Reglas vigentes">
              <div className="grid gap-3">
                {rules.length === 0 && <p className="app-muted text-[13px]">Sin reglas: ninguna cotización necesita autorización interna.</p>}
                {rules.map((rule, index) => (
                  <div key={index} className="grid gap-2 rounded-xl border border-[color:var(--app-border)] p-3 sm:grid-cols-[1fr_auto]">
                    <div className="grid gap-2 sm:grid-cols-2">
                      <Field label={`Regla ${index + 1}`} span={2}>
                        <input className={INPUT} value={rule.name} disabled={!canManage} maxLength={80} placeholder="Ej.: Monto alto"
                          onChange={(e) => setRules((list) => list.map((r, i) => (i === index ? { ...r, name: e.target.value } : r)))} />
                      </Field>
                      <Field label="Criterio">
                        <PickSelect value={rule.metric} disabled={!canManage} ariaLabel="Criterio" className="h-10 w-full"
                          options={Object.entries(data.metrics)}
                          onChange={(value) => setRules((list) => list.map((r, i) => (i === index ? { ...r, metric: value as Rule["metric"] } : r)))} />
                      </Field>
                      <Field label="Mayor a">
                        <input className={INPUT} type="number" min={0} step="0.01" value={rule.value} disabled={!canManage}
                          onChange={(e) => setRules((list) => list.map((r, i) => (i === index ? { ...r, value: e.target.value } : r)))} />
                      </Field>
                      <Field label="Nivel" span={2}>
                        <PickSelect value={String(rule.level)} disabled={!canManage} ariaLabel="Nivel" className="h-10 w-full"
                          options={[["1", `1 · ${levelName(1)}`], ["2", `2 · ${levelName(2)}`]]}
                          onChange={(value) => setRules((list) => list.map((r, i) => (i === index ? { ...r, level: Number(value) } : r)))} />
                      </Field>
                    </div>
                    <div className="flex items-start justify-end">
                      <Btn variant="danger-ghost" size="sm" permission="approvals.manage" onClick={() => setRules((list) => list.filter((_, i) => i !== index))}>
                        <Trash2 size={14} />
                        <span className="sm:sr-only">Quitar</span>
                      </Btn>
                    </div>
                  </div>
                ))}
                <div>
                  <Btn variant="secondary" size="sm" permission="approvals.manage" disabled={rules.length >= 20}
                    onClick={() => setRules((list) => [...list, { name: "", metric: "total", value: "", level: 1 }])}>
                    <Plus size={14} />
                    <span>Agregar regla</span>
                  </Btn>
                </div>
                <p className="app-muted text-[12px]">Las cotizaciones en dólares se evalúan convertidas a soles con su tipo de cambio. El descuento es el mayor de sus líneas.</p>
              </div>
            </Card>

            <Card title="Quién autoriza cada nivel">
              <div className="grid gap-4">
                {(["1", "2"] as const).map((key) => (
                  <div key={key} className="grid gap-2">
                    <Field label={`Nivel ${key}`}>
                      <input className={INPUT} value={levels[key].name} disabled={!canManage} maxLength={60}
                        onChange={(e) => setLevels((l) => ({ ...l, [key]: { ...l[key], name: e.target.value } }))} />
                    </Field>
                    <div className="flex flex-wrap gap-1.5">
                      {data.roles.map((role) => {
                        const on = levels[key].role_ids.includes(role.id);
                        return (
                          <button key={role.id} type="button" disabled={!canManage} aria-pressed={on}
                            onClick={() => setLevels((l) => ({ ...l, [key]: { ...l[key], role_ids: on ? l[key].role_ids.filter((id) => id !== role.id) : [...l[key].role_ids, role.id] } }))}
                            className={`inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-[12.5px] transition-colors disabled:cursor-default ${
                              on ? "border-[#1B8FC9] bg-[var(--p-brand-soft)] text-[color:var(--p-brand-text)]" : "border-[color:var(--app-border)] hover:bg-[var(--app-surface-muted)]"
                            }`}>
                            {on && <Check size={12} />}
                            {role.name}
                          </button>
                        );
                      })}
                    </div>
                  </div>
                ))}
                <p className="app-muted text-[12px]">Quien creó la cotización nunca la autoriza, y una misma persona no autoriza dos niveles.</p>
                {!canManage && <p className="app-muted text-[12px]">{deniedText("approvals.manage")}</p>}
                {saveError && <p className="text-[12.5px] font-medium text-[color:var(--p-danger-text)]">{saveError}</p>}
                <div className="flex justify-end">
                  <Btn permission="approvals.manage" onClick={save} disabled={saving}>{saving ? "Guardando…" : "Guardar reglas"}</Btn>
                </div>
              </div>
            </Card>
          </div>

          <div className="min-w-0">
            <Card title="Simulador">
              <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Monto total (S/)">
                  <input className={INPUT} type="number" min={0} value={sim.total} onChange={(e) => setSim((s) => ({ ...s, total: e.target.value }))} />
                </Field>
                <Field label="Descuento máximo (%)">
                  <input className={INPUT} type="number" min={0} max={100} value={sim.discount} onChange={(e) => setSim((s) => ({ ...s, discount: e.target.value }))} />
                </Field>
              </div>
              <div className="mt-3">
                <Notice tone={simulation.level ? "warn" : "info"}>
                  <p className="flex items-center gap-1.5 font-semibold">
                    {simulation.level ? <ShieldAlert size={15} /> : <Check size={15} />}
                    {simulation.level ? `Requiere autorización hasta el nivel ${simulation.level}` : "No requiere autorización interna"}
                  </p>
                  {simulation.reasons.length > 0 && <p className="text-[12.5px]">{simulation.reasons.join(" · ")}</p>}
                  {simulation.level > 0 && (
                    <p className="text-[12.5px]">
                      Autorizan: {[1, 2].filter((l) => l <= simulation.level).map((l) => `nivel ${l} — ${roleNames(levels[String(l) as "1" | "2"].role_ids)}`).join("; ")}
                    </p>
                  )}
                </Notice>
              </div>
            </Card>

            <Card title="Cotizaciones esperando autorización" flush>
              <DataTable
                tableId="approval-pending"
                cols={pendingCols}
                rows={data.pending}
                rowKey={(p) => p.id}
                onRowClick={(p) => router.push(`/quotations/${p.id}`)}
                empty="Ninguna pendiente."
              />
              <div className="px-5 py-3">
                <ExportButtons permission="quotations.export" total={data.pending.length} busy={exporting} onExport={runExport} />
              </div>
            </Card>
          </div>
        </div>
      )}
      {toasts}
    </Screen>
  );
}
