"use client";

import { useRef, useState, type DragEvent, type FormEvent } from "react";
import { CheckCircle2, Download, FileSpreadsheet, Upload } from "lucide-react";
import { getAuthToken } from "@/lib/auth";
import { Btn, Modal, Notice } from "@/components/ui/proto";

const API_BASE = process.env.NEXT_PUBLIC_API_URL ?? "";

type Result = { imported?: number; updated?: number; message?: string; warnings?: string[] };

const authHeaders = (): Record<string, string> => {
  const token = getAuthToken();
  return token ? { Authorization: `Bearer ${token}` } : {};
};

/** Mensaje y avisos por fila de una respuesta de error de la API. */
function failure(data: { message?: string; errors?: Record<string, string[]> } | null, status: number): [string, string[]] {
  const rows = data?.errors ? Object.values(data.errors).flat() : [];
  return [data?.message || rows[0] || `Error ${status} al importar el archivo.`, rows];
}

/**
 * Importación desde Excel o CSV, como la de Clientes: descargar la plantilla,
 * elegir el archivo y ver cuántas filas entraron y cuáles no (con su motivo).
 */
export function ImportModal({
  title, endpoint, templateName, hint, onClose, onImported,
}: {
  title: string;
  /** Ruta de la API: `${endpoint}/template` y `${endpoint}/import`. */
  endpoint: string;
  /** Nombre con el que se descarga la plantilla. */
  templateName: string;
  /** Qué acepta el archivo, en una línea. */
  hint: string;
  onClose: () => void;
  /** Entró al menos una fila: refrescar el listado. */
  onImported: () => void;
}) {
  const [file, setFile] = useState<File | null>(null);
  const [dragging, setDragging] = useState(false);
  const [busy, setBusy] = useState<"template" | "import" | null>(null);
  const [error, setError] = useState("");
  const [rowErrors, setRowErrors] = useState<string[]>([]);
  const [result, setResult] = useState<Result | null>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  function pick(candidate: File | undefined) {
    if (!candidate) return;
    if (!/\.(xlsx|xls|csv)$/i.test(candidate.name)) {
      setError("Formato no admitido. Usa un archivo .xlsx o .csv.");
      return;
    }
    setFile(candidate);
    setError("");
    setRowErrors([]);
  }

  function onDrop(event: DragEvent<HTMLLabelElement>) {
    event.preventDefault();
    setDragging(false);
    pick(event.dataTransfer.files?.[0]);
  }

  async function downloadTemplate() {
    setBusy("template");
    setError("");
    try {
      const response = await fetch(`${API_BASE}${endpoint}/template`, { headers: authHeaders() });
      if (!response.ok) throw new Error(failure(await response.json().catch(() => null), response.status)[0]);

      const url = URL.createObjectURL(await response.blob());
      const link = document.createElement("a");
      link.href = url;
      link.download = templateName;
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);
    } catch (err) {
      setError(err instanceof Error ? err.message : "No se pudo descargar la plantilla.");
    } finally {
      setBusy(null);
    }
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (!file) {
      setError("Selecciona un archivo Excel o CSV.");
      return;
    }

    setBusy("import");
    setError("");
    setRowErrors([]);
    try {
      const body = new FormData();
      body.append("file", file);
      // Sin Content-Type: el navegador pone el multipart con su separador.
      const response = await fetch(`${API_BASE}${endpoint}/import`, { method: "POST", headers: { ...authHeaders(), Accept: "application/json" }, body });
      const data = await response.json().catch(() => null);

      if (!response.ok) {
        const [message, rows] = failure(data, response.status);
        setError(message);
        setRowErrors(rows.filter((row) => row !== message));
        return;
      }
      setResult(data ?? {});
      onImported();
    } catch {
      setError("No se pudo conectar con el servidor.");
    } finally {
      setBusy(null);
    }
  }

  const warnings = result?.warnings ?? [];

  return (
    <Modal title={title} size="md" onClose={onClose}>
      {result ? (
        <div>
          <div className="flex flex-col items-center py-4 text-center">
            <CheckCircle2 size={44} className="mb-2 text-[color:var(--p-success)]" />
            <p className="text-base font-semibold">Importación terminada</p>
            <p className="app-muted mt-1">{result.message}</p>
          </div>
          {warnings.length > 0 && (
            <Notice tone="warn">
              <strong className="font-semibold">{warnings.length === 1 ? "1 fila no entró tal cual" : `${warnings.length} filas no entraron tal cual`}</strong>
              <ul className="mt-1 max-h-40 list-disc overflow-y-auto pl-4 text-[12.5px]">
                {warnings.map((warning, index) => <li key={index}>{warning}</li>)}
              </ul>
            </Notice>
          )}
          <div className="mt-4 flex justify-end">
            <Btn onClick={onClose}>Cerrar</Btn>
          </div>
        </div>
      ) : (
        <form onSubmit={submit}>
          <div className="mb-3.5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-[color:var(--app-border)] bg-[var(--app-surface-muted)] px-4 py-3">
            <div className="min-w-0">
              <p className="font-semibold">¿Necesitas la estructura correcta?</p>
              <p className="app-muted text-[12.5px]">Descarga la plantilla con las columnas, instrucciones y ejemplos.</p>
            </div>
            <Btn variant="secondary" size="sm" onClick={downloadTemplate} disabled={busy !== null}>
              <Download size={14} />
              <span>{busy === "template" ? "Descargando…" : "Plantilla Excel"}</span>
            </Btn>
          </div>

          <Notice>
            <p className="text-[12.5px]">{hint}</p>
          </Notice>

          <label
            onDragOver={(event) => {
              event.preventDefault();
              setDragging(true);
            }}
            onDragLeave={() => setDragging(false)}
            onDrop={onDrop}
            className={`flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed px-4 py-7 text-center transition-colors ${
              dragging ? "border-[#1B8FC9] bg-[var(--p-brand-soft)]" : "border-[color:var(--app-border)] hover:bg-[var(--app-surface-muted)]"
            }`}
          >
            {file ? <FileSpreadsheet size={28} className="mb-2 text-[color:var(--p-brand)]" /> : <Upload size={28} className="app-muted mb-2" />}
            <span className="font-semibold text-[color:var(--p-brand-text)]">{file ? file.name : "Haz clic para buscar tu archivo o arrástralo aquí"}</span>
            <span className="app-muted mt-1 text-xs">{file ? "Haz clic para cambiarlo" : "Formatos admitidos: .xlsx y .csv (máximo 5 MB)"}</span>
            <input ref={inputRef} type="file" accept=".xlsx,.xls,.csv" className="sr-only" onChange={(event) => pick(event.target.files?.[0])} />
          </label>

          {error && (
            <div className="mt-3 text-[12.5px] text-[color:var(--p-danger-text)]">
              <p className="font-medium">{error}</p>
              {rowErrors.length > 0 && (
                <ul className="mt-1 max-h-32 list-disc overflow-y-auto pl-4">
                  {rowErrors.map((row, index) => <li key={index}>{row}</li>)}
                </ul>
              )}
            </div>
          )}

          <div className="mt-4 flex justify-end gap-2">
            <Btn variant="ghost" onClick={onClose} disabled={busy === "import"}>Cancelar</Btn>
            <Btn type="submit" disabled={busy !== null || !file}>{busy === "import" ? "Importando…" : "Importar archivo"}</Btn>
          </div>
        </form>
      )}
    </Modal>
  );
}
