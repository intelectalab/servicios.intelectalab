"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { motion } from "framer-motion";
import {
  Search,
  Plus,
  ChevronLeft,
  ChevronRight,
  Users,
  Building2,
  User,
  AlertCircle,
  RefreshCw,
} from "lucide-react";
import AppShell from "@/components/layout/AppShell";
import { apiFetch, ApiError } from "@/lib/api";
import {
  type Customer,
  type PaginatedResponse,
  getCustomerDisplayName,
  getCustomerDocument,
} from "@/types/customer";

const NAVY = "#002347";
const NAVY_HOVER = "#013a70";

const STATUS_STYLES: Record<string, { bg: string; text: string; label: string }> = {
  ACTIVE: { bg: "#E8F5EC", text: "#2E7D4F", label: "Activo" },
  INACTIVE: { bg: "#F1F3F5", text: "#5C6F81", label: "Inactivo" },
  SUSPENDED: { bg: "#FCEAE8", text: "#C4453B", label: "Suspendido" },
};

function StatusBadge({ status }: { status: string }) {
  const style = STATUS_STYLES[status] ?? { bg: "#F1F3F5", text: "#5C6F81", label: status };
  return (
    <span
      className="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium"
      style={{ backgroundColor: style.bg, color: style.text }}
    >
      {style.label}
    </span>
  );
}

function formatCurrency(value: string | number | null) {
  if (value === null || value === undefined) return "—";
  const num = typeof value === "string" ? parseFloat(value) : value;
  if (Number.isNaN(num)) return "—";
  return num.toLocaleString("es-PE", { style: "currency", currency: "PEN" });
}

