"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { Archive, Copy, Download, FileText, Link2, Lock, Pencil, Upload } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { fieldErrors, firstError, dateTime } from "@/lib/crm";
import { fileSize } from "@/lib/operations";
import { stateOf } from "@/lib/commercial";
import { ACCEPT, DOCUMENT_STATES, MAX_BYTES, VISIBILITY_OPTIONS, antivirusText, sha256Of, shortSha } from "@/lib/documents";
import { useAccess } from "@/lib/access-context";
import { Badge, Btn, Card, Chip, Field, FormGrid, INPUT, Kv, LoadError, Modal, Notice, SearchSelect, TD, TH } from "@/components/ui/proto";
import { PickSelect } from "@/components/ui/pick-select";
import type {
  AntivirusStatus, DocumentDetail, DocumentLink, DocumentOptions, DocumentRow, DocumentVisibility, FixedLink, LinkTarget, TemporaryUrl,
} from "@/types/documents";

type Toast = (text: string, tone?: "success" | "danger" | "warning", detail?: string) => void;

// ---------------------------------------------------------------------------------------------
// Piezas de la subida, compartidas con el archivo del entregable.

export type StepKey = "upload" | "scan" | "hash" | "version" | "link";
export type StepState = { tone: "pending" | "running" | "done" | "warn" | "bad"; text: string };

const STEPS: [StepKey, string][] = [["upload", "Subida"], ["scan", "Antivirus"], ["hash", "Hash"], ["version", "Versión"], ["link", "Vínculo"]];
export const PENDING_STEP: StepState = { tone: "pending", text: "Pendiente" };
export const IDLE_STEPS: Record<StepKey, StepState> = { upload: PENDING_STEP, scan: PENDING_STEP, hash: PENDING_STEP, version: PENDING_STEP, link: PENDING_STEP };

const STEP_BORDER: Record<StepState["tone"], string> = {
  pending: "border-[color:var(--app-border)]",
  running: "border-[#1B8FC9]",
  done: "border-[color:color-mix(in_srgb,var(--p-success)_45%,transparent)]",
  warn: "border-[color:color-mix(in_srgb,var(--p-warning-text)_55%,transparent)]",
  bad: "border-[color:var(--p-danger-text)]",
};
const STEP_DOT: Record<StepState["tone"], string> = {
  pending: "bg-[var(--app-border)]",
  running: "animate-pulse bg-[#1B8FC9]",
  done: "bg-[var(--p-success)]",
  warn: "bg-[var(--p-warning-text)]",
  bad: "bg-[var(--p-danger-text)]",
};

/** Paso "Antivirus" con el resultado del análisis. */
export function scanStep(status: AntivirusStatus, engine?: string | null): StepState {
  const [text, tone] = antivirusText(status, engine);
  return { tone: tone === "success" ? "done" : tone === "danger" ? "bad" : "warn", text };
}

/** Subida → Antivirus → Hash → Versión → Vínculo, como en el prototipo. */
export function UploadSteps({ steps }: { steps: Record<StepKey, StepState> }) {
  return (
    <ol className="mt-3.5 grid grid-cols-2 gap-1.5 sm:grid-cols-5" aria-live="polite">
      {STEPS.map(([key, label]) => (
        <li key={key} data-step={key} data-tone={steps[key].tone} className={`grid content-start gap-0.5 rounded-[7px] border p-2 text-xs ${STEP_BORDER[steps[key].tone]}`}>
          <span aria-hidden="true" className={`h-2.5 w-2.5 rounded-full ${STEP_DOT[steps[key].tone]}`} />
          <strong className="font-semibold">{label}</strong>
          <small className="app-muted text-[11px] [overflow-wrap:anywhere]">{steps[key].text}</small>
        </li>
      ))}
    </ol>
  );
}

