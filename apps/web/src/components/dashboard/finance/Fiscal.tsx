"use client";

import { useEffect, useState, type FormEvent } from "react";
import Link from "next/link";
import { CircleCheck, Clock, Settings, ShieldCheck, TriangleAlert } from "lucide-react";
import { apiFetch, apiFetchLater } from "@/lib/api";
import { money, stateOf } from "@/lib/commercial";
import { dateTime, fieldErrors, firstError } from "@/lib/crm";
import { FISCAL_STATES, VOID_STATES } from "@/lib/finance";
import { useAccess } from "@/lib/access-context";
import {
  Badge, Btn, Card, Chip, Field, FormGrid, INPUT, Kpi, Kpis, Kv, LoadError, Modal, Notice, PageHead, Screen, TD, TH, Tabs, useToasts,
} from "@/components/ui/proto";
import { PickSelect } from "@/components/ui/pick-select";
import { ReasonModal } from "@/components/dashboard/crm/CrmModals";
import type { FiscalContingency, FiscalSettings, FiscalSummary, FiscalVoid } from "@/types/finance";

type Tab = "CFG" | "CONT" | "VOID";

const LINK = "text-[color:var(--p-brand-text)] hover:underline";
const TABLE = "proto-tbl w-full border-collapse text-[13px]";
const SKELETON = <div className="h-40 animate-pulse rounded bg-[var(--app-surface-muted)]" aria-busy="true" />;

/** Enlace al comprobante si la persona puede verlo; si no, solo su número. */
function InvoiceLink({ id, number }: { id: string; number: string | null }) {
  const { can } = useAccess();
  return can("billing.view")
    ? <Link href={`/invoices/${id}`} className={`${LINK} whitespace-nowrap tabular-nums`}>{number ?? "—"}</Link>
    : <span className="whitespace-nowrap tabular-nums">{number ?? "—"}</span>;
}

