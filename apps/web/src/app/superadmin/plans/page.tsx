"use client";

import { useState, useEffect, useMemo, useRef } from "react";
import { apiFetch } from "@/lib/api";
import { Plus, Search, X, Loader2, RefreshCw, AlertCircle, CreditCard, Users, Clock, DollarSign } from "lucide-react";
import { SaasPlanTable } from "@/components/superadmin/plans/SaasPlanTable";
import { SaasPlanDrawer } from "@/components/superadmin/plans/SaasPlanDrawer";

export default function SuperAdminPlansPage() {
  const [plans, setPlans] = useState<any[]>([]);
  const [metrics, setMetrics] = useState<any>({});
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [search, setSearch] = useState("");
  const searchInputRef = useRef<HTMLInputElement>(null);
  const [activeTab, setActiveTab] = useState<string>("all");
  const [refreshTrigger, setRefreshTrigger] = useState(0);

  // Estados para controlar el Drawer (Crear o Editar)
  const [isDrawerOpen, setIsDrawerOpen] = useState(false);
  const [selectedPlanToEdit, setSelectedPlanToEdit] = useState<any | null>(null);

  useEffect(() => {
    let cancelled = false;

    async function fetchData() {
      setLoading(true);
      setError("");
      try {
        const [plansData, metricsData]: [any, any] = await Promise.all([
          apiFetch("/v1/superadmin/plans"),
          apiFetch("/v1/superadmin/dashboard/metrics"),
        ]);
        if (cancelled) return;
        setPlans(plansData);
        setMetrics(metricsData);
      } catch (err) {
        if (cancelled) return;
        setError("No se pudieron cargar los datos del panel de superadmin.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    fetchData();
    return () => { cancelled = true; };
  }, [refreshTrigger]);

  const filteredPlans = useMemo(() => {
    return plans.filter((plan) => {
      const matchesSearch = plan.name.toLowerCase().includes(search.toLowerCase()) || 
                            (plan.description && plan.description.toLowerCase().includes(search.toLowerCase()));
      
      if (activeTab === "active") return matchesSearch && plan.active;
      if (activeTab === "inactive") return matchesSearch && !plan.active;
      return matchesSearch;
    });
  }, [plans, search, activeTab]);

  const TABS = [
    { id: "all", label: "Todos" },
    { id: "active", label: "Activos" },
    { id: "inactive", label: "Inactivos" },
  ];

  return (
    <div className="flex h-full min-h-0 flex-col p-0.1 text-slate-900">
      <div className="flex h-full min-h-0 flex-col overflow-hidden bg-white shadow-sm rounded-tl-[40px] rounded-br-[40px]">

        {/* Cabecera */}
        <div className="flex shrink-0 flex-col gap-4 px-8 pt-8 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <h1 className="text-2xl font-bold tracking-tight">Planes SaaS</h1>
            <p className="mt-1 text-sm text-slate-500">
              Configura los planes comerciales, módulos y límites de tu ERP multi-tenant.
            </p>
          </div>
          <button 
            onClick={() => {
              setSelectedPlanToEdit(null); // Limpiamos para que sea creación nueva
              setIsDrawerOpen(true);
            }}
            className="flex items-center gap-2 rounded-xl bg-[var(--app-brand-deep)] px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-[var(--app-brand)] cursor-pointer"
          >
            <Plus size={16} /> Nuevo plan
          </button>
        </div>

        {/* Métricas */}
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 px-8 pt-6">
          <div className="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm space-y-1">
            <div className="flex items-center justify-between text-slate-500">
              <span className="text-xs font-semibold">Planes activos</span>
              <CreditCard size={18} className="text-[var(--app-brand-deep)]" />
            </div>
            <div className="text-3xl font-extrabold text-slate-900">{metrics.active_plans ?? 0}</div>
          </div>
          <div className="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm space-y-1">
            <div className="flex items-center justify-between text-slate-500">
              <span className="text-xs font-semibold">Tenants activos</span>
              <Users size={18} className="text-emerald-600" />
            </div>
            <div className="text-3xl font-extrabold text-slate-900">{metrics.active_tenants ?? 0}</div>
          </div>
          <div className="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm space-y-1">
            <div className="flex items-center justify-between text-slate-500">
              <span className="text-xs font-semibold">En prueba</span>
              <Clock size={18} className="text-blue-500" />
            </div>
            <div className="text-3xl font-extrabold text-slate-900">{metrics.in_trial ?? 0}</div>
          </div>
          <div className="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm space-y-1">
            <div className="flex items-center justify-between text-slate-500">
              <span className="text-xs font-semibold">Ingresos mensuales</span>
              <DollarSign size={18} className="text-emerald-500" />
            </div>
            <div className="text-3xl font-extrabold text-slate-900">
              S/ {(metrics.monthly_revenue ?? 0).toLocaleString("es-PE", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
            </div>
          </div>
        </div>

        {/* Tabs */}
        <div className="mt-6 flex shrink-0 gap-6 border-b border-slate-200 px-8">
          {TABS.map((tab) => (
            <button
              key={tab.id}
              onClick={() => setActiveTab(tab.id)}
              className={`border-b-2 pb-3 text-sm font-medium transition-colors cursor-pointer ${
                activeTab === tab.id
                  ? "border-[var(--app-brand-deep)] text-[var(--app-brand-deep)] font-semibold"
                  : "border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700"
              }`}
            >
              {tab.label}
            </button>
          ))}
        </div>

        {/* Buscador */}
        <div className="flex shrink-0 flex-col border-b border-slate-200 bg-white px-8 py-4 z-20">
          <div className="relative w-full max-w-sm">
            <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <input
              type="text"
              placeholder="Buscar plan por nombre..."
              ref={searchInputRef}
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="w-full rounded-md border border-slate-300 bg-white py-1.5 pl-9 pr-9 text-sm transition-colors focus:border-[var(--app-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--app-brand)]"
            />
            {search && (
              <button
                onClick={() => setSearch("")}
                className="absolute right-2.5 top-1/2 -translate-y-1/2 rounded-full p-0.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 focus:outline-none cursor-pointer"
                aria-label="Limpiar búsqueda"
              >
                <X size={14} />
              </button>
            )}
          </div>
        </div>

        {/* Tabla */}
        <div className="flex-1 min-h-0 bg-white px-2">
          <div className="h-full min-w-0 overflow-auto">
            {error ? (
              <div className="flex flex-col items-center justify-center py-20 text-center">
                <AlertCircle size={32} className="mb-3 text-red-500" />
                <p className="text-sm text-slate-600">{error}</p>
                <button
                  onClick={() => setRefreshTrigger(prev => prev + 1)}
                  className="mt-4 flex items-center gap-2 text-sm font-medium text-[var(--app-brand-deep)] hover:underline cursor-pointer"
                >
                  <RefreshCw size={14} /> Reintentar
                </button>
              </div>
            ) : loading ? (
              <div className="divide-y divide-slate-100 px-6 py-4 space-y-4">
                {Array.from({ length: 3 }).map((_, i) => (
                  <div key={i} className="flex items-center gap-4 py-4">
                    <div className="h-4 w-4 animate-pulse rounded bg-slate-200" />
                    <div className="flex-1 space-y-2">
                      <div className="h-4 w-48 animate-pulse rounded bg-slate-200" />
                      <div className="h-3 w-24 animate-pulse rounded bg-slate-200" />
                    </div>
                  </div>
                ))}
              </div>
            ) : (
              <SaasPlanTable 
                plans={filteredPlans} 
                onEditPlan={(plan) => {
                  setSelectedPlanToEdit(plan); // Asignamos el plan a editar
                  setIsDrawerOpen(true);        // Abrimos el drawer
                }} 
              />
            )}
          </div>
        </div>

        {/* Drawer Lateral para Crear / Editar Plan */}
        <SaasPlanDrawer
          isOpen={isDrawerOpen}
          onClose={() => {
            setIsDrawerOpen(false);
            setSelectedPlanToEdit(null);
          }}
          onSuccess={() => setRefreshTrigger(prev => prev + 1)}
          planToEdit={selectedPlanToEdit}
        />

      </div>
    </div>
  );
}