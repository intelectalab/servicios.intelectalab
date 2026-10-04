"use client";

import { useState, useEffect } from "react";
import { useRouter } from "next/navigation";
import { Zap, ArrowRight, TrendingUp, Gift } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { LandingPlans } from "@/components/landing/LandingPlans";
import { TenantRegisterModal } from "@/components/landing/TenantRegisterModal";
import type { BillingPeriod, SaasPlan, SelectedPlan } from "@/types/saas-plan";

export default function MarketingPage() {
  const router = useRouter();
  const [plans, setPlans] = useState<SaasPlan[]>([]);
  const [loadingPlans, setLoadingPlans] = useState(true);
  const [isRegisterOpen, setIsRegisterOpen] = useState(false);
  const [selectedPlan, setSelectedPlan] = useState<SelectedPlan | null>(null);

  useEffect(() => {
    async function fetchPlans() {
      try {
        const data = await apiFetch<SaasPlan[]>("/v1/saas-plans");
        setPlans(data);
      } catch (err) {
        console.error("No se pudieron cargar los planes", err);
      } finally {
        setLoadingPlans(false);
      }
    }
    fetchPlans();
  }, []);

  const handleOpenRegister = (plan: SaasPlan | null = null, billingPeriod: BillingPeriod = "MONTHLY", isTrial: boolean = false) => {
    // Si no viene plan (ej. clic en "Probar 30 días gratis"), seleccionamos por defecto el primer plan (Plan Base)
    const targetPlan = plan || plans[0] || null;
    setSelectedPlan({ ...targetPlan, billingPeriod, isTrial });
    setIsRegisterOpen(true);
  };

  const scrollToPlans = () => {
    const element = document.getElementById("plans");
    element?.scrollIntoView({ behavior: "smooth" });
  };

  return (
    <div className="space-y-12 pb-16">
      {/* Banner Superior de Prueba Gratis */}
      <div className="bg-blue-50/80 border-b border-blue-100 py-2.5 px-4 text-center text-xs font-semibold text-[var(--app-brand-deep)] flex items-center justify-center gap-2">
        <span><Gift size={16} /></span>
        <span>Prueba gratis durante 30 días · Sin compromiso</span>
      </div>

      {/* Hero Section Principal */}
      <section className="pt-6 px-8">
        <div className="max-w-7xl mx-auto grid grid-cols-1 lg:grid-cols-12 gap-12 items-center">
          
          <div className="lg:col-span-7 space-y-6">
            <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-blue-50 text-[var(--app-brand-deep)] text-xs font-bold tracking-wide uppercase">
              <Zap size={13} /> Plataforma Empresarial SaaS
            </span>
            <h1 className="text-4xl sm:text-6xl font-extrabold tracking-tight text-slate-900 leading-[1.1]">
              Gestiona tu empresa desde una sola plataforma.
            </h1>
            <p className="text-base text-slate-600 max-w-xl leading-relaxed">
              Selecciona el plan que necesitas, registra tu empresa y accede a los módulos habilitados para tu suscripción.
            </p>
            <div className="flex flex-wrap items-center gap-4 pt-2">
              <button
                onClick={() => handleOpenRegister(null, "MONTHLY", true)}
                className="flex items-center gap-2 px-6 py-3.5 text-sm font-bold rounded-xl bg-[var(--app-brand-deep)] text-white shadow-lg shadow-blue-500/20 hover:bg-[var(--app-brand)] transition-all cursor-pointer"
              >
                Probar 30 días gratis <ArrowRight size={16} />
              </button>
              <button
                onClick={scrollToPlans}
                className="px-6 py-3.5 text-sm font-semibold rounded-xl border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 transition-all cursor-pointer shadow-2xs"
              >
                Ver planes
              </button>
              <button
                onClick={() => router.push("/login")}
                className="text-sm font-semibold text-slate-600 hover:text-[var(--app-brand-deep)] transition-colors cursor-pointer ml-1"
              >
                Ya tengo una cuenta
              </button>
            </div>
          </div>

          {/* Panel Visual / Mockup del ERP (derecha) */}
          <div className="lg:col-span-5">
            <div className="bg-white rounded-3xl p-6 border border-slate-200/80 shadow-2xl space-y-6">
              <div className="flex items-center gap-1.5 pb-2 border-b border-slate-100">
                <div className="w-3 h-3 rounded-full bg-rose-400"></div>
                <div className="w-3 h-3 rounded-full bg-amber-400"></div>
                <div className="w-3 h-3 rounded-full bg-emerald-400"></div>
              </div>

              <div className="p-5 rounded-2xl bg-slate-50 border border-slate-100 space-y-1">
                <p className="text-xs font-semibold text-slate-500">Ventas del mes</p>
                <div className="text-3xl font-extrabold text-slate-900 tracking-tight">S/ 48,250</div>
                <div className="flex items-center gap-1 text-[11px] font-bold text-emerald-600 pt-1">
                  <TrendingUp size={13} /> +12.8% respecto al mes anterior
                </div>
              </div>

              <div className="p-4 rounded-2xl bg-slate-50 border border-slate-100 space-y-2">
                <p className="text-xs font-semibold text-slate-500">Módulos disponibles</p>
                <p className="text-xs font-bold text-slate-800 tracking-wide">
                  Ventas <span className="text-slate-400 font-normal">·</span> Clientes <span className="text-slate-400 font-normal">·</span> Inventario <span className="text-slate-400 font-normal">·</span> CRM
                </p>
              </div>
            </div>
          </div>

        </div>
      </section>

      {/* Sección de Planes */}
      <section id="plans" className="py-8 px-8 bg-slate-100/60 border-y border-slate-200 [scroll-margin-top:80px]">
        <div className="max-w-7xl mx-auto space-y-12">
          <div className="text-center space-y-3">
            <h2 className="text-3xl font-extrabold tracking-tight text-slate-900">Elige el plan para tu empresa</h2>
            <p className="text-sm text-slate-500 max-w-lg mx-auto">Los módulos disponibles dependen del plan contratado.</p>
          </div>
          <LandingPlans plans={plans} loading={loadingPlans} onSelectPlan={handleOpenRegister} />
        </div>
      </section>

      {/* Tarjeta Inferior CTA */}
      <section className="px-8">
        <div className="max-w-7xl mx-auto bg-white rounded-3xl px-8 py-4 sm:p-10 border border-slate-200/80 shadow-lg flex flex-col sm:flex-row items-center justify-between gap-6">
          <div className="space-y-1.5 text-center sm:text-left">
            <h3 className="text-xl font-bold text-slate-900">Empieza con 30 días gratis</h3>
            <p className="text-xs text-slate-500 max-w-md">
              Registra tu empresa, configura tu cuenta y prueba la plataforma antes de contratar un plan.
            </p>
          </div>
          <button
            onClick={() => handleOpenRegister(null, "MONTHLY", true)}
            className="px-6 py-3.5 rounded-xl bg-[var(--app-brand-deep)] text-white text-xs font-bold hover:bg-[var(--app-brand)] shadow-md shadow-blue-500/20 transition-all cursor-pointer shrink-0"
          >
            Crear mi cuenta gratis
          </button>
        </div>
      </section>

      <TenantRegisterModal
        isOpen={isRegisterOpen}
        onClose={() => setIsRegisterOpen(false)}
        selectedPlan={selectedPlan}
      />
    </div>
  );
}