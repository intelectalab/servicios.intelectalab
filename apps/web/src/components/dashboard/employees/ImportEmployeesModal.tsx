"use client";

import { useState } from "react";
import { motion, AnimatePresence } from "framer-motion";
import { X, Upload, FileSpreadsheet, Loader2, CheckCircle2, Download, Info } from "lucide-react";

interface ImportEmployeesModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSuccess: () => void;
}

export function ImportEmployeesModal({ isOpen, onClose, onSuccess }: ImportEmployeesModalProps) {
  const [file, setFile] = useState<File | null>(null);
  const [isUploading, setIsUploading] = useState(false);
  const [isDownloadingTemplate, setIsDownloadingTemplate] = useState(false);
  const [uploadResult, setUploadResult] = useState<{ imported?: number; message?: string } | null>(null);
  const [error, setError] = useState("");

  const apiUrl = process.env.NEXT_PUBLIC_API_URL || "http://localhost:8000/api";

  const authHeaders = () => ({
    Authorization: `Bearer ${localStorage.getItem("token") || ""}`,
    "X-Tenant-ID": localStorage.getItem("tenant_id") || "",
  });

  // Descarga la plantilla .xlsx para empleados
  const handleDownloadTemplate = async () => {
    setIsDownloadingTemplate(true);
    setError("");
    try {
      const res = await fetch(`${apiUrl}/v1/employees/template`, {
        headers: authHeaders(),
      });

      if (!res.ok) {
        let detail = `Error ${res.status} al generar la plantilla.`;
        try {
          const data = await res.json();
          detail = data.message || detail;
        } catch {
          // respuesta no era JSON
        }
        throw new Error(detail);
      }

      const blob = await res.blob();
      const url = window.URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;
      link.setAttribute("download", "plantilla_importacion_empleados.xlsx");
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      window.URL.revokeObjectURL(url);
    } catch (err) {
      setError((err instanceof Error && err.message) || "No se pudo descargar la plantilla.");
    } finally {
      setIsDownloadingTemplate(false);
    }
  };

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    if (e.target.files && e.target.files[0]) {
      setFile(e.target.files[0]);
      setError("");
    }
  };

  const handleUpload = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!file) {
      setError("Por favor selecciona un archivo Excel o CSV.");
      return;
    }

    setIsUploading(true);
    setError("");

    const formData = new FormData();
    formData.append("file", file);

    try {
      const res = await fetch(`${apiUrl}/v1/employees/import`, {
        method: "POST",
        headers: {
          ...authHeaders(),
          Accept: "application/json",
        },
        body: formData,
      });

      const responseText = await res.text();
      let data: { errors?: Record<string, string[]>; message?: string } = {};
      try {
        data = responseText ? JSON.parse(responseText) : {};
      } catch {
        data = { message: responseText || `Error ${res.status} al importar el archivo.` };
      }

      if (!res.ok) {
        console.error("Detalle del error:", data);
        const errorMessage = data.errors && Object.keys(data.errors).length > 0
          ? Object.values(data.errors).flat().join(", ")
          : (data.message || `Error ${res.status} al importar el archivo.`);
        throw new Error(errorMessage);
      }

      setUploadResult(data);
      setTimeout(() => {
        onSuccess();
        handleClose();
      }, 2000);
    } catch (err) {
      setError((err instanceof Error && err.message) || "Ocurrió un error al importar el archivo.");
    } finally {
      setIsUploading(false);
    }
  };

  const handleClose = () => {
    setFile(null);
    setUploadResult(null);
    setError("");
    onClose();
  };

  return (
    <AnimatePresence>
      {isOpen && (
        <div className="fixed inset-0 z-[210] flex items-center justify-center bg-slate-900/40 backdrop-blur-xs px-4">
          <motion.div
            initial={{ opacity: 0, scale: 0.95 }}
            animate={{ opacity: 1, scale: 1 }}
            exit={{ opacity: 0, scale: 0.95 }}
            className="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl space-y-5 text-slate-900"
          >
            <div className="flex items-center justify-between border-b border-slate-100 pb-3">
              <div className="flex items-center gap-2.5">
                <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-sky-50 text-[#1C8FC5]">
                  <FileSpreadsheet size={20} />
                </div>
                <div>
                  <h3 className="text-base font-bold">Importar Empleados Masivamente</h3>
                  <p className="text-xs text-slate-500">Sube tu archivo y añade columnas personalizadas al vuelo</p>
                </div>
              </div>
              <button onClick={handleClose} className="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600">
                <X size={18} />
              </button>
            </div>

            {uploadResult ? (
              <div className="py-8 text-center space-y-3">
                <CheckCircle2 size={48} className="mx-auto text-emerald-500" />
                <h4 className="text-base font-bold text-slate-800">¡Importación exitosa!</h4>
                <p className="text-sm text-slate-600">{uploadResult.message || `Se importaron ${uploadResult.imported || 0} empleados correctamente.`}</p>
              </div>
            ) : (
              <form onSubmit={handleUpload} className="space-y-4">
                {/* Descarga de plantilla Excel con formato y ejemplos */}
                <div className="flex items-center justify-between rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                  <div>
                    <p className="text-xs font-semibold text-slate-800">¿Necesitas la estructura correcta?</p>
                    <p className="text-[11px] text-slate-500">Descarga la plantilla con ejemplos para Empleados.</p>
                  </div>
                  <button
                    type="button"
                    onClick={handleDownloadTemplate}
                    disabled={isDownloadingTemplate}
                    className="flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors shadow-xs disabled:opacity-50"
                  >
                    {isDownloadingTemplate ? <Loader2 size={13} className="animate-spin" /> : <Download size={13} />}
                    Plantilla Excel
                  </button>
                </div>

                {/* Nota informativa sobre columnas extra */}
                <div className="flex items-start gap-2 rounded-xl bg-sky-50/60 p-3 text-sky-900">
                  <Info size={16} className="mt-0.5 shrink-0 text-[#1C8FC5]" />
                  <p className="text-[11px] leading-relaxed">
                    Puedes escribir los nombres de tus columnas con espacios o guiones. El sistema creará o mapeará las columnas automáticamente.
                  </p>
                </div>

                <div className="flex flex-col items-center justify-center rounded-xl border-2 border-dashed border-slate-200 bg-slate-50/50 p-6 text-center hover:bg-slate-50 transition-colors">
                  <Upload size={28} className="mb-2 text-slate-400" />
                  <label className="cursor-pointer text-xs font-semibold text-[var(--app-brand-deep)] hover:underline">
                    <span>Haz clic para buscar tu archivo</span>
                    <input type="file" accept=".xlsx,.xls,.csv" onChange={handleFileChange} className="hidden" />
                  </label>
                  <p className="mt-1 text-[11px] text-slate-400">{file ? file.name : "Formatos soportados: .xlsx, .csv"}</p>
                </div>

                {error && <p className="text-xs font-medium text-red-600">{error}</p>}

                <div className="flex justify-end gap-3 pt-2">
                  <button
                    type="button"
                    disabled={isUploading}
                    onClick={handleClose}
                    className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 transition-colors"
                  >
                    Cancelar
                  </button>
                  <button
                    type="submit"
                    disabled={isUploading || !file}
                    className="flex items-center gap-1.5 rounded-xl bg-[var(--app-brand-deep)] px-4 py-2 text-xs font-medium text-white shadow-sm hover:bg-[var(--app-brand)] transition-colors disabled:opacity-50"
                  >
                    {isUploading && <Loader2 size={13} className="animate-spin" />}
                    Importar archivo
                  </button>
                </div>
              </form>
            )}
          </motion.div>
        </div>
      )}
    </AnimatePresence>
  );
}