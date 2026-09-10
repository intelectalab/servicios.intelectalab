"use client";

import type { ComponentPropsWithoutRef, ReactNode } from "react";
import Image from "next/image";
import { motion, useReducedMotion, type Variants } from "framer-motion";
import { Users, ShieldCheck, Building2 } from "lucide-react";

/**
 * ─────────────────────────────────────────────────────────
 * Paleta — tomada directo del logo de intelectaLab.
 * Si el logo cambia, solo edita estos valores.
 * ─────────────────────────────────────────────────────────
 */
export const NAVY = "#002347";
export const NAVY_DEEP = "#001325";
export const NAVY_HOVER = "#013a70";
export const TEAL = "#80CED7";
export const TEAL_DARK = "#4FA9B4";

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
        alt="intelectaLab"
        fill
        priority
        className="object-contain object-left"
        style={{ filter: variant === "light" ? "brightness(0) invert(1)" : "none" }}
      />
    </div>
  );
}

/** Link con la línea que se dibuja al hover, usado en toda la sección de auth. */
export function UnderlineLink({
  className = "",
  children,
  ...props
}: ComponentPropsWithoutRef<"a">) {
  return (
    <a className={`group relative ${className}`} {...props}>
      {children}
      <span className="absolute -bottom-0.5 left-0 h-px w-0 bg-current transition-[width] duration-200 ease-out group-hover:w-full" />
    </a>
  );
}

const FEATURES = [
  { icon: Users, text: "Gestión de clientes centralizada" },
  { icon: ShieldCheck, text: "Permisos y roles por equipo" },
  { icon: Building2, text: "Multi-tenant, pensado para escalar" },
];

export default function AuthShell({
  title,
  subtitle,
  children,
  footer,
}: {
  title: string;
  subtitle: string;
  children: ReactNode;
  footer?: ReactNode;
}) {
  const prefersReducedMotion = useReducedMotion();

  return (
    <div className="flex min-h-screen bg-[#F6F8FA] text-[#0F1F2E]">
      {/* Panel izquierdo — marca (oculto en mobile) */}
      <aside
        className="relative hidden w-[44%] flex-col justify-between overflow-hidden p-14 text-white md:flex"
        style={{ background: `linear-gradient(165deg, ${NAVY} 0%, ${NAVY_DEEP} 100%)` }}
      >
        <div
          className="absolute inset-0"
          style={{
            backgroundImage:
              "linear-gradient(rgba(255,255,255,0.07) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.07) 1px, transparent 1px)",
            backgroundSize: "40px 40px",
            maskImage: "radial-gradient(ellipse 95% 85% at 35% 30%, black 35%, transparent 95%)",
            WebkitMaskImage:
              "radial-gradient(ellipse 95% 85% at 35% 30%, black 35%, transparent 95%)",
          }}
        />

        <motion.div
          className="absolute -bottom-32 -right-24 h-[340px] w-[340px] rounded-full blur-[60px]"
          style={{ background: `radial-gradient(circle, ${TEAL}3d 0%, transparent 70%)` }}
          animate={
            prefersReducedMotion ? undefined : { x: [0, 24, 0], y: [0, -30, 0], scale: [1, 1.12, 1] }
          }
          transition={{ duration: 16, repeat: Infinity, ease: "easeInOut" }}
        />
        <motion.div
          className="absolute -left-20 -top-16 h-[260px] w-[260px] rounded-full blur-[60px]"
          style={{ background: `radial-gradient(circle, ${TEAL}26 0%, transparent 70%)` }}
          animate={
            prefersReducedMotion ? undefined : { x: [0, -18, 0], y: [0, 22, 0], scale: [1, 1.08, 1] }
          }
          transition={{ duration: 20, repeat: Infinity, ease: "easeInOut" }}
        />

        <div className="relative mt-10 max-w-sm">
          <Logo variant="light" />
          <p className="mt-12 text-[32px] font-bold leading-tight tracking-tight">
            Toda tu operación, en un solo lugar.
          </p>
          <p className="mt-4 max-w-[340px] text-[15px] leading-relaxed text-[#C7D6E4]">
            Clientes, permisos y equipos — administrados desde un mismo panel,
            con el control que tu empresa necesita.
          </p>

          <ul className="mt-10 flex flex-col gap-4">
            {FEATURES.map(({ icon: Icon, text }) => (
              <li key={text} className="flex items-center gap-3 text-[14px] text-[#DCE7F0]">
                <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-white/[0.06] ring-1 ring-white/10">
                  <Icon size={15} className="text-[#80CED7]" />
                </span>
                {text}
              </li>
            ))}
          </ul>
        </div>

        <p className="relative text-[13px] text-[#7C93A8]">
          © {new Date().getFullYear()} intelectaLab
        </p>
      </aside>

      {/* Panel derecho — contenido de la pantalla */}
      <main className="flex flex-1 items-center justify-center p-6">
        <motion.div
          className="w-full max-w-sm rounded-2xl border border-[#E1E7EC] bg-white p-7 shadow-[0_1px_2px_rgba(15,31,46,0.04),0_12px_28px_-16px_rgba(15,31,46,0.12)] sm:p-9"
          variants={container}
          initial="hidden"
          animate="show"
        >
          <motion.div variants={item} className="mb-8 md:hidden">
            <Logo variant="dark" />
          </motion.div>

          <motion.div variants={item}>
            <h1 className="text-[27px] font-bold tracking-tight">{title}</h1>
            <p className="mt-2 text-[15px] text-[#5C6F81]">{subtitle}</p>
          </motion.div>

          {children}

          {footer && (
            <motion.p variants={item} className="mt-7 text-center text-[13.5px] text-[#5C6F81]">
              {footer}
            </motion.p>
          )}
        </motion.div>
      </main>
    </div>
  );
}
