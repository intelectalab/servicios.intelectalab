"use client";

import type { ReactNode } from "react";
import Image from "next/image";
import { motion, type Variants } from "framer-motion";
import { FileText, Wrench, Receipt } from "lucide-react";
import { poppins } from "@/lib/fonts";

/**
 * ─────────────────────────────────────────────────────────
 * Paleta — medida sobre el logo de Grupo de Soluciones Creativas
 * (public/logo.png): azul de marca + negro cálido sobre blanco.
 * BRAND_DARK / BRAND_DEEP son el mismo azul oscurecido, para hover
 * y para el fondo del panel de marca. Si el logo cambia, edita aquí.
 * ─────────────────────────────────────────────────────────
 */
export const BRAND = "#1B8FC9";
export const BRAND_DARK = "#1679AB";
export const BRAND_DEEP = "#0F5A82";
export const BRAND_SOFT = "var(--p-brand-soft)";
export const INK = "#1D1D1B";

/** Estilos de formulario compartidos por las pantallas de auth. */
export const authLabel = "mb-1.5 block text-[12.5px] font-medium text-[color:var(--app-muted)]";
export const authInput =
  "min-h-[42px] w-full rounded-[9px] border border-[color:var(--p-field-border)] bg-white px-3 py-2 pr-[42px] text-[14px] text-[color:var(--app-text)] transition-[border-color,box-shadow] placeholder:text-[#8A93A3] focus:border-[#1B8FC9] focus:shadow-[0_0_0_3px_var(--p-brand-soft)] focus:outline-none";
export const authInputIcon = "pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[#8A93A3]";
export const authGhostButton =
  "flex h-9 w-full items-center justify-center rounded-[9px] text-[13.5px] font-medium text-[color:var(--app-muted)] transition-colors hover:bg-[var(--app-surface-muted)] hover:text-[color:var(--app-text)]";
export const authButton =
  "flex h-[46px] w-full items-center justify-center gap-2 rounded-[9px] bg-[#1B8FC9] text-[14px] font-semibold text-white transition-colors hover:bg-[#1679AB] disabled:cursor-not-allowed disabled:opacity-60";

export const container: Variants = {
  hidden: {},
  show: { transition: { staggerChildren: 0.07, delayChildren: 0.05 } },
};

export const item: Variants = {
  hidden: { opacity: 0, y: 12 },
  show: { opacity: 1, y: 0, transition: { duration: 0.4, ease: "easeOut" } },
};

/** Logo real (public/logo.png). En el panel navy se vuelve blanco vía filtro. */
export function Logo({ variant = "dark" }: { variant?: "dark" | "light" }) {
  return (
    <div className="relative h-11 w-[200px]">
      <Image
        src="/logo.png"
        alt="GsCreativas"
        fill
        priority
        sizes="200px"
        className="object-contain object-left"
        style={{ filter: variant === "light" ? "brightness(0) invert(1)" : "none" }}
      />
    </div>
  );
}

const FEATURES = [
  { icon: FileText, title: "Cotiza y contrata", text: "Precios con snapshot y aprobación por roles" },
  { icon: Wrench, title: "Ejecuta con SLA", text: "OT, proyectos, checklists y evidencias" },
  { icon: Receipt, title: "Factura y cobra", text: "SUNAT, cuentas por cobrar y pagos en línea" },
];

export default function AuthShell({
  title,
  subtitle,
  children,
  footer,
}: {
  title: string;
  subtitle: ReactNode;
  children: ReactNode;
  footer?: ReactNode;
}) {
  return (
    <div className={`${poppins.className} app-surface grid min-h-screen w-full grid-rows-[auto_1fr] lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)] lg:grid-rows-1`}>
      {/* Panel de marca: columna izquierda en escritorio; bajo lg queda como franja superior con solo logo y titular */}
      <aside
        className="relative flex flex-col justify-between gap-4 overflow-hidden px-[22px] py-[26px] text-white lg:gap-8 lg:px-12 lg:py-10"
        style={{
          background: [
            "radial-gradient(circle at 80% 15%, rgba(142,205,235,.55), transparent 40%)",
            "radial-gradient(circle at 10% 90%, rgba(27,143,201,.45), transparent 45%)",
            "repeating-linear-gradient(135deg, rgba(255,255,255,.04) 0 2px, transparent 2px 22px)",
            `linear-gradient(150deg, ${BRAND} 0%, ${BRAND_DARK} 40%, ${BRAND_DEEP} 100%)`,
          ].join(", "),
        }}
      >
        <Logo variant="light" />

        <div>
          <h1 className="max-w-[16ch] text-[24px] font-bold lg:text-[clamp(28px,3.2vw,40px)] leading-[1.12] tracking-[-0.02em]">
            Servicios bajo control, <span className="text-[#BFE6F8]">clientes satisfechos.</span>
          </h1>
          <p className="mt-3.5 hidden max-w-[46ch] text-[14.5px] leading-relaxed text-white/90 lg:block">
            Plataforma SaaS para empresas de servicios: CRM, cotizaciones, contratos,
            órdenes de trabajo, facturación electrónica, cobranza, soporte y portal de
            clientes en un solo lugar.
          </p>

          <div className="mt-6 hidden max-w-[620px] grid-cols-3 gap-3 lg:grid">
            {FEATURES.map(({ icon: Icon, title, text }) => (
              <div
                key={title}
                className="rounded-xl border border-white/20 bg-[rgba(255,255,255,.07)] p-3 text-[12px] leading-[1.35]"
              >
                <Icon size={20} className="mb-1.5 text-[#BFE6F8]" />
                <strong className="block text-[12.5px]">{title}</strong>
                <span className="text-white/85">{text}</span>
              </div>
            ))}
          </div>
        </div>

        <p className="hidden text-[12px] text-white/70 lg:block">
          © {new Date().getFullYear()} · Grupo de Soluciones Creativas
        </p>
      </aside>

      {/* Panel derecho — contenido de la pantalla */}
      <main className="flex flex-col items-center justify-center gap-[18px] px-6 pb-6 pt-10">
        <motion.div
          className="my-auto w-full max-w-[400px]"
          variants={container}
          initial="hidden"
          animate="show"
        >
          <motion.div variants={item} className="mb-7">
            <span className="logo-light block"><Logo variant="dark" /></span>
            <span className="logo-dark"><Logo variant="light" /></span>
          </motion.div>

          <motion.div variants={item}>
            <h2 className="app-text text-[24px] font-bold tracking-[-0.01em]">{title}</h2>
            <p className="app-muted mt-1 text-[13px]">{subtitle}</p>
          </motion.div>

          <div className="mt-[18px]">
            {children}
          </div>

          {footer && (
            <motion.p variants={item} className="app-muted mt-3.5 text-[12.5px]">
              {footer}
            </motion.p>
          )}
        </motion.div>

        <p className="text-[12px] text-[#8A93A3]">Desarrollado por GSCREATIVAS</p>
      </main>
    </div>
  );
}
