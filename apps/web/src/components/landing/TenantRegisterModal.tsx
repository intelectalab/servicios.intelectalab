"use client";

import { useState } from "react";
import { motion, AnimatePresence } from "framer-motion";
import { X, Check, Loader2, Gift } from "lucide-react";
import { useRouter } from "next/navigation";
import { apiFetch } from "@/lib/api";

interface TenantRegisterModalProps {
  isOpen: boolean;
  onClose: () => void;
  selectedPlan: any;
}

export function TenantRegisterModal({ isOpen, onClose, selectedPlan }: TenantRegisterModalProps) {
  const router = useRouter();
  const [step, setStep] = useState(1);
  
  // Estado del formulario unificado para los pasos
  const [formData, setFormData] = useState({
    ruc: "",
    razon_social: "",
    nombre_comercial: "",
    telefono: "",
    direccion: "",
    departamento: "Lima",
    distrito: "Los Olivos",
    nombres: "",
    apellidos: "",
    email: "",
    celular: "",
    password: "",
    password_confirmation: "",
  });

  const [isSubmitting, setIsSubmitting] = useState(false);
  const [createdTenantCode, setCreatedTenantCode] = useState("");
  const [errorMessage, setErrorMessage] = useState("");

  if (!isOpen) return null;

  // Datos dinámicos del plan seleccionado
  const planName = selectedPlan?.name || "Plan Base";
  const isTrial = selectedPlan?.isTrial ?? false;
  const trialDays = selectedPlan?.trial_days || 30;

  // Cálculo del precio con dos decimales exactos
  const rawPrice = selectedPlan?.billingPeriod === "ANNUAL" 
    ? (selectedPlan?.annual_price ?? 990) 
    : (selectedPlan?.monthly_price ?? 99);
  const priceNumber = Number(rawPrice);
  const priceDisplay = selectedPlan?.billingPeriod === "ANNUAL" 
    ? `S/ ${priceNumber.toFixed(2)} / año` 
    : `S/ ${priceNumber.toFixed(2)} / mes`;

  const handleRegister = async () => {
    if (formData.password !== formData.password_confirmation) {
      setErrorMessage("Las contraseñas no coinciden.");
      return;
    }

    setIsSubmitting(true);
    setErrorMessage("");

    try {
      const response: any = await apiFetch("/v1/tenants/register", {
        method: "POST",
        body: JSON.stringify({
          ...formData,
          plan_id: selectedPlan?.id,
          billing_period: selectedPlan?.billingPeriod || "MONTHLY",
          is_trial: isTrial,
        }),
      });

      setCreatedTenantCode(response.tenant_code || "EMP-22524");
      setStep(3);
    } catch (err: any) {
      setErrorMessage(err.message || "Ocurrió un error al registrar la empresa.");
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <AnimatePresence>
      <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 backdrop-blur-xs p-4">
        <motion.div
          initial={{ opacity: 0, scale: 0.95 }}
          animate={{ opacity: 1, scale: 1 }}
          exit={{ opacity: 0, scale: 0.95 }}
          className="w-full max-w-2xl bg-white rounded-3xl shadow-2xl p-8 space-y-6 text-slate-900 max-h-[90vh] overflow-y-auto"
        >
          {/* Header del Modal */}
          <div className="flex items-center justify-between border-b border-slate-100 pb-4">
            <div>
              <h3 className="text-lg font-bold">Crear cuenta empresarial</h3>
              <p className="text-xs text-slate-500">Completa los datos para activar tu empresa.</p>
            </div>
            <button onClick={onClose} className="rounded-full p-1 text-slate-400 hover:bg-slate-100 cursor-pointer">
              <X size={18} />
            </button>
          </div>

          {/* Barra de Progreso */}
          <div className="grid grid-cols-3 gap-2">
            <div className={`h-1.5 rounded-full ${step >= 1 ? "bg-[var(--app-brand-deep)]" : "bg-slate-200"}`}></div>
            <div className={`h-1.5 rounded-full ${step >= 2 ? "bg-[var(--app-brand-deep)]" : "bg-slate-200"}`}></div>
            <div className={`h-1.5 rounded-full ${step >= 3 ? "bg-[var(--app-brand-deep)]" : "bg-slate-200"}`}></div>
          </div>

          {errorMessage && (
            <div className="p-3 rounded-xl bg-rose-50 border border-rose-200 text-xs text-rose-600 font-medium">
              {errorMessage}
            </div>
          )}

          {/* PASO 1: Datos de la Empresa y Plan */}
          {step === 1 && (
            <div className="space-y-4">
              <div className="p-4 rounded-2xl bg-slate-50 border border-slate-200 text-xs flex justify-between items-center">
                <div>
                  <span className="text-slate-500 block">Plan seleccionado</span>
                  <strong className="text-slate-900 text-sm">{planName}</strong>
                </div>
                <div className="text-right">
                  <span className="text-slate-500 block">{isTrial ? "Modalidad" : "Precio"}</span>
                  {isTrial ? (
                    <span className="inline-flex items-center gap-1 text-emerald-600 font-bold text-xs bg-emerald-50 px-2.5 py-1 rounded-full border border-emerald-200">
                      <Gift size={12} /> Prueba de {trialDays} días
                    </span>
                  ) : (
                    <strong className="text-[var(--app-brand-deep)] text-sm">{priceDisplay}</strong>
                  )}
                </div>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">RUC *</label>
                  <input
                    type="text"
                    value={formData.ruc}
                    onChange={(e) => setFormData({ ...formData, ruc: e.target.value })}
                    placeholder="20123456789"
                    className="w-full rounded-xl border border-slate-300 px-3.5 py-2 text-sm focus:outline-none focus:border-[var(--app-brand)]"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">Razón social *</label>
                  <input
                    type="text"
                    value={formData.razon_social}
                    onChange={(e) => setFormData({ ...formData, razon_social: e.target.value })}
                    placeholder="Empresa Ejemplo SAC"
                    className="w-full rounded-xl border border-slate-300 px-3.5 py-2 text-sm focus:outline-none focus:border-[var(--app-brand)]"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">Nombre comercial</label>
                  <input
                    type="text"
                    value={formData.nombre_comercial}
                    onChange={(e) => setFormData({ ...formData, nombre_comercial: e.target.value })}
                    placeholder="Mi Empresa"
                    className="w-full rounded-xl border border-slate-300 px-3.5 py-2 text-sm focus:outline-none focus:border-[var(--app-brand)]"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">Teléfono</label>
                  <input
                    type="text"
                    value={formData.telefono}
                    onChange={(e) => setFormData({ ...formData, telefono: e.target.value })}
                    placeholder="+51 999 999 999"
                    className="w-full rounded-xl border border-slate-300 px-3.5 py-2 text-sm focus:outline-none focus:border-[var(--app-brand)]"
                  />
                </div>
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">Dirección fiscal</label>
                <input
                  type="text"
                  value={formData.direccion}
                  onChange={(e) => setFormData({ ...formData, direccion: e.target.value })}
                  placeholder="Av. Principal 123"
                  className="w-full rounded-xl border border-slate-300 px-3.5 py-2 text-sm focus:outline-none focus:border-[var(--app-brand)]"
                />
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">Departamento</label>
                  <select
                    value={formData.departamento}
                    onChange={(e) => setFormData({ ...formData, departamento: e.target.value })}
                    className="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm bg-white focus:outline-none focus:border-[var(--app-brand)]"
                  >
                    <option value="Lima">Lima</option>
                    <option value="Arequipa">Arequipa</option>
                    <option value="Trujillo">Trujillo</option>
                  </select>
                </div>
                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">Distrito</label>
                  <select
                    value={formData.distrito}
                    onChange={(e) => setFormData({ ...formData, distrito: e.target.value })}
                    className="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm bg-white focus:outline-none focus:border-[var(--app-brand)]"
                  >
                    <option value="Los Olivos">Los Olivos</option>
                    <option value="Miraflores">Miraflores</option>
                    <option value="San Isidro">San Isidro</option>
                  </select>
                </div>
              </div>

              <div className="flex justify-end gap-3 pt-4 border-t border-slate-100">
                <button onClick={onClose} className="px-5 py-2.5 rounded-xl border border-slate-300 text-xs font-semibold text-slate-600 hover:bg-slate-50 cursor-pointer">
                  Cancelar
                </button>
                <button
                  onClick={() => {
                    if (!formData.ruc || !formData.razon_social) {
                      setErrorMessage("El RUC y la Razón social son obligatorios.");
                      return;
                    }
                    setErrorMessage("");
                    setStep(2);
                  }}
                  className="px-6 py-2.5 rounded-xl bg-[var(--app-brand-deep)] text-white text-xs font-bold hover:bg-[var(--app-brand)] cursor-pointer shadow-sm"
                >
                  Continuar
                </button>
              </div>
            </div>
          )}

          {/* PASO 2: Datos del Administrador */}
          {step === 2 && (
            <div className="space-y-4">
              <h4 className="text-xs font-bold uppercase tracking-wider text-slate-400">Datos del Administrador</h4>
              
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">Nombres *</label>
                  <input
                    type="text"
                    value={formData.nombres}
                    onChange={(e) => setFormData({ ...formData, nombres: e.target.value })}
                    placeholder="Juan"
                    className="w-full rounded-xl border border-slate-300 px-3.5 py-2 text-sm focus:outline-none focus:border-[var(--app-brand)]"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">Apellidos *</label>
                  <input
                    type="text"
                    value={formData.apellidos}
                    onChange={(e) => setFormData({ ...formData, apellidos: e.target.value })}
                    placeholder="Pérez García"
                    className="w-full rounded-xl border border-slate-300 px-3.5 py-2 text-sm focus:outline-none focus:border-[var(--app-brand)]"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">Correo electrónico *</label>
                  <input
                    type="email"
                    value={formData.email}
                    onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                    placeholder="admin@empresa.com"
                    className="w-full rounded-xl border border-slate-300 px-3.5 py-2 text-sm focus:outline-none focus:border-[var(--app-brand)]"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">Celular</label>
                  <input
                    type="text"
                    value={formData.celular}
                    onChange={(e) => setFormData({ ...formData, celular: e.target.value })}
                    placeholder="999 999 999"
                    className="w-full rounded-xl border border-slate-300 px-3.5 py-2 text-sm focus:outline-none focus:border-[var(--app-brand)]"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">Contraseña *</label>
                  <input
                    type="password"
                    value={formData.password}
                    onChange={(e) => setFormData({ ...formData, password: e.target.value })}
                    placeholder="Mínimo 8 caracteres"
                    className="w-full rounded-xl border border-slate-300 px-3.5 py-2 text-sm focus:outline-none focus:border-[var(--app-brand)]"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">Confirmar contraseña *</label>
                  <input
                    type="password"
                    value={formData.password_confirmation}
                    onChange={(e) => setFormData({ ...formData, password_confirmation: e.target.value })}
                    placeholder="Repite la contraseña"
                    className="w-full rounded-xl border border-slate-300 px-3.5 py-2 text-sm focus:outline-none focus:border-[var(--app-brand)]"
                  />
                </div>
              </div>

              {/* Resumen final antes de crear */}
              <div className="p-4 rounded-2xl bg-slate-50 border border-slate-200 text-xs space-y-1.5">
                <div className="flex justify-between"><span>Empresa:</span><strong>{formData.razon_social || "Empresa SAC"}</strong></div>
                <div className="flex justify-between"><span>Plan:</span><strong>{planName} {isTrial ? `(${trialDays} días gratis)` : ""}</strong></div>
              </div>

              <div className="flex justify-between pt-4 border-t border-slate-100">
                <button onClick={() => setStep(1)} className="px-5 py-2.5 rounded-xl border border-slate-300 text-xs font-semibold text-slate-600 hover:bg-slate-50 cursor-pointer">
                  Atrás
                </button>
                <button
                  onClick={handleRegister}
                  disabled={isSubmitting}
                  className="flex items-center gap-2 px-6 py-2.5 rounded-xl bg-[var(--app-brand-deep)] text-white text-xs font-bold hover:bg-[var(--app-brand)] cursor-pointer shadow-sm disabled:opacity-50"
                >
                  {isSubmitting && <Loader2 size={14} className="animate-spin" />} Crear cuenta
                </button>
              </div>
            </div>
          )}

          {/* PASO 3: Éxito */}
          {step === 3 && (
            <div className="py-6 text-center space-y-4">
              <div className="w-14 h-14 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center mx-auto">
                <Check size={28} strokeWidth={3} />
              </div>
              <h4 className="text-xl font-bold">¡Cuenta creada!</h4>
              <p className="text-xs text-slate-500">
                La empresa ha sido registrada y el plan <strong>{planName}</strong> está listo para activarse 
                {isTrial ? ` con tus ${trialDays} días de prueba gratis.` : "."}
              </p>
              
              <div className="p-4 rounded-2xl bg-slate-50 text-left text-xs space-y-2 border border-slate-200">
                <div className="flex justify-between"><span>Tenant Code:</span><strong className="font-mono">{createdTenantCode}</strong></div>
                <div className="flex justify-between"><span>Plan:</span><strong>{planName}</strong></div>
                <div className="flex justify-between"><span>Acceso:</span><strong>Administrador</strong></div>
              </div>

              <button
                onClick={() => {
                  onClose();
                  router.push("/login");
                }}
                className="w-full py-3.5 rounded-xl bg-[var(--app-brand-deep)] text-white text-xs font-bold hover:bg-[var(--app-brand)] cursor-pointer shadow-md shadow-blue-500/20"
              >
                Ingresar a la plataforma
              </button>
            </div>
          )}
        </motion.div>
      </div>
    </AnimatePresence>
  );
}