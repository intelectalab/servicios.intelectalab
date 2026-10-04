"use client";

import { motion } from "framer-motion";
import { Edit, Sparkles } from "lucide-react";

interface Plan {
  id: number | string;
  name: string;
  description?: string;
  monthly_price?: number | null;
  annual_price?: number | null;
  has_trial?: boolean;
  trial_days?: number;
  active?: boolean;
  modules?: Record<string, any>;
}

interface SaasPlanTableProps {
  plans: Plan[];
  onEditPlan: (plan: Plan) => void;
}

export function SaasPlanTable({ plans, onEditPlan }: SaasPlanTableProps) {
  return (
    <div className="relative w-full">
      <table className="w-full border-separate border-spacing-0 text-left text-sm">
        <thead className="sticky top-0 z-50 bg-slate-50">
          <tr className="text-xs font-semibold uppercase tracking-wider text-slate-500 border-b border-slate-200">
            <th className="px-6 py-3.5">Plan</th>
            <th className="px-6 py-3.5">Precio Mensual</th>
            <th className="px-6 py-3.5">Precio Anual</th>
            <th className="px-6 py-3.5">Módulos</th>
            <th className="px-6 py-3.5">Prueba</th>
            <th className="px-6 py-3.5">Estado</th>
            <th className="px-6 py-3.5 text-right">Acciones</th>
          </tr>
        </thead>
        <tbody className="bg-white divide-y divide-slate-100">
          {plans.length > 0 ? (
            plans.map((plan) => {
              const modulesCount = plan.modules ? Object.keys(plan.modules).length : 0;

              return (
                <motion.tr
                  key={plan.id}
                  initial={{ opacity: 0 }}
                  animate={{ opacity: 1 }}
                  className="group transition-colors hover:bg-slate-50/75"
                >
                  <td className="px-6 py-4">
                    <strong className="text-slate-900 text-sm block">{plan.name}</strong>
                    <span className="text-xs text-slate-400">{plan.description || "Sin descripción"}</span>
                  </td>
                  <td className="px-6 py-4 font-semibold text-slate-700 text-xs">
                    {plan.monthly_price !== null && plan.monthly_price !== undefined 
                      ? `S/ ${Number(plan.monthly_price).toFixed(2)}` 
                      : "Personalizado"}
                  </td>
                  <td className="px-6 py-4 font-semibold text-slate-700 text-xs">
                    {plan.annual_price !== null && plan.annual_price !== undefined 
                      ? `S/ ${Number(plan.annual_price).toFixed(2)}` 
                      : "Personalizado"}
                  </td>
                  <td className="px-6 py-4 font-semibold text-slate-700 text-xs">
                    {modulesCount > 0 ? `${modulesCount} módulos` : "Todos"}
                  </td>
                  <td className="px-6 py-4 text-xs">
                    {plan.has_trial ? (
                      <span className="bg-blue-50 text-[var(--app-brand-deep)] px-2.5 py-1 rounded-full font-bold border border-blue-100">
                        {plan.trial_days || 30} días
                      </span>
                    ) : (
                      <span className="text-slate-400 font-medium">No</span>
                    )}
                  </td>
                  <td className="px-6 py-4 text-xs">
                    <span className={`px-2.5 py-1 rounded-full font-bold ${plan.active ? "bg-emerald-50 text-emerald-600 border border-emerald-100" : "bg-rose-50 text-rose-600 border border-rose-100"}`}>
                      {plan.active ? "Activo" : "Inactivo"}
                    </span>
                  </td>
                  <td className="px-6 py-4 text-right">
                    <button 
                      onClick={() => onEditPlan(plan)}
                      className="text-[var(--app-brand-deep)] hover:underline font-bold inline-flex items-center gap-1.5 text-xs cursor-pointer bg-blue-50 hover:bg-blue-100 px-3 py-1.5 rounded-lg transition-colors"
                    >
                      <Edit size={13} /> Editar
                    </button>
                  </td>
                </motion.tr>
              );
            })
          ) : (
            <tr>
              <td colSpan={7} className="px-6 py-12 text-center text-slate-400 text-sm">
                No se encontraron planes registrados.
              </td>
            </tr>
          )}
        </tbody>
      </table>
    </div>
  );
}