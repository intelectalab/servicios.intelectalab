"use client";

import { useEffect, useState, type FormEvent } from "react";
import { Check, LifeBuoy, Mail, MessageSquare, Phone, Save } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { Btn, Card, Chip, Field, INPUT, LoadError, PageHead, Screen, useToasts } from "@/components/ui/proto";
import type { PlatformSettings, PlatformSupportSettings } from "@/types/platform";

export function PlatformSettingsView() {
  const { toast, toasts } = useToasts();
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const [form, setForm] = useState<PlatformSupportSettings>({
    whatsapp: "+51999999999",
    email: "soporte@erp-saas.com",
    schedule: "Lunes a Viernes de 8:00 a 18:00",
  });

  useEffect(() => {
    let cancelled = false;
    apiFetch<PlatformSettings>("/v1/superadmin/settings")
      .then((data) => {
        if (!cancelled && data.support) {
          setForm({
            whatsapp: data.support.whatsapp ?? "+51999999999",
            email: data.support.email ?? "soporte@erp-saas.com",
            schedule: data.support.schedule ?? "",
          });
        }
      })
      .catch((err) => {
        if (!cancelled) setError("No se pudo cargar la configuración de la plataforma.");
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, []);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    try {
      const res = await apiFetch<{ message: string; support: PlatformSupportSettings }>("/v1/superadmin/settings", {
        method: "PUT",
        body: JSON.stringify({ support: form }),
      });
      setForm(res.support);
      toast("Configuración de soporte guardada correctamente.", "success");
    } catch {
      toast("Error al guardar la configuración de la plataforma.", "danger");
    } finally {
      setSaving(false);
    }
  }

  if (loading) {
    return (
      <Screen>
        <PageHead title="Configuración de la plataforma" />
        <Card>
          <div className="h-48 animate-pulse rounded bg-[var(--app-surface-muted)]" aria-busy="true" />
        </Card>
      </Screen>
    );
  }

  if (error) {
    return (
      <Screen>
        <PageHead title="Configuración de la plataforma" />
        <Card>
          <LoadError text={error} onRetry={() => window.location.reload()} />
        </Card>
      </Screen>
    );
  }

  return (
    <Screen>
      <PageHead
        title="Configuración de la plataforma"
        sub="Administra los canales oficiales de contacto, soporte y cobranza compartidos por todas las empresas del SaaS."
      />

      <form onSubmit={handleSubmit} className="grid grid-cols-1 gap-6 lg:grid-cols-12" noValidate>
        {/* Formulario de canales */}
        <div className="lg:col-span-7">
          <Card title="Canales oficiales de soporte y renovación">
            <p className="app-muted mb-4 text-xs leading-relaxed">
              Estos datos se muestran automáticamente en el ERP de cada cliente cuando consultan su plan,
              cuando reciben alertas preventivas de vencimiento o al solicitar extensiones de vigencia.
            </p>

            <div className="space-y-4">
              <Field
                label="WhatsApp oficial de soporte y pagos"
                required
                hint="Incluye código de país. Ej: +51 987 654 321 o 51987654321."
              >
                <div className="relative">
                  <span className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-[color:var(--app-text-muted)]">
                    <Phone size={15} />
                  </span>
                  <input
                    type="text"
                    className={`${INPUT} pl-9`}
                    value={form.whatsapp}
                    onChange={(e) => setForm({ ...form, whatsapp: e.target.value })}
                    placeholder="+51999999999"
                    required
                  />
                </div>
              </Field>

              <Field
                label="Correo electrónico de facturación y soporte"
                required
                hint="Casilla donde los clientes pueden enviar consultas o adjuntar comprobantes."
              >
                <div className="relative">
                  <span className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-[color:var(--app-text-muted)]">
                    <Mail size={15} />
                  </span>
                  <input
                    type="email"
                    className={`${INPUT} pl-9`}
                    value={form.email}
                    onChange={(e) => setForm({ ...form, email: e.target.value })}
                    placeholder="soporte@tu-empresa.com"
                    required
                  />
                </div>
              </Field>

              <Field
                label="Horario de atención (opcional)"
                hint="Informativo para que los clientes sepan cuándo recibirán respuesta inmediata."
              >
                <input
                  type="text"
                  className={INPUT}
                  value={form.schedule ?? ""}
                  onChange={(e) => setForm({ ...form, schedule: e.target.value })}
                  placeholder="Lunes a Viernes de 8:30 a 18:30"
                />
              </Field>

              <div className="pt-2">
                <Btn type="submit" disabled={saving}>
                  <Save size={15} />
                  <span>{saving ? "Guardando cambios…" : "Guardar configuración"}</span>
                </Btn>
              </div>
            </div>
          </Card>
        </div>

        {/* Vista previa en vivo */}
        <div className="lg:col-span-5">
          <Card title="Vista previa en tiempo real">
            <span className="app-muted block text-xs mb-3">
              Así es como tus clientes verán estas opciones en su pantalla de <strong>Mi perfil</strong>:
            </span>

            <div className="space-y-3 rounded-xl border border-[color:var(--app-border)] bg-[var(--app-surface-muted)] p-3.5 text-xs">
              <div className="flex items-center justify-between border-b border-[color:var(--app-border)] pb-2.5">
                <div>
                  <span className="app-muted block text-[10.5px] uppercase font-medium">Empresa ejemplo</span>
                  <span className="font-semibold text-xs">Tu Cliente SAC</span>
                </div>
                <Chip tone="success">Plan Activo</Chip>
              </div>

              <div className="rounded-lg border border-[color:var(--app-border)] bg-[var(--app-surface)] p-2.5">
                <div className="flex items-center gap-2">
                  <div className="flex h-7 w-7 items-center justify-center rounded-md bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
                    <MessageSquare size={14} />
                  </div>
                  <div className="min-w-0 flex-1">
                    <strong className="block text-xs font-semibold">WhatsApp de Soporte</strong>
                    <span className="app-muted truncate block text-[11px]">{form.whatsapp || "Sin número"}</span>
                  </div>
                  <span className="text-[11px] font-medium text-[color:var(--p-brand)]">Chat →</span>
                </div>
              </div>

              <div className="rounded-lg border border-[color:var(--app-border)] bg-[var(--app-surface)] p-2.5">
                <div className="flex items-center gap-2">
                  <div className="flex h-7 w-7 items-center justify-center rounded-md bg-sky-50 text-sky-700 dark:bg-sky-950/60 dark:text-sky-300">
                    <Mail size={14} />
                  </div>
                  <div className="min-w-0 flex-1">
                    <strong className="block text-xs font-semibold">Correo de Facturación</strong>
                    <span className="app-muted truncate block text-[11px]">{form.email || "Sin correo"}</span>
                  </div>
                  <span className="text-[11px] font-medium text-[color:var(--p-brand)]">Correo →</span>
                </div>
              </div>

              {form.schedule && (
                <div className="text-[11px] text-[color:var(--app-text-muted)] flex items-center gap-1.5 pt-1">
                  <LifeBuoy size={13} />
                  <span>Horario: {form.schedule}</span>
                </div>
              )}
            </div>
          </Card>
        </div>
      </form>

      {toasts}
    </Screen>
  );
}
