"use client";

import { useEffect, useState, type MouseEvent } from "react";
import Link from "next/link";
import { Check, Lock, Plus } from "lucide-react";
import { apiFetch, apiFetchLater } from "@/lib/api";
import { ACTIVITY_TYPES, activityBucket, activityTypeLabel, dateTime, fieldErrors, firstError, toLocalInput } from "@/lib/crm";
import { useAccess } from "@/lib/access-context";
import { Btn, Card, Chip, Field, FormGrid, INPUT, Modal, SearchSelect, deniedText, type SelectOption } from "@/components/ui/proto";
import { PickSelect } from "@/components/ui/pick-select";
import type { PaginatedResponse } from "@/types/customer";
import type { Activity, ActivityOptions, ScoreGrade } from "@/types/crm";
import type { Tone } from "@/components/ui/proto";

type Toast = (text: string, tone?: "success" | "danger" | "warning", detail?: string) => void;

/** Lead u oportunidad al que se ata una actividad registrada desde su ficha. */
export type ActivityRelation = { type: "lead" | "opportunity"; id: string };

const GRADE_TONES: Record<ScoreGrade, Tone> = { A: "success", B: "accent", C: "warning", D: "danger" };

/** Puntaje automático de un lead: grado y puntos, con los criterios que cumple al pasar el cursor. */
export function ScoreChip({ score, grade, hits }: { score: number | null; grade: ScoreGrade | null; hits?: string[] | null }) {
  if (score === null || grade === null) return <span className="app-muted">—</span>;
  return (
    <span title={`Puntaje automático ${grade}: ${hits?.length ? hits.join(" · ") : "sin criterios cumplidos"}`}>
      <Chip tone={GRADE_TONES[grade]}>{grade} {score}</Chip>
    </span>
  );
}

/** Enlace al lead o a la oportunidad de una actividad. */
export function RelatedLink({ activity }: { activity: Activity }) {
  const related = activity.related;
  if (!related) return <span className="app-muted">—</span>;
  // Fuera del alcance de quien mira: se sabe que existe, no cuál es.
  if (!related.name) return <span className="app-muted">{related.type === "lead" ? "Lead" : "Oportunidad"} de otra persona</span>;
  return (
    <Link
      href={related.type === "lead" ? `/crm/leads/${related.id}` : `/crm/opportunities/${related.id}`}
      onClick={(event) => event.stopPropagation()}
      className="text-[color:var(--p-brand-text)] hover:underline"
    >
      {related.name}
    </Link>
  );
}

/** "Atrasada" u "Hoy" junto a la fecha de una actividad pendiente. */
export function DueChip({ activity }: { activity: Activity }) {
  const bucket = activityBucket(activity);
  if (bucket === "LATE") return <Chip tone="danger">Atrasada</Chip>;
  if (bucket === "TODAY") return <Chip tone="warning">Hoy</Chip>;
  return null;
}

/** Casilla redonda que marca una actividad como realizada (o la reabre). Sin permiso queda con candado. */
export function ActivityCheck({ activity, busy, onToggle }: { activity: Activity; busy?: boolean; onToggle: (activity: Activity) => void }) {
  const { can, loading } = useAccess();
  const denied = !loading && !can("activities.manage");

  function click(event: MouseEvent) {
    event.stopPropagation();
    onToggle(activity);
  }

  return (
    <button
      type="button"
      onClick={click}
      disabled={busy || denied}
      aria-pressed={activity.done}
      aria-label={activity.done ? `Reabrir: ${activity.subject}` : `Marcar realizada: ${activity.subject}`}
      title={denied ? deniedText("activities.manage") : activity.done ? "Realizada: pulsa para reabrirla" : "Marcar realizada"}
      className={`grid h-[22px] w-[22px] shrink-0 cursor-pointer place-items-center rounded-full border transition-colors disabled:cursor-not-allowed disabled:opacity-60 ${
        activity.done
          ? "border-[#1B8FC9] bg-[#1B8FC9] text-[#fff]"
          : "border-[color:var(--p-field-border)] bg-[var(--app-surface)] text-[color:var(--app-muted)] hover:border-[#1B8FC9]"
      }`}
    >
      {denied ? <Lock size={11} aria-hidden="true" /> : activity.done ? <Check size={13} aria-hidden="true" /> : null}
    </button>
  );
}

/** Marca realizada o reabre una actividad y avisa del resultado. */
export async function toggleActivity(activity: Activity, toast: Toast): Promise<Activity | null> {
  try {
    const updated = await apiFetch<Activity>(`/v1/crm/activities/${activity.id}/toggle`, { method: "POST" });
    toast(updated.done ? "Actividad realizada" : "Actividad reabierta");
    return updated;
  } catch (err) {
    toast(firstError(err, "No se pudo actualizar la actividad."), "danger");
    return null;
  }
}

