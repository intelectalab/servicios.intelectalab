"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { ArrowRight } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { getSessionKey } from "@/lib/auth";
import { announceTenantName } from "@/lib/tenant-name";
import { firstError } from "@/lib/crm";
import { Btn, Card, Field, INPUT, LoadError } from "@/components/ui/proto";

type Identity = { trade_name: string; display_name: string; legal_name: string | null; ruc: string | null; timezone: string; currency: string };

const TIMEZONES: Record<string, string> = { "America/Lima": "America/Lima (Perú, UTC−5)" };
const CURRENCIES: Record<string, string> = { PEN: "Soles (PEN)", USD: "Dólares (USD)" };

// Lo último cargado, de esta misma sesión: al volver a la pestaña General se muestra al instante
// (sin parpadeo de carga) y se actualiza por detrás. Otra sesión en el navegador no lo ve.
let cached: { token: string | null; identity: Identity; validity: string } | null = null;
const fromCache = () => (cached && cached.token === getSessionKey() ? cached : null);

/**
 * Configuración → Identidad y región y Comercial (como en el prototipo), más dónde se configura
 * lo que el prototipo también pone en esta pantalla y en el sistema vive en otra (series e IGV,
 * segregación y orden directa, verificación y sesiones).
 */
export function CompanySettingsCards({ canManage, toast }: {
  canManage: boolean;
  toast: (text: string, tone?: "success" | "warning" | "danger") => void;
}) {
  const [identity, setIdentity] = useState<Identity | null>(() => fromCache()?.identity ?? null);
  const [tradeName, setTradeName] = useState(() => fromCache()?.identity.trade_name ?? "");
  const [validity, setValidity] = useState(() => fromCache()?.validity ?? "");
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [busy, setBusy] = useState<"identity" | "commercial" | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    let cancelled = false;
    Promise.all([
      apiFetch<Identity>("/v1/tenant/identity"),
      apiFetch<{ quotation_validity_days: number }>("/v1/tenant/settings"),
    ])
      .then(([data, settings]) => {
        if (cancelled) return;
        cached = { token: getSessionKey(), identity: data, validity: String(settings.quotation_validity_days) };
        setIdentity(data);
        setTradeName(data.trade_name);
        setValidity(String(settings.quotation_validity_days));
        setError("");
      })
      .catch(() => { if (!cancelled) setError("No se pudieron cargar los datos de la empresa."); });
    return () => { cancelled = true; };
  }, [reload]);

  async function saveIdentity() {
    setBusy("identity");
    setErrors({});
    try {
      const data = await apiFetch<Identity>("/v1/tenant/identity", { method: "PUT", body: JSON.stringify({ trade_name: tradeName }) });
      setIdentity(data);
      setTradeName(data.trade_name);
      cached = { token: getSessionKey(), identity: data, validity };
      announceTenantName(data.display_name);
      toast("Nombre comercial guardado");
    } catch (err) {
      setErrors({ trade_name: firstError(err, "No se pudo guardar.") });
    } finally {
      setBusy(null);
    }
  }

  async function saveCommercial() {
    setBusy("commercial");
    setErrors({});
    try {
      const data = await apiFetch<{ quotation_validity_days: number }>("/v1/tenant/settings", {
        method: "PUT", body: JSON.stringify({ quotation_validity_days: Number(validity) }),
      });
      setValidity(String(data.quotation_validity_days));
      if (identity) cached = { token: getSessionKey(), identity, validity: String(data.quotation_validity_days) };
      toast("Vigencia guardada");
    } catch (err) {
      setErrors({ validity: firstError(err, "No se pudo guardar.") });
    } finally {
      setBusy(null);
    }
  }

  if (error) return <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>;
  if (!identity) return <div className="mb-[18px] h-40 animate-pulse rounded-xl bg-[var(--app-surface-muted)]" aria-busy="true" />;

  const elsewhere: [string, string, string][] = [
    ["Fiscal (Perú / SUNAT)", "Series de facturas, boletas y notas de crédito, IGV, detracciones y proveedor fiscal.", "/fiscal"],
    ["Reglas de la empresa", "Segregación de funciones, orden directa sin contrato, firma electrónica y límite de crédito.", "/roles"],
    ["Seguridad y aislamiento", "Código de verificación, duración de la sesión y cierre por inactividad. El portal siempre pide código.", "/security"],
  ];

  return (
    <div className="grid items-start gap-x-[18px] lg:grid-cols-2 xl:grid-cols-[minmax(0,1.7fr)_minmax(0,1fr)_minmax(0,1fr)]">
      <Card title="Identidad y región">
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Nombre comercial" error={errors.trade_name}>
            <input className={INPUT} value={tradeName} maxLength={255} disabled={!canManage} onChange={(e) => setTradeName(e.target.value)} />
          </Field>
          <Field label="Razón social" hint="La registrada ante SUNAT.">
            <input className={INPUT} value={identity.legal_name ?? ""} disabled readOnly />
          </Field>
          <Field label="RUC">
            <input className={INPUT} value={identity.ruc ?? ""} disabled readOnly />
          </Field>
          <Field label="Zona horaria" hint="Fechas y horas del sistema.">
            <input className={INPUT} value={TIMEZONES[identity.timezone] ?? identity.timezone} disabled readOnly />
          </Field>
          <Field label="Moneda base" hint="La contabilidad se lleva en soles; también se cotiza en dólares." span={2}>
            <input className={INPUT} value={CURRENCIES[identity.currency] ?? identity.currency} disabled readOnly />
          </Field>
        </div>
        <div className="mt-3 flex justify-end">
          <Btn permission="tenant.manage" onClick={saveIdentity} disabled={busy !== null || (tradeName.trim() === identity.trade_name && identity.trade_name === identity.display_name)}>
            {busy === "identity" ? "Guardando…" : "Guardar"}
          </Btn>
        </div>
      </Card>

      <Card title="Comercial">
        <Field label="Vigencia por defecto de cotizaciones (días)" error={errors.validity} hint="Con la que nace una cotización nueva; se puede cambiar en cada una.">
          <input className={INPUT} type="number" min={1} max={365} value={validity} disabled={!canManage} onChange={(e) => setValidity(e.target.value)} />
        </Field>
        <div className="mt-3 flex justify-end">
          <Btn permission="tenant.manage" onClick={saveCommercial} disabled={busy !== null || !validity}>
            {busy === "commercial" ? "Guardando…" : "Guardar"}
          </Btn>
        </div>
      </Card>

      <Card title="También se configura en">
        <ul className="grid gap-2">
          {elsewhere.map(([title, text, href]) => (
            <li key={href}>
              <Link href={href} className="group flex items-start justify-between gap-2 rounded-lg border border-[color:var(--app-border)] px-3 py-2 hover:bg-[var(--app-surface-muted)]">
                <span className="min-w-0">
                  <strong className="block text-[13px] font-semibold">{title}</strong>
                  <small className="app-muted block text-[12px]">{text}</small>
                </span>
                <ArrowRight size={15} className="app-muted mt-0.5 shrink-0 group-hover:text-[color:var(--p-brand-text)]" />
              </Link>
            </li>
          ))}
        </ul>
      </Card>
    </div>
  );
}
