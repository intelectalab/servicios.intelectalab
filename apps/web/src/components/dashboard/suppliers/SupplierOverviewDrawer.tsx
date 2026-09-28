"use client";

import { useEffect, useState, useCallback } from "react";
import { motion, AnimatePresence } from "framer-motion";
import {
  X,
  Building2,
  User,
  Loader2,
  AlertCircle,
  Info,
  Edit3,
  Save,
  Plus,
  Mail,
  Phone,
  Trash2
} from "lucide-react";
import { apiFetch, ApiError } from "@/lib/api";
import { type Supplier, getSupplierDisplayName, getSupplierDocument } from "@/types/supplier";
import { type Contact } from "@/types/contact";
import { type CustomFieldDefinition, type CustomFieldValue } from "@/types/custom-field";
import { StatusBadge } from "@/components/StatusBadge";
import { ContactModal } from "@/components/dashboard/customers/ContactModal";

interface SupplierOverviewData {
  supplier: Supplier;
  summary: {
    total_cost_entries?: number;
    total_cost_value?: number;
  };
}

interface SupplierOverviewDrawerProps {
  supplierId: string | null;
  onClose: () => void;
  onUpdate: () => void;
}

type SupplierUpdatePayload = {
  party_type: "ORGANIZATION" | "PERSON";
  document_number?: string;
  document_type?: string;
  legal_name?: string;
  trade_name?: string | null;
  given_names?: string;
  family_name?: string;
};

