"use client";

import { useEffect, useMemo, useState } from "react";
import { motion, AnimatePresence } from "framer-motion";
import { X, Loader2, AlertCircle, Lock } from "lucide-react";
import { apiFetch, ApiError } from "@/lib/api";
import type { BillingPeriod, SaasPlan } from "@/types/saas-plan";

interface SaasPlanDrawerProps {
  isOpen: boolean;
  onClose: () => void;
  onSuccess: () => void;
  planToEdit?: SaasPlan | null;
}

const TABS = [
  "1. Información",
  "2. Precio y suscripción",
  "3. Módulos",
  "4. Límites",
  "5. Consumo",
  "6. Soporte",
  "7. Características",
];

/** Módulo del catálogo del ERP (module_catalog), con sus reglas. */
type CatalogModule = {
  code: string;
  name: string;
  description: string | null;
  category: string;
  is_core: boolean;
  own_screens: boolean;
  provided_by: string[];
};

const CATEGORY_TITLES: Record<string, string> = {
  CORE: "Base del ERP",
  COMERCIAL: "Comercial",
  OPERACIONES: "Operaciones",
  FINANZAS: "Finanzas",
  COMPRAS: "Compras",
  CONTABILIDAD: "Contabilidad",
  SOPORTE: "Soporte",
  CRM: "CRM",
  PORTAL: "Portal de clientes",
  CONTROL: "Control",
};

const initialFormState = {
  name: "",
  code: "",
  description: "",
  active: true,
  display_order: 1,
  is_public: true,
  monthly_price: 0,
  annual_price: 0,
  currency: "PEN",
  has_trial: true,
  trial_days: 30,
  requires_payment_upfront: false,
  auto_renew_default: true,
  default_billing_period: "MONTHLY" as BillingPeriod,
  modules: {
    CLIENTES: true, SERVICIOS: true, COTIZACIONES: true, CONTRATOS: true,
    ORDENES_TRABAJO: true, PROYECTOS: true, TAREAS: true, CHECKLISTS: true,
    AGENDA: true, RECURSOS: true, DOCUMENTOS: true, ENTREGABLES: true,
  } as Record<string, boolean>,
  limits: {
    users: 5, branches: 1, sites: 3, clients: 100,
    services: 50, products: 100, projects: 10, work_orders: 100,
    documents: 1000, portal_users: 20, timesheet_hours: 1000,
    project_users: 20, crm_leads: 500, crm_opportunities: 200,
  } as Record<string, number>,
  consumption: {
    storage_gb: 20, files_count: 5000, documents_generated: 3000,
    exports_month: 100, api_requests_month: 10000, backups_included: 7,
  } as Record<string, number>,
  support_level: "STANDARD",
  support_tickets_month: 10,
  support_sla_hours: 24,
  support_channels: {
    portal: true, email: true, whatsapp: false, priority_attention: false,
  } as Record<string, boolean>,
  features: {
    multi_tenant: true, roles_permissions: true, audit: true,
    mfa: false, integrations: false, api: false,
    advanced_reports: false, automations: false, client_portal: false, electronic_billing: false,
  } as Record<string, boolean>,
};

type PlanForm = typeof initialFormState;

function buildForm(plan?: SaasPlan | null): PlanForm {
  if (!plan) return initialFormState;

  return {
    name: plan.name ?? "",
    code: plan.code ?? "",
    description: plan.description ?? "",
    active: plan.active ?? true,
    display_order: plan.display_order ?? 1,
    is_public: plan.is_public ?? true,
    monthly_price: Number(plan.monthly_price ?? 0),
    annual_price: Number(plan.annual_price ?? 0),
    currency: plan.currency ?? "PEN",
    has_trial: plan.has_trial ?? true,
    trial_days: plan.trial_days ?? 30,
    requires_payment_upfront: plan.requires_payment_upfront ?? false,
    auto_renew_default: plan.auto_renew_default ?? true,
    default_billing_period: plan.default_billing_period ?? "MONTHLY",
    // Al editar, solo los módulos del plan guardado (sin los marcados por defecto de un plan nuevo).
    modules: { ...(plan.modules || {}) },
    limits: { ...initialFormState.limits, ...(plan.limits || {}) },
    consumption: { ...initialFormState.consumption, ...(plan.consumption || {}) },
    support_level: plan.support_level ?? "STANDARD",
    support_tickets_month: plan.support_tickets_month ?? 10,
    support_sla_hours: plan.support_sla_hours ?? 24,
    support_channels: { ...initialFormState.support_channels, ...(plan.support_channels || {}) },
    features: { ...initialFormState.features, ...(plan.features || {}) },
  };
}

export function SaasPlanDrawer(props: SaasPlanDrawerProps) {
  // El panel se monta en cada apertura, así el formulario y la pestaña
  // arrancan siempre desde el plan recibido (o vacío si es uno nuevo).
  return (
    <AnimatePresence>
      {props.isOpen && <SaasPlanDrawerPanel key="panel" {...props} />}
    </AnimatePresence>
  );
}