/** Zona para elegir o soltar el archivo. */
export function DropZone({
  file, error, disabled, onPick,
}: { file: File | null; error?: string; disabled?: boolean; onPick: (file: File | undefined) => void }) {
  const [dragging, setDragging] = useState(false);
  return (
    <>
      <label
        onDragOver={(event) => { event.preventDefault(); setDragging(true); }}
        onDragLeave={() => setDragging(false)}
        onDrop={(event) => {
          event.preventDefault();
          setDragging(false);
          onPick(event.dataTransfer.files?.[0]);
        }}
        className={`relative mt-3 grid cursor-pointer place-items-center gap-1 rounded-xl border-[1.5px] border-dashed p-[18px] text-center transition-colors ${
          dragging ? "border-[#1B8FC9] bg-[var(--p-brand-soft)]" : error ? "border-[color:var(--p-danger-text)]" : "border-[color:var(--app-border)] hover:border-[#1B8FC9]"
        }`}
      >
        <span className="inline-flex max-w-full items-center gap-2 font-medium [overflow-wrap:anywhere]">
          {file ? <FileText size={17} className="shrink-0 text-[color:var(--p-brand)]" /> : <Upload size={17} className="app-muted shrink-0" />}
          {file ? `${file.name} · ${fileSize(file.size)}` : "Elige un archivo o arrástralo aquí"}
        </span>
        <small className="app-muted max-w-[60ch] text-xs">
          {file
            ? "Haz clic para cambiarlo."
            : "PDF, documentos de oficina, imágenes o comprimidos, hasta 20 MB. Su huella SHA-256 se calcula en tu navegador y se comprueba al llegar."}
        </small>
        <input type="file" accept={ACCEPT} className="sr-only" disabled={disabled} onChange={(event) => onPick(event.target.files?.[0])} />
      </label>
      {error && <p className="mt-1.5 text-[12.5px] text-[color:var(--p-danger-text)]">{error}</p>}
    </>
  );
}

// ---------------------------------------------------------------------------------------------

const targetKey = (target: { type: string; id: string }) => `${target.type}:${target.id}`;
const TYPE_HINT: Record<string, string> = { Party: "Cliente o actor", Contract: "Contrato", Project: "Proyecto", WorkOrder: "Orden de trabajo" };

/**
 * "Subir documento" del prototipo: título, categoría, vínculo y visibilidad, y la línea de pasos.
 * Con `document` es "Nueva versión" de ese documento (solo se elige el archivo). Con `fixedLink`
 * se abre desde la ficha de un registro y queda vinculado a él.
 */
