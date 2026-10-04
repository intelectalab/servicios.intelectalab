"use client";

import { useEffect, useState, useCallback } from "react";
import { motion, AnimatePresence } from "framer-motion";
import {
  X,
  Building2,
  User,
  Loader2,
  AlertCircle,
  FileText,
  Briefcase,
  Info,
  Calendar,
  Edit3,
  Save,
  Plus,
  Mail,
  Phone,
  Trash2
} from "lucide-react";
import { apiFetch, ApiError } from "@/lib/api";
import { type Customer, getCustomerDisplayName, getCustomerDocument } from "@/types/customer";
import { type Contact } from "@/types/contact";
import { type CustomFieldDefinition, type CustomFieldValue } from "@/types/custom-field";
import { StatusBadge } from "@/components/StatusBadge";
import { ContactModal } from "@/components/dashboard/customers/ContactModal";

interface OverviewData {
  customer: Customer;
  quotations: Array<{
    id: string;
    quotation_number: string;
    status: string;
    total: number;
    currency: string;
    created_at: string;
  }>;
  contracts: Array<{
    id: string;
    contract_number: string;
    status: string;
    total_value: number;
    currency: string;
    start_date: string;
    end_date: string;
  }>;
  summary: {
    total_quotations: number;
    total_contracts: number;
    active_contracts: number;
    total_contracted_value: number;
  };
}

interface CustomerOverviewDrawerProps {
  customerId: string | null;
  onClose: () => void;
  onUpdate: () => void;
}

type CustomerUpdatePayload = {
  party_type: "ORGANIZATION" | "PERSON";
  document_number?: string;
  document_type?: string;
  payment_terms_days: number | null;
  credit_limit: number | string | null;
  preferred_currency: "PEN" | "USD";
  legal_name?: string;
  trade_name?: string | null;
  given_names?: string;
  family_name?: string;
};

function formatCurrency(value: string | number | null, currency = "PEN") {
  if (value === null || value === undefined) return "—";
  const num = typeof value === "string" ? parseFloat(value) : value;
  if (Number.isNaN(num)) return "—";
  return num.toLocaleString("es-PE", { style: "currency", currency });
}

function formatDateOnly(dateString: string) {
  if (!dateString) return "—";
  const dateObj = new Date(dateString);
  if (isNaN(dateObj.getTime())) return dateString.split("T")[0];
  return dateObj.toLocaleDateString("es-PE");
}

