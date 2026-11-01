"use client";

import { useEffect, useState } from "react";
import { Lock, Mail, MessageSquare, Power } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { money, shortDate } from "@/lib/commercial";
import { dateTime, firstError } from "@/lib/crm";
import { RENEWAL_WARNING_DAYS, daysUntil, expiryText } from "@/lib/subscription";
import { exportCsv, exportXlsx, recordExport, type ExportColumn } from "@/lib/export-table";
import {
  Btn, Card, Chip, ExportButtons, Field, INPUT, Kv, LoadError, Modal, Notice, PageHead, Screen, TD, TH, Timeline, useToasts,
} from "@/components/ui/proto";

type Module = {
  code: string;
  name: string;
  description: string | null;
  category: string;
  in_plan: boolean;
  enabled: boolean;
  disabled_by: "tenant" | "platform" | null;
  since: string | null;
  data: string | null;
};

type PlanData = {
  tenant: string;
  plan: {
    name: string; description: string | null; status: string; is_trial: boolean; starts_at: string | null; ends_at: string | null;
    monthly_price: number | null; annual_price: number | null; currency: string; support_level: string | null;
  } | null;
  core: string[];
  modules: Module[];
  usage: { key: string; label: string; used: number; limit: number; enforced: boolean }[];
  payments: { id: string; amount: number; currency: string; method: string | null; reference: string | null; months: number; previous_ends_at: string | null; new_ends_at: string | null; at: string }[];
  history: { action: string; module: string | null; reason: string | null; detail: string | null; user: string | null; at: string }[];
  support: { whatsapp?: string; email?: string } | null;
};

const CATEGORIES: Record<string, string> = {
  COMERCIAL: "Comercial", OPERACIONES: "Operaciones", FINANZAS: "Finanzas", COMPRAS: "Compras", CONTABILIDAD: "Contabilidad",
  SOPORTE: "Soporte", CRM: "CRM", PORTAL: "Portal de clientes", CONTROL: "Control",
};

const METHODS: Record<string, string> = { TRANSFER: "Transferencia", CASH: "Efectivo", CARD: "Tarjeta", YAPE: "Yape", PLIN: "Plin", DEPOSIT: "Depósito" };

const PAYMENT_EXPORT: ExportColumn<PlanData["payments"][number]>[] = [
  { label: "Fecha", value: (p) => dateTime(p.at), width: 22, kind: "center" },
  { label: "Monto", value: (p) => p.amount, width: 14, kind: "money", currency: (p) => p.currency },
  { label: "Meses", value: (p) => p.months, width: 10, kind: "number" },
  { label: "Medio", value: (p) => (p.method ? METHODS[p.method] ?? p.method : ""), width: 16 },
  { label: "Referencia", value: (p) => p.reference ?? "", width: 22 },
  { label: "Vigencia anterior", value: (p) => shortDate(p.previous_ends_at), width: 16, kind: "center" },
  { label: "Nueva vigencia", value: (p) => shortDate(p.new_ends_at), width: 16, kind: "center" },
];

/** Uso frente al límite: ámbar desde el 60 %, rojo desde el 85 % (como el prototipo). */
function UsageBar({ used, limit }: { used: number; limit: number }) {
  const pct = Math.min(100, Math.round((used / Math.max(limit, 1)) * 100));
  const tone = pct >= 85 ? "bg-[#E5484D]" : pct >= 60 ? "bg-[#E8A33A]" : "bg-[var(--p-chart-1)]";
  return (
    <span className="block h-2 overflow-hidden rounded-full bg-[var(--app-surface-muted)]" role="progressbar" aria-valuenow={pct} aria-valuemin={0} aria-valuemax={100}>
      <span className={`block h-full rounded-full ${tone}`} style={{ width: `${pct}%` }} />
    </span>
  );
}