export default function CustomersPage() {
  const [customers, setCustomers] = useState<Customer[]>([]);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [statusFilter, setStatusFilter] = useState<string>("");
  const [search, setSearch] = useState("");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setLoading(true);
      setError("");
      try {
        const params = new URLSearchParams({ page: String(page), per_page: "20" });
        if (statusFilter) params.set("status", statusFilter);

        const res = await apiFetch<PaginatedResponse<Customer>>(`/v1/customers?${params}`);
        if (cancelled) return;
        setCustomers(res.data);
        setLastPage(res.last_page);
        setTotal(res.total);
      } catch (err) {
        if (cancelled) return;
        setError(err instanceof ApiError ? err.message : "No se pudo cargar la lista de clientes.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }

    load();
    return () => {
      cancelled = true;
    };
  }, [page, statusFilter]);

  // Filtro de texto en cliente (nombre/documento) sobre la página actual.
  // El backend no expone un parámetro de búsqueda todavía — si lo agregas,
  // lo movemos al fetch para que filtre sobre todo el listado, no solo la página.
  const filtered = useMemo(() => {
    if (!search.trim()) return customers;
    const q = search.trim().toLowerCase();
    return customers.filter(
      (c) =>
        getCustomerDisplayName(c).toLowerCase().includes(q) ||
        getCustomerDocument(c).toLowerCase().includes(q)
    );
  }, [customers, search]);

  return (
    <AppShell>
      <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Clientes</h1>
          <p className="mt-1 text-sm text-[#5C6F81]">
            {loading ? "Cargando…" : `${total} cliente${total === 1 ? "" : "s"} registrado${total === 1 ? "" : "s"}`}
          </p>
        </div>
        <Link
          href="/customers/new"
          className="mt-4 flex w-fit items-center gap-2 rounded-full px-4 py-2.5 text-sm font-semibold text-white shadow-[0_4px_14px_-4px_rgba(0,35,71,0.35)] transition-shadow hover:shadow-[0_10px_24px_-8px_rgba(0,35,71,0.4)] sm:mt-0"
          style={{ background: `linear-gradient(180deg, ${NAVY_HOVER} 0%, ${NAVY} 100%)` }}
        >
          <Plus size={16} />
          Nuevo cliente
        </Link>
      </div>

      {/* Filtros */}
      <div className="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center">
        <div className="relative flex-1 sm:max-w-xs">
          <Search size={16} className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-[#5C6F81]" />
          <input
            type="text"
            placeholder="Buscar por nombre o documento…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="w-full rounded-xl border-[1.5px] border-[#E1E7EC] bg-white py-2 pl-10 pr-3 text-sm text-[#0F1F2E] transition-colors placeholder:text-[#5C6F81]/60 focus:border-[#4FA9B4] focus:outline-none focus:ring-4 focus:ring-[#80CED7]/20"
          />
        </div>

        <div className="flex gap-2">
          {["", "ACTIVE", "INACTIVE", "SUSPENDED"].map((s) => (
            <button
              key={s || "all"}
              onClick={() => {
                setStatusFilter(s);
                setPage(1);
              }}
              className="rounded-full px-3.5 py-1.5 text-xs font-medium transition-colors"
              style={
                statusFilter === s
                  ? { backgroundColor: NAVY, color: "#fff" }
                  : { backgroundColor: "#fff", color: "#5C6F81", border: "1.5px solid #E1E7EC" }
              }
            >
              {s === "" ? "Todos" : STATUS_STYLES[s]?.label ?? s}
            </button>
          ))}
        </div>
      </div>

      {/* Tabla */}
      <motion.div
        initial={{ opacity: 0, y: 8 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.3 }}
        className="mt-5 overflow-hidden rounded-2xl border border-[#E1E7EC] bg-white"
      >
        {error ? (
          <div className="flex flex-col items-center gap-3 py-16 text-center">
            <AlertCircle size={28} className="text-[#C4453B]" />
            <p className="text-sm text-[#5C6F81]">{error}</p>
            <button
              onClick={() => setPage((p) => p)}
              className="mt-1 flex items-center gap-1.5 text-sm font-semibold text-[#002347] hover:underline"
            >
              <RefreshCw size={14} />
              Reintentar
            </button>
          </div>
        ) : loading ? (
          <div className="divide-y divide-[#F0F2F5]">
            {Array.from({ length: 6 }).map((_, i) => (
              <div key={i} className="flex items-center gap-4 px-6 py-4">
                <div className="h-9 w-9 animate-pulse rounded-full bg-[#F0F2F5]" />
                <div className="flex-1 space-y-2">
                  <div className="h-3 w-40 animate-pulse rounded bg-[#F0F2F5]" />
                  <div className="h-2.5 w-24 animate-pulse rounded bg-[#F0F2F5]" />
                </div>
                <div className="h-5 w-16 animate-pulse rounded-full bg-[#F0F2F5]" />
              </div>
            ))}
          </div>
        ) : filtered.length === 0 ? (
          <div className="flex flex-col items-center gap-2 py-16 text-center">
            <Users size={28} className="text-[#5C6F81]" />
            <p className="text-sm font-medium text-[#0F1F2E]">No se encontraron clientes</p>
            <p className="text-sm text-[#5C6F81]">Prueba con otro término de búsqueda o filtro.</p>
          </div>
        ) : (
          <table className="w-full text-left text-sm">
            <thead>
              <tr className="border-b border-[#E1E7EC] text-xs font-semibold uppercase tracking-wide text-[#5C6F81]">
                <th className="px-6 py-3">Cliente</th>
                <th className="px-6 py-3">Documento</th>
                <th className="px-6 py-3">Límite de crédito</th>
                <th className="px-6 py-3">Estado</th>
                <th className="px-6 py-3" />
              </tr>
            </thead>
            <tbody className="divide-y divide-[#F0F2F5]">
              {filtered.map((customer) => {
                const isOrg = customer.party.party_type === "ORGANIZATION";
                return (
                  <tr key={customer.id} className="transition-colors hover:bg-[#FAFBFC]">
                    <td className="px-6 py-4">
                      <div className="flex items-center gap-3">
                        <span
                          className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full"
                          style={{ backgroundColor: "#E9F4F6" }}
                        >
                          {isOrg ? (
                            <Building2 size={15} className="text-[#4FA9B4]" />
                          ) : (
                            <User size={15} className="text-[#4FA9B4]" />
                          )}
                        </span>
                        <div>
                          <p className="font-medium text-[#0F1F2E]">{getCustomerDisplayName(customer)}</p>
                          <p className="text-xs text-[#5C6F81]">{isOrg ? "Empresa" : "Persona natural"}</p>
                        </div>
                      </div>
                    </td>
                    <td className="px-6 py-4 text-[#5C6F81]">{getCustomerDocument(customer)}</td>
                    <td className="px-6 py-4 text-[#5C6F81]">{formatCurrency(customer.credit_limit)}</td>
                    <td className="px-6 py-4">
                      <StatusBadge status={customer.status} />
                    </td>
                    <td className="px-6 py-4 text-right">
                      <Link
                        href={`/customers/${customer.id}`}
                        className="text-sm font-semibold text-[#002347] hover:underline"
                      >
                        Ver
                      </Link>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        )}
      </motion.div>

      {/* Paginación */}
      {!loading && !error && lastPage > 1 && (
        <div className="mt-5 flex items-center justify-between">
          <p className="text-sm text-[#5C6F81]">
            Página {page} de {lastPage}
          </p>
          <div className="flex gap-2">
            <button
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              disabled={page <= 1}
              className="flex items-center gap-1 rounded-lg border-[1.5px] border-[#E1E7EC] px-3 py-1.5 text-sm font-medium text-[#5C6F81] transition-colors hover:bg-[#F6F8FA] disabled:opacity-40"
            >
              <ChevronLeft size={15} />
              Anterior
            </button>
            <button
              onClick={() => setPage((p) => Math.min(lastPage, p + 1))}
              disabled={page >= lastPage}
              className="flex items-center gap-1 rounded-lg border-[1.5px] border-[#E1E7EC] px-3 py-1.5 text-sm font-medium text-[#5C6F81] transition-colors hover:bg-[#F6F8FA] disabled:opacity-40"
            >
              Siguiente
              <ChevronRight size={15} />
            </button>
          </div>
        </div>
      )}
    </AppShell>
  );
}
