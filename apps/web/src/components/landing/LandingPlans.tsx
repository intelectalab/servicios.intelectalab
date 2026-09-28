"use client";

import { useState } from "react";
import { Check, Loader2, Sparkles, Gift } from "lucide-react";

interface SaasPlan {
  id: string;
  code: string;
  name: string;
  description?: string;
  monthly_price?: number;
  annual_price?: number;
  currency?: string;
  has_trial?: boolean;
  trial_days?: number;
  requires_payment_upfront?: boolean;
  active: boolean;
  limits: Record<string, any>;
  modules: Record<string, any>;
  features?: Record<string, any>;
}

interface LandingPlansProps {
  plans: SaasPlan[];
  loading: boolean;
  onSelectPlan: (plan: SaasPlan, billingPeriod: "MONTHLY" | "ANNUAL", isTrial?: boolean) => void;
}

export function LandingPlans({ plans, loading, onSelectPlan }: LandingPlansProps) {
  const [billingPeriod, setBillingPeriod] = useState<"MONTHLY" | "ANNUAL">("MONTHLY");

  if (loading) {
    return (
      <div className="flex justify-center py-12">
        <Loader2 size={32} className="animate-spin text-[var(--app-brand-deep)]" />
      </div>
    );
  }

  const getCurrencySymbol = (currency?: string) => {
    switch (currency?.toUpperCase()) {
      case "USD":
        return "$";
      case "PEN":
      default:
        return "S/";
    }
  };

  return (
    <div className="space-y-10">
      {/* Selector de periodo de pago (Switch) */}
      <div className="flex justify-center">
        <div className="inline-flex rounded-2xl bg-slate-200/70 m-2 p-1.5 border border-slate-300/60 shadow-inner">
          <button
            onClick={() => setBillingPeriod("MONTHLY")}
            className={`flex flex-col items-center px-6 py-2.5 rounded-xl text-xs font-bold transition-all cursor-pointer ${
              billingPeriod === "MONTHLY" ? "bg-white text-slate-900 shadow-sm" : "text-slate-600 hover:text-slate-900"
            }`}
          >
            <span>Pago mensual</span>
            <span className="text-[10px] font-normal text-slate-500 mt-0.5">Compromiso mensual</span>
          </button>
          <button
            onClick={() => setBillingPeriod("ANNUAL")}
            className={`flex flex-col items-center px-6 py-2.5 rounded-xl text-xs font-bold transition-all cursor-pointer ${
              billingPeriod === "ANNUAL" ? "bg-white text-slate-900 shadow-sm" : "text-slate-600 hover:text-slate-900"
            }`}
          >
            <span className="flex items-center gap-1.5">
              Pago anual <span className="text-[9px] bg-emerald-100 text-emerald-700 px-1.5 py-0.5 rounded-full font-extrabold uppercase">Mejor valor</span>
            </span>
            <span className="text-[10px] font-normal text-emerald-600 mt-0.5">Ahorra con facturación anual</span>
          </button>
        </div>
      </div>

      {/* Grid de Planes */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-8">
        {plans.map((plan, index) => {
          const isFeatured = index === 1;
          const rawPrice = billingPeriod === "ANNUAL" ? plan.annual_price : plan.monthly_price;
          const priceNumber = rawPrice !== null && rawPrice !== undefined ? Number(rawPrice) : (billingPeriod === "ANNUAL" ? 990 : 99);
          const symbol = getCurrencySymbol(plan.currency);
          const priceFormatted = `${symbol} ${priceNumber.toFixed(2)}`;

          return (
            <div
              key={plan.id}
              className={`relative bg-white rounded-3xl p-8 border transition-all duration-300 shadow-sm flex flex-col justify-between hover:shadow-xl ${
                isFeatured ? "border-2 border-[var(--app-brand-deep)] shadow-lg scale-105 md:-translate-y-2" : "border-slate-200"
              }`}
            >
              {isFeatured && (
                <span className="absolute -top-3.5 right-6 bg-blue-50 text-[var(--app-brand-deep)] text-xs font-extrabold px-3 py-1 rounded-full border border-blue-100 flex items-center gap-1 shadow-2xs">
                  <Sparkles size={12} /> Más popular
                </span>
              )}

              <div className="space-y-4">
                <div>
                  <div className="flex items-center justify-between">
                    <h3 className="text-xl font-bold text-slate-900">{plan.name}</h3>
                    <span className="text-[10px] font-mono font-bold px-2 py-0.5 bg-slate-100 text-slate-600 rounded-md">
                      {plan.currency || "PEN"}
                    </span>
                  </div>
                  <p className="text-xs text-slate-500 mt-1 min-h-[32px]">
                    {plan.description || "Para empresas en constante crecimiento y optimización."}
                  </p>
                </div>

                <div className="py-2">
                  <div className="flex items-baseline gap-1">
                    <span className="text-4xl font-extrabold tracking-tight text-slate-900">{priceFormatted}</span>
                    <span className="text-sm font-medium text-slate-500">
                      {billingPeriod === "ANNUAL" ? "/ año" : "/ mes"}
                    </span>
                  </div>
                </div>

                <div className="pt-4 border-t border-slate-100 space-y-3">
                  <p className="text-xs font-bold text-slate-700 uppercase tracking-wider">Módulos y características:</p>
                  <ul className="space-y-2.5 text-xs text-slate-600">
                    {plan.modules && Object.entries(plan.modules).map(([mod, enabled]) => (
                      enabled && (
                        <li key={mod} className="flex items-center gap-2">
                          <Check size={14} className="text-emerald-500 shrink-0 font-bold" />
                          <span className="capitalize">{mod.replace("_", " ")}</span>
                        </li>
                      )
                    ))}
                    <li className="flex items-center gap-2">
                      <Check size={14} className="text-emerald-500 shrink-0 font-bold" />
                      <span>Hasta {plan.limits?.max_users || (isFeatured ? "10" : "3")} usuarios</span>
                    </li>
                  </ul>
                </div>
              </div>

              {/* Botones de Acción: Elegir Plan + Botón Extra de Prueba Gratis */}
              <div className="pt-8 space-y-3">
                <button
                  onClick={() => onSelectPlan(plan, billingPeriod, false)}
                  className={`w-full py-3 rounded-xl text-xs font-bold transition-all cursor-pointer ${
                    isFeatured
                      ? "bg-[var(--app-brand-deep)] text-white hover:bg-[var(--app-brand)] shadow-md shadow-blue-500/20"
                      : "border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 hover:border-slate-400"
                  }`}
                >
                  Elegir plan
                </button>

                {plan.has_trial && (
                  <button
                    onClick={() => onSelectPlan(plan, billingPeriod, true)}
                    className="w-full py-2.5 rounded-xl text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100 transition-all cursor-pointer flex items-center justify-center gap-1.5 shadow-2xs"
                  >
                    <Gift size={14} /> Prueba gratis de {plan.trial_days || 30} días
                  </button>
                )}
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}