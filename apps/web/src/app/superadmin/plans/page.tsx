"use client";

import { useState, useEffect } from "react";
import { apiFetch } from "@/lib/api";
import { Plus, Edit, Trash2, Sparkles, Loader2 } from "lucide-react";

export default function SuperAdminPlansPage() {
  const [plans, setPlans] = useState<any[]>([]);
  const [metrics, setMetrics] = useState<any>({});
  const [loading, setLoading] = useState(true);
  const [searchTerm, setSearchTerm] = useState("");
  const [statusFilter, setStatusFilter] = useState("all");

  useEffect(() => {
    async function fetchData() {
      try {
        const [plansData, metricsData]: [any, any] = await Promise.all([
          apiFetch("/v1/superadmin/plans"),
          apiFetch("/v1/superadmin/dashboard/metrics"),
        ]);
        setPlans(plansData);
        setMetrics(metricsData);
      } catch (err) {
        console.error("Error al cargar datos del panel de superadmin", err);
      } finally {
        setLoading(false);
      }
    }
    fetchData();
  }, []);

  // Filtrado de planes según búsqueda y estado
  const filteredPlans = plans.filter((plan) => {
    const matchesSearch = plan.name.toLowerCase().includes(searchTerm.toLowerCase()) || 
                          (plan.description && plan.description.toLowerCase().includes(searchTerm.toLowerCase()));
    
    if (statusFilter === "active") return matchesSearch && plan.active;
    if (statusFilter === "inactive") return matchesSearch && !plan.active;
    return matchesSearch;
  });

  if (loading) {
    return (
      <div className="flex justify-center items-center h-[70vh]">
        <Loader2 size={36} className="animate-spin text-[var(--app-brand-deep)]" />
      </div>
    );
  }

  return (
    <div className="p-8 space-y-8 max-w-7xl mx-auto">
      {/* Cabecera */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-extrabold text-slate-900">Planes SaaS</h1>
          <p className="text-xs text-slate-500">Configura los planes comerciales, módulos y límites de tu ERP multi-tenant.</p>
        </div>
        <button 
          onClick={() => alert("Aquí abrirás tu modal para crear un nuevo plan")}
          className="flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl bg-[var(--app-brand-deep)] text-white text-xs font-bold hover:bg-[var(--app-brand)] cursor-pointer shadow-sm transition-all"
        >
          <Plus size={16} /> Nuevo plan
        </button>
      </div>

      {/* Tarjetas de Métricas Superiores */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div className="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm space-y-1">
          <span className="text-xs font-semibold text-slate-500">Planes activos</span>
          <div className="text-3xl font-extrabold text-slate-900">{metrics.active_plans ?? 0}</div>
        </div>
        <div className="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm space-y-1">
          <span className="text-xs font-semibold text-slate-500">Tenants activos</span>
          <div className="text-3xl font-extrabold text-slate-900">{metrics.active_tenants ?? 0}</div>
        </div>
        <div className="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm space-y-1">
          <span className="text-xs font-semibold text-slate-500">En prueba</span>
          <div className="text-3xl font-extrabold text-slate-900">{metrics.in_trial ?? 0}</div>
        </div>
        <div className="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm space-y-1">
          <span className="text-xs font-semibold text-slate-500">Ingresos mensuales</span>
          <div className="text-3xl font-extrabold text-slate-900">
            S/ {(metrics.monthly_revenue ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
          </div>
        </div>
      </div>

      {/* Filtros de Búsqueda y Estado */}
      <div className="flex flex-col sm:flex-row gap-4 items-center justify-between bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
        <input
          type="text"
          placeholder="Buscar plan..."
          value={searchTerm}
          onChange={(e) => setSearchTerm(e.target.value)}
          className="w-full sm:w-72 rounded-xl border border-slate-300 px-3.5 py-2 text-xs focus:outline-none focus:border-[var(--app-brand)]"
        />
        <select
          value={statusFilter}
          onChange={(e) => setStatusFilter(e.target.value)}
          className="w-full sm:w-48 rounded-xl border border-slate-300 px-3.5 py-2 text-xs bg-white focus:outline-none focus:border-[var(--app-brand)]"
        >
          <option value="all">Todos los estados</option>
          <option value="active">Activos</option>
          <option value="inactive">Inactivos</option>
        </select>
      </div>

      {/* Tabla de Planes */}
      <div className="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse text-xs">
            <thead>
              <tr className="border-b border-slate-100 bg-slate-50/75 text-slate-500 uppercase tracking-wider">
                <th className="p-4 font-bold">Plan</th>
                <th className="p-4 font-bold">Precio Mensual</th>
                <th className="p-4 font-bold">Precio Anual</th>
                <th className="p-4 font-bold">Módulos</th>
                <th className="p-4 font-bold">Prueba</th>
                <th className="p-4 font-bold">Estado</th>
                <th className="p-4 font-bold text-right">Acciones</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {filteredPlans.length > 0 ? (
                filteredPlans.map((plan) => {
                  const modulesCount = plan.modules ? Object.keys(plan.modules).length : 0;
                  
                  return (
                    <tr key={plan.id} className="hover:bg-slate-50/50 transition-colors">
                      <td className="p-4">
                        <strong className="text-slate-900 text-sm block">{plan.name}</strong>
                        <span className="text-slate-400">{plan.description || "Sin descripción"}</span>
                      </td>
                      <td className="p-4 font-semibold text-slate-700">
                        {plan.monthly_price !== null ? `S/ ${Number(plan.monthly_price).toFixed(2)}` : "Personalizado"}
                      </td>
                      <td className="p-4 font-semibold text-slate-700">
                        {plan.annual_price !== null ? `S/ ${Number(plan.annual_price).toFixed(2)}` : "Personalizado"}
                      </td>
                      <td className="p-4 font-semibold text-slate-700">
                        {modulesCount > 0 ? `${modulesCount} módulos` : "Todos"}
                      </td>
                      <td className="p-4">
                        {plan.has_trial ? (
                          <span className="bg-blue-50 text-[var(--app-brand-deep)] px-2.5 py-1 rounded-full font-bold border border-blue-100">
                            {plan.trial_days || 30} días
                          </span>
                        ) : (
                          <span className="text-slate-400 font-medium">No</span>
                        )}
                      </td>
                      <td className="p-4">
                        <span className={`px-2.5 py-1 rounded-full font-bold ${plan.active ? "bg-emerald-50 text-emerald-600 border border-emerald-100" : "bg-rose-50 text-rose-600 border border-rose-100"}`}>
                          {plan.active ? "Activo" : "Inactivo"}
                        </span>
                      </td>
                      <td className="p-4 text-right space-x-3">
                        <button 
                          onClick={() => alert(`Editar plan: ${plan.name}`)}
                          className="text-[var(--app-brand-deep)] hover:underline font-bold inline-flex items-center gap-1 cursor-pointer"
                        >
                          <Edit size={13} /> Editar
                        </button>
                      </td>
                    </tr>
                  );
                })
              ) : (
                <tr>
                  <td colSpan={7} className="p-8 text-center text-slate-400">
                    No se encontraron planes registrados.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}