"use client";

import { useState, type ReactNode } from "react";
import { Check, Copy } from "lucide-react";
import { Btn, Modal, type BtnVariant } from "@/components/ui/proto";

export type Toast = (text: string, tone?: "success" | "danger" | "warning", detail?: string) => void;

/** Tipo de cada ejecución de la sincronización (el estado trae la última sin su texto). */
export const RUN_KINDS: Record<string, string> = {
  INITIAL_MATCH: "Carga inicial",
  WEBHOOK: "Cambios recibidos",
  FULL_RESYNC: "Relectura completa",
};

/**
 * Fecha y hora de la API. Las que salen tal cual de la base vienen en UTC, con o sin zona
 * ("2026-11-16 15:04:05", "2026-11-16 15:04:05+00"): se muestran en la hora de quien mira.
 */
export function when(value: string | null | undefined): string {
  if (!value) return "—";
  let text = value.trim().replace(" ", "T");
  if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?$/.test(text)) text += "Z";
  else if (/[+-]\d{2}$/.test(text)) text += ":00";
  const date = new Date(text);
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleString("es-PE", { dateStyle: "medium", timeStyle: "short" });
}

/** Botón "Copiar" de un dato que hay que llevar al otro sistema. */
export function CopyButton({ value, label = "Copiar" }: { value: string; label?: string }) {
  const [copied, setCopied] = useState(false);
  return (
    <Btn
      variant="secondary" size="sm"
      disabled={!value}
      onClick={async () => {
        try {
          await navigator.clipboard.writeText(value);
          setCopied(true);
          window.setTimeout(() => setCopied(false), 2000);
        } catch {
          setCopied(false);
        }
      }}
    >
      {copied ? <Check size={14} /> : <Copy size={14} />}
      <span>{copied ? "Copiado" : label}</span>
    </Btn>
  );
}

/** Dato con su botón de copiar (dirección del webhook, ID de conexión, RUC…). */
export function CopyLine({ label, value, hint }: { label: string; value: string | null; hint?: ReactNode }) {
  return (
    <div className="grid min-w-0 gap-1">
      <span className="text-[12.5px] font-medium">{label}</span>
      <div className="flex min-w-0 items-center gap-2">
        <code className="min-w-0 flex-1 break-all rounded-lg bg-[var(--app-surface-muted)] px-2.5 py-2 font-mono text-[12px]">{value || "—"}</code>
        {value && <CopyButton value={value} />}
      </div>
      {hint && <small className="app-muted text-xs">{hint}</small>}
    </div>
  );
}

/** Confirmación de una acción con consecuencias (activar, desactivar, nueva clave). */
export function ConfirmModal({
  title, children, confirmLabel, variant = "primary", busy, onClose, onConfirm,
}: {
  title: string;
  children: ReactNode;
  confirmLabel: string;
  variant?: BtnVariant;
  busy: boolean;
  onClose: () => void;
  onConfirm: () => void;
}) {
  return (
    <Modal
      title={title}
      size="sm"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose} disabled={busy}>Volver</Btn>
          <Btn variant={variant} onClick={onConfirm} disabled={busy}>{busy ? "Procesando…" : confirmLabel}</Btn>
        </>
      }
    >
      <div className="grid gap-2 text-[13.5px]">{children}</div>
    </Modal>
  );
}
