"use client";

import { useEffect, useLayoutEffect, useRef, useState, type CSSProperties, type FormEvent } from "react";
import { createPortal } from "react-dom";
import { ChevronDown, Globe, Info, Search, X } from "lucide-react";
import { ApiError, apiFetch } from "@/lib/api";
import { COUNTRIES } from "@/lib/countries";
import { poppins } from "@/lib/fonts";
import { Btn, Field, INPUT, Modal } from "@/components/ui/proto";
import type { ActorContact, ActorDetail } from "@/types/actor";

const CONTACT_TYPES: [string, string][] = [
  ["PHONE", "Teléfono"], ["WHATSAPP", "WhatsApp"], ["EMAIL", "Correo electrónico"], ["ADDRESS", "Dirección"], ["OTHER", "Otro"],
];

const CHECK = "mt-0.5 h-[17px] w-[17px] accent-[#1B8FC9]";

function firstError(err: unknown, fallback: string) {
  if (err instanceof ApiError) return (err.errors && Object.values(err.errors)[0]?.[0]) || err.message;
  return fallback;
}

/**
 * Prefijo del país de un teléfono o WhatsApp: desplegable con buscador y,
 * con "Otro país", un código escrito a mano. Igual que en los otros módulos.
 */
function CountryCode({ code, onChange }: { code: string; onChange: (code: string) => void }) {
  const known = COUNTRIES.find((country) => country.code === code);
  const [iso, setIso] = useState(known?.iso ?? "OT");
  const [custom, setCustom] = useState(!known);
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const ref = useRef<HTMLDivElement>(null);
  const listRef = useRef<HTMLDivElement>(null);
  const [place, setPlace] = useState<CSSProperties | null>(null);

  useEffect(() => {
    const onClickOutside = (event: MouseEvent) => {
      const target = event.target as Node;
      if (!ref.current?.contains(target) && !listRef.current?.contains(target)) setOpen(false);
    };
    document.addEventListener("mousedown", onClickOutside);
    return () => document.removeEventListener("mousedown", onClickOutside);
  }, []);

  // La lista se dibuja fuera del modal (que recorta lo que sobresale): se coloca
  // bajo el botón, o encima si abajo no cabe, y lo sigue al desplazar o cambiar el tamaño.
  useLayoutEffect(() => {
    if (!open) return;
    const update = () => {
      const rect = ref.current?.getBoundingClientRect();
      if (!rect) return;
      const height = 290;
      const below = window.innerHeight - rect.bottom;
      const left = Math.max(8, Math.min(rect.left, window.innerWidth - 256 - 8));
      setPlace(below < height && rect.top > below
        ? { left, bottom: window.innerHeight - rect.top + 6 }
        : { left, top: rect.bottom + 6 });
    };
    update();
    window.addEventListener("resize", update);
    window.addEventListener("scroll", update, true);
    return () => {
      window.removeEventListener("resize", update);
      window.removeEventListener("scroll", update, true);
    };
  }, [open]);

  const text = query.trim().toLowerCase();
  const countries = COUNTRIES.filter((country) =>
    country.name.toLowerCase().includes(text) || country.code.includes(text) || country.iso.toLowerCase().includes(text));

  if (custom) {
    return (
      <div className="flex shrink-0 items-center gap-1 border-r border-[#CFD5DE] bg-[var(--app-surface-muted)] px-2">
        <span className="app-muted text-xs font-medium">OT</span>
        <input
          className="w-14 bg-transparent text-[13.5px] font-semibold text-[#1679AB] outline-none"
          value={code}
          onChange={(event) => onChange(event.target.value.replace(/\s/g, ""))}
          placeholder="+XX"
          aria-label="Código del país"
          maxLength={5}
          autoFocus
        />
        <button
          type="button"
          onClick={() => {
            setCustom(false);
            setIso("PE");
            onChange("+51");
          }}
          className="app-muted cursor-pointer rounded p-0.5 hover:text-[var(--app-text)]"
          title="Volver a la lista"
          aria-label="Volver a la lista de países"
        >
          <X size={13} />
        </button>
      </div>
    );
  }

  return (
    <div ref={ref} className="relative shrink-0">
      <button
        type="button"
        onClick={() => setOpen(!open)}
        aria-haspopup="listbox"
        aria-expanded={open}
        className="flex h-full min-w-[96px] cursor-pointer items-center justify-between gap-2 rounded-l-[8px] border-r border-[#CFD5DE] bg-[var(--app-surface-muted)] px-3 text-[13.5px] font-medium"
      >
        <span className="flex items-center gap-1.5">
          <span>{iso}</span>
          <span className="font-semibold">{code}</span>
        </span>
        <ChevronDown size={13} className="app-muted shrink-0" />
      </button>

      {open && place && createPortal(
        <div
          ref={listRef}
          style={place}
          className={`${poppins.className} app-surface app-text fixed z-[350] w-64 rounded-xl border border-[color:var(--app-border)] p-2 text-sm shadow-[0_20px_50px_rgba(8,25,55,.28)]`}
        >
          <label className="app-muted mb-2 flex h-9 items-center gap-2 rounded-lg bg-[var(--app-surface-muted)] px-2.5">
            <Search size={14} className="shrink-0" />
            <input
              className="app-text min-w-0 flex-1 bg-transparent text-xs outline-none"
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              placeholder="Buscar país o prefijo..."
              aria-label="Buscar país o prefijo"
              autoFocus
            />
          </label>
          <div role="listbox" className="max-h-48 overflow-y-auto">
            {countries.map((country) => {
              const on = country.code === code && country.iso === iso;
              return (
                <button
                  key={`${country.iso}-${country.code}`}
                  type="button"
                  role="option"
                  aria-selected={on}
                  onClick={() => {
                    setIso(country.iso);
                    onChange(country.code);
                    setOpen(false);
                    setQuery("");
                  }}
                  className={`flex w-full cursor-pointer items-center justify-between rounded-lg px-3 py-1.5 text-left text-xs ${
                    on ? "bg-[#1B8FC9] font-medium text-[#fff]" : "hover:bg-[var(--app-surface-muted)]"
                  }`}
                >
                  <span>{country.name}</span>
                  <span className="font-semibold">{country.code}</span>
                </button>
              );
            })}
            <button
              type="button"
              onClick={() => {
                setCustom(true);
                setIso("OT");
                onChange("+");
                setOpen(false);
              }}
              className="mt-1 flex w-full cursor-pointer items-center gap-1.5 rounded-lg border-t border-[color:var(--app-border)] px-3 py-2 text-left text-xs font-semibold text-[#1679AB] hover:bg-[var(--app-surface-muted)]"
            >
              <Globe size={13} /> Otro país (Escribir código libre)
            </button>
          </div>
        </div>,
        document.body,
      )}
    </div>
  );
}

