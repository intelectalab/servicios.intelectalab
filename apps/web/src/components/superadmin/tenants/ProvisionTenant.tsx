"use client";

import { useEffect, useMemo, useState, type FormEvent } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { ArrowLeft, Check } from "lucide-react";
import { apiFetch, ApiError } from "@/lib/api";
import { money } from "@/lib/commercial";
import { PERIODS } from "@/lib/platform";
import { Btn, Card, Field, FormGrid, INPUT, LoadError, PageHead, Screen } from "@/components/ui/proto";
import { PickSelect } from "@/components/ui/pick-select";
import type { CatalogModule, PlatformPlanOption } from "@/types/platform";

type StepState = "done" | "todo";
type Step = { label: string; state: StepState; hint: string; target?: string };

const RUC_OK = /^(10|20)\d{9}$/;
const EMAIL_OK = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/** Pasos que se llenan en esta pantalla: se marcan listos a medida que se completan. */
function provisionSteps(form: Form, planChosen: boolean): Step[] {
  const company = Boolean(form.name.trim()) && RUC_OK.test(form.ruc);
  const admin = Boolean(form.admin_name.trim()) && EMAIL_OK.test(form.admin_email.trim());
  const doneIf = (ok: boolean): StepState => (ok ? "done" : "todo");
  return [
    { label: "Tenant", state: doneIf(Boolean(form.name.trim())), hint: "Nombre comercial de la empresa", target: "provision-company" },
    { label: "Empresa y sedes", state: doneIf(company), hint: "RUC válido y sede principal", target: "provision-company" },
    { label: "Administrador", state: doneIf(admin), hint: "Nombre y correo del administrador", target: "provision-admin" },
    { label: "Plan", state: doneIf(planChosen), hint: "Plan y periodo de la suscripción", target: "provision-plan" },
    { label: "Módulos", state: doneIf(planChosen), hint: "Salen del plan; CRM y Portal se pueden dejar apagados", target: "provision-plan" },
  ];
}

const STEP_STYLE: Record<StepState, [string, string]> = {
  done: ["bg-[var(--p-brand-soft)] text-[color:var(--p-brand-text)]", "bg-[#1B8FC9] text-[#fff]"],
  todo: ["border border-dashed border-[color:var(--app-border)] app-muted", "bg-[var(--app-surface-muted)] app-text"],
};

type Form = {
  name: string; ruc: string; legal_name: string; branch: string; admin_name: string; admin_email: string;
  plan_id: string; billing_period: string; trial: boolean; crm: boolean; portal: boolean;
};

const EMPTY: Form = {
  name: "", ruc: "", legal_name: "", branch: "Lima", admin_name: "", admin_email: "",
  plan_id: "", billing_period: "MONTHLY", trial: true, crm: true, portal: true,
};

function Check2({ label, checked, onChange, disabled, hint }: { label: string; checked: boolean; onChange?: (v: boolean) => void; disabled?: boolean; hint?: string }) {
  return (
    <label className={`relative flex items-start gap-2.5 ${disabled ? "cursor-not-allowed opacity-70" : "cursor-pointer"}`} title={hint}>
      <input type="checkbox" checked={checked} disabled={disabled} onChange={(e) => onChange?.(e.target.checked)} className="mt-0.5 h-4 w-4 accent-[#1B8FC9]" />
      <span>
        <span className="block text-[13px] font-medium">{label}</span>
        {hint && <small className="app-muted block text-xs">{hint}</small>}
      </span>
    </label>
  );
}