/** "Configuración fiscal": proveedor, RUC emisor, ambiente y detracciones. */
function SettingsModal({ settings, onClose, onSaved }: { settings: FiscalSettings; onClose: () => void; onSaved: (next: FiscalSettings) => void }) {
  const [provider, setProvider] = useState(settings.provider.code ?? "");
  const [taxId, setTaxId] = useState(settings.tax_identifier ?? "");
  const [environment, setEnvironment] = useState<string>(settings.environment);
  const [account, setAccount] = useState(settings.detraction.account ?? "");
  const [code, setCode] = useState(settings.detraction.code);
  const [enabled, setEnabled] = useState(settings.detraction.enabled);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setSaving(true);
    setError("");
    setErrors({});
    try {
      onSaved(await apiFetch<FiscalSettings>("/v1/fiscal/settings", {
        method: "PUT",
        body: JSON.stringify({
          provider_code: provider || null, tax_identifier: taxId.trim() || null, environment,
          detraction_enabled: enabled, detraction_code: code, detraction_account: account.trim() || null,
        }),
      }));
    } catch (err) {
      const fields = fieldErrors(err);
      setErrors(fields);
      if (!Object.keys(fields).length) setError(firstError(err, "No se pudo guardar la configuración."));
      setSaving(false);
    }
  }

  return (
    <Modal
      title="Configuración fiscal"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn type="submit" form="fiscal-settings-form" disabled={saving} permission="fiscal.configure">{saving ? "Guardando…" : "Guardar"}</Btn>
        </>
      }
    >
      <form id="fiscal-settings-form" onSubmit={submit}>
        {error && <Notice tone="warn">{error}</Notice>}
        <FormGrid>
          <Field
            label="Proveedor OSE/PSE" span={2} error={errors.provider_code}
            hint={settings.provider.available.length ? undefined : "Aún no hay ningún proveedor disponible en el sistema: los comprobantes quedan sin enviar a SUNAT."}
          >
            <PickSelect
              className={`${INPUT} disabled:opacity-60`} ariaLabel="Proveedor OSE/PSE" disabled={!settings.provider.available.length} value={provider} onChange={setProvider}
              options={[["", "Sin proveedor"], ...settings.provider.available.map((item): [string, string] => [item.code, item.name])]}
            />
          </Field>
          <Field label="Ambiente" error={errors.environment}>
            <PickSelect className={INPUT} ariaLabel="Ambiente" value={environment} onChange={setEnvironment} options={[["BETA", "Pruebas"], ["PRODUCTION", "Producción"]]} />
          </Field>
          <Field label="RUC del emisor" error={errors.tax_identifier} hint={settings.legal_name ?? undefined}>
            <input className={INPUT} value={taxId} onChange={(e) => setTaxId(e.target.value)} maxLength={11} inputMode="numeric" placeholder="20000000000" />
          </Field>
          <Field label="Cuenta de detracciones (BN)" error={errors.detraction_account}>
            <input className={INPUT} value={account} onChange={(e) => setAccount(e.target.value)} maxLength={13} placeholder="00-000-000000" />
          </Field>
          <Field label="Código de detracción por defecto" span={3} error={errors.detraction_code}>
            <PickSelect
              className={INPUT} ariaLabel="Código de detracción por defecto" searchable value={code} onChange={setCode}
              options={settings.detraction.codes.map((item): [string, string] => [item.code, `${item.code} · ${item.name} (${item.rate} %)`])}
            />
          </Field>
        </FormGrid>
        <label className="relative mt-3.5 flex cursor-pointer items-start gap-2 text-[13px]">
          <input type="checkbox" className="mt-0.5 h-4 w-4 accent-[#1B8FC9]" checked={enabled} onChange={(e) => setEnabled(e.target.checked)} />
          <span>Aplicar detracción en ventas</span>
        </label>
        <p className="app-muted mt-2 text-xs">Las credenciales del proveedor no se guardan aquí. Valida los códigos y porcentajes vigentes con tu área contable.</p>
      </form>
    </Modal>
  );
}

