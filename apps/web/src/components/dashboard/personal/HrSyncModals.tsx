"use client";

import { useEffect, useState } from "react";
import { apiFetch } from "@/lib/api";
import { useAccess } from "@/lib/access-context";
import { firstError } from "@/lib/crm";
import { Btn, Kv, Modal, Notice, SearchInput } from "@/components/ui/proto";
import { CopyButton, CopyLine, type Toast } from "@/components/dashboard/personal/HrSyncShared";
import type { HrEmployee, HrUnmatched } from "@/types/personal";

/**
 * La clave de firma se ve una sola vez (al registrar la conexión o al cambiarla), junto con
 * todo lo que hay que cargar en el SaaS de RRHH.
 */
export function HrSyncSecretModal({
  secret, webhookUrl, connectionId, ruc, onClose,
}: { secret: string; webhookUrl: string; connectionId: string | null; ruc: string | null; onClose: () => void }) {
  return (
    <Modal title="Clave de firma del SaaS de RRHH" onClose={onClose} footer={<Btn onClick={onClose}>Ya la guardé</Btn>}>
      <Notice tone="warn">Cópiala ahora y cárgala en el SaaS de RRHH: no se vuelve a mostrar. Si la pierdes, genera una nueva.</Notice>
      <div className="flex items-center gap-2">
        <code className="min-w-0 flex-1 break-all rounded-lg bg-[var(--app-surface-muted)] px-2.5 py-2 font-mono text-[12px]">{secret}</code>
        <CopyButton value={secret} />
      </div>
      <div className="mt-4 grid gap-3">
        <CopyLine label="Dirección del webhook" value={webhookUrl} />
        <CopyLine label="ID de conexión" value={connectionId} hint="RRHH lo envía en la cabecera X-Connection-Id." />
        <CopyLine label="RUC de la empresa" value={ruc} />
      </div>
      <div className="mt-4">
        <Kv
          rows={[
            ["Firma", <code key="signature" className="font-mono text-[12px]">X-Signature: sha256=HMAC-SHA256 del cuerpo con esta clave</code>],
            ["Mensaje", <code key="event" className="font-mono text-[12px]">X-Event-Id: ID único de cada mensaje</code>],
          ]}
        />
      </div>
    </Modal>
  );
}

/** Vincular un trabajador de RRHH con uno del ERP: de los candidatos o buscando en el personal. */
export function HrLinkModal({
  item, toast, onClose, onDone,
}: { item: HrUnmatched; toast: Toast; onClose: () => void; onDone: () => void }) {
  const { can, loading: accessLoading } = useAccess();
  const canSearch = !accessLoading && can("employees.view");
  const [chosen, setChosen] = useState(item.candidates.length === 1 ? item.candidates[0].id : "");
  const [query, setQuery] = useState("");
  const [found, setFound] = useState<{ id: string; code: string | null; name: string; hint?: string }[]>([]);
  const [searching, setSearching] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    const text = query.trim();
    if (!canSearch || text.length < 2) return;
    let cancelled = false;
    const timer = window.setTimeout(async () => {
      setSearching(true);
      try {
        const page = await apiFetch<{ data: HrEmployee[] }>(`/v1/hr/employees?${new URLSearchParams({ search: text, per_page: "10" })}`);
        if (!cancelled) {
          setFound(page.data.map((employee) => ({
            id: employee.id, code: employee.code, name: employee.name,
            hint: [employee.document_number, employee.position?.name].filter(Boolean).join(" · "),
          })));
        }
      } catch {
        if (!cancelled) setFound([]);
      } finally {
        if (!cancelled) setSearching(false);
      }
    }, 350);
    return () => { cancelled = true; window.clearTimeout(timer); };
  }, [query, canSearch]);

  async function link() {
    if (!chosen) {
      setError("Elige el trabajador del ERP.");
      return;
    }
    setBusy(true);
    try {
      await apiFetch(`/v1/hr/sync/unmatched/${item.id}/resolve`, { method: "POST", body: JSON.stringify({ action: "link", employee_id: chosen }) });
      toast(`${item.full_name} vinculado`);
      onDone();
    } catch (err) {
      setError(firstError(err, "No se pudo vincular."));
    } finally {
      setBusy(false);
    }
  }

  const option = (person: { id: string; code: string | null; name: string; hint?: string }) => (
    <label
      key={person.id}
      className={`flex cursor-pointer items-start gap-2.5 rounded-lg border px-3 py-2 ${chosen === person.id ? "border-[color:var(--p-accent,#1B8FC9)] bg-[var(--p-brand-soft)]" : "border-[color:var(--app-border)]"}`}
    >
      <input type="radio" name="employee" className="mt-0.5 h-4 w-4 accent-[color:var(--p-accent,#1B8FC9)]" checked={chosen === person.id} onChange={() => { setChosen(person.id); setError(""); }} />
      <span className="min-w-0">
        <span className="block font-medium">{person.name}</span>
        <small className="app-muted block text-xs">{[person.code, person.hint].filter(Boolean).join(" · ") || "Sin código"}</small>
      </span>
    </label>
  );

  const searched = canSearch && query.trim().length >= 2;
  const extra = (searched ? found : []).filter((person) => !item.candidates.some((candidate) => candidate.id === person.id));

  return (
    <Modal
      title="Vincular con un trabajador del ERP"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose} disabled={busy}>Volver</Btn>
          <Btn onClick={link} disabled={busy} permission="hr_sync.manage">{busy ? "Vinculando…" : "Vincular"}</Btn>
        </>
      }
    >
      <Kv
        rows={[
          ["En el SaaS de RRHH", <strong key="name" className="font-semibold">{item.full_name}</strong>],
          ["Documento", item.document || "—"],
          ["Motivo", item.reason_label],
        ]}
      />
      <div className="mt-3 grid gap-2">
        <h3 className="text-[13px] font-semibold">Candidatos</h3>
        {item.candidates.length ? item.candidates.map((candidate) => option(candidate)) : <p className="app-muted text-[13px]">Sin candidatos con ese documento.</p>}
      </div>
      <div className="mt-4 grid gap-2">
        <h3 className="text-[13px] font-semibold">Otro trabajador</h3>
        {canSearch ? (
          <>
            <SearchInput value={query} onChange={setQuery} placeholder="Buscar por nombre, código o documento" />
            {searching && <p className="app-muted text-xs">Buscando…</p>}
            {extra.map((person) => option(person))}
            {!searching && searched && !extra.length && <p className="app-muted text-xs">Ningún otro trabajador coincide.</p>}
          </>
        ) : (
          <p className="app-muted text-[13px]">Tu rol no tiene permiso employees.view: solo puedes elegir entre los candidatos.</p>
        )}
      </div>
      {error && <p className="mt-3 text-[13px] text-[color:var(--p-danger-text)]">{error}</p>}
    </Modal>
  );
}
