"use client";

import { useEffect, useMemo, useRef, useState, type ComponentType } from "react";
import { useRouter } from "next/navigation";
import { createPortal } from "react-dom";
import { Building2, CornerDownLeft, LayoutGrid, Loader2, Search, UserRound } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { actorDocument, rolesText } from "@/lib/actors";
import type { Actor } from "@/types/actor";
import type { PaginatedResponse } from "@/types/customer";
import { poppins } from "@/lib/fonts";

export type SearchScreen = { label: string; href: string; group?: string };

const RECORD_LIMIT = 8;

type Entry = {
  key: string;
  title: string;
  subtitle?: string | null;
  section: string;
  icon: ComponentType<{ size?: number; className?: string }>;
  run: () => void;
};

type GlobalSearchProps = {
  /** Pantallas a las que el usuario puede entrar. */
  screens: SearchScreen[];
  /** Si el usuario puede buscar en Clientes y actores (permiso core.view). */
  canSearchRecords: boolean;
};

/** Botón de la cabecera + diálogo de búsqueda (Ctrl K). */
export default function GlobalSearch({ screens, canSearchRecords }: GlobalSearchProps) {
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const [records, setRecords] = useState<Actor[]>([]);
  const [searching, setSearching] = useState(false);
  const [selected, setSelected] = useState(0);
  const inputRef = useRef<HTMLInputElement>(null);

  const term = query.trim();

  function close() {
    setOpen(false);
    setQuery("");
    setRecords([]);
    setSelected(0);
  }

  function changeQuery(value: string) {
    setQuery(value);
    setSelected(0);
    if (value.trim().length < 2) {
      setRecords([]);
      setSearching(false);
    } else if (canSearchRecords) {
      setSearching(true);
    }
  }

  // Ctrl K (o Cmd K) abre y cierra el buscador desde cualquier pantalla.
  useEffect(() => {
    function onKeyDown(event: KeyboardEvent) {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === "k") {
        event.preventDefault();
        // Con el buscador abierto, lo cierra. Con otro modal abierto no se abre encima.
        setOpen((value) => (value ? false : !document.querySelector('[aria-modal="true"]')));
      }
    }
    window.addEventListener("keydown", onKeyDown);
    return () => window.removeEventListener("keydown", onKeyDown);
  }, []);

  useEffect(() => {
    if (open) inputRef.current?.focus();
  }, [open]);

  // Registros: se consulta a la API con una pequeña espera para no pedir en cada tecla.
  useEffect(() => {
    if (!open || !canSearchRecords || term.length < 2) return;

    let cancelled = false;
    const timer = window.setTimeout(async () => {
      try {
        const data = await apiFetch<PaginatedResponse<Actor>>(
          `/v1/parties?search=${encodeURIComponent(term)}&per_page=${RECORD_LIMIT}`,
        );
        if (!cancelled) setRecords(data.data);
      } catch {
        if (!cancelled) setRecords([]);
      } finally {
        if (!cancelled) setSearching(false);
      }
    }, 250);

    return () => {
      cancelled = true;
      window.clearTimeout(timer);
    };
  }, [open, canSearchRecords, term]);

  const entries = useMemo<Entry[]>(() => {
    const needle = term.toLowerCase();

    const screenEntries: Entry[] = screens
      .filter((screen) => !needle || `${screen.label} ${screen.group ?? ""}`.toLowerCase().includes(needle))
      .map((screen) => ({
        key: `screen-${screen.href}`,
        title: screen.label,
        subtitle: screen.group,
        section: "Pantallas",
        icon: LayoutGrid,
        run: () => router.push(screen.href),
      }));

    // Cada identidad sale una sola vez, con sus roles, y abre su vista 360.
    const recordEntries: Entry[] = records.map((actor) => ({
      key: `actor-${actor.id}`,
      title: actor.name,
      subtitle: [actorDocument(actor), rolesText(actor)].filter(Boolean).join(" · ") || null,
      section: "Clientes y actores",
      icon: actor.kind === "ORGANIZATION" ? Building2 : UserRound,
      run: () => router.push(`/actors/${actor.id}`),
    }));

    return [...recordEntries, ...screenEntries];
  }, [screens, records, term, router]);

  function choose(entry: Entry) {
    entry.run();
    close();
  }

  function onInputKeyDown(event: React.KeyboardEvent) {
    if (event.key === "Escape") {
      close();
    } else if (event.key === "ArrowDown") {
      event.preventDefault();
      setSelected((index) => Math.min(index + 1, entries.length - 1));
    } else if (event.key === "ArrowUp") {
      event.preventDefault();
      setSelected((index) => Math.max(index - 1, 0));
    } else if (event.key === "Enter" && entries[selected]) {
      event.preventDefault();
      choose(entries[selected]);
    }
  }

  return (
    <>
      {/* Escritorio: caja de búsqueda como en el prototipo */}
      <button
        type="button"
        onClick={() => setOpen(true)}
        aria-label="Buscar (Ctrl+K)"
        className="app-surface-muted app-border app-muted hidden h-10 w-[min(300px,30vw)] cursor-pointer items-center gap-2.5 rounded-[10px] border pl-3 pr-2 text-[13px] transition-colors hover:border-[#1B8FC9] lg:flex"
      >
        <Search size={17} className="shrink-0" />
        <span className="flex-1 truncate text-left">Buscar en la plataforma…</span>
        <kbd className="app-surface app-border rounded-[5px] border px-1.5 py-px text-[10.5px]">Ctrl K</kbd>
      </button>

      {/* Pantallas chicas: solo el ícono */}
      <button
        type="button"
        onClick={() => setOpen(true)}
        aria-label="Buscar"
        className="relative flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-[10px] text-[color:var(--p-brand)] transition-colors hover:bg-[rgb(27_143_201/0.1)] lg:hidden"
      >
        <Search size={20} />
      </button>

      {open && typeof document !== "undefined" && createPortal(
        <div
          className={`${poppins.className} fixed inset-0 z-[300] flex items-start justify-center bg-[rgb(16_24_40/0.5)] px-4 pt-[12vh]`}
          onMouseDown={(event) => {
            if (event.target === event.currentTarget) close();
          }}
        >
          <div
            role="dialog"
            aria-modal="true"
            aria-label="Buscar en la plataforma"
            className="app-surface app-border w-full max-w-[560px] overflow-hidden rounded-xl border shadow-[0_20px_50px_rgba(8,25,55,0.28)]"
          >
            <div className="app-border flex items-center gap-2.5 border-b px-4">
              <Search size={18} className="app-muted shrink-0" />
              <input
                ref={inputRef}
                value={query}
                onChange={(event) => changeQuery(event.target.value)}
                onKeyDown={onInputKeyDown}
                placeholder={canSearchRecords ? "Busca un cliente, proveedor, contacto, empleado o pantalla…" : "Busca una pantalla…"}
                aria-label="Texto a buscar"
                className="app-text h-12 min-w-0 flex-1 bg-transparent text-[15px] outline-none placeholder:text-[color:var(--app-muted)]"
              />
              {searching && <Loader2 size={16} className="app-muted shrink-0 animate-spin" />}
              <kbd className="app-surface-muted app-border app-muted rounded-[5px] border px-1.5 py-px text-[10.5px]">Esc</kbd>
            </div>

            <div className="max-h-[52vh] overflow-y-auto py-1.5">
              {entries.length === 0 ? (
                <p className="app-muted px-4 py-8 text-center text-[13px]">
                  {searching ? "Buscando…" : term ? `Sin resultados para «${term}».` : "Escribe para buscar."}
                </p>
              ) : (
                entries.map((entry, index) => {
                  const Icon = entry.icon;
                  const firstOfSection = index === 0 || entries[index - 1].section !== entry.section;

                  return (
                    <div key={entry.key}>
                      {firstOfSection && (
                        <div className="app-muted px-4 pb-1 pt-2 text-[10.5px] font-semibold uppercase tracking-[0.09em]">
                          {entry.section}
                        </div>
                      )}
                      <button
                        type="button"
                        onClick={() => choose(entry)}
                        onMouseMove={() => setSelected(index)}
                        aria-current={index === selected}
                        className={`flex w-full cursor-pointer items-center gap-3 px-4 py-2 text-left ${
                          index === selected ? "bg-[rgb(27_143_201/0.12)]" : ""
                        }`}
                      >
                        <span className="app-surface-muted flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-[color:var(--p-brand)]">
                          <Icon size={16} />
                        </span>
                        <span className="min-w-0 flex-1">
                          <span className="app-text block truncate text-[13.5px] font-medium">{entry.title}</span>
                          {entry.subtitle && (
                            <span className="app-muted block truncate text-[11.5px]">{entry.subtitle}</span>
                          )}
                        </span>
                        {index === selected && <CornerDownLeft size={14} className="app-muted shrink-0" />}
                      </button>
                    </div>
                  );
                })
              )}
            </div>
          </div>
        </div>,
        document.body,
      )}
    </>
  );
}