/** Aviso bajo la barra: al llegar al límite, o cuando queda poco (85 % o más), con el pedido para ampliarlo. */
function LimitNote({ label, used, limit, onRequest }: { label: string; used: number; limit: number; onRequest: (text: string) => void }) {
  if (used < limit * 0.85) return null;
  const reached = used >= limit;
  const left = limit - used;
  return (
    // Desde el 85 % la barra ya está en rojo: el aviso va del mismo color.
    <p className="mt-1 flex flex-wrap items-center gap-x-2 text-xs text-[color:var(--p-danger-text)]">
      <span className="font-medium">{reached ? "Llegaste al límite de tu plan" : `Te ${left === 1 ? "queda 1" : `quedan ${left.toLocaleString("es-PE")}`}`}</span>
      <button type="button" onClick={() => onRequest(`Quisiera ampliar el límite de ${label.toLowerCase()} de mi plan (hoy ${used.toLocaleString("es-PE")} de ${limit.toLocaleString("es-PE")}).`)} className="cursor-pointer font-semibold text-[color:var(--p-brand-text)] underline-offset-2 hover:underline">
        Solicitar más
      </button>
    </p>
  );
}

/** Pedido de cambio por WhatsApp o correo de soporte, con el mensaje ya escrito; queda registrado. */
function RequestModal({ data, subject, onClose, toast }: { data: PlanData; subject: string; onClose: () => void; toast: (text: string, tone?: "success" | "danger") => void }) {
  const [detail, setDetail] = useState(subject);
  const whatsappRaw = data.support?.whatsapp ?? "";
  const whatsapp = whatsappRaw.replace(/[^0-9]/g, "");
  const email = data.support?.email ?? "";
  const message = `Hola, soy de la empresa ${data.tenant} (${data.plan?.name ?? "sin plan"}). ${detail.trim()}`;

  async function record() {
    try {
      await apiFetch("/v1/tenant/plan/request", { method: "POST", body: JSON.stringify({ detail: detail.trim() }) });
    } catch (err) {
      toast(firstError(err, "No se pudo registrar el pedido."), "danger");
    }
  }

  return (
    <Modal title="Solicitar cambio de plan" onClose={onClose} footer={<Btn variant="ghost" onClick={onClose}>Cerrar</Btn>}>
      <div className="grid gap-3">
        <Field label="¿Qué necesitas?" hint="Va en el mensaje a soporte.">
          <textarea className={INPUT} rows={3} maxLength={300} value={detail} onChange={(e) => setDetail(e.target.value)} />
        </Field>
        {!whatsapp && !email && <Notice tone="warn">La plataforma todavía no publicó sus canales de soporte. Vuelve a intentarlo más tarde.</Notice>}
        <div className="grid gap-2 sm:grid-cols-2">
          {whatsapp && (
            <a
              href={`https://wa.me/${whatsapp}?text=${encodeURIComponent(message)}`}
              target="_blank" rel="noreferrer"
              onClick={() => { if (detail.trim()) record(); }}
              aria-disabled={!detail.trim()}
              className={`flex items-center gap-2.5 rounded-lg border border-[color:var(--app-border)] p-3 hover:border-[color:var(--p-brand)] ${detail.trim() ? "" : "pointer-events-none opacity-50"}`}
            >
              <MessageSquare size={18} className="text-[color:var(--p-success-text)]" />
              <span><strong className="block text-[13px]">WhatsApp</strong><small className="app-muted">{whatsappRaw}</small></span>
            </a>
          )}
          {email && (
            <a
              href={`mailto:${email}?subject=${encodeURIComponent(`Cambio de plan - ${data.tenant}`)}&body=${encodeURIComponent(message)}`}
              onClick={() => { if (detail.trim()) record(); }}
              aria-disabled={!detail.trim()}
              className={`flex items-center gap-2.5 rounded-lg border border-[color:var(--app-border)] p-3 hover:border-[color:var(--p-brand)] ${detail.trim() ? "" : "pointer-events-none opacity-50"}`}
            >
              <Mail size={18} className="text-[color:var(--p-brand)]" />
              <span><strong className="block text-[13px]">Correo</strong><small className="app-muted">{email}</small></span>
            </a>
          )}
        </div>
      </div>
    </Modal>
  );
}