export function DocumentUploadModal({
  document, fixedLink, defaultCategory = "Otros", defaultTitle = "", toast, onClose, onUploaded,
}: {
  document?: DocumentDetail;
  fixedLink?: FixedLink;
  defaultCategory?: string;
  defaultTitle?: string;
  toast: Toast;
  onClose: () => void;
  /** El documento quedó subido; la ventana sigue abierta mostrando el resultado. */
  onUploaded: (document: DocumentDetail) => void;
}) {
  const { can } = useAccess();
  const canPublish = can("documents.manage");
  // El título de la ventana no cambia al terminar la subida.
  const [heading] = useState(document ? "Nueva versión" : "Subir documento");
  const [baseVersion] = useState(document?.version);
  const [title, setTitle] = useState(defaultTitle);
  const [category, setCategory] = useState(defaultCategory);
  const [link, setLink] = useState(fixedLink ? targetKey(fixedLink) : "");
  const [visibility, setVisibility] = useState<DocumentVisibility>("INTERNAL");
  const [options, setOptions] = useState<DocumentOptions | null>(null);
  const [file, setFile] = useState<File | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [steps, setSteps] = useState(IDLE_STEPS);
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState(false);

  useEffect(() => {
    if (document || fixedLink) return;
    let cancelled = false;
    apiFetch<DocumentOptions>("/v1/documents/options")
      .then((data) => { if (!cancelled) setOptions(data); })
      .catch(() => { if (!cancelled) setErrors((current) => ({ ...current, link_id: "No se pudieron cargar los registros." })); });
    return () => { cancelled = true; };
  }, [document, fixedLink]);

  const targets: (LinkTarget | FixedLink)[] = fixedLink ? [fixedLink] : options?.targets ?? [];
  const chosen = targets.find((target) => targetKey(target) === link);
  const portalBlocked = !chosen?.customer;

  function pick(next: File | undefined) {
    if (!next || busy) return;
    setFile(next);
    setDone(false);
    setSteps(IDLE_STEPS);
    setErrors((current) => ({ ...current, file: next.size > MAX_BYTES ? "El archivo supera los 20 MB." : "" }));
  }

  async function upload() {
    const problems: Record<string, string> = {};
    if (!document && !title.trim()) problems.title = "El título es obligatorio.";
    if (!file) problems.file = "Elige el archivo.";
    else if (file.size > MAX_BYTES) problems.file = "El archivo supera los 20 MB.";
    if (!document && visibility === "PORTAL" && portalBlocked) problems.visibility = "Para mostrarlo en el portal, vincúlalo a un cliente o a su contrato, proyecto u orden de trabajo.";
    setErrors(problems);
    if (!file || Object.values(problems).some(Boolean)) return;

    setBusy(true);
    setSteps({ ...IDLE_STEPS, hash: { tone: "running", text: "Calculando…" } });
    try {
      const sha = await sha256Of(file).catch(() => null);
      setSteps({ ...IDLE_STEPS, upload: { tone: "running", text: "Subiendo…" }, scan: { tone: "running", text: "Analizando…" }, hash: sha ? { tone: "running", text: `${sha.slice(0, 16)}…` } : PENDING_STEP });

      const body = new FormData();
      if (!document) {
        body.set("title", title.trim());
        body.set("category", category);
        body.set("visibility", visibility);
        if (chosen) {
          body.set("link_type", chosen.type);
          body.set("link_id", chosen.id);
        }
      }
      if (sha) body.set("sha256", sha);
      body.set("file", file);

      const saved = await apiFetch<DocumentDetail>(document ? `/v1/documents/${document.id}/versions` : "/v1/documents", { method: "POST", body });
      const latest = saved.versions[0];
      setSteps({
        upload: { tone: "done", text: `${fileSize(latest?.size ?? file.size)} recibidos` },
        scan: scanStep(latest?.antivirus ?? "PENDING", latest?.engine),
        hash: { tone: "done", text: `${(latest?.sha256 ?? sha ?? "").slice(0, 16)}…${sha ? " verificado" : ""}` },
        version: { tone: "done", text: `v${saved.version}` },
        link: { tone: "done", text: saved.links.length ? saved.links.map((item) => item.label).join(" · ") : "Sin vínculo" },
      });
      setDone(true);
      if (saved.status === "QUARANTINED") toast("Archivo en cuarentena: no estará disponible", "warning", `Versión v${saved.version}`);
      else toast(document ? `Versión v${saved.version} subida` : "Documento disponible", "success", `Versión v${saved.version}`);
      onUploaded(saved);
    } catch (err) {
      const found = fieldErrors(err);
      const message = found.file ?? found.visibility ?? found.link_id ?? firstError(err, "No se pudo subir el archivo.");
      setErrors(found);
      setSteps({ ...IDLE_STEPS, upload: { tone: "bad", text: message } });
      toast(message, "danger");
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal
      title={heading}
      size="lg"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cerrar</Btn>
          <Btn onClick={upload} disabled={busy || done} permission="documents.create">{busy ? "Subiendo…" : "Subir"}</Btn>
        </>
      }
    >
      {document ? (
        <p className="mb-1">
          <strong className="font-semibold">{document.title}</strong>
          <span className="app-muted"> · hoy en v{baseVersion}. La versión anterior se conserva.</span>
        </p>
      ) : (
        <FormGrid>
          <Field label="Título" required span={2} error={errors.title}>
            <input className={INPUT} value={title} onChange={(e) => setTitle(e.target.value)} maxLength={255} disabled={busy} />
          </Field>
          <Field label="Categoría" error={errors.category}>
            <PickSelect
              className={INPUT} ariaLabel="Categoría" value={category} onChange={setCategory} disabled={busy}
              options={(options?.categories ?? CATEGORIES).map((item): [string, string] => [item, item])}
            />
          </Field>
          <Field label="Vincular a" span={2} error={errors.link_id}>
            {fixedLink ? (
              <PickSelect className={INPUT} ariaLabel="Vincular a" value={link} onChange={() => undefined} options={[[link, fixedLink.label]]} disabled />
            ) : (
              <SearchSelect
                value={link}
                onChange={(value) => { setLink(value); setErrors((current) => ({ ...current, link_id: "", visibility: "" })); }}
                disabled={busy || !options}
                placeholder={options ? "Sin vínculo" : "Cargando…"}
                searchPlaceholder="Buscar cliente, contrato, proyecto u OT…"
                options={[
                  { value: "", label: "Sin vínculo" },
                  ...(options?.targets ?? []).map((target) => ({ value: targetKey(target), label: target.label, hint: TYPE_HINT[target.type] })),
                ]}
              />
            )}
          </Field>
          <Field
            label="Visibilidad"
            error={errors.visibility}
            hint={!canPublish ? (
              <span className="inline-flex items-center gap-1"><Lock size={11} aria-hidden="true" />Tu rol no tiene permiso documents.manage</span>
            ) : visibility === "PORTAL" && portalBlocked ? "Vincúlalo a un cliente o a su contrato, proyecto u OT." : undefined}
          >
            <PickSelect
              className={INPUT} ariaLabel="Visibilidad" value={visibility} disabled={busy || !canPublish}
              onChange={(value) => setVisibility(value as DocumentVisibility)}
              options={VISIBILITY_OPTIONS}
            />
          </Field>
        </FormGrid>
      )}

      <DropZone file={file} error={errors.file} disabled={busy} onPick={pick} />
      <UploadSteps steps={steps} />
    </Modal>
  );
}