export function ProvisionTenant() {
  const router = useRouter();
  const [options, setOptions] = useState<{ plans: PlatformPlanOption[]; catalog: CatalogModule[] } | null>(null);
  const [loadError, setLoadError] = useState(false);
  const [form, setForm] = useState<Form>(EMPTY);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [message, setMessage] = useState("");
  const [saving, setSaving] = useState(false);

  async function load() {
    setLoadError(false);
    try {
      const data = await apiFetch<{ plans: PlatformPlanOption[]; catalog: CatalogModule[] }>("/v1/superadmin/tenants/options");
      setOptions(data);
    } catch {
      setLoadError(true);
    }
  }

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- primera carga de planes y módulos
    void load();
  }, []);

  const plan = options?.plans.find((p) => p.id === form.plan_id);
  const hasCrm = Boolean(plan?.modules.CRM);
  const hasPortal = Boolean(plan?.modules.PORTAL);
  const planModules = useMemo(() => (options?.catalog ?? [])
    .filter((m) => plan?.modules[m.code] && !(m.code === "CRM" && !form.crm) && !(m.code === "PORTAL" && !form.portal))
    .map((m) => m.name), [options, plan, form.crm, form.portal]);

  const set = <K extends keyof Form>(key: K, value: Form[K]) => setForm((f) => ({ ...f, [key]: value }));

  async function submit(event: FormEvent) {
    event.preventDefault();
    setSaving(true);
    setErrors({});
    setMessage("");
    try {
      const created = await apiFetch<{ id: string }>("/v1/superadmin/tenants", {
        method: "POST",
        body: JSON.stringify({ ...form, trial: form.trial && Boolean(plan?.has_trial), crm: form.crm && hasCrm, portal: form.portal && hasPortal }),
      });
      router.push(`/superadmin/tenants/${created.id}`);
    } catch (err) {
      if (err instanceof ApiError && err.errors) {
        setErrors(Object.fromEntries(Object.entries(err.errors).map(([key, list]) => [key, list[0]])));
      }
      setMessage(err instanceof ApiError ? err.message : "No se pudo aprovisionar la empresa.");
      setSaving(false);
    }
  }

  return (
    <Screen>
      <Link href="/superadmin/tenants" className="app-muted mb-2 inline-flex items-center gap-1.5 text-[13px] hover:text-[var(--app-text)]">
        <ArrowLeft size={15} /> Tenants
      </Link>
      <PageHead title="Aprovisionar tenant" showTitle />

      <ol className="mb-4 flex flex-wrap gap-1.5" aria-label="Pasos del aprovisionamiento">
        {provisionSteps(form, Boolean(plan)).map((step, index) => {
          const [pill, dot] = STEP_STYLE[step.state];
          const ready = step.state === "done";
          const status = ready ? "Listo" : "Por completar";
          const content = (
            <>
              <span className={`grid h-[18px] w-[18px] shrink-0 place-items-center rounded-full text-[10px] ${dot}`}>
                {ready ? <Check size={11} strokeWidth={3} /> : index + 1}
              </span>
              {step.label}
            </>
          );
          const className = `flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[12px] font-medium ${pill}`;
          return (
            <li key={step.label} title={`${status}: ${step.hint}`}>
              {step.target ? (
                <button
                  type="button" className={`${className} cursor-pointer hover:brightness-95`} aria-label={`${step.label}: ${status}`}
                  onClick={() => document.getElementById(step.target!)?.scrollIntoView({ behavior: "smooth", block: "start" })}
                >
                  {content}
                </button>
              ) : (
                <span className={className} aria-label={`${step.label}: ${status}`}>{content}</span>
              )}
            </li>
          );
        })}
      </ol>

      {loadError ? (
        <Card><LoadError text="No se pudieron cargar los planes." onRetry={() => void load()} /></Card>
      ) : (
        <form onSubmit={(e) => void submit(e)}>
          <div id="provision-company" className="scroll-mt-4" />
          <Card title="Empresa">
            <FormGrid>
              <Field label="Nombre comercial" required span={2} error={errors.name}>
                <input value={form.name} onChange={(e) => set("name", e.target.value)} className={INPUT} maxLength={255} required />
              </Field>
              <Field label="RUC" required error={errors.ruc}>
                <input value={form.ruc} onChange={(e) => set("ruc", e.target.value.replace(/\D/g, "").slice(0, 11))} className={INPUT} inputMode="numeric" placeholder="20XXXXXXXXX" required />
              </Field>
              <Field label="Razón social" span={2} error={errors.legal_name}>
                <input value={form.legal_name} onChange={(e) => set("legal_name", e.target.value)} className={INPUT} maxLength={255} />
              </Field>
              <Field label="Sede principal" error={errors.branch}>
                <input value={form.branch} onChange={(e) => set("branch", e.target.value)} className={INPUT} maxLength={120} />
              </Field>
            </FormGrid>
          </Card>

          <div id="provision-admin" className="scroll-mt-4" />
          <Card title="Administrador del tenant">
            <FormGrid>
              <Field label="Nombre" required span={2} error={errors.admin_name}>
                <input value={form.admin_name} onChange={(e) => set("admin_name", e.target.value)} className={INPUT} maxLength={255} required />
              </Field>
              <Field label="Correo" required error={errors.admin_email} hint="Al activar la empresa recibe un código para crear su contraseña.">
                <input type="email" value={form.admin_email} onChange={(e) => set("admin_email", e.target.value)} className={INPUT} maxLength={255} required />
              </Field>
            </FormGrid>
          </Card>

          <div id="provision-plan" className="scroll-mt-4" />
          <Card title="Suscripción y módulos">
            <FormGrid>
              <Field label="Plan" required error={errors.plan_id}>
                <PickSelect value={form.plan_id} onChange={(v) => set("plan_id", v)} ariaLabel="Plan" options={(options?.plans ?? []).map((p) => [p.id, p.name])} className={INPUT} />
              </Field>
              <Field label="Periodo" required error={errors.billing_period}>
                <PickSelect value={form.billing_period} onChange={(v) => set("billing_period", v)} ariaLabel="Periodo" options={Object.entries(PERIODS)} className={INPUT} />
              </Field>
              <Field label="Precio">
                <span className="flex min-h-10 items-center">
                  {plan ? `${money(form.billing_period === "ANNUAL" ? plan.annual_price : plan.monthly_price, plan.currency)} ${form.billing_period === "ANNUAL" ? "al año" : "al mes"}` : "—"}
                </span>
              </Field>
            </FormGrid>
            <div className="mt-4 grid gap-3 md:grid-cols-3">
              <Check2
                label={plan?.has_trial ? `Prueba gratis de ${plan.trial_days ?? 30} días` : "Prueba gratis"}
                checked={form.trial && Boolean(plan?.has_trial)} onChange={(v) => set("trial", v)} disabled={!plan?.has_trial}
                hint={plan && !plan.has_trial ? "El plan no tiene prueba" : undefined}
              />
              <Check2 label="Activar CRM" checked={form.crm && hasCrm} onChange={(v) => set("crm", v)} disabled={!hasCrm} hint={!hasCrm ? "El plan no incluye CRM" : undefined} />
              <Check2 label="Activar Portal Cliente" checked={form.portal && hasPortal} onChange={(v) => set("portal", v)} disabled={!hasPortal} hint={!hasPortal ? "El plan no incluye el portal" : undefined} />
              <Check2 label="Cargar datos maestros base (categorías, SLA, plantillas)" checked disabled hint="Se crean al usar cada módulo por primera vez" />
            </div>
            {plan && (
              <p className="mt-4 text-[13px]">
                <strong className="font-medium">Módulos que tendrá:</strong> {planModules.join(", ")}.
              </p>
            )}
          </Card>

          {message && <p className="mb-3 text-[13px] text-[color:var(--p-danger-text)]">{message}</p>}
          <div className="flex justify-end">
            <Btn type="submit" disabled={saving || !options || !form.plan_id} title={form.plan_id ? undefined : "Elige el plan"}><Check size={16} /><span>{saving ? "Aprovisionando…" : "Aprovisionar"}</span></Btn>
          </div>
        </form>
      )}
    </Screen>
  );
}