export function SupplierOverviewDrawer({ supplierId, onClose, onUpdate }: SupplierOverviewDrawerProps) {
  const isOpen = Boolean(supplierId);
  const [activeTab, setActiveTab] = useState<"summary" | "custom">("summary");
  
  const [data, setData] = useState<SupplierOverviewData | null>(null);
  const [customValues, setCustomValues] = useState<CustomFieldValue[]>([]);
  const [customDefinitions, setCustomDefinitions] = useState<CustomFieldDefinition[]>([]);
  const [contacts, setContacts] = useState<Contact[]>([]);
  
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  // Estados de Edición
  const [isEditingGeneral, setIsEditingGeneral] = useState(false);
  const [isEditingCustom, setIsEditingCustom] = useState(false);
  
  const [generalForm, setGeneralForm] = useState({
    legal_name: "",
    trade_name: "",
    given_names: "",
    family_name: "",
    document_type: "DNI",
    document_number: "",
  });

  const [editableCustomValues, setEditableCustomValues] = useState<Record<string, string | boolean>>({});

  // Notas Internas (Manejadas mediante EAV con key "internal_notes")
  const [internalNoteId, setInternalNoteId] = useState<string | null>(null);
  const [noteText, setNoteText] = useState("");
  const [isSavingNote, setIsSavingNote] = useState(false);

  // Modal de Contactos
  const [isContactModalOpen, setIsContactModalOpen] = useState(false);
  const [editingContact, setEditingContact] = useState<Contact | null>(null);

  // Modal para Añadir Nuevo Campo Personalizado al vuelo
  const [showNewFieldModal, setShowNewFieldModal] = useState(false);
  const [newFieldData, setNewFieldData] = useState({
    label: "",
    field_type: "text" as "text" | "number" | "date" | "boolean",
  });
  const [isCreatingField, setIsCreatingField] = useState(false);

  const [isSaving, setIsSaving] = useState(false);

  const fetchOverview = useCallback(async () => {
    if (!supplierId) return;
    setLoading(true);
    setError("");
    try {
      const supplierRes = await apiFetch<Supplier>(`/v1/suppliers/${supplierId}`).catch(() => null);
      if (!supplierRes) {
        throw new Error("No se pudo cargar la información general del proveedor.");
      }

      const overviewRes: SupplierOverviewData = {
        supplier: supplierRes,
        summary: {}
      };

      const customRes = await apiFetch<CustomFieldValue[]>(
        `/v1/custom-fields/values?entity_type=Supplier&entity_id=${supplierId}`
      ).catch(() => [] as CustomFieldValue[]);

      const defsRes = await apiFetch<CustomFieldDefinition[]>(
        "/v1/custom-fields/definitions?entity_type=Supplier"
      ).catch(() => [] as CustomFieldDefinition[]);

      // Asegurar que exista la definición de notas internas en el sistema EAV
      let noteDef = defsRes.find(d => d.field_key === "internal_notes");
      if (!noteDef) {
        try {
          noteDef = await apiFetch<CustomFieldDefinition>("/v1/custom-fields/definitions", {
            method: "POST",
            body: JSON.stringify({
              entity_type: "Supplier",
              field_key: "internal_notes",
              label: "Notas Internas",
              field_type: "text",
            }),
          });
          defsRes.push(noteDef);
        } catch {}
      }

      let contactsRes: Contact[] = [];
      if (overviewRes.supplier?.party_id) {
        contactsRes = await apiFetch<Contact[]>(`/v1/contacts?party_id=${overviewRes.supplier.party_id}`).catch(() => []);
      }

      setData(overviewRes);
      setCustomValues(customRes);
      setCustomDefinitions(defsRes);
      setContacts(contactsRes);

      // Extraer nota interna si existe
      if (noteDef) {
        const noteItem = customRes.find(v => v.field_id === noteDef?.id);
        if (noteItem) {
          setInternalNoteId(noteDef.id);
          setNoteText(noteItem.value || "");
        } else {
          setInternalNoteId(noteDef.id);
          setNoteText("");
        }
      }

      const isOrg = overviewRes.supplier.party?.party_type === "ORGANIZATION";
      const orgData = overviewRes.supplier.party?.organization;
      const personData = overviewRes.supplier.party?.person;

      setGeneralForm({
        legal_name: isOrg ? (orgData?.legal_name || "") : "",
        trade_name: isOrg ? (orgData?.trade_name || "") : "",
        given_names: !isOrg ? (personData?.given_names || "") : "",
        family_name: !isOrg ? (personData?.family_name || "") : "",
        document_type: !isOrg ? (personData?.document_type || "DNI") : "RUC",
        document_number: isOrg ? (orgData?.tax_identifier || "") : (personData?.document_number || ""),
      });

      const customMap: Record<string, string | boolean> = {};
      customRes.forEach(item => {
        customMap[item.field_id] = item.value === "true" ? true : item.value === "false" ? false : item.value;
      });
      setEditableCustomValues(customMap);

    } catch (err) {
      setError(err instanceof ApiError ? err.message : (err as Error).message || "No se pudo cargar la ficha 360 del proveedor.");
    } finally {
      setLoading(false);
    }
  }, [supplierId]);

  useEffect(() => {
    if (!isOpen) return;
    const timer = window.setTimeout(() => {
      setIsEditingGeneral(false);
      setIsEditingCustom(false);
      void fetchOverview();
    }, 0);
    return () => window.clearTimeout(timer);
  }, [isOpen, fetchOverview]);

  const handleSaveGeneral = async () => {
    if (!supplierId || !data) return;
    setIsSaving(true);
    try {
      const isOrg = data.supplier.party?.party_type === "ORGANIZATION";
      const payload: SupplierUpdatePayload = {
        party_type: isOrg ? "ORGANIZATION" : "PERSON",
        document_number: generalForm.document_number,
      };

      if (isOrg) {
        payload.legal_name = generalForm.legal_name;
        payload.trade_name = generalForm.trade_name;
      } else {
        payload.document_type = generalForm.document_type;
        payload.given_names = generalForm.given_names;
        payload.family_name = generalForm.family_name;
      }

      await apiFetch(`/v1/suppliers/${supplierId}`, {
        method: "PUT",
        body: JSON.stringify(payload),
      });

      await fetchOverview();
      onUpdate();
      setIsEditingGeneral(false);
    } catch (err) {
      alert(err instanceof ApiError ? err.message : "No se pudo actualizar la información general.");
    } finally {
      setIsSaving(false);
    }
  };

  const handleSaveCustom = async () => {
    if (!supplierId) return;
    setIsSaving(true);
    try {
      const promises = Object.entries(editableCustomValues).map(([field_id, val]) => {
        if (val !== "" && val !== null && val !== undefined) {
          return apiFetch("/v1/custom-fields/values", {
            method: "POST",
            body: JSON.stringify({
              field_id,
              entity_type: "Supplier",
              entity_id: supplierId,
              value: String(val),
            }),
          });
        }
        return Promise.resolve();
      });

      await Promise.all(promises);
      await fetchOverview();
      onUpdate();
      setIsEditingCustom(false);
    } catch {
      alert("No se pudieron guardar los campos adicionales.");
    } finally {
      setIsSaving(false);
    }
  };

  // Guardar Nota Interna vía EAV
  const handleSaveNote = async () => {
    if (!supplierId || !internalNoteId) return;
    setIsSavingNote(true);
    try {
      await apiFetch("/v1/custom-fields/values", {
        method: "POST",
        body: JSON.stringify({
          field_id: internalNoteId,
          entity_type: "Supplier",
          entity_id: supplierId,
          value: noteText,
        }),
      });
      alert("Nota interna guardada correctamente.");
      await fetchOverview();
    } catch {
      alert("No se pudo guardar la nota interna.");
    } finally {
      setIsSavingNote(false);
    }
  };

  // Crear nuevo campo adicional al vuelo
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

      await fetchOverview();
      setEditableCustomValues(prev => ({
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

  // Guardar o Actualizar Contacto
  const handleSaveContact = async (formData: {
    contact_name: string;
    contact_type: string;
    value: string;
    country_code: string;
    is_primary: boolean;
  }) => {
    if (!data?.supplier?.party_id) return;
    setIsSaving(true);
    try {
      if (editingContact) {
        await apiFetch(`/v1/contacts/${editingContact.id}`, { method: "DELETE" }).catch(() => {});
      }

      await apiFetch("/v1/contacts", {
        method: "POST",
        body: JSON.stringify({
          party_id: data.supplier.party_id,
          contact_name: formData.contact_name,
          contact_type: formData.contact_type,
          value: formData.value,
          country_code: formData.country_code,
          is_primary: formData.is_primary,
        }),
      });

      await fetchOverview();
      setIsContactModalOpen(false);
      setEditingContact(null);
    } catch (err) {
      alert(err instanceof ApiError ? err.message : "No se pudo guardar el contacto.");
    } finally {
      setIsSaving(false);
    }
  };

  const handleOpenEditContact = (contact: Contact) => {
    setEditingContact(contact);
    setIsContactModalOpen(true);
  };

  const handleDeleteContact = async (contactId: string) => {
    if (!window.confirm("¿Estás seguro de eliminar este contacto?")) return;
    try {
      await apiFetch(`/v1/contacts/${contactId}`, { method: "DELETE" });
      await fetchOverview();
    } catch {
      alert("No se pudo eliminar el contacto.");
    }
  };

  const supplier = data?.supplier;
  const isOrg = supplier?.party?.party_type === "ORGANIZATION";

  // Filtramos la definición de notas internas para que no se mezcle en la sección de "Campos Dinámicos"
  const visibleCustomDefinitions = customDefinitions.filter(d => d.field_key !== "internal_notes");
  const visibleCustomValues = customValues.filter(v => v.definition?.field_key !== "internal_notes");

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
              className="w-screen max-w-2xl bg-white shadow-2xl flex flex-col"
            >
              {/* Header */}
              <div className="flex items-start justify-between border-b border-slate-200 px-8 py-6 shrink-0 bg-slate-50">
                <div className="flex items-center gap-4">
                  <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-blue-100 text-blue-600 shadow-inner">
                    {isOrg ? <Building2 size={28} /> : <User size={28} />}
                  </div>
                  <div>
                    <div className="flex items-center gap-2">
                      <h2 className="text-xl font-bold text-slate-900">
                        {supplier ? getSupplierDisplayName(supplier) : "Cargando..."}
                      </h2>
                      {supplier && <StatusBadge status={supplier.status} />}
                    </div>
                    <p className="text-xs text-slate-500 mt-0.5">
                      {isOrg ? "Organización (Empresa)" : "Persona Natural"} • Doc: {supplier ? getSupplierDocument(supplier) : ""}
                    </p>
                  </div>
                </div>

                <button
                  onClick={onClose}
                  className="rounded-full p-1.5 text-slate-400 hover:bg-slate-200 hover:text-slate-600 transition-colors"
                >
                  <X size={20} />
                </button>
              </div>

              {/* Tabs */}
              <div className="flex border-b border-slate-200 px-8 bg-white shrink-0">
                <button
                  onClick={() => setActiveTab("summary")}
                  className={`border-b-2 py-3 mr-6 text-sm font-medium transition-colors ${
                    activeTab === "summary"
                      ? "border-[var(--app-brand-deep)] text-[var(--app-brand-deep)]"
                      : "border-transparent text-slate-500 hover:text-slate-700"
                  }`}
                >
                  Resumen
                </button>
                <button
                  onClick={() => setActiveTab("custom")}
                  className={`border-b-2 py-3 text-sm font-medium transition-colors flex items-center gap-1.5 ${
                    activeTab === "custom"
                      ? "border-[var(--app-brand-deep)] text-[var(--app-brand-deep)]"
                      : "border-transparent text-slate-500 hover:text-slate-700"
                  }`}
                >
                  <Info size={15} /> Adicionales ({visibleCustomValues.length})
                </button>
              </div>

              {/* Contenido */}
              <div className="flex-1 overflow-y-auto p-8 bg-white">
                {error ? (
                  <div className="flex flex-col items-center justify-center py-20 text-center">
                    <AlertCircle size={32} className="mb-3 text-red-500" />
                    <p className="text-sm text-slate-600">{error}</p>
                  </div>
                ) : loading ? (
                  <div className="flex flex-col items-center justify-center py-24">
                    <Loader2 size={36} className="animate-spin text-[var(--app-brand-deep)] mb-3" />
                    <p className="text-sm text-slate-500">Cargando ficha 360 del proveedor...</p>
                  </div>
                ) : data && supplier ? (
                  <>
                    {activeTab === "summary" && (
                      <div className="space-y-6">
                        {/* Información General */}
                        <div className="rounded-2xl border border-slate-200 p-6 space-y-4">
                          <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                            <h3 className="text-xs font-semibold uppercase tracking-wider text-slate-500">Información General</h3>
                            <button
                              onClick={() => setIsEditingGeneral(!isEditingGeneral)}
                              className="flex items-center gap-1 text-xs font-medium text-[var(--app-brand-deep)] hover:underline"
                            >
                              <Edit3 size={13} /> {isEditingGeneral ? "Cancelar" : "Editar"}
                            </button>
                          </div>

                          <div className="space-y-3 text-sm">
                            <div className="flex justify-between items-center">
                              <span className="text-slate-500">Tipo de Proveedor</span>
                              <span className="font-medium text-slate-900">{isOrg ? "Organización (Empresa)" : "Persona Natural"}</span>
                            </div>

                            {isOrg ? (
                              <>
                                <div className="flex justify-between items-center">
                                  <span className="text-slate-500">Razón Social *</span>
                                  {isEditingGeneral ? (
                                    <input
                                      type="text"
                                      value={generalForm.legal_name}
                                      onChange={(e) => setGeneralForm({ ...generalForm, legal_name: e.target.value })}
                                      className="w-60 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm focus:border-[var(--app-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--app-brand)]"
                                    />
                                  ) : (
                                    <span className="font-medium text-slate-900">{orgDataText(supplier, 'legal_name')}</span>
                                  )}
                                </div>
                                <div className="flex justify-between items-center">
                                  <span className="text-slate-500">Nombre Comercial</span>
                                  {isEditingGeneral ? (
                                    <input
                                      type="text"
                                      value={generalForm.trade_name}
                                      onChange={(e) => setGeneralForm({ ...generalForm, trade_name: e.target.value })}
                                      className="w-60 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm focus:border-[var(--app-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--app-brand)]"
                                    />
                                  ) : (
                                    <span className="font-medium text-slate-900">{orgDataText(supplier, 'trade_name')}</span>
                                  )}
                                </div>
                                <div className="flex justify-between items-center">
                                  <span className="text-slate-500">RUC *</span>
                                  {isEditingGeneral ? (
                                    <input
                                      type="text"
                                      value={generalForm.document_number}
                                      onChange={(e) => setGeneralForm({ ...generalForm, document_number: e.target.value })}
                                      className="w-48 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm focus:border-[var(--app-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--app-brand)]"
                                    />
                                  ) : (
                                    <span className="font-medium text-slate-900">{getSupplierDocument(supplier)}</span>
                                  )}
                                </div>
                              </>
                            ) : (
                              <>
                                <div className="flex justify-between items-center">
                                  <span className="text-slate-500">Nombres *</span>
                                  {isEditingGeneral ? (
                                    <input
                                      type="text"
                                      value={generalForm.given_names}
                                      onChange={(e) => setGeneralForm({ ...generalForm, given_names: e.target.value })}
                                      className="w-52 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm focus:border-[var(--app-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--app-brand)]"
                                    />
                                  ) : (
                                    <span className="font-medium text-slate-900">{personDataText(supplier, 'given_names')}</span>
                                  )}
                                </div>
                                <div className="flex justify-between items-center">
                                  <span className="text-slate-500">Apellidos *</span>
                                  {isEditingGeneral ? (
                                    <input
                                      type="text"
                                      value={generalForm.family_name}
                                      onChange={(e) => setGeneralForm({ ...generalForm, family_name: e.target.value })}
                                      className="w-52 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm focus:border-[var(--app-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--app-brand)]"
                                    />
                                  ) : (
                                    <span className="font-medium text-slate-900">{personDataText(supplier, 'family_name')}</span>
                                  )}
                                </div>
                                <div className="flex justify-between items-center">
                                  <span className="text-slate-500">Tipo de Documento *</span>
                                  {isEditingGeneral ? (
                                    <select
                                      value={generalForm.document_type}
                                      onChange={(e) => setGeneralForm({ ...generalForm, document_type: e.target.value })}
                                      className="w-48 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm focus:border-[var(--app-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--app-brand)]"
                                    >
                                      <option value="DNI">DNI</option>
                                      <option value="CE">Carnet de Extranjería</option>
                                      <option value="PASSPORT">Pasaporte</option>
                                    </select>
                                  ) : (
                                    <span className="font-medium text-slate-900">{personDataText(supplier, 'document_type')}</span>
                                  )}
                                </div>
                                <div className="flex justify-between items-center">
                                  <span className="text-slate-500">N° Documento *</span>
                                  {isEditingGeneral ? (
                                    <input
                                      type="text"
                                      value={generalForm.document_number}
                                      onChange={(e) => setGeneralForm({ ...generalForm, document_number: e.target.value })}
                                      className="w-48 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm focus:border-[var(--app-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--app-brand)]"
                                    />
                                  ) : (
                                    <span className="font-medium text-slate-900">{getSupplierDocument(supplier)}</span>
                                  )}
                                </div>
                              </>
                            )}
                          </div>

                          {isEditingGeneral && (
                            <div className="pt-3 flex justify-end">
                              <button
                                onClick={handleSaveGeneral}
                                disabled={isSaving}
                                className="flex items-center gap-1.5 rounded-lg bg-[var(--app-brand-deep)] px-4 py-2 text-xs font-medium text-white shadow-sm hover:bg-[var(--app-brand)]"
                              >
                                {isSaving ? <Loader2 size={13} className="animate-spin" /> : <Save size={13} />} Guardar cambios
                              </button>
                            </div>
                          )}
                        </div>

                        {/* Información Adicional (EAV) con botón de Añadir Campo al vuelo */}
                        <div className="rounded-2xl border border-slate-200 p-6 space-y-4">
                          <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                            <h3 className="text-xs font-semibold uppercase tracking-wider text-slate-500">Información Adicional (Campos Dinámicos)</h3>
                            <div className="flex items-center gap-3">
                              <button
                                onClick={() => setShowNewFieldModal(true)}
                                className="flex items-center gap-1 text-xs font-medium text-[var(--app-brand-deep)] hover:underline"
                              >
                                <Plus size={13} /> Nuevo campo
                              </button>
                              {visibleCustomDefinitions.length > 0 && (
                                <button
                                  onClick={() => setIsEditingCustom(!isEditingCustom)}
                                  className="flex items-center gap-1 text-xs font-medium text-[var(--app-brand-deep)] hover:underline"
                                >
                                  <Edit3 size={13} /> {isEditingCustom ? "Cancelar" : "Editar"}
                                </button>
                              )}
                            </div>
                          </div>

                          {/* Modal rápido para crear campo nuevo al vuelo */}
                          {showNewFieldModal && (
                            <form onSubmit={handleCreateCustomField} className="bg-blue-50/50 p-4 rounded-xl border border-blue-100 space-y-3">
                              <div className="flex items-center justify-between">
                                <span className="text-xs font-semibold text-slate-900">Crear nuevo campo personalizado</span>
                                <button type="button" onClick={() => setShowNewFieldModal(false)} className="text-slate-400 hover:text-slate-600"><X size={14} /></button>
                              </div>
                              <div>
                                <label className="mb-1 block text-xs font-medium text-slate-700">Etiqueta *</label>
                                <input
                                  required
                                  type="text"
                                  value={newFieldData.label}
                                  onChange={(e) => setNewFieldData({ ...newFieldData, label: e.target.value })}
                                  placeholder="Ej. Categoría, Banco..."
                                  className="w-full rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm focus:border-[var(--app-brand)] focus:outline-none"
                                />
                              </div>
                              <div className="flex items-center gap-3">
                                <div className="flex-1">
                                  <label className="mb-1 block text-xs font-medium text-slate-700">Tipo de dato</label>
                                  <select
                                    value={newFieldData.field_type}
                                    onChange={(e) => setNewFieldData({
                                      ...newFieldData,
                                      field_type: e.target.value as "text" | "number" | "date" | "boolean",
                                    })}
                                    className="w-full rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm"
                                  >
                                    <option value="text">Texto</option>
                                    <option value="number">Número</option>
                                    <option value="date">Fecha</option>
                                    <option value="boolean">Sí / No</option>
                                  </select>
                                </div>
                                <div className="pt-5">
                                  <button type="submit" disabled={isCreatingField} className="rounded-lg bg-[var(--app-brand-deep)] px-4 py-1.5 text-xs font-medium text-white hover:bg-[var(--app-brand)]">
                                    {isCreatingField ? "Guardando..." : "Crear campo"}
                                  </button>
                                </div>
                              </div>
                            </form>
                          )}

                          {visibleCustomDefinitions.length === 0 ? (
                            <div className="text-center py-4 text-slate-400 text-xs">
                              No hay campos personalizados definidos en el sistema.
                            </div>
                          ) : (
                            <div className="grid grid-cols-2 gap-4 text-sm">
                              {visibleCustomDefinitions.map((def) => {
                                const currentValue = editableCustomValues[def.id] ?? "";
                                return (
                                  <div key={def.id} className="rounded-xl border border-slate-100 bg-slate-50 p-3">
                                    <span className="block text-[10px] uppercase font-semibold text-slate-400 mb-1">
                                      {def.label}
                                    </span>
                                    {isEditingCustom ? (
                                      def.field_type === "boolean" ? (
                                        <input
                                          type="checkbox"
                                          checked={Boolean(currentValue)}
                                          onChange={(e) => setEditableCustomValues({ ...editableCustomValues, [def.id]: e.target.checked })}
                                          className="h-4 w-4 rounded border-slate-300 text-[var(--app-brand-deep)]"
                                        />
                                      ) : (
                                        <input
                                          type={def.field_type === "number" ? "number" : def.field_type === "date" ? "date" : "text"}
                                          value={String(currentValue)}
                                          onChange={(e) => setEditableCustomValues({ ...editableCustomValues, [def.id]: e.target.value })}
                                          className="w-full rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs focus:border-[var(--app-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--app-brand)]"
                                        />
                                      )
                                    ) : (
                                      <span className="text-xs font-semibold text-slate-800">
                                        {currentValue === true ? "Sí" : currentValue === false ? "No" : currentValue || "—"}
                                      </span>
                                    )}
                                  </div>
                                );
                              })}
                            </div>
                          )}

                          {isEditingCustom && (
                            <div className="pt-3 flex justify-end">
                              <button
                                onClick={handleSaveCustom}
                                disabled={isSaving}
                                className="flex items-center gap-1.5 rounded-lg bg-[var(--app-brand-deep)] px-4 py-2 text-xs font-medium text-white shadow-sm hover:bg-[var(--app-brand)]"
                              >
                                {isSaving ? <Loader2 size={13} className="animate-spin" /> : <Save size={13} />} Guardar campos
                              </button>
                            </div>
                          )}
                        </div>

                        {/* Contactos */}
                        <div className="rounded-2xl border border-slate-200 p-6 space-y-4 bg-white shadow-xs">
                          <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                            <h3 className="text-xs font-semibold uppercase tracking-wider text-slate-500">Contactos</h3>
                            <button
                              onClick={() => { setEditingContact(null); setIsContactModalOpen(true); }}
                              className="flex items-center gap-1 text-xs font-medium text-[var(--app-brand-deep)] hover:underline"
                            >
                              <Plus size={13} /> Añadir contacto
                            </button>
                          </div>
                          
                          {contacts.length === 0 ? (
                            <div className="text-center py-6 text-slate-400 text-xs">
                              No hay contactos directos asociados a este proveedor.
                            </div>
                          ) : (
                            <div className="space-y-2.5">
                              {contacts.map((contact) => {
                                const isEmail = contact.contact_type === 'EMAIL';
                                const isAddress = contact.contact_type === 'ADDRESS';
                                
                                return (
                                  <div key={contact.id} className="flex items-center justify-between rounded-xl border border-slate-200/80 bg-slate-50/60 p-3.5 transition-all hover:bg-slate-50 hover:border-slate-300">
                                    <div className="flex items-start gap-3">
                                      <div className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-white border border-slate-200 text-slate-500 shadow-2xs">
                                        {isEmail ? <Mail size={15} /> : isAddress ? <Building2 size={15} /> : <Phone size={15} />}
                                      </div>
                                      <div className="space-y-0.5">
                                        {contact.contact_name && (
                                          <p className="text-xs font-semibold text-slate-900">{contact.contact_name}</p>
                                        )}
                                        <div className="flex items-center gap-2">
                                          <p className="text-xs font-medium text-slate-700">
                                            {contact.country_code ? `${contact.country_code} ` : ""}{contact.value}
                                          </p>
                                          {contact.is_primary && (
                                            <span className="rounded-md bg-blue-50 px-1.5 py-0.5 text-[10px] font-semibold text-blue-600 border border-blue-100">Principal</span>
                                          )}
                                        </div>
                                      </div>
                                    </div>

                                    <div className="flex items-center gap-3">
                                      <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400">{contact.contact_type}</span>
                                      <div className="flex items-center gap-1 border-l border-slate-200 pl-3">
                                        <button onClick={() => handleOpenEditContact(contact)} className="rounded-lg p-1.5 text-slate-400 hover:bg-white hover:text-blue-600 transition-colors" title="Editar">
                                          <Edit3 size={14} />
                                        </button>
                                        <button onClick={() => handleDeleteContact(contact.id)} className="rounded-lg p-1.5 text-slate-400 hover:bg-white hover:text-red-600 transition-colors" title="Eliminar">
                                          <Trash2 size={14} />
                                        </button>
                                      </div>
                                    </div>
                                  </div>
                                );
                              })}
                            </div>
                          )}
                        </div>

                        {/* Notas Internas (Guardadas en el motor EAV de forma limpia) */}
                        <div className="rounded-2xl border border-slate-200 p-6 space-y-3">
                          <h3 className="text-xs font-semibold uppercase tracking-wider text-slate-500 border-b border-slate-100 pb-3">Notas Internas</h3>
                          <textarea
                            value={noteText}
                            onChange={(e) => setNoteText(e.target.value)}
                            placeholder="Escribe una nota importante sobre este proveedor..."
                            className="w-full rounded-xl border border-slate-200 p-3 text-xs focus:border-[var(--app-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--app-brand)]"
                            rows={3}
                          />
                          <div className="flex justify-end">
                            <button 
                              onClick={handleSaveNote}
                              disabled={isSavingNote}
                              className="flex items-center gap-1.5 rounded-lg bg-[var(--app-brand-deep)] px-4 py-2 text-xs font-medium text-white shadow-sm hover:bg-[var(--app-brand)] disabled:opacity-50"
                            >
                              {isSavingNote ? <Loader2 size={13} className="animate-spin" /> : <Save size={13} />} Guardar nota
                            </button>
                          </div>
                        </div>

                        {/* Detalles de Registro */}
                        <div className="rounded-2xl border border-slate-200 p-6 space-y-4">
                          <h3 className="text-xs font-semibold uppercase tracking-wider text-slate-500">Detalles de Registro</h3>
                          <div className="grid grid-cols-2 gap-4 text-sm">
                            <div>
                              <span className="block text-xs text-slate-400">ID del Sistema</span>
                              <span className="font-mono text-xs text-slate-600">{supplier.id}</span>
                            </div>
                            <div>
                              <span className="block text-xs text-slate-400">Fecha de Alta</span>
                              <span className="font-medium text-slate-800">
                                {supplier.created_at ? new Date(supplier.created_at).toLocaleDateString("es-PE") : "—"}
                              </span>
                            </div>
                          </div>
                        </div>
                      </div>
                    )}

                    {/* TAB ADICIONALES */}
                    {activeTab === "custom" && (
                      <div className="space-y-3">
                        {visibleCustomValues.length === 0 ? (
                          <div className="text-center py-16 text-slate-400 text-sm">No hay información adicional registrada.</div>
                        ) : (
                          <div className="grid grid-cols-2 gap-4">
                            {visibleCustomValues.map((item) => (
                              <div key={item.id} className="rounded-xl border border-slate-200 p-4 bg-slate-50/50">
                                <span className="block text-xs font-medium text-slate-400 mb-1">{item.definition.label}</span>
                                <span className="text-sm font-semibold text-slate-800">{item.value === "true" ? "Sí" : item.value === "false" ? "No" : item.value}</span>
                              </div>
                            ))}
                          </div>
                        )}
                      </div>
                    )}
                  </>
                ) : null}
              </div>

              {/* Footer */}
              <div className="shrink-0 border-t border-slate-200 bg-slate-50 px-8 py-4 flex items-center justify-between">
                <span className="text-xs text-slate-400">ERP SaaS - Ficha 360 Proveedor</span>
                <button
                  type="button"
                  onClick={onClose}
                  className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 transition-colors"
                >
                  Cerrar
                </button>
              </div>

            </motion.div>
          </div>

          {/* Componente Modal de Contactos Independiente */}
          <ContactModal
            isOpen={isContactModalOpen}
            onClose={() => setIsContactModalOpen(false)}
            onSave={handleSaveContact}
            initialContact={editingContact}
            isSaving={isSaving}
          />
        </div>
      )}
    </AnimatePresence>
  );
}

function orgDataText(supplier: Supplier, field: "legal_name" | "trade_name") {
  const org = supplier.party?.organization;
  return org?.[field] || "—";
}

function personDataText(supplier: Supplier, field: "given_names" | "family_name" | "document_type") {
  const person = supplier.party?.person;
  return person?.[field] || "—";
}