const CATEGORIES = ["Contrato", "Entregable", "Evidencia", "Proyecto", "Cliente", "Proveedor", "Servidor", "Dominio", "Hosting", "Cloud", "Otros"];

// ---------------------------------------------------------------------------------------------

/** Enlace temporal de descarga, con la cuenta atrás de sus 5 minutos. */
export function TemporaryUrlModal({ link, onClose, toast }: { link: TemporaryUrl; onClose: () => void; toast: Toast }) {
  const expires = new Date(link.expires_at).getTime();
  const [left, setLeft] = useState(() => Math.max(0, Math.round((expires - Date.now()) / 1000)));

  useEffect(() => {
    const timer = window.setInterval(() => setLeft(Math.max(0, Math.round((expires - Date.now()) / 1000))), 1000);
    return () => window.clearInterval(timer);
  }, [expires]);

  async function copy() {
    try {
      await navigator.clipboard.writeText(link.url);
      toast("Enlace copiado");
    } catch {
      toast("No se pudo copiar: selecciona el enlace a mano.", "warning");
    }
  }

  return (
    <Modal
      title="URL temporal firmada"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cerrar</Btn>
          <Btn variant="secondary" onClick={copy} disabled={!left}><Copy size={15} /><span>Copiar enlace</span></Btn>
          <Btn onClick={() => window.open(link.url, "_blank", "noopener")} disabled={!left}><Download size={15} /><span>Descargar</span></Btn>
        </>
      }
    >
      <p>
        <strong className="font-semibold">{link.name}</strong>
        <span className="app-muted"> · v{link.version} · {fileSize(link.size)}</span>
      </p>
      <p className="app-muted mt-1 text-[12.5px]">Enlace válido por 5 minutos. El acceso quedó registrado.</p>
      <pre className="mt-2.5 max-h-28 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-[var(--app-surface-muted)] px-3 py-2 text-[11.5px]">{link.url}</pre>
      <p className="mt-2.5 text-[13px]" aria-live="polite">
        {left ? <>Expira en <strong className="font-semibold">{Math.floor(left / 60)}:{String(left % 60).padStart(2, "0")}</strong></> : <strong className="font-semibold text-[color:var(--p-danger-text)]">URL expirada</strong>}
      </p>
    </Modal>
  );
}

/** Vínculos de un documento, cada uno con su enlace si quien mira ve ese registro. */
export function LinkChips({ links, stop = false }: { links: DocumentLink[]; stop?: boolean }) {
  if (!links.length) return <span className="app-muted">—</span>;
  // Como Chip, pero baja de línea: un vínculo largo no se monta sobre la columna de al lado.
  const chip = "inline-block max-w-full rounded-md bg-[var(--p-neutral-soft)] px-2 py-0.5 text-[11px] font-semibold leading-normal text-[color:var(--p-neutral-text)] [overflow-wrap:anywhere]";
  return (
    <span className="flex flex-wrap gap-1">
      {links.map((link) => (link.href ? (
        <Link key={`${link.type}${link.id}`} href={link.href} onClick={(event) => stop && event.stopPropagation()} className={`${chip} hover:underline`}>
          {link.label}
        </Link>
      ) : (
        <span key={`${link.type}${link.id}`} className={chip}>{link.label}</span>
      )))}
    </span>
  );
}

