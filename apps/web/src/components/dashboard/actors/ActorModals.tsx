"use client";

import { useEffect, useLayoutEffect, useRef, useState, type CSSProperties, type FormEvent } from "react";
import { createPortal } from "react-dom";
import { ChevronDown, Globe, Info, Search, X } from "lucide-react";
import { ApiError, apiFetch } from "@/lib/api";
import { COUNTRIES } from "@/lib/countries";
import { poppins } from "@/lib/fonts";
import { Btn, Field, INPUT, Modal } from "@/components/ui/proto";
import { PickSelect } from "@/components/ui/pick-select";
import type { ActorContact } from "@/types/actor";

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
      <div className="flex shrink-0 items-center gap-1 border-r border-[color:var(--p-field-border)] bg-[var(--app-surface-muted)] px-2">
        <span className="app-muted text-xs font-medium">OT</span>
        <input
          className="w-14 bg-transparent text-[13.5px] font-semibold text-[color:var(--p-brand-text)] outline-none"
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
        className="flex h-full min-w-[96px] cursor-pointer items-center justify-between gap-2 rounded-l-[8px] border-r border-[color:var(--p-field-border)] bg-[var(--app-surface-muted)] px-3 text-[13.5px] font-medium"
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
              className="mt-1 flex w-full cursor-pointer items-center gap-1.5 rounded-lg border-t border-[color:var(--app-border)] px-3 py-2 text-left text-xs font-semibold text-[color:var(--p-brand-text)] hover:bg-[var(--app-surface-muted)]"
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

/** Teléfono con el prefijo del país en su desplegable. */
export function PhoneInput({
  code, onCode, value, onValue,
}: { code: string; onCode: (code: string) => void; value: string; onValue: (value: string) => void }) {
  return (
    <div className="app-surface flex min-h-10 rounded-[9px] border border-[color:var(--p-field-border)] focus-within:border-[#1B8FC9] focus-within:shadow-[0_0_0_3px_rgba(27,143,201,.18)]">
      <CountryCode code={code} onChange={onCode} />
      <input
        className="app-text min-w-0 flex-1 rounded-r-[9px] bg-transparent px-2.5 text-[13.5px] outline-none"
        value={value}
        onChange={(event) => onValue(event.target.value)}
        placeholder="999888777"
        inputMode="tel"
      />
    </div>
  );
}

/**
 * Campo de teléfono completo: prefijo del país + número, o el número escrito
 * entero con "Eliminar formato de número". Igual que en los otros módulos.
 */
export function PhoneField({
  code, onCode, value, onValue, unformatted, onUnformatted,
}: {
  code: string;
  onCode: (code: string) => void;
  value: string;
  onValue: (value: string) => void;
  /** El número va completo, sin prefijo aparte. */
  unformatted: boolean;
  onUnformatted: (unformatted: boolean) => void;
}) {
  return (
    <div>
      {unformatted ? (
        <input className={INPUT} value={value} onChange={(event) => onValue(event.target.value)} placeholder="Escribe el número completo..." inputMode="tel" />
      ) : (
        <>
          <PhoneInput code={code} onCode={onCode} value={value} onValue={onValue} />
          <p className="app-muted mt-1 text-[11.5px]">Selecciona un código de país o usa &quot;Otro país&quot; para introducir uno manual.</p>
        </>
      )}
      <button
        type="button"
        onClick={() => {
          // Al volver al formato, un prefijo vacío arranca en Perú.
          if (unformatted && code.length < 2) onCode("+51");
          onUnformatted(!unformatted);
        }}
        className="mt-1 flex cursor-pointer items-center gap-1.5 text-xs font-semibold text-[color:var(--p-brand-text)] hover:underline"
      >
        <Info size={13} />
        {unformatted ? "Aplicar formato de número" : "Eliminar formato de número"}
      </button>
    </div>
  );
}

/**
 * Agregar o editar un dato de contacto de la identidad: un teléfono, correo o
 * dirección (los mismos contactos de Clientes, Proveedores y Empleados). Una
 * persona con nombre y cargo no va aquí: es una persona de contacto.
 */
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
      onSaved(contact ? "Dato de contacto actualizado" : "Dato de contacto agregado");
    } catch (err) {
      setError(firstError(err, "No se pudo guardar el contacto."));
      setSaving(false);
    }
  }

  return (
    <Modal title={contact ? "Editar dato de contacto" : "Agregar teléfono, correo o dirección"} size="sm" onClose={onClose}>
      <form onSubmit={submit} noValidate>
        <div className="grid gap-3">
          <Field label="Tipo" required>
            <PickSelect className={INPUT} value={type} onChange={setType} options={CONTACT_TYPES} />
          </Field>
          <Field label={type === "EMAIL" ? "Correo" : type === "ADDRESS" ? "Dirección" : isPhone ? "Número" : "Dato"} required error={error}>
            {isPhone ? (
              <PhoneField code={code} onCode={setCode} value={value} onValue={setValue} unformatted={unformatted} onUnformatted={setUnformatted} />
            ) : (
              <input
                className={INPUT}
                type={type === "EMAIL" ? "email" : "text"}
                value={value}
                onChange={(e) => setValue(e.target.value)}
                placeholder={type === "EMAIL" ? "correo@empresa.com" : type === "ADDRESS" ? "Dirección completa..." : ""}
              />
            )}
          </Field>
          <Field label="Etiqueta" hint="Opcional: para distinguirlo de otros. Si es una persona, créala como persona de contacto.">
            <input className={INPUT} value={name} onChange={(e) => setName(e.target.value)} placeholder="Ej. Central, Facturación, Almacén" />
          </Field>
          <label className="flex cursor-pointer items-start gap-2.5">
            <input type="checkbox" className={CHECK} checked={primary} onChange={(e) => setPrimary(e.target.checked)} />
            <span>
              Principal
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
