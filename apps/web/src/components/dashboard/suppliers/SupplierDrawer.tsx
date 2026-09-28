"use client";

import { useCallback, useEffect, useState } from "react";
import { motion, AnimatePresence } from "framer-motion";
import {
  X,
  Building2,
  User,
  Loader2,
  AlertCircle,
  Plus
} from "lucide-react";
import { apiFetch, ApiError } from "@/lib/api";

type PartyType = "ORGANIZATION" | "PERSON";

interface CustomFieldDefinition {
  id: string;
  field_key: string;
  label: string;
  field_type: "text" | "number" | "date" | "boolean";
}

type SupplierPayload = {
  party_type: PartyType;
  document_number: string;
  document_type?: string;
  legal_name?: string;
  trade_name?: string;
  given_names?: string;
  family_name?: string;
};

interface SupplierDrawerProps {
  isOpen: boolean;
  onClose: () => void;
  onSuccess: () => void;
}

const initialFormState = {
  document_type: "DNI",
  document_number: "",
  legal_name: "",
  trade_name: "",
  given_names: "",
  family_name: "",
};

export function SupplierDrawer({ isOpen, onClose, onSuccess }: SupplierDrawerProps) {
  const [partyType, setPartyType] = useState<PartyType>("ORGANIZATION");
  const [formData, setFormData] = useState(initialFormState);

  // Campos dinámicos (EAV)
  const [customDefinitions, setCustomDefinitions] = useState<CustomFieldDefinition[]>([]);
  const [customValues, setCustomValues] = useState<Record<string, string | boolean>>({});
  
  // Estado para el modal/formulario rápido de "Nuevo Campo Personalizado"
  const [showNewFieldModal, setShowNewFieldModal] = useState(false);
  const [newFieldData, setNewFieldData] = useState({
    label: "",
    field_type: "text" as "text" | "number" | "date" | "boolean",
  });
  const [isCreatingField, setIsCreatingField] = useState(false);

  const [isLoadingFields, setIsLoadingFields] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [saveActionType, setSaveActionType] = useState<"close" | "another" | null>(null);
  const [error, setError] = useState("");

  const loadCustomFields = async () => {
    try {
      const defs = await apiFetch<CustomFieldDefinition[]>(
        "/v1/custom-fields/definitions?entity_type=Supplier"
      );
      setCustomDefinitions(defs);
      
      setCustomValues((prev) => {
        const updated = { ...prev };
        defs.forEach((def) => {
          if (updated[def.id] === undefined) {
            updated[def.id] = def.field_type === "boolean" ? false : "";
          }
        });
        return updated;
      });
    } catch (err) {
      console.error("No se pudieron cargar los campos personalizados", err);
    } finally {
      setIsLoadingFields(false);
    }
  };

  const handleStandardChange = (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
    const { name, value } = e.target;
    setFormData((prev) => ({ ...prev, [name]: value }));
  };

  const handleCustomChange = (fieldId: string, value: string | boolean) => {
    setCustomValues((prev) => ({ ...prev, [fieldId]: value }));
  };

  const handleCreateCustomField = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newFieldData.label.trim()) return;

    setIsCreatingField(true);
    try {
      const field_key = newFieldData.label
        .toLowerCase()
        .trim()
        .replace(/[^\w\s]/gi, '')
        .replace(/\s+/g, '_');

      const createdField = await apiFetch<CustomFieldDefinition>("/v1/custom-fields/definitions", {
        method: "POST",
        body: JSON.stringify({
          entity_type: "Supplier",
          field_key,
          label: newFieldData.label,
          field_type: newFieldData.field_type,
        }),
      });

      await loadCustomFields();
      setCustomValues(prev => ({
        ...prev,
        [createdField.id]: createdField.field_type === "boolean" ? false : ""
      }));

      setNewFieldData({ label: "", field_type: "text" });
      setShowNewFieldModal(false);
    } catch (err) {
      alert(err instanceof ApiError ? err.message : "No se pudo crear el campo personalizado.");
    } finally {
      setIsCreatingField(false);
    }
  };

  const resetForm = useCallback(() => {
    setFormData(initialFormState);
    setPartyType("ORGANIZATION");
    const resetValues: Record<string, string | boolean> = {};
    customDefinitions.forEach((def) => {
      resetValues[def.id] = def.field_type === "boolean" ? false : "";
    });
    setCustomValues(resetValues);
    setError("");
  }, [customDefinitions]);

  useEffect(() => {
    if (isOpen) {
      const timer = window.setTimeout(() => void loadCustomFields(), 0);
      return () => window.clearTimeout(timer);
    }

    const timer = window.setTimeout(resetForm, 0);
    return () => window.clearTimeout(timer);
  }, [isOpen, resetForm]);

  const executeSave = async (action: "close" | "another") => {
    setError("");
    setSaveActionType(action);
    setIsSaving(true);

    try {
      const payload: SupplierPayload = {
        party_type: partyType,
        document_number: formData.document_number,
      };

      if (partyType === "ORGANIZATION") {
        payload.legal_name = formData.legal_name;
        payload.trade_name = formData.trade_name;
      } else {
        payload.document_type = formData.document_type;
        payload.given_names = formData.given_names;
        payload.family_name = formData.family_name;
      }

      const newSupplier = await apiFetch<{ id: string }>("/v1/suppliers", {
        method: "POST",
        body: JSON.stringify(payload),
      });

      const customFieldPromises = customDefinitions.map((def) => {
        const val = customValues[def.id];
        if (val !== "" && val !== false && val !== null) {
          return apiFetch("/v1/custom-fields/values", {
            method: "POST",
            body: JSON.stringify({
              field_id: def.id,
              entity_type: "Supplier",
              entity_id: newSupplier.id,
              value: String(val),
            }),
          });
        }
        return Promise.resolve();
      });

      await Promise.all(customFieldPromises);

      onSuccess();

      if (action === "close") {
        onClose();
      } else {
        resetForm();
      }
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Ocurrió un error al guardar.");
    } finally {
      setIsSaving(false);
      setSaveActionType(null);
    }
  };

  return (
    <AnimatePresence>
      {isOpen && (
        <div className="fixed inset-0 z-50 overflow-hidden">
          <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            onClick={onClose}
            className="absolute inset-0 bg-slate-900/30 backdrop-blur-sm"
          />

          <div className="absolute inset-y-0 right-0 flex max-w-full pl-10">
            <motion.div
              initial={{ x: "100%" }}
              animate={{ x: 0 }}
              exit={{ x: "100%" }}
              transition={{ type: "spring", damping: 25, stiffness: 200 }}
              className="w-screen max-w-xl bg-white shadow-2xl flex flex-col"
            >
              {/* Header */}
              <div className="flex items-center justify-between border-b border-slate-200 px-6 py-4 shrink-0">
                <h2 className="text-lg font-bold text-slate-900">Crear Proveedor</h2>
                <button
                  onClick={onClose}
                  className="rounded-full p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-colors"
                >
                  <X size={20} />
                </button>
              </div>

              {/* Contenido scrolleable */}
              <div className="flex-1 overflow-y-auto p-6 space-y-6">
                {error && (
                  <div className="flex items-center gap-3 rounded-xl bg-red-50 p-4 text-sm text-red-800 border border-red-100">
                    <AlertCircle size={20} className="text-red-500 shrink-0" />
                    <p>{error}</p>
                  </div>
                )}

                {/* Tipo de Proveedor */}
                <div>
                  <label className="mb-2 block text-xs font-semibold uppercase tracking-wider text-slate-500">
                    Tipo de Proveedor
                  </label>
                  <div className="grid grid-cols-2 gap-3">
                    <button
                      type="button"
                      onClick={() => setPartyType("ORGANIZATION")}
                      className={`flex items-center gap-3 rounded-xl border-2 p-3 text-left transition-all ${
                        partyType === "ORGANIZATION" 
                          ? "border-[var(--app-brand-deep)] bg-blue-50/50" 
                          : "border-slate-200 bg-white hover:border-slate-300"
                      }`}
                    >
                      <Building2 size={20} className={partyType === "ORGANIZATION" ? "text-[var(--app-brand-deep)]" : "text-slate-400"} />
                      <div>
                        <p className="text-xs font-semibold text-slate-900">Organización</p>
                        <p className="text-[10px] text-slate-500">Empresa / RUC</p>
                      </div>
                    </button>

                    <button
                      type="button"
                      onClick={() => setPartyType("PERSON")}
                      className={`flex items-center gap-3 rounded-xl border-2 p-3 text-left transition-all ${
                        partyType === "PERSON" 
                          ? "border-[var(--app-brand-deep)] bg-blue-50/50" 
                          : "border-slate-200 bg-white hover:border-slate-300"
                      }`}
                    >
                      <User size={20} className={partyType === "PERSON" ? "text-[var(--app-brand-deep)]" : "text-slate-400"} />
                      <div>
                        <p className="text-xs font-semibold text-slate-900">Persona Natural</p>
                        <p className="text-[10px] text-slate-500">Individual / DNI</p>
                      </div>
                    </button>
                  </div>
                </div>

                {/* Datos Principales */}
                <div className="space-y-4">
                  <h3 className="text-xs font-semibold uppercase tracking-wider text-slate-500">Datos Principales</h3>
                  
                  {partyType === "ORGANIZATION" ? (
                    <>
                      <div>
                        <label className="mb-1 block text-sm font-medium text-slate-700">Razón Social *</label>
                        <input
                          required
                          type="text"
                          name="legal_name"
                          value={formData.legal_name}
                          onChange={handleStandardChange}
                          className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--app-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--app-brand)]"
                          placeholder="Ej. Suministros Generales S.A.C."
                        />
                      </div>
                      <div className="grid grid-cols-2 gap-4">
                        <div>
                          <label className="mb-1 block text-sm font-medium text-slate-700">Nombre Comercial</label>
                          <input
                            type="text"
                            name="trade_name"
                            value={formData.trade_name}
                            onChange={handleStandardChange}
                            className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--app-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--app-brand)]"
                            placeholder="Opcional"
                          />
                        </div>
                        <div>
                          <label className="mb-1 block text-sm font-medium text-slate-700">RUC *</label>
                          <input
                            required
                            type="text"
                            name="document_number"
                            value={formData.document_number}
                            onChange={handleStandardChange}
                            className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--app-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--app-brand)]"
                            placeholder="20XXXXXXXXX"
                          />
                        </div>
                      </div>
                    </>
                  ) : (
                    <>
                      <div className="grid grid-cols-2 gap-4">
                        <div>
                          <label className="mb-1 block text-sm font-medium text-slate-700">Nombres *</label>
                          <input
                            required
                            type="text"
                            name="given_names"
                            value={formData.given_names}
                            onChange={handleStandardChange}
                            className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--app-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--app-brand)]"
                          />
                        </div>
                        <div>
                          <label className="mb-1 block text-sm font-medium text-slate-700">Apellidos *</label>
                          <input
                            required
                            type="text"
                            name="family_name"
                            value={formData.family_name}
                            onChange={handleStandardChange}
                            className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--app-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--app-brand)]"
                          />
                        </div>
                      </div>
                      <div className="grid grid-cols-2 gap-4">
                        <div>
                          <label className="mb-1 block text-sm font-medium text-slate-700">Tipo Documento *</label>
                          <select
                            name="document_type"
                            value={formData.document_type}
                            onChange={handleStandardChange}
                            className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--app-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--app-brand)] bg-white"
                          >
                            <option value="DNI">DNI</option>
                            <option value="CE">Carnet de Extranjería</option>
                            <option value="PASSPORT">Pasaporte</option>
                          </select>
                        </div>
                        <div>
                          <label className="mb-1 block text-sm font-medium text-slate-700">N° Documento *</label>
                          <input
                            required
                            type="text"
                            name="document_number"
                            value={formData.document_number}
                            onChange={handleStandardChange}
                            className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--app-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--app-brand)]"
                          />
                        </div>
                      </div>
                    </>
                  )}
                </div>

                {/* INFORMACIÓN ADICIONAL & NUEVO CAMPO */}
                <div className="space-y-4">
                  <div className="flex items-center justify-between">
                    <h3 className="text-xs font-semibold uppercase tracking-wider text-slate-500">Información Adicional</h3>
                    <button
                      type="button"
                      onClick={() => setShowNewFieldModal(true)}
                      className="flex items-center gap-1 text-xs font-medium text-[var(--app-brand-deep)] hover:underline"
                    >
                      <Plus size={14} /> Agregar campo personalizado
                    </button>
                  </div>

                  {/* Formulario rápido colapsable para crear un campo */}
                  {showNewFieldModal && (
                    <form onSubmit={handleCreateCustomField} className="bg-blue-50/50 p-4 rounded-xl border border-blue-100 space-y-3">
                      <div className="flex items-center justify-between">
                        <span className="text-xs font-semibold text-slate-900">Nuevo Campo Dinámico</span>
                        <button type="button" onClick={() => setShowNewFieldModal(false)} className="text-slate-400 hover:text-slate-600">
                          <X size={14} />
                        </button>
                      </div>
                      <div>
                        <label className="mb-1 block text-xs font-medium text-slate-700">Nombre de la Etiqueta *</label>
                        <input
                          required
                          type="text"
                          placeholder="Ej. Categoría, Banco..."
                          value={newFieldData.label}
                          onChange={(e) => setNewFieldData(prev => ({ ...prev, label: e.target.value }))}
                          className="w-full rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm focus:border-[var(--app-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--app-brand)]"
                        />
                      </div>
                      <div className="flex items-center gap-3">
                        <div className="flex-1">
                          <label className="mb-1 block text-xs font-medium text-slate-700">Tipo de Dato *</label>
                          <select
                            value={newFieldData.field_type}
                            onChange={(e) => setNewFieldData(prev => ({
                              ...prev,
                              field_type: e.target.value as CustomFieldDefinition["field_type"],
                            }))}
                            className="w-full rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm focus:border-[var(--app-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--app-brand)]"
                          >
                            <option value="text">Texto</option>
                            <option value="number">Número</option>
                            <option value="date">Fecha</option>
                            <option value="boolean">Sí / No (Booleano)</option>
                          </select>
                        </div>
                        <div className="pt-5">
                          <button
                            type="submit"
                            disabled={isCreatingField}
                            className="rounded-lg bg-[var(--app-brand-deep)] px-4 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-[var(--app-brand)] disabled:opacity-50 flex items-center gap-1.5"
                          >
                            {isCreatingField ? <Loader2 size={12} className="animate-spin" /> : "Guardar campo"}
                          </button>
                        </div>
                      </div>
                    </form>
                  )}

                  {/* Renderizado de los campos personalizados existentes */}
                  {!isLoadingFields && customDefinitions.length > 0 && (
                    <div className="space-y-4 bg-slate-50 p-4 rounded-xl border border-slate-100">
                      {customDefinitions.map((field) => (
                        <div key={field.id} className={field.field_type === "boolean" ? "flex items-center gap-3 pt-2" : ""}>
                          {field.field_type !== "boolean" && (
                            <label className="mb-1 block text-xs font-medium text-slate-700">
                              {field.label}
                            </label>
                          )}

                          {field.field_type === "text" && (
                            <input
                              type="text"
                              value={String(customValues[field.id] || "")}
                              onChange={(e) => handleCustomChange(field.id, e.target.value)}
                              className="w-full rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm focus:border-[var(--app-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--app-brand)]"
                            />
                          )}

                          {field.field_type === "number" && (
                            <input
                              type="number"
                              value={String(customValues[field.id] || "")}
                              onChange={(e) => handleCustomChange(field.id, e.target.value)}
                              className="w-full rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm focus:border-[var(--app-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--app-brand)]"
                            />
                          )}

                          {field.field_type === "date" && (
                            <input
                              type="date"
                              value={String(customValues[field.id] || "")}
                              onChange={(e) => handleCustomChange(field.id, e.target.value)}
                              className="w-full rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm focus:border-[var(--app-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--app-brand)]"
                            />
                          )}

                          {field.field_type === "boolean" && (
                            <>
                              <input
                                type="checkbox"
                                id={`drawer-supplier-custom-${field.id}`}
                                checked={Boolean(customValues[field.id])}
                                onChange={(e) => handleCustomChange(field.id, e.target.checked)}
                                className="h-4 w-4 rounded border-slate-300 text-[var(--app-brand-deep)] focus:ring-[var(--app-brand)] cursor-pointer"
                              />
                              <label htmlFor={`drawer-supplier-custom-${field.id}`} className="text-sm font-medium text-slate-700 cursor-pointer">
                                {field.label}
                              </label>
                            </>
                          )}
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              </div>

              {/* Footer */}
              <div className="shrink-0 border-t border-slate-200 bg-slate-50 px-6 py-4 flex items-center justify-end gap-3">
                <button
                  type="button"
                  onClick={onClose}
                  className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 transition-colors"
                >
                  Cancelar
                </button>
                <button
                  type="button"
                  disabled={isSaving}
                  onClick={() => executeSave("another")}
                  className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 transition-colors disabled:opacity-50"
                >
                  {isSaving && saveActionType === "another" ? (
                    <span className="flex items-center gap-1.5"><Loader2 size={14} className="animate-spin" /> Guardando...</span>
                  ) : (
                    "Crear y agregar otro"
                  )}
                </button>
                <button
                  type="button"
                  disabled={isSaving}
                  onClick={() => executeSave("close")}
                  className="rounded-lg bg-[var(--app-brand-deep)] px-4 py-2 text-sm font-medium text-white shadow-sm transition-colors hover:bg-[var(--app-brand)] disabled:opacity-50"
                >
                  {isSaving && saveActionType === "close" ? (
                    <span className="flex items-center gap-1.5"><Loader2 size={14} className="animate-spin" /> Guardando...</span>
                  ) : (
                    "Crear"
                  )}
                </button>
              </div>

            </motion.div>
          </div>
        </div>
      )}
    </AnimatePresence>
  );
}