/** Alta o edición de una actividad. Con `relation`, queda atada a ese lead u oportunidad. */
export function ActivityFormModal({
  activity, relation, onClose, onSaved, toast,
}: {
  activity?: Activity;
  relation?: ActivityRelation;
  onClose: () => void;
  onSaved: (activity: Activity) => void;
  toast: Toast;
}) {
  const { can, loading: accessLoading } = useAccess();
  // Una actividad realizada no se edita (se reabre antes); sin permiso, tampoco.
  const readOnly = Boolean(activity?.done) || (!accessLoading && !can("activities.manage"));

  const [subject, setSubject] = useState(activity?.subject ?? "");
  const [type, setType] = useState<string>(activity?.activity_type ?? "CALL");
  const [dueAt, setDueAt] = useState(() => toLocalInput(activity?.due_at ?? new Date(Date.now() + 86_400_000)));
  const [notes, setNotes] = useState(activity?.description ?? "");
  const [related, setRelated] = useState(
    activity?.opportunity_id ? `opportunity:${activity.opportunity_id}` : activity?.lead_id ? `lead:${activity.lead_id}` : "",
  );
  const [options, setOptions] = useState<ActivityOptions | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (relation) return;
    let cancelled = false;
    apiFetch<ActivityOptions>("/v1/crm/activities/options").then((next) => { if (!cancelled) setOptions(next); }).catch(() => undefined);
    return () => { cancelled = true; };
  }, [relation]);

  const relatedOptions: SelectOption[] = [
    { value: "", label: "Sin relación" },
    ...(options?.opportunities ?? []).map((item) => ({ value: `opportunity:${item.id}`, label: item.name, hint: `Oportunidad ${item.number}` })),
    ...(options?.leads ?? []).map((item) => ({ value: `lead:${item.id}`, label: item.name ?? item.number, hint: `Lead ${item.number}` })),
  ];
  // La relación actual puede no estar entre las abiertas (oportunidad ya cerrada): se conserva.
  if (related && activity?.related && !relatedOptions.some((option) => option.value === related)) {
    relatedOptions.push({
      value: related,
      label: activity.related.name ?? "Fuera de tu alcance",
      hint: `${activity.related.type === "lead" ? "Lead" : "Oportunidad"} ${activity.related.number ?? ""}`.trim(),
    });
  }

  async function save() {
    const found: Record<string, string> = {};
    if (!subject.trim()) found.subject = "El asunto es obligatorio.";
    const due = new Date(dueAt);
    if (!dueAt || Number.isNaN(due.getTime())) found.due_at = "Indica la fecha y la hora.";
    setErrors(found);
    if (Object.keys(found).length) return;

    const [relatedType, relatedId] = relation ? [relation.type, relation.id] : related ? related.split(":") : [null, null];
    setSaving(true);
    try {
      const saved = await apiFetch<Activity>(activity ? `/v1/crm/activities/${activity.id}` : "/v1/crm/activities", {
        method: activity ? "PUT" : "POST",
        body: JSON.stringify({
          subject: subject.trim(),
          activity_type: type,
          due_at: due.toISOString(),
          description: notes.trim() || null,
          lead_id: relatedType === "lead" ? relatedId : null,
          opportunity_id: relatedType === "opportunity" ? relatedId : null,
        }),
      });
      toast(activity ? "Actividad actualizada" : "Actividad registrada");
      onSaved(saved);
    } catch (err) {
      setErrors(fieldErrors(err));
      toast(firstError(err, "No se pudo guardar la actividad."), "danger");
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      title={activity ? (readOnly ? "Actividad" : "Editar actividad") : "Nueva actividad"}
      onClose={onClose}
      footer={
        readOnly ? (
          <Btn variant="secondary" onClick={onClose}>Cerrar</Btn>
        ) : (
          <>
            <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
            <Btn onClick={save} disabled={saving} permission="activities.manage">Guardar</Btn>
          </>
        )
      }
    >
      {activity?.done && (
        <p className="app-muted mb-3 text-[12.5px]">
          Realizada{activity.completed_at ? ` el ${dateTime(activity.completed_at)}` : ""}: para corregirla, reábrela primero.
        </p>
      )}
      <FormGrid>
        <Field label="Asunto" required span={2} error={errors.subject}>
          <input className={INPUT} value={subject} onChange={(e) => setSubject(e.target.value)} maxLength={255} autoFocus={!readOnly} disabled={readOnly} />
        </Field>
        <Field label="Tipo" error={errors.activity_type}>
          <PickSelect className={INPUT} ariaLabel="Tipo" value={type} onChange={setType} options={ACTIVITY_TYPES} disabled={readOnly} />
        </Field>
        <Field label="Fecha y hora" required error={errors.due_at}>
          <input className={INPUT} type="datetime-local" value={dueAt} onChange={(e) => setDueAt(e.target.value)} disabled={readOnly} />
        </Field>
        {!relation && (
          <Field label="Relacionado con" span={2} error={errors.opportunity_id ?? errors.lead_id} hint="Una oportunidad o un lead abiertos (opcional).">
            <SearchSelect
              value={related}
              onChange={setRelated}
              options={relatedOptions}
              placeholder="Sin relación"
              searchPlaceholder="Buscar oportunidad o lead…"
              disabled={readOnly}
            />
          </Field>
        )}
        <Field label="Notas" span={3} error={errors.description}>
          <textarea className={INPUT} rows={3} value={notes} onChange={(e) => setNotes(e.target.value)} maxLength={2000} disabled={readOnly} />
        </Field>
      </FormGrid>
    </Modal>
  );
}

