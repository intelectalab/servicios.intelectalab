"use client";

import { useState } from "react";
import { motion, AnimatePresence } from "framer-motion";
import { X, Loader2, AlertCircle } from "lucide-react";
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

const MODULE_SECTIONS = [
  {
    title: "CORE — base del ERP",
    modules: [
      { key: "CLIENTES", name: "Clientes", desc: "Gestión de clientes" },
      { key: "SERVICIOS", name: "Servicios", desc: "Catálogo y motor de servicios" },
      { key: "COTIZACIONES", name: "Cotizaciones", desc: "Propuestas comerciales" },
      { key: "CONTRATOS", name: "Contratos", desc: "Gestión contractual" },
      { key: "ORDENES_TRABAJO", name: "Órdenes de trabajo", desc: "Operación y ejecución" },
      { key: "PROYECTOS", name: "Proyectos", desc: "Planificación de proyectos" },
      { key: "TAREAS", name: "Tareas", desc: "Actividades y seguimiento" },
      { key: "CHECKLISTS", name: "Checklists", desc: "Listas de verificación" },
      { key: "AGENDA", name: "Agenda", desc: "Citas y programación" },
      { key: "RECURSOS", name: "Recursos", desc: "Personas y recursos" },
      { key: "DOCUMENTOS", name: "Documentos", desc: "Gestión documental" },
      { key: "ENTREGABLES", name: "Entregables", desc: "Control de entregas" },
    ],
  },
  {
    title: "Finanzas y facturación",
    modules: [
      { key: "FINANZAS", name: "Finanzas", desc: "Ingresos, gastos y control" },
      { key: "FACTURACION_ELECTRONICA", name: "Facturación electrónica", desc: "Facturación / SUNAT" },
      { key: "PAGOS_COBRANZAS", name: "Pagos y cobranzas", desc: "Gestión de pagos" },
      { key: "RENTABILIDAD", name: "Rentabilidad", desc: "Costos y margen" },
    ],
  },
  {
    title: "Operaciones y servicio",
    modules: [
      { key: "PERSONAL", name: "Personal", desc: "Interno y externo" },
      { key: "PROVEEDORES", name: "Proveedores", desc: "Gestión de proveedores" },
      { key: "TIMESHEETS", name: "Timesheets", desc: "Horas trabajadas" },
      { key: "SOPORTE", name: "Soporte", desc: "Tickets y atención" },
      { key: "GARANTIAS", name: "Garantías", desc: "Control de garantías" },
      { key: "CONDICIONES_ENTREGA", name: "Condiciones de entrega", desc: "Entrega y conformidad" },
    ],
  },
  {
    title: "Portal e integraciones",
    modules: [
      { key: "PORTAL_CLIENTE", name: "Portal del cliente", desc: "Acceso externo" },
      { key: "API", name: "API", desc: "Integraciones externas" },
      { key: "AUTOMATIZACIONES", name: "Automatizaciones", desc: "Workflows y triggers" },
      { key: "REPORTES_AVANZADOS", name: "Reportes avanzados", desc: "BI y análisis" },
    ],
  },
  {
    title: "CRM — opcional",
    modules: [
      { key: "CRM", name: "CRM", desc: "Leads, oportunidades y pipeline" },
    ],
  },
  {
    title: "Módulos especializados",
    modules: [
      { key: "HOSTING", name: "Hosting", desc: "Servicios de hosting" },
      { key: "DOMINIOS", name: "Dominios", desc: "Gestión de dominios" },
      { key: "SERVIDORES", name: "Servidores", desc: "Infraestructura" },
      { key: "CLOUD", name: "Cloud / Infraestructura", desc: "Servicios cloud" },
      { key: "ECOMMERCE", name: "eCommerce", desc: "Comercio electrónico" },
      { key: "OTROS", name: "Otros", desc: "Especialización por tenant" },
    ],
  },
];

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
    modules: { ...initialFormState.modules, ...(plan.modules || {}) },
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

      await apiFetch(endpoint, {
        method,
        body: JSON.stringify(form),
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
        <div className="fixed inset-0 z-[200] overflow-hidden">
          <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            onClick={onClose}
            className="absolute inset-0 bg-slate-900/40 backdrop-blur-xs"
          />

          <div className="absolute inset-y-0 right-0 flex max-w-full pl-10">
            <motion.div
              initial={{ x: "100%" }}
              animate={{ x: 0 }}
              exit={{ x: "100%" }}
              transition={{ type: "spring", damping: 25, stiffness: 200 }}
              className="w-screen max-w-4xl bg-white shadow-2xl flex flex-col h-full"
            >
              {/* Header */}
              <div className="flex items-center justify-between border-b border-slate-200 px-8 py-6 shrink-0 bg-slate-50/50">
                <div>
                  <h2 className="text-xl font-extrabold text-slate-900">
                    {planToEdit ? "Editar plan SaaS" : "Nuevo plan SaaS"}
                  </h2>
                  <p className="text-xs text-slate-500 mt-0.5">Define qué puede contratar y utilizar cada empresa.</p>
                </div>
                <button
                  onClick={onClose}
                  className="rounded-full p-2 text-slate-400 hover:bg-slate-200/60 hover:text-slate-600 transition-colors cursor-pointer"
                >
                  <X size={20} />
                </button>
              </div>

              {/* Pestañas */}
              <div className="flex shrink-0 border-b border-slate-200 bg-white px-8 overflow-x-auto">
                {TABS.map((tab, idx) => (
                  <button
                    key={tab}
                    onClick={() => setActiveTabIdx(idx)}
                    className={`border-b-2 py-4 px-3 text-xs font-bold transition-colors whitespace-nowrap cursor-pointer ${
                      activeTabIdx === idx
                        ? "border-[var(--app-brand-deep)] text-[var(--app-brand-deep)]"
                        : "border-transparent text-slate-500 hover:text-slate-700"
                    }`}
                  >
                    {tab}
                  </button>
                ))}
              </div>

              {/* Contenido scrolleable */}
              <div className="flex-1 overflow-y-auto p-8 space-y-6 bg-slate-50/30">
                {error && (
                  <div className="flex items-center gap-3 rounded-xl bg-red-50 p-4 text-sm text-red-800 border border-red-100">
                    <AlertCircle size={20} className="text-red-500 shrink-0" />
                    <p>{error}</p>
                  </div>
                )}

                {/* 1. Información */}
                {activeTabIdx === 0 && (
                  <div className="space-y-6 bg-white p-6 rounded-2xl border border-slate-200 shadow-2xs">
                    <div className="grid grid-cols-2 gap-5">
                      <div>
                        <label className="mb-1.5 block text-xs font-bold text-slate-700">Nombre del plan *</label>
                        <input
                          type="text"
                          placeholder="Ej. Professional"
                          value={form.name}
                          onChange={(e) => handleChange("name", e.target.value)}
                          className="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-xs text-slate-900 focus:outline-none"
                        />
                      </div>
                      <div>
                        <label className="mb-1.5 block text-xs font-bold text-slate-700">Código interno *</label>
                        <input
                          type="text"
                          placeholder="Ej. PRO"
                          value={form.code}
                          onChange={(e) => handleChange("code", e.target.value)}
                          className="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-xs text-slate-900 focus:outline-none"
                        />
                      </div>
                    </div>

                    <div>
                      <label className="mb-1.5 block text-xs font-bold text-slate-700">Descripción</label>
                      <textarea
                        rows={3}
                        placeholder="Describe para qué tipo de empresa está pensado este plan."
                        value={form.description}
                        onChange={(e) => handleChange("description", e.target.value)}
                        className="w-full rounded-xl border border-slate-300 p-3.5 text-xs text-slate-900 focus:outline-none"
                      />
                    </div>

                    <div className="grid grid-cols-3 gap-5">
                      <div>
                        <label className="mb-1.5 block text-xs font-bold text-slate-700">Estado</label>
                        <select
                          value={form.active ? "true" : "false"}
                          onChange={(e) => handleChange("active", e.target.value === "true")}
                          className="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-xs text-slate-900 focus:outline-none"
                        >
                          <option value="true">Activo</option>
                          <option value="false">Inactivo</option>
                        </select>
                      </div>
                      <div>
                        <label className="mb-1.5 block text-xs font-bold text-slate-700">Orden de presentación</label>
                        <input
                          type="number"
                          value={form.display_order}
                          onChange={(e) => handleChange("display_order", Number(e.target.value))}
                          className="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-xs text-slate-900 focus:outline-none"
                        />
                      </div>
                      <div>
                        <label className="mb-1.5 block text-xs font-bold text-slate-700">Catálogo público</label>
                        <select
                          value={form.is_public ? "true" : "false"}
                          onChange={(e) => handleChange("is_public", e.target.value === "true")}
                          className="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-xs text-slate-900 focus:outline-none"
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
                    <div className="bg-white p-6 rounded-2xl border border-slate-200 shadow-2xs space-y-4">
                      <h3 className="text-xs font-extrabold uppercase tracking-wider text-slate-500">Precio del plan</h3>
                      <div className="grid grid-cols-3 gap-5">
                        <div>
                          <label className="mb-1.5 block text-xs font-bold text-slate-700">Precio mensual</label>
                          <input
                            type="number"
                            step="0.01"
                            value={form.monthly_price}
                            onChange={(e) => handleChange("monthly_price", Number(e.target.value))}
                            className="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-xs text-slate-900 focus:outline-none"
                          />
                        </div>
                        <div>
                          <label className="mb-1.5 block text-xs font-bold text-slate-700">Precio anual</label>
                          <input
                            type="number"
                            step="0.01"
                            value={form.annual_price}
                            onChange={(e) => handleChange("annual_price", Number(e.target.value))}
                            className="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-xs text-slate-900 focus:outline-none"
                          />
                        </div>
                        <div>
                          <label className="mb-1.5 block text-xs font-bold text-slate-700">Moneda</label>
                          <select
                            value={form.currency}
                            onChange={(e) => handleChange("currency", e.target.value)}
                            className="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-xs text-slate-900 focus:outline-none"
                          >
                            <option value="PEN">PEN - Sol peruano</option>
                            <option value="USD">USD - Dólar</option>
                          </select>
                        </div>
                      </div>
                    </div>

                    <div className="bg-white p-6 rounded-2xl border border-slate-200 shadow-2xs space-y-4">
                      <h3 className="text-xs font-extrabold uppercase tracking-wider text-slate-500">Prueba gratuita</h3>
                      <div className="grid grid-cols-3 gap-5">
                        <div>
                          <label className="mb-1.5 block text-xs font-bold text-slate-700">¿Tiene prueba?</label>
                          <select
                            value={form.has_trial ? "true" : "false"}
                            onChange={(e) => handleChange("has_trial", e.target.value === "true")}
                            className="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-xs text-slate-900 focus:outline-none"
                          >
                            <option value="true">Sí</option>
                            <option value="false">No</option>
                          </select>
                        </div>
                        <div>
                          <label className="mb-1.5 block text-xs font-bold text-slate-700">Días de prueba</label>
                          <input
                            type="number"
                            value={form.trial_days}
                            onChange={(e) => handleChange("trial_days", Number(e.target.value))}
                            className="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-xs text-slate-900 focus:outline-none"
                          />
                        </div>
                        <div>
                          <label className="mb-1.5 block text-xs font-bold text-slate-700">¿Solicitar pago al iniciar?</label>
                          <select
                            value={form.requires_payment_upfront ? "true" : "false"}
                            onChange={(e) => handleChange("requires_payment_upfront", e.target.value === "true")}
                            className="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-xs text-slate-900 focus:outline-none"
                          >
                            <option value="true">Sí</option>
                            <option value="false">No</option>
                          </select>
                        </div>
                      </div>
                    </div>

                    <div className="bg-white p-6 rounded-2xl border border-slate-200 shadow-2xs space-y-4">
                      <h3 className="text-xs font-extrabold uppercase tracking-wider text-slate-500">Suscripción</h3>
                      <div className="grid grid-cols-2 gap-5">
                        <div>
                          <label className="mb-1.5 block text-xs font-bold text-slate-700">Renovación automática</label>
                          <select
                            value={form.auto_renew_default ? "true" : "false"}
                            onChange={(e) => handleChange("auto_renew_default", e.target.value === "true")}
                            className="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-xs text-slate-900 focus:outline-none"
                          >
                            <option value="true">Sí</option>
                            <option value="false">No</option>
                          </select>
                        </div>
                        <div>
                          <label className="mb-1.5 block text-xs font-bold text-slate-700">Periodo predeterminado</label>
                          <select
                            value={form.default_billing_period}
                            onChange={(e) => handleChange("default_billing_period", e.target.value)}
                            className="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-xs text-slate-900 focus:outline-none"
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
                    {MODULE_SECTIONS.map((sec) => (
                      <div key={sec.title} className="bg-white p-6 rounded-2xl border border-slate-200 shadow-2xs space-y-3">
                        <h3 className="text-xs font-extrabold text-slate-800">{sec.title}</h3>
                        <div className="grid grid-cols-3 gap-3">
                          {sec.modules.map((mod) => {
                            const isChecked = Boolean(form.modules[mod.key]);
                            return (
                              <label
                                key={mod.key}
                                className={`flex items-start gap-3 p-3 rounded-xl border cursor-pointer transition-all ${
                                  isChecked ? "border-[var(--app-brand-deep)] bg-blue-50/30" : "border-slate-200 hover:bg-slate-50"
                                }`}
                              >
                                <input
                                  type="checkbox"
                                  checked={isChecked}
                                  onChange={(e) => handleNestedChange("modules", mod.key, e.target.checked)}
                                  className="mt-0.5 h-4 w-4 rounded border-slate-300 text-[var(--app-brand-deep)] cursor-pointer"
                                />
                                <div>
                                  <span className="text-xs font-bold text-slate-900 block leading-tight">{mod.name}</span>
                                  <span className="text-[10px] text-slate-400 block mt-0.5 leading-tight">{mod.desc}</span>
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
                    <div className="bg-white p-6 rounded-2xl border border-slate-200 shadow-2xs space-y-4">
                      <h3 className="text-xs font-extrabold uppercase tracking-wider text-slate-500">Límites principales del tenant</h3>
                      <div className="grid grid-cols-2 gap-5">
                        {Object.entries({
                          users: "Usuarios", branches: "Sucursales",
                          sites: "Sedes / sitios", clients: "Clientes",
                          services: "Servicios", products: "Productos",
                          projects: "Proyectos activos", work_orders: "Órdenes de trabajo / mes",
                          documents: "Documentos", portal_users: "Usuarios externos / portal",
                        }).map(([key, label]) => (
                          <div key={key}>
                            <label className="mb-1 block text-xs font-bold text-slate-700">{label}</label>
                            <input
                              type="number"
                              value={form.limits[key] ?? 0}
                              onChange={(e) => handleNestedChange("limits", key, Number(e.target.value))}
                              className="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-xs text-slate-900 focus:outline-none"
                            />
                          </div>
                        ))}
                      </div>
                      <p className="text-[11px] text-slate-400 italic">Usa -1 para representar &quot;ilimitado&quot;.</p>
                    </div>

                    <div className="bg-white p-6 rounded-2xl border border-slate-200 shadow-2xs space-y-4">
                      <h3 className="text-xs font-extrabold uppercase tracking-wider text-slate-500">Límites por módulo</h3>
                      <div className="grid grid-cols-2 gap-5">
                        {Object.entries({
                          timesheet_hours: "Horas de Timesheets / mes", project_users: "Usuarios por proyecto",
                          crm_leads: "Leads CRM", crm_opportunities: "Oportunidades CRM",
                        }).map(([key, label]) => (
                          <div key={key}>
                            <label className="mb-1 block text-xs font-bold text-slate-700">{label}</label>
                            <input
                              type="number"
                              value={form.limits[key] ?? 0}
                              onChange={(e) => handleNestedChange("limits", key, Number(e.target.value))}
                              className="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-xs text-slate-900 focus:outline-none"
                            />
                          </div>
                        ))}
                      </div>
                    </div>
                  </div>
                )}

                {/* 5. Consumo */}
                {activeTabIdx === 4 && (
                  <div className="bg-white p-6 rounded-2xl border border-slate-200 shadow-2xs space-y-4">
                    <h3 className="text-xs font-extrabold uppercase tracking-wider text-slate-500">Consumo SaaS</h3>
                    <div className="grid grid-cols-2 gap-5">
                      <div>
                        <label className="mb-1.5 block text-xs font-bold text-slate-700">Almacenamiento (GB)</label>
                        <input
                          type="number"
                          value={form.consumption.storage_gb}
                          onChange={(e) => handleNestedChange("consumption", "storage_gb", Number(e.target.value))}
                          className="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-xs text-slate-900 focus:outline-none"
                        />
                      </div>
                      <div>
                        <label className="mb-1.5 block text-xs font-bold text-slate-700">Archivos</label>
                        <input
                          type="number"
                          value={form.consumption.files_count}
                          onChange={(e) => handleNestedChange("consumption", "files_count", Number(e.target.value))}
                          className="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-xs text-slate-900 focus:outline-none"
                        />
                      </div>
                      <div>
                        <label className="mb-1.5 block text-xs font-bold text-slate-700">Documentos generados</label>
                        <input
                          type="number"
                          value={form.consumption.documents_generated}
                          onChange={(e) => handleNestedChange("consumption", "documents_generated", Number(e.target.value))}
                          className="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-xs text-slate-900 focus:outline-none"
                        />
                      </div>
                      <div>
                        <label className="mb-1.5 block text-xs font-bold text-slate-700">Exportaciones / mes</label>
                        <input
                          type="number"
                          value={form.consumption.exports_month}
                          onChange={(e) => handleNestedChange("consumption", "exports_month", Number(e.target.value))}
                          className="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-xs text-slate-900 focus:outline-none"
                        />
                      </div>
                      <div>
                        <label className="mb-1.5 block text-xs font-bold text-slate-700">Solicitudes API / mes</label>
                        <input
                          type="number"
                          value={form.consumption.api_requests_month}
                          onChange={(e) => handleNestedChange("consumption", "api_requests_month", Number(e.target.value))}
                          className="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-xs text-slate-900 focus:outline-none"
                        />
                      </div>
                      <div>
                        <label className="mb-1.5 block text-xs font-bold text-slate-700">Backups incluidos</label>
                        <input
                          type="number"
                          value={form.consumption.backups_included}
                          onChange={(e) => handleNestedChange("consumption", "backups_included", Number(e.target.value))}
                          className="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-xs text-slate-900 focus:outline-none"
                        />
                      </div>
                    </div>
                  </div>
                )}

                {/* 6. Soporte */}
                {activeTabIdx === 5 && (
                  <div className="bg-white p-6 rounded-2xl border border-slate-200 shadow-2xs space-y-4">
                    <h3 className="text-xs font-extrabold uppercase tracking-wider text-slate-500">Nivel de soporte</h3>
                    <div className="grid grid-cols-3 gap-5">
                      <div>
                        <label className="mb-1.5 block text-xs font-bold text-slate-700">Nivel</label>
                        <select
                          value={form.support_level}
                          onChange={(e) => handleChange("support_level", e.target.value)}
                          className="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-xs text-slate-900 focus:outline-none"
                        >
                          <option value="BASIC">Básico</option>
                          <option value="STANDARD">Estándar</option>
                          <option value="PREMIUM">Premium</option>
                          <option value="DEDICATED">Dedicado</option>
                        </select>
                      </div>
                      <div>
                        <label className="mb-1.5 block text-xs font-bold text-slate-700">Tickets / mes</label>
                        <input
                          type="number"
                          value={form.support_tickets_month}
                          onChange={(e) => handleChange("support_tickets_month", Number(e.target.value))}
                          className="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-xs text-slate-900 focus:outline-none"
                        />
                      </div>
                      <div>
                        <label className="mb-1.5 block text-xs font-bold text-slate-700">SLA inicial (horas)</label>
                        <input
                          type="number"
                          value={form.support_sla_hours}
                          onChange={(e) => handleChange("support_sla_hours", Number(e.target.value))}
                          className="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-xs text-slate-900 focus:outline-none"
                        />
                      </div>
                    </div>

                    <h4 className="text-xs font-bold text-slate-700 pt-3">Canales incluidos</h4>
                    <div className="grid grid-cols-2 gap-3">
                      {Object.entries({
                        portal: "Portal de soporte", email: "Correo electrónico",
                        whatsapp: "WhatsApp", priority_attention: "Atención prioritaria",
                      }).map(([key, label]) => {
                        const checked = Boolean(form.support_channels[key]);
                        return (
                          <label key={key} className="flex items-center gap-3 p-3 rounded-xl border border-slate-200 cursor-pointer hover:bg-slate-50">
                            <input
                              type="checkbox"
                              checked={checked}
                              onChange={(e) => handleNestedChange("support_channels", key, e.target.checked)}
                              className="h-4 w-4 rounded border-slate-300 text-[var(--app-brand-deep)] cursor-pointer"
                            />
                            <span className="text-xs font-bold text-slate-800">{label}</span>
                          </label>
                        );
                      })}
                    </div>
                  </div>
                )}

                {/* 7. Características */}
                {activeTabIdx === 6 && (
                  <div className="space-y-6">
                    <div className="bg-white p-6 rounded-2xl border border-slate-200 shadow-2xs space-y-4">
                      <h3 className="text-xs font-extrabold uppercase tracking-wider text-slate-500">Características incluidas</h3>
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
                            <label key={key} className="flex items-center gap-3 p-3 rounded-xl border border-slate-200 cursor-pointer hover:bg-slate-50">
                              <input
                                type="checkbox"
                                checked={checked}
                                onChange={(e) => handleNestedChange("features", key, e.target.checked)}
                                className="h-4 w-4 rounded border-slate-300 text-[var(--app-brand-deep)] cursor-pointer"
                              />
                              <span className="text-xs font-bold text-slate-800">{label}</span>
                            </label>
                          );
                        })}
                      </div>
                    </div>

                    {/* Vista previa del catálogo */}
                    <div className="bg-white p-6 rounded-2xl border border-slate-200 shadow-2xs space-y-4">
                      <h3 className="text-xs font-extrabold uppercase tracking-wider text-slate-500">Vista previa del catálogo</h3>
                      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div className="rounded-2xl border border-slate-200 p-5 space-y-3 bg-slate-50/50">
                          <span className="text-xs font-bold text-slate-800 block">{form.name || "Nombre del plan"}</span>
                          <div>
                            <span className="text-2xl font-extrabold text-slate-900">
                              {form.currency === "USD" ? "$" : "S/"} {form.monthly_price || 0}
                            </span>
                            <span className="text-xs text-slate-500"> / mes</span>
                          </div>
                          <p className="text-xs text-slate-500">{form.has_trial ? `${form.trial_days || 30} días gratis` : "Sin periodo de prueba"}</p>
                          <div className="w-full bg-blue-600 text-white text-center py-2 rounded-xl text-xs font-bold shadow-sm">
                            Comenzar prueba
                          </div>
                        </div>

                        <div className="rounded-2xl border border-slate-200 p-5 space-y-2 bg-slate-50/50">
                          <span className="text-xs font-bold text-slate-800 block">Incluye</span>
                          <p className="text-xs text-slate-600">✓ Módulos seleccionados</p>
                          <p className="text-xs text-slate-600">✓ Límites configurados</p>
                          <p className="text-xs text-slate-600">✓ Soporte según plan</p>
                        </div>

                        <div className="rounded-2xl border border-slate-200 p-5 space-y-2 bg-slate-50/50">
                          <span className="text-xs font-bold text-slate-800 block">Administración</span>
                          <p className="text-xs text-slate-600 leading-relaxed">El plan determina las capacidades contratadas.</p>
                          <p className="text-xs text-slate-600 leading-relaxed">Los permisos de cada usuario se gestionan por roles.</p>
                        </div>
                      </div>
                    </div>
                  </div>
                )}
              </div>

              {/* Footer */}
              <div className="shrink-0 border-t border-slate-200 bg-slate-50 px-8 py-4 flex items-center justify-end gap-3">
                <button
                  type="button"
                  onClick={onClose}
                  className="rounded-xl border border-slate-300 bg-white px-5 py-2.5 text-xs font-bold text-slate-700 hover:bg-slate-100 transition-colors cursor-pointer"
                >
                  Cancelar
                </button>
                <button
                  type="button"
                  disabled={isSaving}
                  onClick={handleSubmit}
                  className="rounded-xl bg-[var(--app-brand-deep)] px-6 py-2.5 text-xs font-bold text-white shadow-sm transition-colors hover:bg-[var(--app-brand)] disabled:opacity-50 flex items-center gap-2 cursor-pointer"
                >
                  {isSaving ? <Loader2 size={14} className="animate-spin" /> : planToEdit ? "Actualizar plan" : "Guardar plan"}
                </button>
              </div>
            </motion.div>
          </div>
        </div>
  );
}