/** Agregar o editar un contacto de la identidad (los mismos contactos de Clientes, Proveedores y Empleados). */
export function ContactForm({
  partyId, contact, onClose, onSaved,
}: { partyId: string; contact?: ActorContact; onClose: () => void; onSaved: (message: string) => void }) {
  const [name, setName] = useState(contact?.contact_name ?? "");
  const [type, setType] = useState(contact?.contact_type ?? "PHONE");
  const [code, setCode] = useState(contact?.country_code || "+51");
  const [value, setValue] = useState(contact?.value ?? "");
  const [primary, setPrimary] = useState(contact?.is_primary ?? false);
  // "Eliminar formato de número": el número se escribe completo, sin prefijo aparte.
  const [unformatted, setUnformatted] = useState(Boolean(contact && !contact.country_code && ["PHONE", "WHATSAPP"].includes(contact.contact_type)));
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  const isPhone = type === "PHONE" || type === "WHATSAPP";

  async function submit(event: FormEvent) {
    event.preventDefault();
    const text = value.trim();
    if (!text) {
      setError("Este dato es obligatorio.");
      return;
    }
    if (type === "EMAIL" && !/^\S+@\S+\.\S+$/.test(text)) {
      setError("Correo inválido.");
      return;
    }

    setSaving(true);
    try {
      await apiFetch(contact ? `/v1/contacts/${contact.id}` : "/v1/contacts", {
        method: contact ? "PUT" : "POST",
        body: JSON.stringify({
          party_id: partyId,
          contact_name: name.trim() || null,
          contact_type: type,
          value: text,
          country_code: isPhone && !unformatted && code.trim().length > 1 ? code.trim() : null,
          is_primary: primary,
        }),
      });
      onSaved(contact ? "Contacto actualizado" : "Contacto agregado");
    } catch (err) {
      setError(firstError(err, "No se pudo guardar el contacto."));
      setSaving(false);
    }
  }

  return (
    <Modal title={contact ? "Editar contacto" : "Agregar contacto"} size="sm" onClose={onClose}>
      <form onSubmit={submit} noValidate>
        <div className="grid gap-3">
          <Field label="Nombre del contacto" hint="Opcional: la persona o el área">
            <input className={INPUT} value={name} onChange={(e) => setName(e.target.value)} placeholder="Ej. María López (Gerente de Operaciones)" autoFocus />
          </Field>
          <Field label="Tipo" required>
            <select className={INPUT} value={type} onChange={(e) => setType(e.target.value)}>
              {CONTACT_TYPES.map(([key, label]) => <option key={key} value={key}>{label}</option>)}
            </select>
          </Field>
          <Field label={type === "EMAIL" ? "Correo" : type === "ADDRESS" ? "Dirección" : isPhone ? "Número" : "Dato"} required error={error}>
            {isPhone && !unformatted ? (
              <div className="app-surface flex min-h-10 rounded-[9px] border border-[#CFD5DE] focus-within:border-[#1B8FC9] focus-within:shadow-[0_0_0_3px_rgba(27,143,201,.18)]">
                <CountryCode code={code} onChange={setCode} />
                <input
                  className="app-text min-w-0 flex-1 rounded-r-[9px] bg-transparent px-2.5 text-[13.5px] outline-none"
                  value={value}
                  onChange={(e) => setValue(e.target.value)}
                  placeholder="999888777"
                  inputMode="tel"
                />
              </div>
            ) : (
              <input
                className={INPUT}
                type={type === "EMAIL" ? "email" : "text"}
                value={value}
                onChange={(e) => setValue(e.target.value)}
                placeholder={type === "EMAIL" ? "correo@empresa.com" : type === "ADDRESS" ? "Dirección completa..." : isPhone ? "Escribe el número completo..." : ""}
              />
            )}
          </Field>
          {isPhone && (
            <div className="-mt-1.5">
              {!unformatted && <p className="app-muted text-[11.5px]">Selecciona un código de país o usa &quot;Otro país&quot; para introducir uno manual.</p>}
              <button
                type="button"
                onClick={() => setUnformatted(!unformatted)}
                className="mt-1 flex cursor-pointer items-center gap-1.5 text-xs font-semibold text-[#1679AB] hover:underline"
              >
                <Info size={13} />
                {unformatted ? "Aplicar formato de número" : "Eliminar formato de número"}
              </button>
            </div>
          )}
          <label className="flex cursor-pointer items-start gap-2.5">
            <input type="checkbox" className={CHECK} checked={primary} onChange={(e) => setPrimary(e.target.checked)} />
            <span>
              Contacto principal
              <small className="app-muted block text-[12.5px]">Reemplaza al principal de este mismo tipo.</small>
            </span>
          </label>
        </div>
        <div className="mt-4 flex justify-end gap-2">
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn type="submit" disabled={saving}>{saving ? "Guardando…" : "Guardar"}</Btn>
        </div>
      </form>
    </Modal>
  );
}

