"use client";

import { useRef, useState } from "react";
import { FileText, Upload } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { fieldErrors, firstError } from "@/lib/crm";
import { DOCUMENT_CATEGORIES, fileSize } from "@/lib/operations";
import { Btn, Field, FormGrid, INPUT, Modal } from "@/components/ui/proto";
import { PickSelect } from "@/components/ui/pick-select";
import type { Deliverable, ProjectDetail } from "@/types/operations";

type Toast = (text: string, tone?: "success" | "danger" | "warning", detail?: string) => void;

const MAX_BYTES = 20 * 1024 * 1024;
const ACCEPT = ".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp,.txt,.csv,.png,.jpg,.jpeg,.webp,.gif,.zip,.rar,.7z,.dwg,.dxf,.mp4";

type StepKey = "upload" | "scan" | "hash" | "version" | "link";
type StepState = { tone: "pending" | "running" | "done" | "warn" | "bad"; text: string };

const STEPS: [StepKey, string][] = [["upload", "Subida"], ["scan", "Antivirus"], ["hash", "Hash"], ["version", "Versión"], ["link", "Vínculo"]];
const PENDING: StepState = { tone: "pending", text: "Pendiente" };
const IDLE: Record<StepKey, StepState> = { upload: PENDING, scan: PENDING, hash: PENDING, version: PENDING, link: PENDING };

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

const ANTIVIRUS: Record<string, StepState> = {
  PENDING: { tone: "warn", text: "Sin analizar: aún no hay antivirus conectado" },
  CLEAN: { tone: "done", text: "Limpio" },
  INFECTED: { tone: "bad", text: "Infectado" },
  FAILED: { tone: "bad", text: "No se pudo analizar" },
};

/** Huella SHA-256 del archivo, calculada en el navegador; null si el navegador no puede. */
async function sha256Of(file: File): Promise<string | null> {
  if (typeof crypto === "undefined" || !crypto.subtle) return null;
  const digest = await crypto.subtle.digest("SHA-256", await file.arrayBuffer());
  return Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, "0")).join("");
}

/**
 * Sube el archivo de un entregable: subida, antivirus, huella, versión y vínculo, como el
 * "Subir documento" del prototipo. Si el entregable ya tenía archivo, queda como versión nueva.
 */