export function VisibilityChip({ visibility }: { visibility: DocumentVisibility }) {
  return visibility === "PORTAL" ? <Chip tone="accent">Portal</Chip> : <Chip tone="neutral">Interno</Chip>;
}

export function DocumentStatusBadge({ status }: { status: string }) {
  const [label, tone] = stateOf(DOCUMENT_STATES, status);
  return <Badge tone={tone}>{label}</Badge>;
}

/** Pide el enlace temporal de una versión (la última si no se indica) y lo muestra. */
export function useTemporaryUrl(toast: Toast) {
  const [link, setLink] = useState<TemporaryUrl | null>(null);
  const [busy, setBusy] = useState(false);

  const open = useCallback(async (documentId: string, version?: number) => {
    setBusy(true);
    try {
      setLink(await apiFetch<TemporaryUrl>(`/v1/documents/${documentId}/download-url${version ? `?version=${version}` : ""}`));
      toast("URL temporal generada");
    } catch (err) {
      toast(firstError(err, "No se pudo generar el enlace."), "danger");
    } finally {
      setBusy(false);
    }
  }, [toast]);

  const modal = link ? <TemporaryUrlModal link={link} toast={toast} onClose={() => setLink(null)} /> : null;
  return { open, busy, modal };
}

// ---------------------------------------------------------------------------------------------

/** Editar título, categoría y visibilidad en el portal. */
function DocumentEditModal({
  document, toast, onClose, onSaved,
}: { document: DocumentDetail; toast: Toast; onClose: () => void; onSaved: (document: DocumentDetail) => void }) {
  const [title, setTitle] = useState(document.title);
  const [category, setCategory] = useState(document.category);
  const [visibility, setVisibility] = useState<DocumentVisibility>(document.visibility);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const quarantined = document.status === "QUARANTINED";

  async function save() {
    if (!title.trim()) {
      setErrors({ title: "El título es obligatorio." });
      return;
    }
    setSaving(true);
    try {
      const saved = await apiFetch<DocumentDetail>(`/v1/documents/${document.id}`, {
        method: "PUT", body: JSON.stringify({ title: title.trim(), category, visibility }),
      });
      toast(saved.visibility !== document.visibility ? (saved.visibility === "PORTAL" ? "Visible para el cliente en el portal" : "Ya no se ve en el portal") : "Documento actualizado");
      onSaved(saved);
    } catch (err) {
      setErrors(fieldErrors(err));
      toast(firstError(err, "No se pudo guardar."), "danger");
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      title="Editar documento"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn onClick={save} disabled={saving} permission="documents.manage">{saving ? "Guardando…" : "Guardar"}</Btn>
        </>
      }
    >
      <FormGrid>
        <Field label="Título" required span={3} error={errors.title}>
          <input className={INPUT} value={title} onChange={(e) => setTitle(e.target.value)} maxLength={255} />
        </Field>
        <Field label="Categoría" error={errors.category}>
          <PickSelect className={INPUT} ariaLabel="Categoría" value={category} onChange={setCategory} options={CATEGORIES.map((item): [string, string] => [item, item])} />
        </Field>
        <Field label="Visibilidad" span={2} error={errors.visibility} hint={quarantined ? "En cuarentena no se publica en el portal." : undefined}>
          <PickSelect
            className={INPUT} ariaLabel="Visibilidad" value={visibility} onChange={(value) => setVisibility(value as DocumentVisibility)}
            options={quarantined ? VISIBILITY_OPTIONS.filter(([key]) => key === "INTERNAL" || document.visibility === "PORTAL") : VISIBILITY_OPTIONS}
          />
        </Field>
      </FormGrid>
    </Modal>
  );
}

/**
 * Ficha del documento (el panel del prototipo): datos, enlace temporal, nueva versión, editar,
 * archivar, versiones (no se destruyen) y registro de accesos.
 */