/** Condiciones comerciales del rol Cliente: días de crédito, límite y moneda. */
export function TermsForm({
  customer, onClose, onSaved,
}: { customer: NonNullable<ActorDetail["customer"]>; onClose: () => void; onSaved: () => void }) {
  const [days, setDays] = useState(customer.payment_terms_days == null ? "" : String(customer.payment_terms_days));
  const [limit, setLimit] = useState(customer.credit_limit == null ? "" : Number(customer.credit_limit).toFixed(2));
  const [currency, setCurrency] = useState(customer.preferred_currency === "USD" ? "USD" : "PEN");
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  // Los plazos habituales, más el que ya tuviera el cliente si es otro.
  const dayOptions = [...new Set(["15", "30", "45", "60", ...(days ? [days] : [])])].sort((a, b) => Number(a) - Number(b));

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (limit !== "" && !(Number(limit) >= 0)) {
      setError("El límite no puede ser negativo.");
      return;
    }
    setSaving(true);
    try {
      await apiFetch(`/v1/customers/${customer.id}`, {
        method: "PUT",
        body: JSON.stringify({
          payment_terms_days: days === "" ? null : Number(days),
          credit_limit: limit === "" ? null : Number(limit),
          preferred_currency: currency,
        }),
      });
      onSaved();
    } catch (err) {
      setError(firstError(err, "No se pudieron guardar las condiciones."));
      setSaving(false);
    }
  }

  return (
    <Modal title="Editar condiciones comerciales" size="sm" onClose={onClose}>
      <form onSubmit={submit} noValidate>
        <div className="grid gap-3">
          <Field label="Días de crédito">
            <select className={INPUT} value={days} onChange={(e) => setDays(e.target.value)} autoFocus>
              <option value="">---</option>
              {dayOptions.map((option) => <option key={option} value={option}>{option} días</option>)}
            </select>
          </Field>
          <Field label="Límite de crédito" error={error}>
            <input className={INPUT} type="number" min="0" step="0.01" value={limit} onChange={(e) => setLimit(e.target.value)} placeholder="Sin límite" />
          </Field>
          <Field label="Moneda">
            <select className={INPUT} value={currency} onChange={(e) => setCurrency(e.target.value)}>
              <option value="PEN">PEN (S/)</option>
              <option value="USD">USD ($)</option>
            </select>
          </Field>
        </div>
        <p className="app-muted mt-3 text-[12.5px]">Son las mismas condiciones que se ven en Clientes.</p>
        <div className="mt-4 flex justify-end gap-2">
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn type="submit" disabled={saving}>{saving ? "Guardando…" : "Guardar"}</Btn>
        </div>
      </form>
    </Modal>
  );
}
