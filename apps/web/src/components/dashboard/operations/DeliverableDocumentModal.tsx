"use client";

import { useState } from "react";
import { apiFetch } from "@/lib/api";
import { fieldErrors, firstError } from "@/lib/crm";
import { DOCUMENT_CATEGORIES, fileSize } from "@/lib/operations";
import { MAX_BYTES, VISIBILITY_OPTIONS, sha256Of } from "@/lib/documents";
import { Btn, Field, FormGrid, INPUT, Modal } from "@/components/ui/proto";
import { DropZone, IDLE_STEPS as IDLE, PENDING_STEP as PENDING, UploadSteps, scanStep } from "@/components/dashboard/documents/DocumentModals";
import { PickSelect } from "@/components/ui/pick-select";
import type { Deliverable, ProjectDetail } from "@/types/operations";

type Toast = (text: string, tone?: "success" | "danger" | "warning", detail?: string) => void;

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
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [steps, setSteps] = useState(IDLE);
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState(false);

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
        scan: scanStep(saved?.antivirus_status ?? "PENDING", saved?.antivirus_engine),
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
        <Field label="Visibilidad" error={errors.visibility}>
          <PickSelect className={INPUT} ariaLabel="Visibilidad" value={visibility} onChange={setVisibility} disabled={busy} options={VISIBILITY_OPTIONS} />
        </Field>
      </FormGrid>

      <DropZone file={file} error={errors.file} disabled={busy} onPick={pick} />
      <UploadSteps steps={steps} />
    </Modal>
  );
}