export function CustomerOverviewDrawer({ customerId, onClose, onUpdate }: CustomerOverviewDrawerProps) {
  const isOpen = Boolean(customerId);
  const [activeTab, setActiveTab] = useState<"summary" | "quotations" | "contracts" | "custom">("summary");
  
  const [data, setData] = useState<OverviewData | null>(null);
  const [customValues, setCustomValues] = useState<CustomFieldValue[]>([]);
  const [customDefinitions, setCustomDefinitions] = useState<CustomFieldDefinition[]>([]);
  const [contacts, setContacts] = useState<Contact[]>([]);
  
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  // Estados de Edición
  const [isEditingGeneral, setIsEditingGeneral] = useState(false);
  const [isEditingCommercial, setIsEditingCommercial] = useState(false);
  const [isEditingCustom, setIsEditingCustom] = useState(false);
  
  const [generalForm, setGeneralForm] = useState({
    legal_name: "",
    trade_name: "",
    given_names: "",
    family_name: "",
    document_type: "DNI",
    document_number: "",
  });

  const [commercialForm, setCommercialForm] = useState({
    payment_terms_days: null as number | null,
    credit_limit: "",
    preferred_currency: "PEN" as "PEN" | "USD",
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
    if (!customerId) return;
    setLoading(true);
    setError("");
    try {
      const overviewRes = await apiFetch<OverviewData>(`/v1/customers/${customerId}/overview`).catch(() => null);
      if (!overviewRes) {
        throw new Error("No se pudo cargar la información general del cliente.");
      }

      const customRes = await apiFetch<CustomFieldValue[]>(
        `/v1/custom-fields/values?entity_type=Customer&entity_id=${customerId}`
      ).catch(() => [] as CustomFieldValue[]);

      const defsRes = await apiFetch<CustomFieldDefinition[]>(
        "/v1/custom-fields/definitions?entity_type=Customer"
      ).catch(() => [] as CustomFieldDefinition[]);

      // Asegurar que exista la definición de notas internas en el sistema EAV
      let noteDef = defsRes.find(d => d.field_key === "internal_notes");
      if (!noteDef) {
        try {
          noteDef = await apiFetch<CustomFieldDefinition>("/v1/custom-fields/definitions", {
            method: "POST",
            body: JSON.stringify({
              entity_type: "Customer",
              field_key: "internal_notes",
              label: "Notas Internas",
              field_type: "text",
            }),
          });
          defsRes.push(noteDef);
        } catch {}
      }

      let contactsRes: Contact[] = [];
      if (overviewRes.customer?.party_id) {
        contactsRes = await apiFetch<Contact[]>(`/v1/contacts?party_id=${overviewRes.customer.party_id}`).catch(() => []);
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

      const isOrg = overviewRes.customer.party?.party_type === "ORGANIZATION";
      const orgData = overviewRes.customer.party?.organization;
      const personData = overviewRes.customer.party?.person;

      setGeneralForm({
        legal_name: isOrg ? (orgData?.legal_name || "") : "",
        trade_name: isOrg ? (orgData?.trade_name || "") : "",
        given_names: !isOrg ? (personData?.given_names || "") : "",
        family_name: !isOrg ? (personData?.family_name || "") : "",
        document_type: !isOrg ? (personData?.document_type || "DNI") : "RUC",
        document_number: isOrg ? (orgData?.tax_identifier || "") : (personData?.document_number || ""),
      });

      setCommercialForm({
        payment_terms_days: overviewRes.customer.payment_terms_days,
        credit_limit: overviewRes.customer.credit_limit == null ? "" : Number(overviewRes.customer.credit_limit).toFixed(2),
        preferred_currency: overviewRes.customer.preferred_currency ?? "PEN",
      });

      const customMap: Record<string, string | boolean> = {};
      customRes.forEach(item => {
        customMap[item.field_id] = item.value === "true" ? true : item.value === "false" ? false : item.value;
      });
      setEditableCustomValues(customMap);

    } catch (err) {
      setError(err instanceof ApiError ? err.message : (err as Error).message || "No se pudo cargar la ficha 360 del cliente.");
    } finally {
      setLoading(false);
    }
  }, [customerId]);

  useEffect(() => {
    if (!isOpen) return;
    const timer = window.setTimeout(() => {
      setIsEditingGeneral(false);
      setIsEditingCommercial(false);
      setIsEditingCustom(false);
      void fetchOverview();
    }, 0);
    return () => window.clearTimeout(timer);
  }, [isOpen, fetchOverview]);

  const handleSaveGeneral = async () => {
    if (!customerId || !data) return;
    setIsSaving(true);
    try {
      const isOrg = data.customer.party?.party_type === "ORGANIZATION";
      const payload: CustomerUpdatePayload = {
        party_type: isOrg ? "ORGANIZATION" : "PERSON",
        document_number: generalForm.document_number,
        payment_terms_days: data.customer.payment_terms_days,
        credit_limit: data.customer.credit_limit,
        preferred_currency: data.customer.preferred_currency,
      };

      if (isOrg) {
        payload.legal_name = generalForm.legal_name;
        payload.trade_name = generalForm.trade_name;
      } else {
        payload.document_type = generalForm.document_type;
        payload.given_names = generalForm.given_names;
        payload.family_name = generalForm.family_name;
      }

      await apiFetch(`/v1/customers/${customerId}`, {
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

  const handleSaveCommercial = async () => {
    if (!customerId || !data) return;
    setIsSaving(true);
    try {
      const isOrg = data.customer.party?.party_type === "ORGANIZATION";
      const orgData = data.customer.party?.organization;
      const personData = data.customer.party?.person;

      const payload: CustomerUpdatePayload = {
        party_type: isOrg ? "ORGANIZATION" : "PERSON",
        document_number: isOrg ? orgData?.tax_identifier : personData?.document_number,
        payment_terms_days: commercialForm.payment_terms_days,
        credit_limit: commercialForm.credit_limit ? Number(commercialForm.credit_limit) : null,
        preferred_currency: commercialForm.preferred_currency,
      };

      if (isOrg) {
        payload.legal_name = orgData?.legal_name;
        payload.trade_name = orgData?.trade_name;
      } else {
        payload.document_type = personData?.document_type;
        payload.given_names = personData?.given_names;
        payload.family_name = personData?.family_name;
      }

      await apiFetch(`/v1/customers/${customerId}`, {
        method: "PUT",
        body: JSON.stringify(payload),
      });

      await fetchOverview();
      onUpdate();
      setIsEditingCommercial(false);
    } catch (err) {
      alert(err instanceof ApiError ? err.message : "No se pudo actualizar las condiciones comerciales.");
    } finally {
      setIsSaving(false);
    }
  };

  const handleSaveCustom = async () => {
    if (!customerId) return;
    setIsSaving(true);
    try {
      const promises = Object.entries(editableCustomValues).map(([field_id, val]) => {
        if (val !== "" && val !== null && val !== undefined) {
          return apiFetch("/v1/custom-fields/values", {
            method: "POST",
            body: JSON.stringify({
              field_id,
              entity_type: "Customer",
              entity_id: customerId,
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
    if (!customerId || !internalNoteId) return;
    setIsSavingNote(true);
    try {
      await apiFetch("/v1/custom-fields/values", {
        method: "POST",
        body: JSON.stringify({
          field_id: internalNoteId,
          entity_type: "Customer",
          entity_id: customerId,
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
          entity_type: "Customer",
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
    if (!data?.customer?.party_id) return;
    setIsSaving(true);
    try {
      if (editingContact) {
        await apiFetch(`/v1/contacts/${editingContact.id}`, { method: "DELETE" }).catch(() => {});
      }

      await apiFetch("/v1/contacts", {
        method: "POST",
        body: JSON.stringify({
          party_id: data.customer.party_id,
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

  const customer = data?.customer;
  const isOrg = customer?.party?.party_type === "ORGANIZATION";

  // Filtramos la definición de notas internas para que no se mezcle en la sección de "Campos Dinámicos"
  const visibleCustomDefinitions = customDefinitions.filter(d => d.field_key !== "internal_notes");
  const visibleCustomValues = customValues.filter(v => v.definition?.field_key !== "internal_notes");

  return (
    <AnimatePresence>
      {isOpen && (
        <div className="fixed inset-0 z-[200] overflow-hidden">
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
                        {customer ? getCustomerDisplayName(customer) : "Cargando..."}
                      </h2>
                      {customer && <StatusBadge status={customer.status} />}
                    </div>
                    <p className="text-xs text-slate-500 mt-0.5">
                      {isOrg ? "Organización (Empresa)" : "Persona Natural"} • Doc: {customer ? getCustomerDocument(customer) : ""}
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
                  onClick={() => setActiveTab("quotations")}
                  className={`border-b-2 py-3 mr-6 text-sm font-medium transition-colors flex items-center gap-1.5 ${
                    activeTab === "quotations"
                      ? "border-[var(--app-brand-deep)] text-[var(--app-brand-deep)]"
                      : "border-transparent text-slate-500 hover:text-slate-700"
                  }`}
                >
                  <FileText size={15} /> Cotizaciones ({data?.quotations?.length || 0})
                </button>
                <button
                  onClick={() => setActiveTab("contracts")}
                  className={`border-b-2 py-3 mr-6 text-sm font-medium transition-colors flex items-center gap-1.5 ${
                    activeTab === "contracts"
                      ? "border-[var(--app-brand-deep)] text-[var(--app-brand-deep)]"
                      : "border-transparent text-slate-500 hover:text-slate-700"
                  }`}
                >
                  <Briefcase size={15} /> Contratos ({data?.contracts?.length || 0})
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
                    <p className="text-sm text-slate-500">Cargando ficha 360 del cliente...</p>
                  </div>
                ) : data && customer ? (
                  <>
                    {activeTab === "summary" && (
                      <div className="space-y-6">
                        {/* Métricas */}
                        <div className="grid grid-cols-3 gap-4">
                          <div className="rounded-2xl border border-slate-100 bg-slate-50 p-4">
                            <p className="text-xs font-medium text-slate-500">Contratos Activos</p>
                            <p className="mt-1 text-2xl font-bold text-slate-900">{data.summary.active_contracts}</p>
                          </div>
                          <div className="rounded-2xl border border-slate-100 bg-slate-50 p-4">
                            <p className="text-xs font-medium text-slate-500">Cotizaciones</p>
                            <p className="mt-1 text-2xl font-bold text-slate-900">{data.summary.total_quotations}</p>
                          </div>
                          <div className="rounded-2xl border border-slate-100 bg-slate-50 p-4">
                            <p className="text-xs font-medium text-slate-500">Valor Contratado</p>
                            <p className="mt-1 text-lg font-bold text-emerald-600">
                              {formatCurrency(data.summary.total_contracted_value)}
                            </p>
                          </div>
                        </div>

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
                              <span className="text-slate-500">Tipo de Cliente</span>
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
                                    <span className="font-medium text-slate-900">{orgDataText(customer, 'legal_name')}</span>
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
                                    <span className="font-medium text-slate-900">{orgDataText(customer, 'trade_name')}</span>
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
                                    <span className="font-medium text-slate-900">{getCustomerDocument(customer)}</span>
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
                                    <span className="font-medium text-slate-900">{personDataText(customer, 'given_names')}</span>
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
                                    <span className="font-medium text-slate-900">{personDataText(customer, 'family_name')}</span>
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
                                    <span className="font-medium text-slate-900">{personDataText(customer, 'document_type')}</span>
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
                                    <span className="font-medium text-slate-900">{getCustomerDocument(customer)}</span>
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

                        {/* Condiciones Comerciales */}
                        <div className="rounded-2xl border border-slate-200 p-6 space-y-4">
                          <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                            <h3 className="text-xs font-semibold uppercase tracking-wider text-slate-500">Condiciones Comerciales</h3>
                            <button
                              onClick={() => setIsEditingCommercial(!isEditingCommercial)}
                              className="flex items-center gap-1 text-xs font-medium text-[var(--app-brand-deep)] hover:underline"
                            >
                              <Edit3 size={13} /> {isEditingCommercial ? "Cancelar" : "Editar"}
                            </button>
                          </div>

                          <div className="grid grid-cols-2 gap-4 text-sm">
                            <div>
                              <span className="block text-xs text-slate-400">Días de Crédito *</span>
                              {isEditingCommercial ? (
                                <select
                                  value={commercialForm.payment_terms_days ?? ""}
                                  onChange={(e) => setCommercialForm({
                                    ...commercialForm,
                                    payment_terms_days: e.target.value === "" ? null : Number(e.target.value),
                                  })}
                                  className="w-full rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm mt-1 focus:border-[var(--app-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--app-brand)]"
                                >
                                  <option value="">—</option>
                                  <option value="15">15 días</option>
                                  <option value="30">30 días</option>
                                  <option value="45">45 días</option>
                                  <option value="60">60 días</option>
                                </select>
                              ) : (
                                <span className="font-medium text-slate-800">{customer.payment_terms_days == null ? "—" : `${customer.payment_terms_days} días`}</span>
                              )}
                            </div>
                            <div>
                              <span className="block text-xs text-slate-400">Límite de Crédito ({commercialForm.preferred_currency === "PEN" ? "S/" : "$"})</span>
                              {isEditingCommercial ? (
                                <input
                                  type="number"
                                  min="0"
                                  step="0.01"
                                  value={commercialForm.credit_limit}
                                  onChange={(e) => setCommercialForm({ ...commercialForm, credit_limit: e.target.value })}
                                  className="w-full rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm mt-1 focus:border-[var(--app-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--app-brand)]"
                                  placeholder="0.00"
                                />
                              ) : (
                                <span className="font-medium text-slate-800">{formatCurrency(customer.credit_limit, customer.preferred_currency)}</span>
                              )}
                            </div>
                            <div>
                              <span className="block text-xs text-slate-400">Moneda *</span>
                              {isEditingCommercial ? (
                                <select
                                  value={commercialForm.preferred_currency}
                                  onChange={(e) => setCommercialForm({ ...commercialForm, preferred_currency: e.target.value as "PEN" | "USD" })}
                                  className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm focus:border-[var(--app-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--app-brand)]"
                                >
                                  <option value="PEN">PEN (S/)</option>
                                  <option value="USD">USD ($)</option>
                                </select>
                              ) : (
                                <span className="font-medium text-slate-800">{customer.preferred_currency === "PEN" ? "PEN (S/)" : "USD ($)"}</span>
                              )}
                            </div>
                          </div>

                          {isEditingCommercial && (
                            <div className="pt-3 flex justify-end">
                              <button
                                onClick={handleSaveCommercial}
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
                                  placeholder="Ej. Red Social, Código Interno..."
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
                              No hay contactos directos asociados a este cliente.
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
                            placeholder="Escribe una nota importante sobre este cliente..."
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
                              <span className="font-mono text-xs text-slate-600">{customer.id}</span>
                            </div>
                            <div>
                              <span className="block text-xs text-slate-400">Fecha de Alta</span>
                              <span className="font-medium text-slate-800">
                                {customer.created_at ? new Date(customer.created_at).toLocaleDateString("es-PE") : "—"}
                              </span>
                            </div>
                          </div>
                        </div>
                      </div>
                    )}

                    {/* TAB COTIZACIONES */}
                    {activeTab === "quotations" && (
                      <div className="space-y-3">
                        {data.quotations.length === 0 ? (
                          <div className="text-center py-16 text-slate-400 text-sm">Este cliente no registra cotizaciones.</div>
                        ) : (
                          data.quotations.map((q) => (
                            <div key={q.id} className="flex items-center justify-between rounded-xl border border-slate-200 p-4 transition-colors hover:border-slate-300">
                              <div className="flex items-center gap-3">
                                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-50 text-blue-600">
                                  <FileText size={18} />
                                </div>
                                <div>
                                  <p className="font-medium text-slate-900">{q.quotation_number}</p>
                                  <p className="text-xs text-slate-500">
                                    Creado el {new Date(q.created_at).toLocaleDateString("es-PE")} a las {new Date(q.created_at).toLocaleTimeString("es-PE", { hour: '2-digit', minute: '2-digit' })}
                                  </p>
                                </div>
                              </div>
                              <div className="text-right">
                                <p className="font-semibold text-slate-900">{formatCurrency(q.total, q.currency)}</p>
                                <span className="inline-block mt-0.5 rounded-full bg-slate-100 px-2.5 py-0.5 text-[10px] font-medium text-slate-600">{q.status}</span>
                              </div>
                            </div>
                          ))
                        )}
                      </div>
                    )}

                    {/* TAB CONTRATOS */}
                    {activeTab === "contracts" && (
                      <div className="space-y-3">
                        {data.contracts.length === 0 ? (
                          <div className="text-center py-16 text-slate-400 text-sm">Este cliente no registra contratos.</div>
                        ) : (
                          data.contracts.map((c) => (
                            <div key={c.id} className="flex items-center justify-between rounded-xl border border-slate-200 p-4 transition-colors hover:border-slate-300">
                              <div className="flex items-center gap-3">
                                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600">
                                  <Briefcase size={18} />
                                </div>
                                <div>
                                  <p className="font-medium text-slate-900">{c.contract_number}</p>
                                  <p className="text-xs text-slate-500 flex items-center gap-1 mt-0.5">
                                    <Calendar size={12} /> {formatDateOnly(c.start_date)} al {formatDateOnly(c.end_date)}
                                  </p>
                                </div>
                              </div>
                              <div className="text-right">
                                <p className="font-semibold text-slate-900">{formatCurrency(c.total_value, c.currency)}</p>
                                <span className={`inline-block mt-0.5 rounded-full px-2.5 py-0.5 text-[10px] font-medium ${c.status === 'ACTIVE' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'}`}>{c.status}</span>
                              </div>
                            </div>
                          ))
                        )}
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
                <span className="text-xs text-slate-400">ERP SaaS - Ficha 360</span>
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

function orgDataText(customer: Customer, field: "legal_name" | "trade_name") {
  const org = customer.party?.organization;
  return org?.[field] || "—";
}

function personDataText(customer: Customer, field: "given_names" | "family_name" | "document_type") {
  const person = customer.party?.person;
  return person?.[field] || "—";
}