function SaasPlanDrawerPanel({ onClose, onSuccess, planToEdit }: SaasPlanDrawerProps) {
  const [activeTabIdx, setActiveTabIdx] = useState(0);
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState("");
  const [form, setForm] = useState(() => buildForm(planToEdit));
  const [catalog, setCatalog] = useState<CatalogModule[] | null>(null);
  const [catalogError, setCatalogError] = useState(false);

  useEffect(() => {
    apiFetch<CatalogModule[]>("/v1/superadmin/modules").then(setCatalog).catch(() => setCatalogError(true));
  }, []);

  // Módulos del catálogo agrupados por categoría, en el orden del catálogo.
  const moduleSections = useMemo(() => {
    const groups = new Map<string, CatalogModule[]>();
    (catalog ?? []).forEach((module) => groups.set(module.category, [...(groups.get(module.category) ?? []), module]));
    return [...groups.entries()].map(([category, modules]) => ({ title: CATEGORY_TITLES[category] ?? category, modules }));
  }, [catalog]);

  const handleChange = (field: keyof PlanForm, value: string | number | boolean) => {
    setForm((prev) => ({ ...prev, [field]: value }));
  };

  const handleNestedChange = (category: "limits" | "consumption" | "support_channels" | "features" | "modules", key: string, value: number | boolean) => {
    setForm((prev) => ({
      ...prev,
      [category]: { ...prev[category], [key]: value },
    }));
  };

  const handleSubmit = async () => {
    setError("");
    setIsSaving(true);
    try {
      const endpoint = planToEdit ? `/v1/superadmin/plans/${planToEdit.id}` : "/v1/superadmin/plans";
      const method = planToEdit ? "PUT" : "POST";

      // Solo módulos del catálogo; los obligatorios siempre van incluidos.
      const modules = catalog
        ? Object.fromEntries(catalog.filter((m) => m.is_core || form.modules[m.code]).map((m) => [m.code, true]))
        : form.modules;
      await apiFetch(endpoint, {
        method,
        body: JSON.stringify({ ...form, modules }),
      });
      onSuccess();
      onClose();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "No se pudo guardar el plan SaaS.");
    } finally {
      setIsSaving(false);
    }
  };

  return (
        <div role="dialog" aria-modal="true" aria-label="Plan SaaS" className="fixed inset-0 z-[200] overflow-hidden">
          <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            onClick={onClose}
            className="absolute inset-0 bg-[rgb(5_20_45/0.55)]"
          />

          <div className="absolute inset-y-0 right-0 flex max-w-full pl-10">
            <motion.div
              initial={{ x: "100%" }}
              animate={{ x: 0 }}
              exit={{ x: "100%" }}
              transition={{ type: "spring", damping: 25, stiffness: 200 }}
              className="app-text flex h-full w-screen max-w-4xl flex-col bg-[var(--app-surface)] shadow-[0_20px_50px_rgba(8,25,55,.28)]"
            >
              {/* Header */}
              <div className="flex shrink-0 items-center justify-between gap-3 bg-[#1B8FC9] px-6 py-4 text-[#fff]">
                <div>
                  <h2 className="text-base font-medium">
                    {planToEdit ? "Editar plan SaaS" : "Nuevo plan SaaS"}
                  </h2>
                </div>
                <button
                  onClick={onClose}
                  className="grid h-9 w-9 cursor-pointer place-items-center rounded-[10px] text-[#fff] hover:bg-[rgb(255_255_255/0.12)]"
                >
                  <X size={20} />
                </button>
              </div>

              {/* Pestañas */}
              <div className="flex shrink-0 overflow-x-auto border-b border-[color:var(--app-border)] bg-[var(--app-surface)] px-6">
                {TABS.map((tab, idx) => (
                  <button
                    key={tab}
                    onClick={() => setActiveTabIdx(idx)}
                    className={`-mb-px cursor-pointer whitespace-nowrap border-b-2 px-3 py-[11px] text-[13px] font-medium transition-colors ${
                      activeTabIdx === idx
                        ? "border-[#1B8FC9] text-[color:var(--p-brand-text)]"
                        : "app-muted border-transparent hover:text-[var(--app-text)]"
                    }`}
                  >
                    {tab}
                  </button>
                ))}
              </div>

              {/* Contenido scrolleable */}
              <div className="app-page flex-1 space-y-[18px] overflow-y-auto p-6">
                {error && (
                  <div className="flex items-center gap-3 rounded-xl border border-[color:var(--p-danger-line)] bg-[var(--p-danger-soft)] p-4 text-sm text-[color:var(--p-danger-text)]">
                    <AlertCircle size={20} className="text-[color:var(--p-danger-text)] shrink-0" />
                    <p>{error}</p>
                  </div>
                )}

                {/* 1. Información */}
                {activeTabIdx === 0 && (
                  <div className="space-y-6 bg-[var(--app-surface)] p-6 rounded-xl border border-[color:var(--app-border)] shadow-[0_1px_2px_rgba(16,24,40,.05),0_2px_8px_rgba(16,24,40,.06)]">
                    <div className="grid grid-cols-2 gap-5">
                      <div>
                        <label className="mb-1.5 block text-[12.5px] font-medium">Nombre del plan *</label>
                        <input
                          type="text"
                          placeholder="Ej. Professional"
                          value={form.name}
                          onChange={(e) => handleChange("name", e.target.value)}
                          className="app-text w-full rounded-[9px] border border-[color:var(--p-field-border)] bg-[var(--app-surface)] px-2.5 py-2 text-[13px] outline-none focus:border-[#1B8FC9] focus:shadow-[0_0_0_3px_rgba(27,143,201,.18)]"
                        />
                      </div>
                      <div>
                        <label className="mb-1.5 block text-[12.5px] font-medium">Código interno *</label>
                        <input
                          type="text"
                          placeholder="Ej. PRO"
                          value={form.code}
                          onChange={(e) => handleChange("code", e.target.value)}
                          className="app-text w-full rounded-[9px] border border-[color:var(--p-field-border)] bg-[var(--app-surface)] px-2.5 py-2 text-[13px] outline-none focus:border-[#1B8FC9] focus:shadow-[0_0_0_3px_rgba(27,143,201,.18)]"
                        />
                      </div>
                    </div>

                    <div>
                      <label className="mb-1.5 block text-[12.5px] font-medium">Descripción</label>
                      <textarea
                        rows={3}
                        placeholder="Describe para qué tipo de empresa está pensado este plan."
                        value={form.description}
                        onChange={(e) => handleChange("description", e.target.value)}
                        className="app-text w-full rounded-[9px] border border-[color:var(--p-field-border)] bg-[var(--app-surface)] px-2.5 py-2 text-[13px] outline-none focus:border-[#1B8FC9] focus:shadow-[0_0_0_3px_rgba(27,143,201,.18)]"
                      />
                    </div>

                    <div className="grid grid-cols-3 gap-5">
                      <div>
                        <label className="mb-1.5 block text-[12.5px] font-medium">Estado</label>
                        <select
                          value={form.active ? "true" : "false"}
                          onChange={(e) => handleChange("active", e.target.value === "true")}
                          className="app-text w-full rounded-[9px] border border-[color:var(--p-field-border)] bg-[var(--app-surface)] px-2.5 py-2 text-[13px] outline-none focus:border-[#1B8FC9] focus:shadow-[0_0_0_3px_rgba(27,143,201,.18)]"
                        >
                          <option value="true">Activo</option>
                          <option value="false">Inactivo</option>
                        </select>
                      </div>
                      <div>
                        <label className="mb-1.5 block text-[12.5px] font-medium">Orden de presentación</label>
                        <input
                          type="number"
                          value={form.display_order}
                          onChange={(e) => handleChange("display_order", Number(e.target.value))}
                          className="app-text w-full rounded-[9px] border border-[color:var(--p-field-border)] bg-[var(--app-surface)] px-2.5 py-2 text-[13px] outline-none focus:border-[#1B8FC9] focus:shadow-[0_0_0_3px_rgba(27,143,201,.18)]"
                        />
                      </div>
                      <div>
                        <label className="mb-1.5 block text-[12.5px] font-medium">Catálogo público</label>
                        <select
                          value={form.is_public ? "true" : "false"}
                          onChange={(e) => handleChange("is_public", e.target.value === "true")}
                          className="app-text w-full rounded-[9px] border border-[color:var(--p-field-border)] bg-[var(--app-surface)] px-2.5 py-2 text-[13px] outline-none focus:border-[#1B8FC9] focus:shadow-[0_0_0_3px_rgba(27,143,201,.18)]"
                        >
                          <option value="true">Sí</option>
                          <option value="false">No</option>
                        </select>
                      </div>
                    </div>
                  </div>
                )}

                {/* 2. Precio y suscripción */}
                {activeTabIdx === 1 && (
                  <div className="space-y-6">
                    <div className="bg-[var(--app-surface)] p-6 rounded-xl border border-[color:var(--app-border)] shadow-[0_1px_2px_rgba(16,24,40,.05),0_2px_8px_rgba(16,24,40,.06)] space-y-4">
                      <h3 className="text-[15px] font-semibold">Precio del plan</h3>
                      <div className="grid grid-cols-3 gap-5">
                        <div>
                          <label className="mb-1.5 block text-[12.5px] font-medium">Precio mensual</label>
                          <input
                            type="number"
                            step="0.01"
                            value={form.monthly_price}
                            onChange={(e) => handleChange("monthly_price", Number(e.target.value))}
                            className="app-text w-full rounded-[9px] border border-[color:var(--p-field-border)] bg-[var(--app-surface)] px-2.5 py-2 text-[13px] outline-none focus:border-[#1B8FC9] focus:shadow-[0_0_0_3px_rgba(27,143,201,.18)]"
                          />
                        </div>
                        <div>
                          <label className="mb-1.5 block text-[12.5px] font-medium">Precio anual</label>
                          <input
                            type="number"
                            step="0.01"
                            value={form.annual_price}
                            onChange={(e) => handleChange("annual_price", Number(e.target.value))}
                            className="app-text w-full rounded-[9px] border border-[color:var(--p-field-border)] bg-[var(--app-surface)] px-2.5 py-2 text-[13px] outline-none focus:border-[#1B8FC9] focus:shadow-[0_0_0_3px_rgba(27,143,201,.18)]"
                          />
                        </div>
                        <div>
                          <label className="mb-1.5 block text-[12.5px] font-medium">Moneda</label>
                          <select
                            value={form.currency}
                            onChange={(e) => handleChange("currency", e.target.value)}
                            className="app-text w-full rounded-[9px] border border-[color:var(--p-field-border)] bg-[var(--app-surface)] px-2.5 py-2 text-[13px] outline-none focus:border-[#1B8FC9] focus:shadow-[0_0_0_3px_rgba(27,143,201,.18)]"
                          >
                            <option value="PEN">PEN - Sol peruano</option>
                            <option value="USD">USD - Dólar</option>
                          </select>
                        </div>
                      </div>
                    </div>

                    <div className="bg-[var(--app-surface)] p-6 rounded-xl border border-[color:var(--app-border)] shadow-[0_1px_2px_rgba(16,24,40,.05),0_2px_8px_rgba(16,24,40,.06)] space-y-4">
                      <h3 className="text-[15px] font-semibold">Prueba gratuita</h3>
                      <div className="grid grid-cols-3 gap-5">
                        <div>
                          <label className="mb-1.5 block text-[12.5px] font-medium">¿Tiene prueba?</label>
                          <select
                            value={form.has_trial ? "true" : "false"}
                            onChange={(e) => handleChange("has_trial", e.target.value === "true")}
                            className="app-text w-full rounded-[9px] border border-[color:var(--p-field-border)] bg-[var(--app-surface)] px-2.5 py-2 text-[13px] outline-none focus:border-[#1B8FC9] focus:shadow-[0_0_0_3px_rgba(27,143,201,.18)]"
                          >
                            <option value="true">Sí</option>
                            <option value="false">No</option>
                          </select>
                        </div>
                        <div>
                          <label className="mb-1.5 block text-[12.5px] font-medium">Días de prueba</label>
                          <input
                            type="number"
                            value={form.trial_days}
                            onChange={(e) => handleChange("trial_days", Number(e.target.value))}
                            className="app-text w-full rounded-[9px] border border-[color:var(--p-field-border)] bg-[var(--app-surface)] px-2.5 py-2 text-[13px] outline-none focus:border-[#1B8FC9] focus:shadow-[0_0_0_3px_rgba(27,143,201,.18)]"
                          />
                        </div>
                        <div>
                          <label className="mb-1.5 block text-[12.5px] font-medium">¿Solicitar pago al iniciar?</label>
                          <select
                            value={form.requires_payment_upfront ? "true" : "false"}
                            onChange={(e) => handleChange("requires_payment_upfront", e.target.value === "true")}
                            className="app-text w-full rounded-[9px] border border-[color:var(--p-field-border)] bg-[var(--app-surface)] px-2.5 py-2 text-[13px] outline-none focus:border-[#1B8FC9] focus:shadow-[0_0_0_3px_rgba(27,143,201,.18)]"
                          >
                            <option value="true">Sí</option>
                            <option value="false">No</option>
                          </select>
                        </div>
                      </div>
                    </div>

                    <div className="bg-[var(--app-surface)] p-6 rounded-xl border border-[color:var(--app-border)] shadow-[0_1px_2px_rgba(16,24,40,.05),0_2px_8px_rgba(16,24,40,.06)] space-y-4">
                      <h3 className="text-[15px] font-semibold">Suscripción</h3>
                      <div className="grid grid-cols-2 gap-5">
                        <div>
                          <label className="mb-1.5 block text-[12.5px] font-medium">Renovación automática</label>
                          <select
                            value={form.auto_renew_default ? "true" : "false"}
                            onChange={(e) => handleChange("auto_renew_default", e.target.value === "true")}
                            className="app-text w-full rounded-[9px] border border-[color:var(--p-field-border)] bg-[var(--app-surface)] px-2.5 py-2 text-[13px] outline-none focus:border-[#1B8FC9] focus:shadow-[0_0_0_3px_rgba(27,143,201,.18)]"
                          >
                            <option value="true">Sí</option>
                            <option value="false">No</option>
                          </select>
                        </div>
                        <div>
                          <label className="mb-1.5 block text-[12.5px] font-medium">Periodo predeterminado</label>
                          <select
                            value={form.default_billing_period}
                            onChange={(e) => handleChange("default_billing_period", e.target.value)}
                            className="app-text w-full rounded-[9px] border border-[color:var(--p-field-border)] bg-[var(--app-surface)] px-2.5 py-2 text-[13px] outline-none focus:border-[#1B8FC9] focus:shadow-[0_0_0_3px_rgba(27,143,201,.18)]"
                          >
                            <option value="MONTHLY">Mensual</option>
                            <option value="ANNUAL">Anual</option>
                          </select>
                        </div>
                      </div>
                    </div>
                  </div>
                )}

                {/* 3. Módulos */}
                {activeTabIdx === 2 && (
                  <div className="space-y-6">
                    {catalogError && (
                      <p className="flex items-center gap-2 rounded-xl border border-[color:var(--p-danger-line)] bg-[var(--p-danger-soft)] px-4 py-3 text-xs text-[color:var(--p-danger-text)]">
                        <AlertCircle size={14} /> No se pudo cargar el catálogo de módulos. Cierra y vuelve a abrir el plan.
                      </p>
                    )}
                    {!catalog && !catalogError && <p className="text-xs app-muted">Cargando módulos…</p>}
                    {moduleSections.map((sec) => (
                      <div key={sec.title} className="bg-[var(--app-surface)] p-6 rounded-xl border border-[color:var(--app-border)] shadow-[0_1px_2px_rgba(16,24,40,.05),0_2px_8px_rgba(16,24,40,.06)] space-y-3">
                        <h3 className="text-[15px] font-semibold">{sec.title}</h3>
                        <div className="grid grid-cols-3 gap-3">
                          {sec.modules.map((mod) => {
                            const isChecked = mod.is_core || Boolean(form.modules[mod.code]);
                            const shared = !mod.own_screens && !mod.is_core;
                            const hint = mod.is_core
                              ? "Toda empresa lo tiene, lo incluya o no el plan"
                              : shared && mod.provided_by.length ? `Sus pantallas ya las da ${mod.provided_by.join(", ")}` : undefined;
                            return (
                              <label
                                key={mod.code}
                                title={hint}
                                className={`flex items-start gap-3 p-3 rounded-xl border transition-all ${
                                  mod.is_core ? "cursor-not-allowed" : "cursor-pointer"
                                } ${isChecked ? "border-[#1B8FC9] bg-[var(--p-brand-soft)]" : "border-[color:var(--app-border)] hover:bg-[var(--app-surface-muted)]"}`}
                              >
                                <input
                                  type="checkbox"
                                  checked={isChecked}
                                  disabled={mod.is_core}
                                  onChange={(e) => handleNestedChange("modules", mod.code, e.target.checked)}
                                  className="mt-0.5 h-4 w-4 rounded border-[color:var(--p-field-border)] accent-[#1B8FC9] cursor-pointer disabled:cursor-not-allowed"
                                />
                                <div className="min-w-0">
                                  <span className="text-xs font-bold app-text block leading-tight">{mod.name}</span>
                                  {mod.description && <span className="text-[10px] app-muted block mt-0.5 leading-tight">{mod.description}</span>}
                                  {mod.is_core && (
                                    <span className="mt-1 inline-flex items-center gap-1 rounded-full bg-[var(--p-brand-soft)] px-2 py-0.5 text-[10px] font-semibold text-[color:var(--p-brand-text)]">
                                      <Lock size={10} /> Obligatorio
                                    </span>
                                  )}
                                  {shared && (
                                    <span className="mt-1 inline-flex rounded-full bg-[var(--app-surface-muted)] px-2 py-0.5 text-[10px] font-semibold app-muted">
                                      {mod.provided_by.length ? "Sin pantallas propias" : "Sin pantallas todavía"}
                                    </span>
                                  )}
                                </div>
                              </label>
                            );
                          })}
                        </div>
                      </div>
                    ))}
                  </div>
                )}

                {/* 4. Límites */}
                {activeTabIdx === 3 && (
                  <div className="space-y-6">
                    <div className="bg-[var(--app-surface)] p-6 rounded-xl border border-[color:var(--app-border)] shadow-[0_1px_2px_rgba(16,24,40,.05),0_2px_8px_rgba(16,24,40,.06)] space-y-4">
                      <h3 className="text-[15px] font-semibold">Límites principales del tenant</h3>
                      <div className="grid grid-cols-2 gap-5">
                        {Object.entries({
                          users: "Usuarios", branches: "Sucursales",
                          sites: "Sedes / sitios", clients: "Clientes",
                          services: "Servicios", products: "Productos",
                          projects: "Proyectos activos", work_orders: "Órdenes de trabajo / mes",
                          documents: "Documentos", portal_users: "Usuarios externos / portal",
                        }).map(([key, label]) => (
                          <div key={key}>
                            <label className="mb-1 block text-[12.5px] font-medium">{label}</label>
                            <input
                              type="number"
                              value={form.limits[key] ?? 0}
                              onChange={(e) => handleNestedChange("limits", key, Number(e.target.value))}
                              className="app-text w-full rounded-[9px] border border-[color:var(--p-field-border)] bg-[var(--app-surface)] px-2.5 py-2 text-[13px] outline-none focus:border-[#1B8FC9] focus:shadow-[0_0_0_3px_rgba(27,143,201,.18)]"
                            />
                          </div>
                        ))}
                      </div>
                      <p className="text-[11px] app-muted italic">Usa -1 para representar &quot;ilimitado&quot;.</p>
                    </div>

                    <div className="bg-[var(--app-surface)] p-6 rounded-xl border border-[color:var(--app-border)] shadow-[0_1px_2px_rgba(16,24,40,.05),0_2px_8px_rgba(16,24,40,.06)] space-y-4">
                      <h3 className="text-[15px] font-semibold">Límites por módulo</h3>
                      <div className="grid grid-cols-2 gap-5">
                        {Object.entries({
                          timesheet_hours: "Horas de Timesheets / mes", project_users: "Usuarios por proyecto",
                          crm_leads: "Leads CRM", crm_opportunities: "Oportunidades CRM",
                        }).map(([key, label]) => (
                          <div key={key}>
                            <label className="mb-1 block text-[12.5px] font-medium">{label}</label>
                            <input
                              type="number"
                              value={form.limits[key] ?? 0}
                              onChange={(e) => handleNestedChange("limits", key, Number(e.target.value))}
                              className="app-text w-full rounded-[9px] border border-[color:var(--p-field-border)] bg-[var(--app-surface)] px-2.5 py-2 text-[13px] outline-none focus:border-[#1B8FC9] focus:shadow-[0_0_0_3px_rgba(27,143,201,.18)]"
                            />
                          </div>
                        ))}
                      </div>
                    </div>
                  </div>
                )}

                {/* 5. Consumo */}
                {activeTabIdx === 4 && (
                  <div className="bg-[var(--app-surface)] p-6 rounded-xl border border-[color:var(--app-border)] shadow-[0_1px_2px_rgba(16,24,40,.05),0_2px_8px_rgba(16,24,40,.06)] space-y-4">
                    <h3 className="text-[15px] font-semibold">Consumo SaaS</h3>
                    <div className="grid grid-cols-2 gap-5">
                      <div>
                        <label className="mb-1.5 block text-[12.5px] font-medium">Almacenamiento (GB)</label>
                        <input
                          type="number"
                          value={form.consumption.storage_gb}
                          onChange={(e) => handleNestedChange("consumption", "storage_gb", Number(e.target.value))}
                          className="app-text w-full rounded-[9px] border border-[color:var(--p-field-border)] bg-[var(--app-surface)] px-2.5 py-2 text-[13px] outline-none focus:border-[#1B8FC9] focus:shadow-[0_0_0_3px_rgba(27,143,201,.18)]"
                        />
                      </div>
                      <div>
                        <label className="mb-1.5 block text-[12.5px] font-medium">Archivos</label>
                        <input
                          type="number"
                          value={form.consumption.files_count}
                          onChange={(e) => handleNestedChange("consumption", "files_count", Number(e.target.value))}
                          className="app-text w-full rounded-[9px] border border-[color:var(--p-field-border)] bg-[var(--app-surface)] px-2.5 py-2 text-[13px] outline-none focus:border-[#1B8FC9] focus:shadow-[0_0_0_3px_rgba(27,143,201,.18)]"
                        />
                      </div>
                      <div>
                        <label className="mb-1.5 block text-[12.5px] font-medium">Documentos generados</label>
                        <input
                          type="number"
                          value={form.consumption.documents_generated}
                          onChange={(e) => handleNestedChange("consumption", "documents_generated", Number(e.target.value))}
                          className="app-text w-full rounded-[9px] border border-[color:var(--p-field-border)] bg-[var(--app-surface)] px-2.5 py-2 text-[13px] outline-none focus:border-[#1B8FC9] focus:shadow-[0_0_0_3px_rgba(27,143,201,.18)]"
                        />
                      </div>
                      <div>
                        <label className="mb-1.5 block text-[12.5px] font-medium">Exportaciones / mes</label>
                        <input
                          type="number"
                          value={form.consumption.exports_month}
                          onChange={(e) => handleNestedChange("consumption", "exports_month", Number(e.target.value))}
                          className="app-text w-full rounded-[9px] border border-[color:var(--p-field-border)] bg-[var(--app-surface)] px-2.5 py-2 text-[13px] outline-none focus:border-[#1B8FC9] focus:shadow-[0_0_0_3px_rgba(27,143,201,.18)]"
                        />
                      </div>
                      <div>
                        <label className="mb-1.5 block text-[12.5px] font-medium">Solicitudes API / mes</label>
                        <input
                          type="number"
                          value={form.consumption.api_requests_month}
                          onChange={(e) => handleNestedChange("consumption", "api_requests_month", Number(e.target.value))}
                          className="app-text w-full rounded-[9px] border border-[color:var(--p-field-border)] bg-[var(--app-surface)] px-2.5 py-2 text-[13px] outline-none focus:border-[#1B8FC9] focus:shadow-[0_0_0_3px_rgba(27,143,201,.18)]"
                        />
                      </div>
                      <div>
                        <label className="mb-1.5 block text-[12.5px] font-medium">Backups incluidos</label>
                        <input
                          type="number"
                          value={form.consumption.backups_included}
                          onChange={(e) => handleNestedChange("consumption", "backups_included", Number(e.target.value))}
                          className="app-text w-full rounded-[9px] border border-[color:var(--p-field-border)] bg-[var(--app-surface)] px-2.5 py-2 text-[13px] outline-none focus:border-[#1B8FC9] focus:shadow-[0_0_0_3px_rgba(27,143,201,.18)]"
                        />
                      </div>
                    </div>
                  </div>
                )}

                {/* 6. Soporte */}
                {activeTabIdx === 5 && (
                  <div className="bg-[var(--app-surface)] p-6 rounded-xl border border-[color:var(--app-border)] shadow-[0_1px_2px_rgba(16,24,40,.05),0_2px_8px_rgba(16,24,40,.06)] space-y-4">
                    <h3 className="text-[15px] font-semibold">Nivel de soporte</h3>
                    <div className="grid grid-cols-3 gap-5">
                      <div>
                        <label className="mb-1.5 block text-[12.5px] font-medium">Nivel</label>
                        <select
                          value={form.support_level}
                          onChange={(e) => handleChange("support_level", e.target.value)}
                          className="app-text w-full rounded-[9px] border border-[color:var(--p-field-border)] bg-[var(--app-surface)] px-2.5 py-2 text-[13px] outline-none focus:border-[#1B8FC9] focus:shadow-[0_0_0_3px_rgba(27,143,201,.18)]"
                        >
                          <option value="BASIC">Básico</option>
                          <option value="STANDARD">Estándar</option>
                          <option value="PREMIUM">Premium</option>
                          <option value="DEDICATED">Dedicado</option>
                        </select>
                      </div>
                      <div>
                        <label className="mb-1.5 block text-[12.5px] font-medium">Tickets / mes</label>
                        <input
                          type="number"
                          value={form.support_tickets_month}
                          onChange={(e) => handleChange("support_tickets_month", Number(e.target.value))}
                          className="app-text w-full rounded-[9px] border border-[color:var(--p-field-border)] bg-[var(--app-surface)] px-2.5 py-2 text-[13px] outline-none focus:border-[#1B8FC9] focus:shadow-[0_0_0_3px_rgba(27,143,201,.18)]"
                        />
                      </div>
                      <div>
                        <label className="mb-1.5 block text-[12.5px] font-medium">SLA inicial (horas)</label>
                        <input
                          type="number"
                          value={form.support_sla_hours}
                          onChange={(e) => handleChange("support_sla_hours", Number(e.target.value))}
                          className="app-text w-full rounded-[9px] border border-[color:var(--p-field-border)] bg-[var(--app-surface)] px-2.5 py-2 text-[13px] outline-none focus:border-[#1B8FC9] focus:shadow-[0_0_0_3px_rgba(27,143,201,.18)]"
                        />
                      </div>
                    </div>

                    <h4 className="text-xs font-bold app-text pt-3">Canales incluidos</h4>
                    <div className="grid grid-cols-2 gap-3">
                      {Object.entries({
                        portal: "Portal de soporte", email: "Correo electrónico",
                        whatsapp: "WhatsApp", priority_attention: "Atención prioritaria",
                      }).map(([key, label]) => {
                        const checked = Boolean(form.support_channels[key]);
                        return (
                          <label key={key} className="flex items-center gap-3 p-3 rounded-xl border border-[color:var(--app-border)] cursor-pointer hover:bg-[var(--app-surface-muted)]">
                            <input
                              type="checkbox"
                              checked={checked}
                              onChange={(e) => handleNestedChange("support_channels", key, e.target.checked)}
                              className="h-4 w-4 rounded border-[color:var(--p-field-border)] accent-[#1B8FC9] cursor-pointer"
                            />
                            <span className="text-xs font-bold app-text">{label}</span>
                          </label>
                        );
                      })}
                    </div>
                  </div>
                )}

                {/* 7. Características */}
                {activeTabIdx === 6 && (
                  <div className="space-y-6">
                    <div className="bg-[var(--app-surface)] p-6 rounded-xl border border-[color:var(--app-border)] shadow-[0_1px_2px_rgba(16,24,40,.05),0_2px_8px_rgba(16,24,40,.06)] space-y-4">
                      <h3 className="text-[15px] font-semibold">Características incluidas</h3>
                      <div className="grid grid-cols-2 gap-3">
                        {Object.entries({
                          multi_tenant: "Multi-tenant", roles_permissions: "Roles y permisos",
                          audit: "Auditoría", mfa: "MFA",
                          integrations: "Integraciones", api: "API",
                          advanced_reports: "Reportes avanzados", automations: "Automatizaciones",
                          client_portal: "Portal del cliente", electronic_billing: "Facturación electrónica",
                        }).map(([key, label]) => {
                          const checked = Boolean(form.features[key]);
                          return (
                            <label key={key} className="flex items-center gap-3 p-3 rounded-xl border border-[color:var(--app-border)] cursor-pointer hover:bg-[var(--app-surface-muted)]">
                              <input
                                type="checkbox"
                                checked={checked}
                                onChange={(e) => handleNestedChange("features", key, e.target.checked)}
                                className="h-4 w-4 rounded border-[color:var(--p-field-border)] accent-[#1B8FC9] cursor-pointer"
                              />
                              <span className="text-xs font-bold app-text">{label}</span>
                            </label>
                          );
                        })}
                      </div>
                    </div>

                    {/* Vista previa del catálogo */}
                    <div className="bg-[var(--app-surface)] p-6 rounded-xl border border-[color:var(--app-border)] shadow-[0_1px_2px_rgba(16,24,40,.05),0_2px_8px_rgba(16,24,40,.06)] space-y-4">
                      <h3 className="text-[15px] font-semibold">Vista previa del catálogo</h3>
                      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div className="rounded-xl border border-[color:var(--app-border)] p-5 space-y-3 bg-[var(--app-surface-muted)]">
                          <span className="text-xs font-bold app-text block">{form.name || "Nombre del plan"}</span>
                          <div>
                            <span className="text-2xl font-semibold app-text">
                              {form.currency === "USD" ? "$" : "S/"} {form.monthly_price || 0}
                            </span>
                            <span className="text-xs app-muted"> / mes</span>
                          </div>
                          <p className="text-xs app-muted">{form.has_trial ? `${form.trial_days || 30} días gratis` : "Sin periodo de prueba"}</p>
                          <div className="w-full rounded-[9px] bg-[#1B8FC9] py-2 text-center text-xs font-medium text-[#fff]">
                            Comenzar prueba
                          </div>
                        </div>

                        <div className="rounded-xl border border-[color:var(--app-border)] p-5 space-y-2 bg-[var(--app-surface-muted)]">
                          <span className="text-xs font-bold app-text block">Incluye</span>
                          <p className="text-xs app-muted">✓ Módulos seleccionados</p>
                          <p className="text-xs app-muted">✓ Límites configurados</p>
                          <p className="text-xs app-muted">✓ Soporte según plan</p>
                        </div>

                        <div className="rounded-xl border border-[color:var(--app-border)] p-5 space-y-2 bg-[var(--app-surface-muted)]">
                          <span className="text-xs font-bold app-text block">Administración</span>
                          <p className="text-xs app-muted leading-relaxed">El plan determina las capacidades contratadas.</p>
                          <p className="text-xs app-muted leading-relaxed">Los permisos de cada usuario se gestionan por roles.</p>
                        </div>
                      </div>
                    </div>
                  </div>
                )}
              </div>

              {/* Footer */}
              <div className="flex shrink-0 items-center justify-end gap-2 border-t border-[color:var(--app-border)] bg-[var(--app-surface)] px-6 py-3">
                <button
                  type="button"
                  onClick={onClose}
                  className="inline-flex h-[38px] cursor-pointer items-center rounded-[9px] border border-[color:var(--app-border)] bg-[var(--app-surface)] px-4 text-[13px] font-medium text-[color:var(--app-text)] transition-colors hover:border-[#1B8FC9] hover:bg-[var(--app-surface-muted)]"
                >
                  Cancelar
                </button>
                <button
                  type="button"
                  disabled={isSaving}
                  onClick={handleSubmit}
                  className="inline-flex h-[38px] cursor-pointer items-center gap-[7px] rounded-[9px] border border-transparent bg-[#1B8FC9] px-4 text-[13px] font-medium text-[#fff] transition-colors hover:bg-[#1679AB] disabled:cursor-not-allowed disabled:opacity-45"
                >
                  {isSaving ? <Loader2 size={14} className="animate-spin" /> : planToEdit ? "Actualizar plan" : "Guardar plan"}
                </button>
              </div>
            </motion.div>
          </div>
        </div>
  );
}