export function DocumentDetailModal({
  documentId, toast, onClose, onChanged,
}: { documentId: string; toast: Toast; onClose: () => void; onChanged: () => void }) {
  const [document, setDocument] = useState<DocumentDetail | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [uploading, setUploading] = useState(false);
  const [editing, setEditing] = useState(false);
  const [archiving, setArchiving] = useState(false);
  const [busy, setBusy] = useState(false);
  const temporary = useTemporaryUrl(toast);

  useEffect(() => {
    let cancelled = false;
    apiFetch<DocumentDetail>(`/v1/documents/${documentId}`)
      .then((data) => { if (!cancelled) { setDocument(data); setError(""); } })
      .catch((err) => { if (!cancelled) setError(firstError(err, "No se pudo cargar el documento.")); });
    return () => { cancelled = true; };
  }, [documentId, reload]);

  function updated(next: DocumentDetail) {
    setDocument(next);
    onChanged();
  }

  async function archive() {
    if (!document) return;
    setBusy(true);
    try {
      updated(await apiFetch<DocumentDetail>(`/v1/documents/${document.id}/archive`, { method: "POST" }));
      setArchiving(false);
      toast("Documento archivado");
    } catch (err) {
      toast(firstError(err, "No se pudo archivar."), "danger");
    } finally {
      setBusy(false);
    }
  }

  const archived = document?.status === "ARCHIVED";
  const quarantined = document?.status === "QUARANTINED";
  // Un archivo con amenaza no se entrega, aunque el documento ya esté archivado.
  const infected = document?.file?.antivirus === "INFECTED";

  return (
    <>
      <Modal title={document?.title ?? "Documento"} size="lg" onClose={onClose}>
        {error ? (
          <LoadError text={error} onRetry={() => setReload((n) => n + 1)} />
        ) : !document ? (
          <p className="app-muted py-6 text-center">Cargando…</p>
        ) : (
          <>
            {quarantined && (
              <Notice tone="warn">El antivirus encontró una amenaza en la última versión: no se puede descargar ni mostrar en el portal.</Notice>
            )}
            <Kv
              rows={[
                ["Categoría", document.category],
                ["Estado", <DocumentStatusBadge key="status" status={document.status} />],
                ["Visibilidad", document.visibility === "PORTAL" ? "Visible en el portal del cliente" : "Interno"],
                ["Vínculos", <LinkChips key="links" links={document.links} />],
                ["Archivo", document.file ? `${document.file.name} · ${fileSize(document.file.size)}` : "—"],
                ["Integridad (SHA-256)", document.file ? <code key="sha" className="text-[12px] [overflow-wrap:anywhere]">{document.file.sha256}</code> : "—"],
                ["Sede", document.branch ?? "—"],
                ["Creado por", `${document.creator ?? "—"} · ${dateTime(document.created_at)}`],
                archived ? ["Archivado", `${document.archived_by ?? "—"} · ${document.archived_at ? dateTime(document.archived_at) : ""}`] : null,
              ]}
            />
            <div className="mb-4 mt-3 flex flex-wrap gap-2">
              <Btn
                onClick={() => temporary.open(document.id)} permission="documents.view"
                disabled={temporary.busy || infected} title={infected ? "Archivo en cuarentena por el antivirus" : undefined}
              >
                <Link2 size={15} /><span>Obtener URL temporal</span>
              </Btn>
              <Btn variant="secondary" onClick={() => setUploading(true)} permission="documents.create" disabled={archived} title={archived ? "Un documento archivado no recibe versiones nuevas" : undefined}>
                <Upload size={15} /><span>Nueva versión</span>
              </Btn>
              <Btn variant="secondary" onClick={() => setEditing(true)} permission="documents.manage" disabled={archived} title={archived ? "Un documento archivado no se modifica" : undefined}>
                <Pencil size={15} /><span>Editar</span>
              </Btn>
              {!archived && (
                <Btn variant="ghost" onClick={() => setArchiving(true)} permission="documents.manage">
                  <Archive size={15} /><span>Archivar</span>
                </Btn>
              )}
            </div>

            <Card title="Versiones (no se destruyen)" flush>
              <div className="overflow-x-auto px-1.5 pb-2">
                <table className="w-full min-w-[640px] border-collapse text-[13px]">
                  <thead>
                    <tr>
                      {["v", "Archivo", "SHA-256", "Antivirus", "Autor", "Fecha", ""].map((label) => <th key={label} className={TH}>{label}</th>)}
                    </tr>
                  </thead>
                  <tbody>
                    {document.versions.map((version) => {
                      const [scan, tone] = antivirusText(version.antivirus, version.engine);
                      return (
                        <tr key={version.version}>
                          <td className={TD}>v{version.version}</td>
                          <td className={`${TD} min-w-[170px]`}>
                            <span className="[overflow-wrap:anywhere]">{version.name}</span>
                            <small className="app-muted block text-xs">{fileSize(version.size)}</small>
                          </td>
                          <td className={TD}><code className="text-[11.5px]" title={version.sha256}>{shortSha(version.sha256, 12)}</code></td>
                          <td className={TD}><Chip tone={tone}>{scan}</Chip></td>
                          <td className={`${TD} min-w-[120px]`}>{version.author ?? "—"}</td>
                          <td className={`${TD} whitespace-nowrap`}>{dateTime(version.at)}</td>
                          <td className={TD}>
                            <Btn
                              variant="ghost" size="sm" permission="documents.view" disabled={temporary.busy || version.antivirus === "INFECTED"}
                              title={version.antivirus === "INFECTED" ? "Archivo en cuarentena" : `Descargar v${version.version}`}
                              onClick={() => temporary.open(document.id, version.version)}
                            >
                              <Download size={14} />
                            </Btn>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </Card>

            <Card title="Registro de accesos">
              {document.accesses.length ? (
                <ul className="grid gap-2">
                  {document.accesses.map((access, index) => (
                    <li key={`${access.at}-${index}`} className="border-b border-[color:var(--app-border)] pb-2 last:border-0 last:pb-0">
                      <span>{access.user ?? "—"} · {access.label}{access.metadata.version ? ` v${access.metadata.version}` : ""}</span>
                      <small className="app-muted block text-xs">
                        {dateTime(access.at)}{access.metadata.note ? ` · ${access.metadata.note}` : ""}
                      </small>
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="app-muted">Sin accesos.</p>
              )}
            </Card>
          </>
        )}
      </Modal>

      {uploading && document && (
        <DocumentUploadModal document={document} toast={toast} onClose={() => setUploading(false)} onUploaded={updated} />
      )}
      {editing && document && (
        <DocumentEditModal document={document} toast={toast} onClose={() => setEditing(false)} onSaved={(saved) => { setEditing(false); updated(saved); }} />
      )}
      {archiving && document && (
        <Modal
          title="Archivar documento"
          size="sm"
          onClose={() => setArchiving(false)}
          footer={
            <>
              <Btn variant="ghost" onClick={() => setArchiving(false)}>Cancelar</Btn>
              <Btn variant="danger" onClick={archive} disabled={busy} permission="documents.manage">{busy ? "Archivando…" : "Archivar"}</Btn>
            </>
          }
        >
          <p>
            <strong className="font-semibold">{document.title}</strong> dejará de verse en el portal y ya no se podrá modificar. Sus versiones se conservan.
          </p>
        </Modal>
      )}
      {temporary.modal}
    </>
  );
}

// ---------------------------------------------------------------------------------------------

/**
 * Documentos de un registro (contrato, proyecto, OT, cliente), para su ficha. `stamp` vuelve a
 * pedirlos cuando el registro cambia (p. ej. una foto de evidencia subida desde el checklist).
 */
export function useRecordDocuments(type: string, id: string | null | undefined, stamp?: unknown) {
  const { can, loading: accessLoading } = useAccess();
  const allowed = can("documents.view");
  const [rows, setRows] = useState<DocumentRow[] | null>(null);
  const [reload, setReload] = useState(0);

  useEffect(() => {
    if (!id || accessLoading || !allowed) return;
    let cancelled = false;
    apiFetch<{ data: DocumentRow[] }>(`/v1/documents?entity_type=${type}&entity_id=${id}&per_page=100`)
      .then((page) => { if (!cancelled) setRows(page.data); })
      .catch(() => { if (!cancelled) setRows([]); });
    return () => { cancelled = true; };
  }, [type, id, allowed, accessLoading, reload, stamp]);

  return { rows: allowed ? rows : [], allowed, refresh: () => setReload((n) => n + 1) };
}