/** Confirmación para apagar (pide motivo) o encender un módulo. */
function ToggleModal({ module, onClose, onDone, toast }: { module: Module; onClose: () => void; onDone: (data: PlanData) => void; toast: (text: string, tone?: "success" | "danger") => void }) {
  const turningOff = module.enabled;
  const [reason, setReason] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  async function confirm() {
    if (turningOff && !reason.trim()) {
      setError("Indica por qué se apaga el módulo.");
      return;
    }
    setBusy(true);
    try {
      const data = await apiFetch<PlanData>(`/v1/tenant/modules/${module.code}`, { method: "PUT", body: JSON.stringify({ enabled: !turningOff, reason: reason.trim() || null }) });
      toast(turningOff ? `${module.name} apagado` : `${module.name} encendido`);
      onDone(data);
    } catch (err) {
      setError(firstError(err, "No se pudo cambiar el módulo."));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal
      title={turningOff ? `Apagar ${module.name}` : `Encender ${module.name}`}
      size="sm"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Volver</Btn>
          <Btn variant={turningOff ? "danger" : "primary"} onClick={confirm} disabled={busy}>{turningOff ? "Apagar módulo" : "Encender módulo"}</Btn>
        </>
      }
    >
      <p className="mb-3 text-[13px]">
        {turningOff
          ? "Quedará con candado («Apagado») en el menú y nadie podrá usarlo. No se borra nada: al encenderlo otra vez, todo sigue ahí."
          : "Se podrá usar otra vez, para quienes tengan permiso, con todo lo que tenía guardado."}
      </p>
      {turningOff && module.data && <p className="app-muted mb-3 text-xs">Guardado: {module.data}.</p>}
      {turningOff && (
        <Field label="Motivo" required error={error}>
          <textarea className={INPUT} rows={2} maxLength={300} value={reason} onChange={(e) => setReason(e.target.value)} autoFocus placeholder="Queda registrado en el historial" />
        </Field>
      )}
      {!turningOff && error && <p className="text-xs text-[color:var(--p-danger-text)]">{error}</p>}
    </Modal>
  );
}