/** "Editar series": con qué serie sale cada comprobante y, si se viene de otro sistema, desde qué número continúa. */
function SeriesModal({ settings, onClose, onSaved }: { settings: FiscalSettings; onClose: () => void; onSaved: (next: FiscalSettings) => void }) {
  const [series, setSeries] = useState<Record<string, string>>(() => Object.fromEntries(settings.series.map((row) => [row.key, row.series])));
  const [contingency, setContingency] = useState<Record<string, string>>(() => Object.fromEntries(settings.series.flatMap((row) => (row.contingency_series ? [[row.key, row.contingency_series]] : []))));
  const [next, setNext] = useState<Record<string, string>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setSaving(true);
    setError("");
    setErrors({});
    try {
      onSaved(await apiFetch<FiscalSettings>("/v1/fiscal/series", {
        method: "PUT",
        body: JSON.stringify({
          series: Object.fromEntries(Object.entries(series).map(([key, value]) => [key, value.trim().toUpperCase()])),
          contingency_series: Object.fromEntries(Object.entries(contingency).map(([key, value]) => [key, value.trim()])),
          next: Object.fromEntries(Object.entries(next).filter(([, value]) => value !== "").map(([key, value]) => [key, Number(value)])),
        }),
      }));
    } catch (err) {
      setErrors(fieldErrors(err));
      setError(firstError(err, "No se pudieron guardar las series."));
      setSaving(false);
    }
  }

  return (
    <Modal
      title="Editar series"
      size="lg"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn type="submit" form="fiscal-series-form" disabled={saving} permission="fiscal.configure">{saving ? "Guardando…" : "Guardar"}</Btn>
        </>
      }
    >
      <form id="fiscal-series-form" onSubmit={submit}>
        {error && <Notice tone="warn">{error}</Notice>}
        <div className="grid gap-3">
          {settings.series.map((row) => (
            <fieldset key={row.key} className="grid grid-cols-1 gap-x-3.5 gap-y-2 border-b border-[color:var(--app-border)] pb-3 last:border-b-0 md:grid-cols-3">
              <legend className="mb-1.5 text-[13px] font-semibold">{row.label}</legend>
              <Field label="Serie normal" required error={errors[`series.${row.key}`]}>
                <input className={`${INPUT} uppercase tabular-nums`} value={series[row.key] ?? ""} onChange={(e) => setSeries((current) => ({ ...current, [row.key]: e.target.value }))} maxLength={4} required />
              </Field>
              {row.contingency_series !== null ? (
                <Field label="Serie de contingencia" required error={errors[`contingency_series.${row.key}`]}>
                  <input className={`${INPUT} tabular-nums`} value={contingency[row.key] ?? ""} onChange={(e) => setContingency((current) => ({ ...current, [row.key]: e.target.value }))} maxLength={4} required />
                </Field>
              ) : <span className="hidden md:block" />}
              <Field label="Próximo correlativo" error={errors[`next.${row.key}`]} hint={`Último usado en ${row.series}: ${row.last_number}. Solo se adelanta.`}>
                <input
                  className={`${INPUT} tabular-nums`} type="number" min={row.last_number + 1} step="1" inputMode="numeric" placeholder={String(row.last_number + 1)}
                  value={next[row.key] ?? ""} onChange={(e) => setNext((current) => ({ ...current, [row.key]: e.target.value }))}
                />
              </Field>
            </fieldset>
          ))}
        </div>
        <p className="app-muted mt-2 text-xs">
          Facturas y sus notas empiezan con F; boletas y sus notas, con B. Las series de contingencia son numéricas (0001). Cada serie debe ser distinta. Cambiar una serie no altera los comprobantes ya emitidos.
        </p>
      </form>
    </Modal>
  );
}

