"use client";

import { useState } from "react";
import { ShieldCheck } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { dateTime, firstError } from "@/lib/crm";
import { Badge, Btn, Card, Field, INPUT, Modal, Notice, type Tone } from "@/components/ui/proto";
import type { QuotationAuthorizationSummary } from "@/types/commercial";

const STATUS: Record<string, [string, Tone]> = {
  NOT_REQUIRED: ["No requiere", "muted"],
  MISSING: ["Falta pedirla", "warning"],
  PENDING: ["Pendiente", "info"],
  APPROVED: ["Autorizada", "success"],
  REJECTED: ["Rechazada", "danger"],
  VOID: ["Anulada", "muted"],
};

/**
 * Autorización interna por niveles (Administración → Reglas de aprobación): qué exige la
 * cotización, cómo va y, para quien puede, autorizar o rechazar el nivel que toca.
 */
export function QuotationAuthorizationCard({ quotationId, status, authorization, onChanged, toast }: {
  quotationId: string;
  status: string;
  authorization: QuotationAuthorizationSummary;
  onChanged: () => void;
  toast: (text: string, tone?: "success" | "warning" | "danger") => void;
}) {
  const [busy, setBusy] = useState(false);
  const [rejecting, setRejecting] = useState(false);
  const [comment, setComment] = useState("");
  const [commentError, setCommentError] = useState("");
  const a = authorization;
  const [label, tone] = STATUS[a.status] ?? [a.status, "neutral"];
  const draft = status === "DRAFT";

  async function call(path: string, done: string, body?: Record<string, string>) {
    setBusy(true);
    try {
      await apiFetch(`/v1/quotations/${quotationId}/authorization${path}`, { method: "POST", body: body ? JSON.stringify(body) : undefined });
      toast(done);
      setRejecting(false);
      setComment("");
      onChanged();
    } catch (err) {
      const message = firstError(err, "No se pudo completar.");
      if (path === "/reject") setCommentError(message);
      else toast(message, "danger");
    } finally {
      setBusy(false);
    }
  }

  if (a.required_level === 0 && !a.request) {
    return (
      <Card title="Autorización interna">
        <p className="app-muted text-[13px]">No requiere: no supera ninguna regla de aprobación.</p>
      </Card>
    );
  }

  return (
    <Card title="Autorización interna">
      <div className="grid gap-2.5 text-[13px]">
        <div className="flex flex-wrap items-center gap-2">
          <Badge tone={tone}>{label}</Badge>
          {a.required_level > 0 && <span>hasta el nivel {a.required_level}</span>}
        </div>
        {a.reasons.length > 0 && <p className="app-muted text-[12.5px]">{a.reasons.join(" · ")}</p>}
        {a.request?.stale && a.request.status !== "VOID" && (
          <Notice tone="warn">La cotización cambió después de pedirla: hay que pedirla de nuevo.</Notice>
        )}
        {a.request?.status === "VOID" && a.request.void_reason && <p className="app-muted text-[12px]">Anulada: {a.request.void_reason}</p>}

        <ol className="grid gap-1.5">
          {a.levels.filter((level) => level.level <= Math.max(a.required_level, a.request?.required_level ?? 0)).map((level) => {
            const step = a.steps.find((s) => s.level === level.level);
            return (
              <li key={level.level} className="rounded-lg border border-[color:var(--app-border)] px-3 py-2">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <strong className="font-semibold">Nivel {level.level} · {level.name}</strong>
                  {step ? (
                    <Badge tone={step.decision === "APPROVED" ? "success" : "danger"}>{step.decision === "APPROVED" ? "Autorizado" : "Rechazado"}</Badge>
                  ) : a.next_level?.level === level.level ? <Badge tone="info">Le toca</Badge> : null}
                </div>
                <small className="app-muted block text-[12px]">
                  {step
                    ? `${step.user_name ?? "—"} · ${dateTime(step.created_at)}${step.comment ? ` · ${step.comment}` : ""}`
                    : `Autorizan: ${level.roles.join(", ") || "sin roles definidos"}`}
                </small>
              </li>
            );
          })}
        </ol>

        {draft && (
          <div className="flex flex-wrap gap-1.5">
            {(a.status === "MISSING" || a.status === "REJECTED" || a.request?.stale) && a.required_level > 0 && (
              <Btn variant="secondary" permission="quotations.manage" disabled={busy} onClick={() => call("", "Autorización pedida")}>
                <ShieldCheck size={15} />
                <span>Pedir autorización</span>
              </Btn>
            )}
            {a.status === "PENDING" && a.next_level && (
              <>
                <Btn disabled={busy || !a.can_decide} title={a.decide_blocked ?? undefined} onClick={() => call("/approve", `Nivel ${a.next_level!.level} autorizado`)}>
                  Autorizar nivel {a.next_level.level}
                </Btn>
                <Btn variant="danger-ghost" disabled={busy || !a.can_decide} title={a.decide_blocked ?? undefined} onClick={() => setRejecting(true)}>
                  Rechazar
                </Btn>
              </>
            )}
          </div>
        )}
      </div>

      {rejecting && (
        <Modal
          title="Rechazar la autorización"
          size="sm"
          onClose={() => setRejecting(false)}
          footer={
            <div className="flex justify-end gap-2">
              <Btn variant="ghost" onClick={() => setRejecting(false)} disabled={busy}>Cancelar</Btn>
              <Btn variant="danger" disabled={busy} onClick={() => {
                if (!comment.trim()) { setCommentError("Indica el motivo del rechazo."); return; }
                call("/reject", "Autorización rechazada", { comment: comment.trim() });
              }}>Rechazar</Btn>
            </div>
          }
        >
          <Field label="Motivo" required error={commentError}>
            <textarea value={comment} onChange={(e) => { setComment(e.target.value); setCommentError(""); }} rows={3} className={`${INPUT} h-auto py-2`} />
          </Field>
        </Modal>
      )}
    </Card>
  );
}
