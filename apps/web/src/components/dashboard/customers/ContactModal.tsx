"use client";

import { useState, useEffect, useRef } from "react";
import { X, Loader2, Search, ChevronDown, Info, Globe } from "lucide-react";
import { type Contact } from "@/types/contact";

interface ContactModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSave: (formData: {
    contact_name: string;
    contact_type: string;
    value: string;
    country_code: string;
    is_primary: boolean;
  }) => Promise<void>;
  initialContact?: Contact | null;
  isSaving: boolean;
}

const COUNTRIES = [
  { name: "Perú", code: "+51", iso: "PE" },
  { name: "España", code: "+34", iso: "ES" },
  { name: "Estados Unidos", code: "+1", iso: "US" },
  { name: "Argentina", code: "+54", iso: "AR" },
  { name: "Chile", code: "+56", iso: "CL" },
  { name: "Colombia", code: "+57", iso: "CO" },
  { name: "México", code: "+52", iso: "MX" },
  { name: "Bolivia", code: "+591", iso: "BO" },
  { name: "Brasil", code: "+55", iso: "BR" },
  { name: "Ecuador", code: "+593", iso: "EC" },
  { name: "Uruguay", code: "+598", iso: "UY" },
  { name: "Venezuela", code: "+58", iso: "VE" },
  { name: "Costa Rica", code: "+506", iso: "CR" },
  { name: "Cuba", code: "+53", iso: "CU" },
  { name: "El Salvador", code: "+503", iso: "SV" },
  { name: "Guatemala", code: "+502", iso: "GT" },
  { name: "Honduras", code: "+504", iso: "HN" },
  { name: "Nicaragua", code: "+505", iso: "NI" },
  { name: "Panamá", code: "+507", iso: "PA" },
  { name: "Paraguay", code: "+595", iso: "PY" },
  { name: "República Dominicana", code: "+1", iso: "DO" },
  { name: "Alemania", code: "+49", iso: "DE" },
  { name: "Francia", code: "+33", iso: "FR" },
  { name: "Italia", code: "+39", iso: "IT" },
  { name: "Reino Unido", code: "+44", iso: "GB" },
  { name: "Japón", code: "+81", iso: "JP" },
  { name: "China", code: "+86", iso: "CN" },
];

