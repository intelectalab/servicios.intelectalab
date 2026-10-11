"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { RotateCcw } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { firstError } from "@/lib/crm";
import { Btn, Card, INPUT, LoadError, PageHead, Screen, TD, TH, deniedText, useDenied, useToasts } from "@/components/ui/proto";
import { SortableBoard } from "@/components/ui/sortable-cards";
import { ScopeNote } from "@/components/dashboard/ScopeNote";
import { ScoreChip } from "@/components/dashboard/crm/Activities";
import type { ScoreGrade, Scoring, ScoringRule } from "@/types/crm";

// Barra de cada grado, con los colores de estado del tema.
const GRADE_BARS: Record<ScoreGrade, string> = {
  A: "bg-[var(--p-success)]",
  B: "bg-[#1B8FC9]",
  C: "bg-[var(--p-warning-text)]",
  D: "bg-[var(--p-danger-text)]",
};

type RescoreResult = { total: number; changed: number };

const rescored = ({ total, changed }: RescoreResult) =>
  `${total} ${total === 1 ? "lead" : "leads"}; ${changed === 0 ? "ninguno cambió" : changed === 1 ? "1 cambió de puntaje" : `${changed} cambiaron de puntaje`}`;

export default function ScoringView() {
  const { toast, toasts } = useToasts();
  const denied = useDenied("scoring.manage");

  const [scoring, setScoring] = useState<Scoring | null>(null);
  const [points, setPoints] = useState<Record<string, string>>({});
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    let cancelled = false;
    apiFetch<Scoring>("/v1/crm/scoring")
      .then((next) => {
        if (cancelled) return;
        setScoring(next);
        setPoints(Object.fromEntries(next.rules.map((rule) => [rule.id, String(rule.points)])));
        setError("");
      })
      .catch(() => { if (!cancelled) setError("No se pudo cargar la puntuación de leads."); });
    return () => { cancelled = true; };
  }, [reload]);

  if (error) {
    return (
      <Screen>
        <PageHead title="Puntuación automática de leads" />
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
        {toasts}
      </Screen>
    );
  }

  if (!scoring) {
    return (
      <Screen>
        <PageHead title="Puntuación automática de leads" />
        <Card><div className="h-48 animate-pulse rounded bg-[var(--app-surface-muted)]" aria-busy="true" /></Card>
        {toasts}
      </Screen>
    );
  }

  const { min, max } = scoring.limits;
  const valid = (rule: ScoringRule) => {
    const value = Number(points[rule.id]);
    return points[rule.id]?.trim() !== "" && Number.isInteger(value) && value >= min && value <= max;
  };
  const changed = scoring.rules.some((rule) => Number(points[rule.id]) !== rule.points);
  const allValid = scoring.rules.every(valid);

  async function save() {
    if (!allValid) {
      toast(`Los puntos van de −${Math.abs(min)} a ${max}.`, "danger");
      return;
    }
    setBusy(true);
    try {
      const result = await apiFetch<RescoreResult>("/v1/crm/scoring/rules", {
        method: "PUT",
        body: JSON.stringify({ points: Object.fromEntries(scoring!.rules.map((rule) => [rule.id, Number(points[rule.id])])) }),
      });
      toast("Reglas guardadas", "success", `Se recalcularon ${rescored(result)}.`);
      setReload((n) => n + 1);
    } catch (err) {
      toast(firstError(err, "No se pudieron guardar las reglas."), "danger");
    } finally {
      setBusy(false);
    }
  }

  async function rescore() {
    setBusy(true);
    try {
      const result = await apiFetch<RescoreResult>("/v1/crm/scoring/rescore", { method: "POST" });
      toast("Puntajes recalculados", "success", rescored(result));
      setReload((n) => n + 1);
    } catch (err) {
      toast(firstError(err, "No se pudo recalcular."), "danger");
    } finally {
      setBusy(false);
    }
  }

  const biggest = Math.max(1, ...scoring.distribution.map((item) => item.total));

  return (
    <Screen>
      <PageHead
        title="Puntuación automática de leads"
        actions={
          <>
            <ScopeNote resource="leads" />
            <Btn variant="secondary" onClick={rescore} disabled={busy} permission="scoring.manage">
              <RotateCcw size={15} />
              <span>Recalcular todos</span>
            </Btn>
          </>
        }
      />

      <SortableBoard
        storageKey="erp.cards.crm-scoring"
        className="grid grid-cols-1 gap-x-4 lg:grid-cols-[2fr_1fr]"
        zones={[
          { className: "min-w-0 lg:min-h-24", cards: ["rules"] },
          { className: "min-w-0 lg:min-h-24", cards: ["distribution", "priority"] },
        ]}
        cards={[
          {
            id: "rules",
            node: (
              <Card title="Reglas" flush>
                <div className="pt-3 sm:px-1.5">
                  <table className="proto-tbl w-full border-collapse text-[13px]">
                    <thead>
                      <tr>
                        <th className={TH}>Criterio</th>
                        <th className={`${TH} w-[120px]`}>Puntos</th>
                      </tr>
                    </thead>
                    <tbody>
                      {scoring.rules.map((rule) => (
                        <tr key={rule.id}>
                          <td className={TD} data-label="Criterio">
                            <strong className="font-semibold">{rule.label}</strong>
                            <small className="app-muted block text-xs">{rule.condition}</small>
                          </td>
                          <td className={TD} data-label="Puntos">
                            <input
                              className={`${INPUT} w-[92px] text-right tabular-nums ${valid(rule) ? "" : "border-[color:var(--p-danger-text)]"}`}
                              type="number"
                              min={min}
                              max={max}
                              step={1}
                              value={points[rule.id] ?? ""}
                              onChange={(event) => setPoints((current) => ({ ...current, [rule.id]: event.target.value }))}
                              aria-label={`Puntos: ${rule.label}`}
                              aria-invalid={!valid(rule)}
                              disabled={Boolean(denied)}
                              title={denied ?? (rule.points !== rule.default_points ? `Valor original: ${rule.default_points}` : undefined)}
                            />
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
                <div className="px-5 pb-[18px] pt-3">
                  <p className="app-muted text-xs">
                    Cada lead recibe de 0 a 100 puntos: se suman los criterios que cumple. A ≥ 70 · B ≥ 45 · C ≥ 25 · D &lt; 25. Los puntos de cada
                    criterio van de −{Math.abs(min)} a {max}. Al guardar se recalculan todos los leads; también se recalcula cada lead al crearlo o
                    editarlo, al registrarle una actividad y al asociarlo a una campaña.
                  </p>
                  {denied && <p className="app-muted mt-2 text-xs">{deniedText("scoring.manage")}: puedes ver las reglas, no cambiarlas.</p>}
                  <div className="mt-3 flex justify-end">
                    <Btn onClick={save} disabled={busy || !changed} permission="scoring.manage" title={changed ? undefined : "No hay cambios que guardar"}>
                      Guardar reglas
                    </Btn>
                  </div>
                </div>
              </Card>
            ),
          },
          {
            id: "distribution",
            node: (
              <Card title="Distribución de leads abiertos">
                <ul className="grid gap-2.5">
                  {scoring.distribution.map((item) => (
                    <li key={item.grade} className="grid grid-cols-[64px_1fr_32px] items-center gap-2 text-[13px]">
                      <span>Grado {item.grade}</span>
                      <span className="h-2.5 overflow-hidden rounded-full bg-[var(--app-surface-muted)]" aria-hidden="true">
                        <span className={`block h-full rounded-full ${GRADE_BARS[item.grade]}`} style={{ width: `${(item.total / biggest) * 100}%` }} />
                      </span>
                      <strong className="text-right font-semibold tabular-nums">{item.total}</strong>
                    </li>
                  ))}
                </ul>
              </Card>
            ),
          },
          {
            id: "priority",
            node: (
              <Card title="Prioridad de contacto">
                {scoring.priority.length === 0 ? (
                  <p className="app-muted">No hay leads abiertos.</p>
                ) : (
                  <ul className="grid gap-2">
                    {scoring.priority.map((lead) => (
                      <li key={lead.id} className="flex items-center justify-between gap-2">
                        <Link href={`/crm/leads/${lead.id}`} className="min-w-0 text-[color:var(--p-brand-text)] [overflow-wrap:anywhere] hover:underline">
                          {lead.name ?? lead.lead_number}
                        </Link>
                        <ScoreChip score={lead.score} grade={lead.score_grade} />
                      </li>
                    ))}
                  </ul>
                )}
              </Card>
            ),
          },
        ]}
      />
      {toasts}
    </Screen>
  );
}