export default function Fiscal() {
  const { toast, toasts } = useToasts();
  const [tab, setTab] = useState<Tab>("CFG");
  const [settings, setSettings] = useState<FiscalSettings | null>(null);
  const [summary, setSummary] = useState<FiscalSummary | null>(null);
  const [contingency, setContingency] = useState<FiscalContingency | null>(null);
  const [voids, setVoids] = useState<FiscalVoid[] | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [modal, setModal] = useState<"settings" | "series" | "start" | "end" | null>(null);
  const [busy, setBusy] = useState(false);

  // Lo principal de la pestaña abierta.
  useEffect(() => {
    let cancelled = false;
    const fail = () => { if (!cancelled) setError("No se pudo cargar la facturación electrónica."); };
    if (tab === "CFG") apiFetch<FiscalSettings>("/v1/fiscal/settings").then((next) => { if (!cancelled) { setSettings(next); setError(""); } }).catch(fail);
    if (tab === "CONT") apiFetch<FiscalContingency>("/v1/fiscal/contingency").then((next) => { if (!cancelled) { setContingency(next); setError(""); } }).catch(fail);
    if (tab === "VOID") apiFetch<FiscalVoid[]>("/v1/fiscal/voids").then((next) => { if (!cancelled) { setVoids(next); setError(""); } }).catch(fail);
    return () => { cancelled = true; };
  }, [tab, reload]);

  // Los indicadores, después.
  useEffect(() => {
    let cancelled = false;
    apiFetchLater<FiscalSummary>("/v1/fiscal/summary")
      .then((next) => { if (!cancelled) setSummary(next); })
      .catch(() => undefined);
    return () => { cancelled = true; };
  }, [reload]);

  async function switchMode(path: "start" | "end", body: unknown, done: string): Promise<boolean> {
    setBusy(true);
    try {
      setContingency(await apiFetch<FiscalContingency>(`/v1/fiscal/contingency/${path}`, { method: "POST", body: body ? JSON.stringify(body) : undefined }));
      toast(done);
      setReload((n) => n + 1);
      return true;
    } catch (err) {
      toast(firstError(err, "No se pudo cambiar el modo de emisión."), "danger");
      return false;
    } finally {
      setBusy(false);
    }
  }

  const detraction = settings?.detraction;
  const defaultCode = detraction?.codes.find((item) => item.code === detraction.code);
  const offline = contingency?.mode === "CONTINGENCY";

  return (
    <Screen>
      <PageHead title="Facturación electrónica (SUNAT)" />

      <Kpis>
        <Kpi label="Aceptados" value={summary?.accepted ?? "—"} icon={<CircleCheck size={22} />} tone="success" />
        <Kpi label="En envío" value={summary?.pending ?? "—"} icon={<Clock size={22} />} />
        <Kpi label="Rechazados" value={summary?.rejected ?? "—"} icon={<TriangleAlert size={22} />} tone="danger" />
        <Kpi label="En contingencia" value={summary?.contingency ?? "—"} icon={<ShieldCheck size={22} />} tone={summary && summary.contingency > 0 ? "warning" : "success"} />
      </Kpis>

      <Tabs
        tabs={[["CFG", "Configuración", null], ["CONT", "Contingencia", summary?.contingency ?? null], ["VOID", "Bajas", summary?.voids ?? null]]}
        value={tab}
        onChange={(value) => setTab(value as Tab)}
      />

      {error ? (
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
      ) : tab === "CFG" ? (
        !settings || !detraction ? <Card>{SKELETON}</Card> : (
          <>
            <div className="grid grid-cols-1 gap-x-4 lg:grid-cols-2">
              <Card title="Proveedor de emisión electrónica (OSE/PSE)">
                <Kv
                  rows={[
                    ["Proveedor", settings.provider.connected ? settings.provider.name : <span key="provider" className="app-muted">Sin proveedor conectado</span>],
                    ["Conexión", settings.provider.connected ? <Chip key="connection" tone="success">Conectado</Chip> : <Chip key="connection" tone="warning">Pendiente de configurar</Chip>],
                    ["RUC del emisor", settings.tax_identifier ? `${settings.tax_identifier}${settings.legal_name ? ` · ${settings.legal_name}` : ""}` : <span key="ruc" className="app-muted">Sin registrar</span>],
                    ["Ambiente", settings.environment === "PRODUCTION" ? "Producción" : "Pruebas"],
                    // Sin proveedor no hay formato ni certificado que mostrar: los define y los gestiona él.
                    ["Formato", <span key="format" className="app-muted">Lo define el proveedor</span>],
                    ["Certificado digital", <span key="certificate" className="app-muted">Lo gestiona el proveedor</span>],
                    ["Credenciales", "No se guardan en la configuración"],
                  ]}
                />
                {!settings.provider.connected && <Notice tone="warn">Sin proveedor, los comprobantes se emiten en el sistema y quedan <strong className="font-semibold">sin enviar a SUNAT</strong>.</Notice>}
                <Btn variant="secondary" onClick={() => setModal("settings")} permission="fiscal.configure">
                  <Settings size={16} />
                  <span>Editar configuración</span>
                </Btn>
              </Card>
              <Card title="Series">
                <div className="proto-tbl-wrap -mx-1 overflow-x-auto">
                  <table className={TABLE}>
                    <thead>
                      <tr>
                        <th className={TH}>Comprobante</th>
                        <th className={TH}>Serie normal</th>
                        <th className={TH}>Serie de contingencia</th>
                        <th className={`${TH} text-right`}>Último correlativo</th>
                      </tr>
                    </thead>
                    <tbody>
                      {settings.series.map((row) => (
                        <tr key={row.key}>
                          <td className={TD} data-label="Comprobante">{row.label}</td>
                          <td className={`${TD} tabular-nums`} data-label="Serie normal">{row.series}</td>
                          <td className={`${TD} tabular-nums`} data-label="Serie de contingencia">
                            {row.contingency_series ?? "—"}
                            {row.contingency_series && row.contingency_last_number ? <small className="app-muted block text-xs">último {row.contingency_last_number}</small> : null}
                          </td>
                          <td className={`${TD} text-right tabular-nums`} data-label="Último correlativo">{row.last_number}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
                <p className="app-muted mt-2 text-xs">
                  La numeración es correlativa y sin duplicados por serie. Las series de contingencia solo se usan cuando el proveedor o la conexión no están disponibles.
                </p>
                <div className="mt-2">
                  <Btn variant="secondary" onClick={() => setModal("series")} permission="fiscal.configure">Editar series</Btn>
                </div>
              </Card>
            </div>
            <div className="grid grid-cols-1 gap-x-4 lg:grid-cols-2">
              <Card title="Detracciones (SPOT)">
                <Kv
                  rows={[
                    ["Aplicar detracción en ventas", detraction.enabled ? <Chip key="enabled" tone="success">Sí</Chip> : <Chip key="enabled">No</Chip>],
                    ["Código por defecto", `${detraction.code} · ${defaultCode?.name ?? ""} (${defaultCode?.rate ?? 0} %)`],
                    ["Cuenta de detracciones (BN)", <span key="account" className="tabular-nums">{detraction.account ?? "No registrada"}</span>],
                    ["Umbral", `Comprobantes mayores a ${money(detraction.threshold)}`],
                    ["Exentos", "Se decide en cada factura antes de emitirla (“No aplica” u otro código)"],
                  ]}
                />
                <p className="app-muted text-xs">
                  Si el cliente es agente de retención y no corresponde detracción, se calcula la retención del {detraction.retention_rate} %. Valida las reglas y porcentajes vigentes con el área contable.
                </p>
              </Card>
              <Card title="Pendientes de clientes">
                <Kv
                  rows={[
                    ["Detracciones por recibir", `${settings.pending.detractions.count} · ${money(settings.pending.detractions.amount)}`],
                    ["Retenciones por recibir", `${settings.pending.retentions.count} · ${money(settings.pending.retentions.amount)}`],
                  ]}
                />
                <p className="text-[13px]">Registra la constancia de depósito o el certificado de retención desde el comprobante.</p>
                {settings.pending.invoices.length ? (
                  <div className="proto-tbl-wrap -mx-1 mt-2 overflow-x-auto">
                    <table className={TABLE}>
                      <thead>
                        <tr>
                          <th className={TH}>Comprobante</th>
                          <th className={TH}>Cliente</th>
                          <th className={TH}>Tipo</th>
                          <th className={`${TH} text-right`}>Monto</th>
                        </tr>
                      </thead>
                      <tbody>
                        {settings.pending.invoices.map((invoice) => (
                          <tr key={invoice.id}>
                            <td className={TD} data-label="Comprobante"><InvoiceLink id={invoice.id} number={invoice.invoice_number} /></td>
                            <td className={TD} data-label="Cliente">{invoice.customer_name}</td>
                            <td className={TD} data-label="Tipo">{invoice.label}</td>
                            <td className={`${TD} whitespace-nowrap text-right tabular-nums`} data-label="Monto">{money(invoice.amount)}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                ) : (
                  <p className="app-muted mt-2">Sin pendientes</p>
                )}
              </Card>
            </div>
          </>
        )
      ) : tab === "CONT" ? (
        !contingency ? <Card>{SKELETON}</Card> : (
          <>
            <div className="grid grid-cols-1 gap-x-4 lg:grid-cols-2">
              <Card title="Modo de emisión">
                <div className={`flex items-start gap-2.5 rounded-xl border p-3.5 ${offline ? "border-[color:var(--p-notice-line)] bg-[var(--p-notice-bg)] text-[color:var(--p-notice-text)]" : "border-[color:var(--app-border)]"}`}>
                  {offline ? <TriangleAlert size={20} className="mt-0.5 shrink-0" /> : <CircleCheck size={20} className="mt-0.5 shrink-0 text-[color:var(--p-success-text)]" />}
                  <div className="min-w-0">
                    <strong className="font-semibold">{offline ? "Emisión en contingencia" : "Emisión en línea"}</strong>
                    <p className="mt-0.5 text-[12.5px]">
                      {offline
                        ? "Los comprobantes se emiten con la serie de contingencia y se informan a SUNAT al restablecer el servicio."
                        : contingency.provider.connected
                          ? `Los comprobantes se envían a SUNAT por ${contingency.provider.name} en cuanto se emiten.`
                          : "No hay proveedor conectado: los comprobantes se emiten en el sistema y quedan sin enviar a SUNAT."}
                    </p>
                  </div>
                </div>
                <div className="mt-3">
                  {offline ? (
                    <Btn onClick={() => setModal("end")} permission="fiscal.configure" disabled={busy}>
                      {contingency.provider.connected ? `Restablecer y enviar resumen (${contingency.pending_count})` : `Restablecer (${contingency.pending_count} por informar)`}
                    </Btn>
                  ) : (
                    <Btn variant="danger-ghost" onClick={() => setModal("start")} permission="fiscal.configure" disabled={busy}>Activar contingencia</Btn>
                  )}
                </div>
              </Card>
              <Card title="Bitácora">
                {contingency.log.length ? (
                  <ul className="grid">
                    {contingency.log.map((entry, index) => {
                      const on = entry.action === "fiscal.contingency_start";
                      return (
                        <li key={`${entry.at}-${index}`} className="border-b border-[color:var(--app-border)] py-1.5 last:border-b-0">
                          <Chip tone={on ? "warning" : "success"}>{on ? "Contingencia" : "En línea"}</Chip> {entry.metadata.note ?? ""}
                          <small className="app-muted block text-xs">{entry.user ?? "Sistema"} · {dateTime(entry.at)}</small>
                        </li>
                      );
                    })}
                  </ul>
                ) : (
                  <p className="app-muted">Sin eventos de contingencia.</p>
                )}
              </Card>
            </div>
            <Card title="Emitidos en contingencia">
              {contingency.invoices.length ? (
                <>
                  <div className="proto-tbl-wrap -mx-1 overflow-x-auto">
                    <table className={TABLE}>
                      <thead>
                        <tr>
                          <th className={TH}>Comprobante</th>
                          <th className={TH}>Cliente</th>
                          <th className={TH}>Emisión</th>
                          <th className={`${TH} text-right`}>Total</th>
                          <th className={TH}>SUNAT</th>
                        </tr>
                      </thead>
                      <tbody>
                        {contingency.invoices.map((invoice) => (
                          <tr key={invoice.id}>
                            <td className={TD} data-label="Comprobante"><InvoiceLink id={invoice.id} number={invoice.invoice_number} /></td>
                            <td className={TD} data-label="Cliente">{invoice.customer_name}</td>
                            <td className={`${TD} whitespace-nowrap`} data-label="Emisión">{invoice.issued_at ? dateTime(invoice.issued_at) : "—"}</td>
                            <td className={`${TD} whitespace-nowrap text-right tabular-nums`} data-label="Total">{money(invoice.total, invoice.currency)}</td>
                            <td className={TD} data-label="SUNAT"><Badge tone={stateOf(FISCAL_STATES, invoice.fiscal_status)[1]}>{stateOf(FISCAL_STATES, invoice.fiscal_status)[0]}</Badge></td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                  {contingency.pending_count > contingency.invoices.length && <p className="app-muted mt-2 text-xs">Se muestran {contingency.invoices.length} de {contingency.pending_count}.</p>}
                </>
              ) : (
                <p className="app-muted">No hay comprobantes pendientes de informar.</p>
              )}
            </Card>
          </>
        )
      ) : !voids ? <Card>{SKELETON}</Card> : (
        <Card title="Comunicaciones de baja">
          {voids.length ? (
            <div className="proto-tbl-wrap -mx-1 overflow-x-auto">
              <table className={TABLE}>
                <thead>
                  <tr>
                    <th className={TH}>Comprobante</th>
                    <th className={TH}>Ticket</th>
                    <th className={TH}>Motivo</th>
                    <th className={TH}>Solicitada</th>
                    <th className={TH}>Estado</th>
                  </tr>
                </thead>
                <tbody>
                  {voids.map((item) => (
                    <tr key={item.id}>
                      <td className={TD} data-label="Comprobante"><InvoiceLink id={item.invoice_id} number={item.invoice_number} /></td>
                      <td className={`${TD} tabular-nums`} data-label="Ticket">{item.ticket ?? "—"}</td>
                      <td className={TD} data-label="Motivo">{item.reason}</td>
                      <td className={TD} data-label="Solicitada">
                        <span className="whitespace-nowrap">{dateTime(item.at)}</span>
                        {item.by && <small className="app-muted block text-xs">{item.by}</small>}
                      </td>
                      <td className={TD} data-label="Estado">
                        <Badge tone={stateOf(VOID_STATES, item.status)[1]}>{stateOf(VOID_STATES, item.status)[0]}</Badge>
                        {item.response && <small className="app-muted block text-xs">{item.response}</small>}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <p className="app-muted">Sin bajas. Se solicitan desde el comprobante (hasta 7 días desde la emisión y sin cobros), cuando SUNAT ya lo aceptó.</p>
          )}
        </Card>
      )}

      {modal === "settings" && settings && (
        <SettingsModal settings={settings} onClose={() => setModal(null)} onSaved={(next) => { setSettings(next); setModal(null); toast("Configuración fiscal guardada"); }} />
      )}
      {modal === "series" && settings && (
        <SeriesModal settings={settings} onClose={() => setModal(null)} onSaved={(next) => { setSettings(next); setModal(null); toast("Series guardadas"); }} />
      )}
      {modal === "start" && (
        <ReasonModal
          title="Activar emisión en contingencia"
          text="Úsalo solo si el proveedor o la conexión no están disponibles. Los comprobantes usarán la serie de contingencia."
          label="Motivo"
          confirmLabel="Activar"
          busy={busy}
          onClose={() => setModal(null)}
          onConfirm={async (reason) => {
            if (await switchMode("start", { reason }, "Contingencia activada")) setModal(null);
          }}
        />
      )}
      {modal === "end" && contingency && (
        <Modal
          title="Restablecer emisión en línea"
          size="sm"
          onClose={() => setModal(null)}
          footer={
            <>
              <Btn variant="ghost" onClick={() => setModal(null)}>Cancelar</Btn>
              <Btn
                disabled={busy} permission="fiscal.configure"
                onClick={async () => {
                  if (await switchMode("end", null, contingency.provider.connected ? "Servicio restablecido: comprobantes enviados" : "Servicio restablecido")) setModal(null);
                }}
              >
                Restablecer
              </Btn>
            </>
          }
        >
          <p>Los comprobantes vuelven a emitirse con su serie normal.</p>
          {contingency.provider.connected ? (
            <p className="app-muted mt-2">
              {contingency.pending_count === 1 ? "El comprobante emitido en contingencia se envía" : `Los ${contingency.pending_count} comprobantes emitidos en contingencia se envían`} a SUNAT por {contingency.provider.name}.
            </p>
          ) : (
            <Notice tone="warn">No hay proveedor conectado: lo emitido en contingencia <strong className="font-semibold">sigue pendiente de informar</strong> a SUNAT.</Notice>
          )}
        </Modal>
      )}
      {toasts}
    </Screen>
  );
}
