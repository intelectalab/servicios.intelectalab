"use client";

import { useEffect, useState } from "react";
import type { Tone } from "@/components/ui/proto";
import { slaView } from "@/lib/support";
import type { TicketPriority, TicketSla } from "@/types/support";

// Colores de la prioridad del prototipo (.prio-p1…p4): P1 rojo, P2 ámbar, P3 azul, P4 gris.
const PRIORITY_TONES: Record<TicketPriority, string> = {
  P1: "border-[color:var(--p-danger-line)] bg-[var(--p-danger-soft)] text-[color:var(--p-danger-text)]",
  P2: "border-[color:color-mix(in_srgb,var(--p-warning-text)_40%,transparent)] text-[color:var(--p-warning-text)]",
  P3: "border-[color:var(--app-border)] text-[color:var(--p-brand-text)]",
  P4: "border-[color:var(--app-border)] text-[color:var(--app-muted)]",
};

/** Prioridad P1–P4 con su color; sin clasificar, un guion. */
export function PriorityTag({ priority }: { priority: TicketPriority | null }) {
  if (!priority) return <span className="app-muted">—</span>;
  return (
    <span className={`inline-flex items-center whitespace-nowrap rounded-md border px-1.5 py-px text-[10.5px] font-semibold tracking-[.03em] ${PRIORITY_TONES[priority]}`}>
      {priority}
    </span>
  );
}

const BAR: Record<Tone, string> = {
  success: "bg-[var(--p-success)]",
  warning: "bg-[#B26E00]",
  danger: "bg-[#E5484D]",
  neutral: "bg-[var(--p-muted-text)]",
  muted: "bg-[var(--p-muted-text)]",
  info: "bg-[#1B8FC9]",
  accent: "bg-[#1B8FC9]",
};

const TEXT: Record<Tone, string> = {
  success: "text-[color:var(--p-success-text)]",
  warning: "text-[color:var(--p-warning-text)]",
  danger: "text-[color:var(--p-danger-text)]",
  neutral: "app-muted",
  muted: "app-muted",
  info: "text-[color:var(--p-brand-text)]",
  accent: "text-[color:var(--p-brand-text)]",
};

/** "Ahora", que avanza cada minuto: el texto "quedan 5 h" no se queda congelado. */
export function useNow(intervalMs = 60_000) {
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), intervalMs);
    return () => window.clearInterval(timer);
  }, [intervalMs]);
  return now;
}

export function ProgressBar({ pct, tone, big = false, label }: { pct: number; tone: Tone; big?: boolean; label: string }) {
  return (
    <span
      className={`block overflow-hidden rounded-full bg-[var(--app-surface-muted)] ${big ? "h-2" : "h-1.5"}`}
      role="progressbar"
      aria-valuenow={pct}
      aria-valuemin={0}
      aria-valuemax={100}
      aria-label={label}
    >
      <span className={`block h-full rounded-full ${BAR[tone]}`} style={{ width: `${Math.max(0, Math.min(100, pct))}%` }} />
    </span>
  );
}

/** Celda SLA del prototipo: barra de avance del plazo y su estado ("A tiempo · quedan 5 h"). */
export function SlaCell({ sla, now }: { sla: TicketSla; now: number }) {
  const view = slaView(sla, now);
  return (
    <div className="grid min-w-[130px] gap-[3px]">
      <ProgressBar pct={view.pct} tone={view.tone} label="Avance del plazo del SLA" />
      <small className={`text-[11.5px] ${TEXT[view.tone]}`}>{view.label}</small>
    </div>
  );
}

/** La versión grande de la ficha. */
export function SlaBig({ sla, now }: { sla: TicketSla; now: number }) {
  const view = slaView(sla, now);
  return (
    <div className="mb-2.5 grid gap-1.5">
      <ProgressBar pct={view.pct} tone={view.tone} big label="Avance del plazo del SLA" />
      <strong className={`text-[15px] font-semibold ${view.tone === "neutral" ? "" : TEXT[view.tone]}`}>{view.label}</strong>
    </div>
  );
}