/** Tarjeta "Actividades" de la ficha de un lead o de una oportunidad. */
export function ActivitiesCard({ relation, toast, onChanged }: { relation: ActivityRelation; toast: Toast; onChanged?: () => void }) {
  const { can, loading: accessLoading } = useAccess();
  const denied = !accessLoading && !can("activities.view");

  const [rows, setRows] = useState<Activity[] | null>(null);
  const [failed, setFailed] = useState(false);
  const [reload, setReload] = useState(0);
  const [busy, setBusy] = useState<string | null>(null);
  const [adding, setAdding] = useState(false);
  const [editing, setEditing] = useState<Activity | null>(null);

  useEffect(() => {
    if (accessLoading || denied) return;
    let cancelled = false;
    apiFetchLater<PaginatedResponse<Activity>>(`/v1/crm/activities?${relation.type}_id=${relation.id}&per_page=100`)
      .then((page) => { if (!cancelled) { setRows(page.data); setFailed(false); } })
      .catch(() => { if (!cancelled) setFailed(true); });
    return () => { cancelled = true; };
  }, [relation.type, relation.id, reload, accessLoading, denied]);

  async function toggle(activity: Activity) {
    setBusy(activity.id);
    const updated = await toggleActivity(activity, toast);
    setBusy(null);
    if (updated) {
      setRows((current) => current?.map((row) => (row.id === updated.id ? updated : row)) ?? current);
      onChanged?.();
    }
  }

  function saved() {
    setAdding(false);
    setEditing(null);
    setReload((n) => n + 1);
    onChanged?.();
  }

  return (
    <Card title="Actividades">
      {denied ? (
        <p className="app-muted flex items-center gap-1.5 text-[13px]"><Lock size={14} aria-hidden="true" />{deniedText("activities.view")}</p>
      ) : failed ? (
        <p className="app-muted text-[13px]">No se pudieron cargar las actividades.</p>
      ) : rows === null ? (
        <div className="h-10 animate-pulse rounded bg-[var(--app-surface-muted)]" aria-busy="true" />
      ) : rows.length === 0 ? (
        <p className="app-muted">Sin actividades.</p>
      ) : (
        <ul className="grid gap-2">
          {rows.map((activity) => (
            <li key={activity.id} className="flex items-start gap-2.5">
              <span className="mt-0.5"><ActivityCheck activity={activity} busy={busy === activity.id} onToggle={toggle} /></span>
              <button type="button" onClick={() => setEditing(activity)} className="min-w-0 flex-1 cursor-pointer text-left" title="Ver o editar la actividad">
                <strong className={`block font-semibold [overflow-wrap:anywhere] ${activity.done ? "app-muted line-through" : ""}`}>{activity.subject}</strong>
                <small className="app-muted flex flex-wrap items-center gap-1.5 text-xs">
                  {activityTypeLabel(activity.activity_type)} · {activity.due_at ? dateTime(activity.due_at) : "Sin fecha"}
                  {activity.owner && <> · {activity.owner.name}</>}
                  <DueChip activity={activity} />
                </small>
              </button>
            </li>
          ))}
        </ul>
      )}
      {!denied && (
        <div className="mt-3">
          <Btn variant="ghost" size="sm" onClick={() => setAdding(true)} permission="activities.manage">
            <Plus size={14} />
            <span>Agregar</span>
          </Btn>
        </div>
      )}

      {adding && <ActivityFormModal relation={relation} toast={toast} onClose={() => setAdding(false)} onSaved={saved} />}
      {editing && <ActivityFormModal activity={editing} relation={relation} toast={toast} onClose={() => setEditing(null)} onSaved={saved} />}
    </Card>
  );
}