export default function PlanModules() {
  const { toast, toasts } = useToasts();
  const [data, setData] = useState<PlanData | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [toggling, setToggling] = useState<Module | null>(null);
  const [requesting, setRequesting] = useState<string | null>(null);
  const [exporting, setExporting] = useState(false);

  useEffect(() => {
    let cancelled = false;
    apiFetch<PlanData>("/v1/tenant/plan")
      .then((response) => { if (!cancelled) { setData(response); setError(""); } })
      .catch(() => { if (!cancelled) setError("No se pudo cargar el plan."); });
    return () => { cancelled = true; };
  }, [reload]);

  async function exportPayments(format: "xlsx" | "csv") {
    if (!data) return;
    setExporting(true);
    try {
      await recordExport("users", format, data.payments.length, true);
      if (format === "xlsx") await exportXlsx("pagos-suscripcion", "Pagos de la suscripción", PAYMENT_EXPORT, data.payments);
      else exportCsv("pagos-suscripcion", PAYMENT_EXPORT, data.payments);
    } catch {
      toast("No se pudo exportar.", "danger");
    } finally {
      setExporting(false);
    }
  }

  if (error) return <Screen><PageHead title="Módulos y plan" /><Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card></Screen>;
  if (!data) return <Screen><PageHead title="Módulos y plan" /><Card><p className="app-muted">Cargando…</p></Card></Screen>;

  const plan = data.plan;
  const days = plan?.ends_at ? daysUntil(plan.ends_at) : null;
  const groups = Object.entries(
    data.modules.reduce<Record<string, Module[]>>((acc, module) => ({ ...acc, [module.category]: [...(acc[module.category] ?? []), module] }), {}),
  );

  return (
    <Screen>
      <PageHead
        title="Módulos y plan"
        actions={
          <Btn variant="secondary" onClick={() => setRequesting(plan ? "Quisiera cambiar de plan." : "Quisiera contratar un plan.")}>
            Solicitar cambio de plan
          </Btn>
        }
      />

      <div className="grid gap-[18px] lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
        <Card title="Mi plan">
          {plan ? (
            <>
              <div className="mb-3 flex flex-wrap items-center gap-2">
                <strong className="text-[18px] font-semibold">{plan.name}</strong>
                <Chip tone={plan.is_trial ? "warning" : "success"}>{plan.is_trial ? "Prueba gratuita" : "Activo"}</Chip>
                {days !== null && days <= RENEWAL_WARNING_DAYS && <Chip tone={days < 0 ? "danger" : "warning"}>{expiryText(days)}</Chip>}
              </div>
              {plan.description && <p className="app-muted mb-3 text-[13px]">{plan.description}</p>}
              <Kv
                rows={[
                  ["Vigencia", plan.ends_at ? `${shortDate(plan.starts_at)} – ${shortDate(plan.ends_at)}` : "Sin fecha de fin"],
                  plan.monthly_price !== null ? ["Precio mensual", money(plan.monthly_price, plan.currency)] : null,
                  plan.annual_price !== null ? ["Precio anual", money(plan.annual_price, plan.currency)] : null,
                  plan.support_level ? ["Soporte", plan.support_level === "STANDARD" ? "Estándar" : plan.support_level] : null,
                  ["Siempre incluido", data.core.join(", ")],
                ]}
              />
            </>
          ) : (
            <Notice tone="warn">Tu empresa no tiene un plan asignado: sus módulos los configuró la plataforma. Para contratar uno, solicítalo.</Notice>
          )}
        </Card>

        <Card title="Uso del plan">
          {data.usage.length ? (
            <div className="grid gap-3">
              {data.usage.map((item) => (
                <div key={item.key}>
                  <div className="mb-1 flex items-center justify-between gap-2 text-[13px]">
                    <span>{item.label}</span>
                    <span className="font-mono text-[12.5px] tabular-nums">{item.used.toLocaleString("es-PE")} / {item.limit.toLocaleString("es-PE")}</span>
                  </div>
                  <UsageBar used={item.used} limit={item.limit} />
                  <LimitNote label={item.label} used={item.used} limit={item.limit} onRequest={(text) => setRequesting(text)} />
                </div>
              ))}
            </div>
          ) : (
            <p className="app-muted text-[13px]">Tu plan no tiene límites de uso.</p>
          )}
        </Card>
      </div>

      <Card title="Módulos">
        <div className="grid gap-5">
          {groups.map(([category, modules]) => (
            <div key={category}>
              <p className="mb-2 text-[11px] font-semibold uppercase tracking-[.05em] text-[color:var(--p-brand-text)]">{CATEGORIES[category] ?? category}</p>
              <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                {modules.map((module) => (
                  <section key={module.code} className={`rounded-xl border p-3.5 ${module.enabled ? "border-[color:var(--p-accent,#1B8FC9)]" : "border-[color:var(--app-border)]"}`}>
                    <div className="flex items-start justify-between gap-2">
                      <div className="min-w-0">
                        <strong className="font-semibold">{module.name}</strong>
                        {module.description && <p className="app-muted mt-0.5 text-xs">{module.description}</p>}
                      </div>
                      {module.in_plan ? (
                        <Btn
                          size="sm"
                          variant={module.enabled ? "ghost" : "secondary"}
                          permission="tenant.manage"
                          disabled={!module.enabled && module.disabled_by === "platform" && !plan}
                          title={!module.enabled && module.disabled_by === "platform" && !plan ? "Lo apagó la plataforma: solicítalo" : undefined}
                          onClick={() => setToggling(module)}
                        >
                          <Power size={13} />
                          <span>{module.enabled ? "Apagar" : "Encender"}</span>
                        </Btn>
                      ) : (
                        <Btn size="sm" variant="ghost" onClick={() => setRequesting(`Quisiera activar el módulo ${module.name}.`)}>
                          <Lock size={13} />
                          <span>Solicitar</span>
                        </Btn>
                      )}
                    </div>
                    <div className="mt-2.5 flex flex-wrap items-center gap-1.5 text-xs">
                      {module.enabled ? <Chip tone="success">Activo</Chip> : module.in_plan ? <Chip tone="muted">{module.disabled_by === "tenant" ? "Apagado por la empresa" : "Apagado"}</Chip> : <Chip tone="warning">No incluido en tu plan</Chip>}
                      {module.data && <span className="app-muted">{module.data}</span>}
                    </div>
                  </section>
                ))}
              </div>
            </div>
          ))}
        </div>
        <p className="app-muted mt-4 text-xs">Apagar un módulo no borra nada: queda con candado en el menú y nadie puede usarlo hasta encenderlo otra vez.</p>
      </Card>

      <div className="grid gap-[18px] lg:grid-cols-2">
        <Card title="Pagos de la suscripción" flush>
          {data.payments.length ? (
            <>
              <div className="overflow-x-auto">
                <table className="w-full border-collapse text-[13px]">
                  <thead><tr><th className={TH}>Fecha</th><th className={`${TH} text-right`}>Monto</th><th className={`${TH} text-right`}>Meses</th><th className={TH}>Nueva vigencia</th></tr></thead>
                  <tbody>
                    {data.payments.map((payment) => (
                      <tr key={payment.id}>
                        <td className={`${TD} whitespace-nowrap`}>{dateTime(payment.at)}{payment.method && <small className="app-muted block text-xs">{METHODS[payment.method] ?? payment.method}{payment.reference ? ` · ${payment.reference}` : ""}</small>}</td>
                        <td className={`${TD} text-right tabular-nums`}>{money(payment.amount, payment.currency)}</td>
                        <td className={`${TD} text-right tabular-nums`}>{payment.months}</td>
                        <td className={`${TD} whitespace-nowrap`}>{shortDate(payment.new_ends_at)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <div className="px-5 py-3"><ExportButtons permission="users.export" total={data.payments.length} busy={exporting} onExport={exportPayments} /></div>
            </>
          ) : (
            <p className="app-muted px-5 pb-5 pt-4 text-[13px]">Todavía no hay pagos registrados.</p>
          )}
        </Card>

        <Card title="Historial de módulos y pedidos">
          <Timeline
            empty="Sin cambios hechos por la empresa."
            items={data.history.map((item, index) => ({
              key: `${item.at}-${index}`,
              head: <strong>{item.action === "module.enable" ? `${item.module} encendido` : item.action === "module.disable" ? `${item.module} apagado` : "Cambio de plan solicitado"}</strong>,
              meta: `${item.user ?? "—"} · ${dateTime(item.at)}`,
              note: item.reason ?? item.detail,
            }))}
          />
        </Card>
      </div>

      {toggling && (
        <ToggleModal
          module={toggling}
          toast={toast}
          onClose={() => setToggling(null)}
          onDone={(next) => {
            setData(next);
            setToggling(null);
            // El menú y los permisos de la sesión se cargan al entrar: se recarga para reflejar el cambio.
            window.setTimeout(() => window.location.reload(), 900);
          }}
        />
      )}
      {requesting !== null && <RequestModal data={data} subject={requesting} toast={toast} onClose={() => { setRequesting(null); setReload((n) => n + 1); }} />}
      {toasts}
    </Screen>
  );
}