export function DeliverableDocumentModal({
  projectId, deliverable, onClose, onUploaded, toast,
}: {
  projectId: string;
  deliverable: Deliverable;
  onClose: () => void;
  /** El archivo quedó adjunto; la ventana sigue abierta mostrando el resultado. */
  onUploaded: (project: ProjectDetail) => void;
  toast: Toast;
}) {
  const current = deliverable.document;
  // El título de la ventana no cambia al terminar la subida.
  const [heading] = useState(current ? "Nueva versión" : "Subir documento");
  const [title, setTitle] = useState(current?.title ?? deliverable.name);
  const [category, setCategory] = useState(current?.category ?? "Entregable");
  const [visibility, setVisibility] = useState<string>(current?.visibility ?? "INTERNAL");
  const [file, setFile] = useState<File | null>(null);
  const [dragging, setDragging] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [steps, setSteps] = useState(IDLE);
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);

  function pick(next: File | undefined) {
    if (!next || busy) return;
    setFile(next);
    setDone(false);
    setSteps(IDLE);
    setErrors(next.size > MAX_BYTES ? { file: "El archivo supera los 20 MB." } : {});
  }

  async function upload() {
    const problems: Record<string, string> = {};
    if (!title.trim()) problems.title = "El título es obligatorio.";
    if (!file) problems.file = "Elige el archivo.";
    else if (file.size > MAX_BYTES) problems.file = "El archivo supera los 20 MB.";
    setErrors(problems);
    if (!file || Object.keys(problems).length) return;

    setBusy(true);
    setSteps({ ...IDLE, hash: { tone: "running", text: "Calculando…" } });
    try {
      const sha = await sha256Of(file).catch(() => null);
      setSteps({ ...IDLE, upload: { tone: "running", text: "Subiendo…" }, hash: sha ? { tone: "running", text: `${sha.slice(0, 16)}…` } : PENDING });

      const body = new FormData();
      body.set("title", title.trim());
      body.set("category", category);
      body.set("visibility", visibility);
      if (sha) body.set("sha256", sha);
      body.set("file", file);

      const project = await apiFetch<ProjectDetail>(`/v1/projects/${projectId}/deliverables/${deliverable.id}/document`, { method: "POST", body });
      const saved = project.deliverables?.find((item) => item.id === deliverable.id)?.document;
      setSteps({
        upload: { tone: "done", text: `${fileSize(saved?.size_bytes ?? file.size)} recibidos` },
        scan: ANTIVIRUS[saved?.antivirus_status ?? "PENDING"],
        hash: { tone: "done", text: `${(saved?.sha256 ?? sha ?? "").slice(0, 16)}…${sha ? " verificado" : ""}` },
        version: { tone: "done", text: `v${saved?.version ?? 1}` },
        link: { tone: "done", text: `Entregable ${deliverable.name}` },
      });
      setDone(true);
      toast(saved && saved.version > 1 ? `Versión ${saved.version} adjuntada` : "Documento adjuntado");
      onUploaded(project);
    } catch (err) {
      const found = fieldErrors(err);
      const message = found.file ?? firstError(err, "No se pudo subir el archivo.");
      setErrors(found);
      setSteps({ ...IDLE, upload: { tone: "bad", text: message } });
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
          <Btn onClick={upload} disabled={busy || done} permission="deliverables.manage">{busy ? "Subiendo…" : "Subir"}</Btn>
        </>
      }
    >
      <FormGrid>
        <Field label="Título" required span={2} error={errors.title}>
          <input className={INPUT} value={title} onChange={(e) => setTitle(e.target.value)} maxLength={255} disabled={busy} />
        </Field>
        <Field label="Categoría" error={errors.category}>
          <PickSelect className={INPUT} ariaLabel="Categoría" value={category} onChange={setCategory} options={DOCUMENT_CATEGORIES.map((item): [string, string] => [item, item])} disabled={busy} />
        </Field>
        <Field label="Vincular a" span={2}>
          <PickSelect className={INPUT} ariaLabel="Vincular a" value="deliverable" onChange={() => undefined} options={[["deliverable", `Entregable ${deliverable.name}`]]} disabled />
        </Field>
        <Field label="Visibilidad" error={errors.visibility} hint={visibility === "PORTAL" ? "Queda marcado; el portal del cliente aún no existe." : undefined}>
          <PickSelect
            className={INPUT} ariaLabel="Visibilidad" value={visibility} onChange={setVisibility} disabled={busy}
            options={[["INTERNAL", "Interno"], ["PORTAL", "Visible para el cliente en el portal"]]}
          />
        </Field>
      </FormGrid>

      <label
        onDragOver={(event) => { event.preventDefault(); setDragging(true); }}
        onDragLeave={() => setDragging(false)}
        onDrop={(event) => {
          event.preventDefault();
          setDragging(false);
          pick(event.dataTransfer.files?.[0]);
        }}
        className={`relative mt-3 grid cursor-pointer place-items-center gap-1 rounded-xl border-[1.5px] border-dashed p-[18px] text-center transition-colors ${
          dragging ? "border-[#1B8FC9] bg-[var(--p-brand-soft)]" : errors.file ? "border-[color:var(--p-danger-text)]" : "border-[color:var(--app-border)] hover:border-[#1B8FC9]"
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
        <input ref={inputRef} type="file" accept={ACCEPT} className="sr-only" disabled={busy} onChange={(event) => pick(event.target.files?.[0])} />
      </label>
      {errors.file && <p className="mt-1.5 text-[12.5px] text-[color:var(--p-danger-text)]">{errors.file}</p>}

      <ol className="mt-3.5 grid grid-cols-2 gap-1.5 sm:grid-cols-5" aria-live="polite">
        {STEPS.map(([key, label]) => (
          <li key={key} data-step={key} data-tone={steps[key].tone} className={`grid content-start gap-0.5 rounded-[7px] border p-2 text-xs ${STEP_BORDER[steps[key].tone]}`}>
            <span aria-hidden="true" className={`h-2.5 w-2.5 rounded-full ${STEP_DOT[steps[key].tone]}`} />
            <strong className="font-semibold">{label}</strong>
            <small className="app-muted text-[11px] [overflow-wrap:anywhere]">{steps[key].text}</small>
          </li>
        ))}
      </ol>
    </Modal>
  );
}