export function ContactModal({
  isOpen,
  onClose,
  onSave,
  initialContact,
  isSaving,
}: ContactModalProps) {
  const [contactName, setContactName] = useState("");
  const [contactType, setContactType] = useState("PHONE");
  const [countryCode, setCountryCode] = useState("+51");
  const [selectedIso, setSelectedIso] = useState("PE");
  const [value, setValue] = useState("");
  const [isPrimary, setIsPrimary] = useState(false);

  // Estados para el Dropdown con Buscador y opción personalizada
  const [isDropdownOpen, setIsDropdownOpen] = useState(false);
  const [searchQuery, setSearchQuery] = useState("");
  const [isCustomCountry, setIsCustomCountry] = useState(false);
  const dropdownRef = useRef<HTMLDivElement>(null);

  // Estado para Alternar Formato de Número
  const [unformattedMode, setUnformattedMode] = useState(false);

  useEffect(() => {
    if (initialContact) {
      setContactName(initialContact.contact_name || "");
      setContactType(initialContact.contact_type || "PHONE");
      setValue(initialContact.value || "");
      const cCode = initialContact.country_code || "+51";
      setCountryCode(cCode);
      
      const found = COUNTRIES.find(c => c.code === cCode);
      if (found) {
        setSelectedIso(found.iso);
        setIsCustomCountry(false);
      } else {
        setSelectedIso("OT");
        setIsCustomCountry(true);
      }

      setIsPrimary(initialContact.is_primary || false);
    } else {
      setContactName("");
      setContactType("PHONE");
      setCountryCode("+51");
      setSelectedIso("PE");
      setIsCustomCountry(false);
      setValue("");
      setIsPrimary(false);
      setUnformattedMode(false);
    }
  }, [initialContact, isOpen]);

  useEffect(() => {
    function handleClickOutside(event: MouseEvent) {
      if (dropdownRef.current && !dropdownRef.current.contains(event.target as Node)) {
        setIsDropdownOpen(false);
      }
    }
    document.addEventListener("mousedown", handleClickOutside);
    return () => document.removeEventListener("mousedown", handleClickOutside);
  }, []);

  if (!isOpen) return null;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    await onSave({
      contact_name: contactName,
      contact_type: contactType,
      value,
      country_code: ["PHONE", "WHATSAPP"].includes(contactType) && !unformattedMode ? countryCode : "",
      is_primary: isPrimary,
    });
  };

  const isPhoneOrWhatsapp = ["PHONE", "WHATSAPP"].includes(contactType);

  const filteredCountries = COUNTRIES.filter(
    (c) =>
      c.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
      c.code.includes(searchQuery) ||
      c.iso.toLowerCase().includes(searchQuery.toLowerCase())
  );

  return (
    <div className="fixed inset-0 z-60 flex items-center justify-center bg-slate-900/40 backdrop-blur-xs">
      <form onSubmit={handleSubmit} className="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl space-y-4">
        <div className="flex items-center justify-between border-b border-slate-100 pb-3">
          <h3 className="text-sm font-bold text-slate-900">
            {initialContact ? "Editar Contacto" : "Añadir Contacto"}
          </h3>
          <button type="button" onClick={onClose} className="text-slate-400 hover:text-slate-600">
            <X size={16} />
          </button>
        </div>

        {/* Nombre del Contacto */}
        <div>
          <label className="mb-1 block text-xs font-medium text-slate-700">Nombre de Contacto / Cargo (Opcional)</label>
          <input
            type="text"
            value={contactName}
            onChange={(e) => setContactName(e.target.value)}
            placeholder="Ej. María López (Gerente de Operaciones)"
            className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-[var(--app-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--app-brand)]"
          />
        </div>

        {/* Tipo de Contacto */}
        <div>
          <label className="mb-1 block text-xs font-medium text-slate-700">Tipo de Contacto *</label>
          <select
            value={contactType}
            onChange={(e) => setContactType(e.target.value)}
            className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-[var(--app-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--app-brand)]"
          >
            <option value="PHONE">Teléfono</option>
            <option value="WHATSAPP">WhatsApp</option>
            <option value="EMAIL">Correo Electrónico</option>
            <option value="ADDRESS">Dirección</option>
            <option value="OTHER">Otro</option>
          </select>
        </div>

        {/* Valor del Contacto */}
        <div>
          <label className="mb-1 block text-xs font-medium text-slate-700">Valor *</label>
          
          {isPhoneOrWhatsapp && !unformattedMode ? (
            <div className="space-y-2">
              <div className="flex rounded-lg border border-slate-300 bg-white focus-within:border-[var(--app-brand)] focus-within:ring-1 focus-within:ring-[var(--app-brand)] overflow-visible relative">
                
                {/* Selector / Dropdown con Buscador o Input Libre si es personalizado */}
                <div className="relative shrink-0" ref={dropdownRef}>
                  {/* Si seleccionó país personalizado, permitimos editar el código directamente sin espacios */}
                  {isCustomCountry ? (
                    <div className="flex items-center bg-slate-50 border-r border-slate-300 h-full rounded-l-lg px-2">
                      <span className="text-xs text-slate-400 font-medium mr-1">OT</span>
                      <input
                        type="text"
                        value={countryCode}
                        onChange={(e) => setCountryCode(e.target.value.replace(/\s/g, ""))}
                        placeholder="+XX"
                        className="w-14 bg-transparent text-sm font-bold text-[var(--app-brand-deep)] focus:outline-none"
                        title="Código personalizado"
                        autoFocus
                      />
                      <button
                        type="button"
                        onClick={() => {
                          setIsCustomCountry(false);
                          setCountryCode("+51");
                          setSelectedIso("PE");
                        }}
                        className="text-[10px] text-slate-400 hover:text-slate-600 ml-1 px-1"
                        title="Volver a lista"
                      >
                        ✕
                      </button>
                    </div>
                  ) : (
                    <button
                      type="button"
                      onClick={() => setIsDropdownOpen(!isDropdownOpen)}
                      className="flex items-center gap-2 bg-slate-50 border-r border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 h-full rounded-l-lg focus:outline-none min-w-[95px] justify-between"
                    >
                      <div className="flex items-center gap-1.5">
                        <span>{selectedIso}</span>
                        <span className="text-slate-600 font-semibold">{countryCode}</span>
                      </div>
                      <ChevronDown size={13} className="text-slate-400 shrink-0" />
                    </button>
                  )}

                  {isDropdownOpen && (
                    <div className="absolute left-0 top-11 z-50 w-64 rounded-xl border border-slate-200 bg-white shadow-xl p-2 space-y-2">
                      <div className="relative">
                        <Search size={14} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400" />
                        <input
                          type="text"
                          autoFocus
                          value={searchQuery}
                          onChange={(e) => setSearchQuery(e.target.value)}
                          placeholder="Buscar país o prefijo..."
                          className="w-full rounded-lg border border-slate-200 bg-slate-50 py-1.5 pl-8 pr-3 text-xs focus:bg-white focus:outline-none focus:ring-1 focus:ring-[var(--app-brand)]"
                        />
                      </div>
                      <div className="max-h-48 overflow-y-auto space-y-0.5">
                        {filteredCountries.map((c) => (
                          <button
                            key={`${c.iso}-${c.code}`}
                            type="button"
                            onClick={() => {
                              setCountryCode(c.code);
                              setSelectedIso(c.iso);
                              setIsCustomCountry(false);
                              setIsDropdownOpen(false);
                              setSearchQuery("");
                            }}
                            className={`flex w-full items-center justify-between rounded-lg px-3 py-1.5 text-xs text-left transition-colors ${
                              countryCode === c.code && selectedIso === c.iso && !isCustomCountry
                                ? "bg-[var(--app-brand-deep)] text-white font-medium"
                                : "text-slate-700 hover:bg-slate-100"
                            }`}
                          >
                            <span>{c.name}</span>
                            <span className="font-semibold">{c.code}</span>
                          </button>
                        ))}
                        
                        <button
                          type="button"
                          onClick={() => {
                            setIsCustomCountry(true);
                            setSelectedIso("OT");
                            setCountryCode("+");
                            setIsDropdownOpen(false);
                          }}
                          className="w-full mt-1 border-t border-slate-100 pt-2 px-3 py-1.5 text-xs text-left font-semibold text-[var(--app-brand-deep)] hover:bg-blue-50 rounded-lg flex items-center gap-1.5"
                        >
                          <Globe size={13} /> Otro país (Escribir código libre)
                        </button>
                      </div>
                    </div>
                  )}
                </div>

                <input
                  required
                  type="text"
                  value={value}
                  onChange={(e) => setValue(e.target.value)}
                  placeholder="999888777"
                  className="w-full px-3 py-2 text-sm focus:outline-none min-w-0"
                />
              </div>

              <p className="text-[11px] text-slate-400">
                Selecciona un código de país o usa "Otro país" para introducir uno manual.
              </p>
            </div>
          ) : (
            <input
              required
              type={contactType === "EMAIL" ? "email" : "text"}
              value={value}
              onChange={(e) => setValue(e.target.value)}
              placeholder={contactType === "EMAIL" ? "correo@empresa.com" : "Escribe el número completo..."}
              className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-[var(--app-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--app-brand)]"
            />
          )}

          {/* Botón para alternar formato de número */}
          {isPhoneOrWhatsapp && (
            <button
              type="button"
              onClick={() => setUnformattedMode(!unformattedMode)}
              className="mt-2 flex items-center gap-1.5 text-xs font-semibold text-[var(--app-brand-deep)] hover:underline focus:outline-none"
            >
              <Info size={13} />
              {unformattedMode ? "Aplicar formato de número" : "Eliminar formato de número"}
            </button>
          )}
        </div>

        {/* Marcar como principal */}
        <div className="flex items-center gap-2 pt-1">
          <input
            type="checkbox"
            id="is_primary_contact"
            checked={isPrimary}
            onChange={(e) => setIsPrimary(e.target.checked)}
            className="h-4 w-4 rounded border-slate-300 text-[var(--app-brand-deep)]"
          />
          <label htmlFor="is_primary_contact" className="text-xs font-medium text-slate-700 cursor-pointer">
            Marcar como principal
          </label>
        </div>

        {/* Botones de acción */}
        <div className="flex justify-end gap-2 pt-3">
          <button
            type="button"
            onClick={onClose}
            className="rounded-lg border border-slate-300 px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50"
          >
            Cancelar
          </button>
          <button
            type="submit"
            disabled={isSaving}
            className="rounded-lg bg-[var(--app-brand-deep)] px-4 py-2 text-xs font-medium text-white hover:bg-[var(--app-brand)] flex items-center gap-1.5"
          >
            {isSaving && <Loader2 size={13} className="animate-spin" />}
            Guardar Contacto
          </button>
        </div>
      </form>
    </div>
  );
}