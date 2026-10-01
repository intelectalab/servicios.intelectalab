"use client";

import { AnimatePresence, motion } from "framer-motion";
import { AlertTriangle, Loader2, X } from "lucide-react";
import type { CustomFieldDefinition } from "@/types/custom-field";

type FieldType = "text" | "number" | "date" | "boolean";

interface CreateCustomFieldModalProps {
  isOpen: boolean;
  form: { label: string; field_type: FieldType };
  isSaving: boolean;
  onChange: (form: { label: string; field_type: FieldType }) => void;
  onClose: () => void;
  onSubmit: (event: React.FormEvent<HTMLFormElement>) => void;
}

export function CreateCustomFieldModal({ isOpen, form, isSaving, onChange, onClose, onSubmit }: CreateCustomFieldModalProps) {
  return (
    <AnimatePresence>
      {isOpen && (
        <div className="fixed inset-0 z-[210] flex items-center justify-center bg-slate-900/40 backdrop-blur-xs">
          <motion.form
            initial={{ opacity: 0, scale: 0.95 }}
            animate={{ opacity: 1, scale: 1 }}
            exit={{ opacity: 0, scale: 0.95 }}
            onSubmit={onSubmit}
            className="w-full max-w-md space-y-4 rounded-2xl bg-white p-6 text-slate-900 shadow-2xl"
          >
            <div className="flex items-center justify-between border-b border-slate-100 pb-3">
              <h3 className="text-base font-bold">Agregar nueva columna personalizada</h3>
              <button type="button" onClick={onClose} className="text-slate-400 hover:text-slate-600" aria-label="Cerrar">
                <X size={18} />
              </button>
            </div>
            <div>
              <label className="mb-1 block text-xs font-medium text-slate-700">Nombre de la columna (Etiqueta) *</label>
              <input
                required
                type="text"
                value={form.label}
                onChange={(event) => onChange({ ...form, label: event.target.value })}
                placeholder="Ej. Categoría, Región, Canal..."
                className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-[var(--app-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--app-brand)]"
              />
            </div>
            <div>
              <label className="mb-1 block text-xs font-medium text-slate-700">Tipo de dato</label>
              <select
                value={form.field_type}
                onChange={(event) => onChange({ ...form, field_type: event.target.value as FieldType })}
                className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-[var(--app-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--app-brand)]"
              >
                <option value="text">Texto</option>
                <option value="number">Número</option>
                <option value="date">Fecha</option>
                <option value="boolean">Sí / No</option>
              </select>
            </div>
            <div className="flex justify-end gap-3 pt-4">
              <button type="button" disabled={isSaving} onClick={onClose} className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50">
                Cancelar
              </button>
              <button type="submit" disabled={isSaving} className="flex items-center gap-1.5 rounded-lg bg-[var(--app-brand-deep)] px-4 py-2 text-xs font-medium text-white shadow-sm hover:bg-[var(--app-brand)]">
                {isSaving && <Loader2 size={13} className="animate-spin" />}
                Crear columna
              </button>
            </div>
          </motion.form>
        </div>
      )}
    </AnimatePresence>
  );
}

interface BulkActionModalProps {
  type: "activate" | "deactivate" | null;
  ids: string[];
  isProcessing: boolean;
  onCancel: () => void;
  onConfirm: () => void;
}

export function BulkActionModal({ type, ids, isProcessing, onCancel, onConfirm }: BulkActionModalProps) {
  const isOpen = type !== null && ids.length > 0;
  const isDeactivation = type === "deactivate";

  return (
    <AnimatePresence>
      {isOpen && (
        <div className="fixed inset-0 z-[210] flex items-center justify-center bg-slate-900/40 backdrop-blur-xs">
          <motion.div initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }} exit={{ opacity: 0, scale: 0.95 }} className="w-full max-w-md space-y-4 rounded-2xl bg-white p-6 text-slate-900 shadow-2xl">
            <div className="flex items-center gap-3 border-b border-slate-100 pb-3">
              <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-full ${isDeactivation ? "bg-red-50 text-red-600" : "bg-emerald-50 text-emerald-600"}`}>
                <AlertTriangle size={20} />
              </div>
              <div>
                <h3 className="text-base font-bold">{isDeactivation ? "Desactivar clientes" : "Activar clientes"}</h3>
                <p className="text-xs text-slate-500">Esta acción afectará a los registros seleccionados.</p>
              </div>
            </div>
            <p className="text-sm text-slate-600">¿Estás seguro de que deseas {isDeactivation ? "desactivar" : "activar"} <strong>{ids.length}</strong> cliente(s)?</p>
            <div className="flex justify-end gap-3 pt-2">
              <button type="button" disabled={isProcessing} onClick={onCancel} className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50">Cancelar</button>
              <button type="button" disabled={isProcessing} onClick={onConfirm} className={`flex items-center gap-1.5 rounded-lg px-4 py-2 text-xs font-medium text-white shadow-sm ${isDeactivation ? "bg-red-600 hover:bg-red-700" : "bg-emerald-600 hover:bg-emerald-700"}`}>
                {isProcessing && <Loader2 size={13} className="animate-spin" />}
                {isDeactivation ? "Sí, desactivar" : "Sí, activar"}
              </button>
            </div>
          </motion.div>
        </div>
      )}
    </AnimatePresence>
  );
}

interface DeleteCustomFieldModalProps {
  definition: CustomFieldDefinition | null;
  isDeleting: boolean;
  onCancel: () => void;
  onConfirm: () => void;
}

export function DeleteCustomFieldModal({ definition, isDeleting, onCancel, onConfirm }: DeleteCustomFieldModalProps) {
  return (
    <AnimatePresence>
      {definition && (
        <div className="fixed inset-0 z-[220] flex items-center justify-center bg-slate-900/40 backdrop-blur-xs">
          <motion.div initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }} exit={{ opacity: 0, scale: 0.95 }} className="w-full max-w-md space-y-4 rounded-2xl bg-white p-6 text-slate-900 shadow-2xl">
            <div className="flex items-center gap-3 border-b border-slate-100 pb-3">
              <div className="flex h-10 w-10 items-center justify-center rounded-full bg-red-50 text-red-600"><AlertTriangle size={20} /></div>
              <div><h3 className="text-base font-bold">Eliminar columna</h3><p className="text-xs text-slate-500">Esta acción no se puede deshacer.</p></div>
            </div>
            <p className="text-sm text-slate-600">¿Seguro que deseas eliminar la columna <strong>&quot;{definition.label}&quot;</strong> y sus valores?</p>
            <div className="flex justify-end gap-3 pt-2">
              <button type="button" disabled={isDeleting} onClick={onCancel} className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50">Cancelar</button>
              <button type="button" disabled={isDeleting} onClick={onConfirm} className="flex items-center gap-1.5 rounded-lg bg-red-600 px-4 py-2 text-xs font-medium text-white shadow-sm hover:bg-red-700">
                {isDeleting && <Loader2 size={13} className="animate-spin" />}
                Eliminar columna
              </button>
            </div>
          </motion.div>
        </div>
      )}
    </AnimatePresence>
  );
}
