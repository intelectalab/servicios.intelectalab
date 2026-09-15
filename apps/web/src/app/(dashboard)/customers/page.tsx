"use client";

import { useEffect, useMemo, useState } from "react";
import { motion, AnimatePresence } from "framer-motion";
import {
  Search,
  Plus,
  AlertCircle,
  RefreshCw,
  Filter,
  Columns,
  Trash2,
  Download,
  UserPlus,
  Loader2,
  CheckCircle,
  X // <-- Aquí está tu icono X
} from "lucide-react";
import { apiFetch, ApiError } from "@/lib/api";
import { Pagination } from "@/components/Pagination";
import { CustomerTable } from "@/components/dashboard/customers/CustomerTable";
import { CustomerDrawer } from "@/components/dashboard/customers/CustomerDrawer";
import {
  type Customer,
  type PaginatedResponse,
  getCustomerDisplayName,
  getCustomerDocument,
} from "@/types/customer";
import { CustomerOverviewDrawer } from "@/components/dashboard/customers/CustomerOverviewDrawer";

export default function CustomersPage() {
  const [customers, setCustomers] = useState<Customer[]>([]);
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(10);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  
  const [activeTab, setActiveTab] = useState<string>(""); 
  const [search, setSearch] = useState("");
  
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [refreshTrigger, setRefreshTrigger] = useState(0); 

  const [selectedIds, setSelectedIds] = useState<string[]>([]);
  const [isProcessing, setIsProcessing] = useState(false);

  const [isDrawerOpen, setIsDrawerOpen] = useState(false);
  const [viewCustomerId, setViewCustomerId] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setLoading(true);
      setError("");
      try {
        const params = new URLSearchParams({ 
          page: String(page), 
          per_page: String(perPage) 
        });
        if (activeTab) params.set("status", activeTab);

        const res = await apiFetch<PaginatedResponse<Customer>>(`/v1/customers?${params}`);
        if (cancelled) return;
        
        setCustomers(res.data);
        setLastPage(res.last_page);
        setTotal(res.total);
        setSelectedIds([]); 
      } catch (err) {
        if (cancelled) return;
        setError(err instanceof ApiError ? err.message : "No se pudo cargar la lista de clientes.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }

    load();
    return () => { cancelled = true; };
  }, [page, perPage, activeTab, refreshTrigger]);

  const filtered = useMemo(() => {
    if (!search.trim()) return customers;
    const q = search.trim().toLowerCase();
    return customers.filter(
      (c) =>
        getCustomerDisplayName(c).toLowerCase().includes(q) ||
        getCustomerDocument(c).toLowerCase().includes(q)
    );
  }, [customers, search]);

  const handlePerPageChange = (newPerPage: number) => {
    setPerPage(newPerPage);
    setPage(1); 
  };

  const handleToggleSelection = (id: string) => {
    setSelectedIds((prev) =>
      prev.includes(id) ? prev.filter((selectedId) => selectedId !== id) : [...prev, id]
    );
  };

  const handleSelectAll = (ids: string[]) => {
    setSelectedIds(ids);
  };

  const handleBulkDeactivate = async () => {
    const idsToDeactivate = selectedIds.filter(id => {
      const customer = customers.find(c => c.id === id);
      return customer && customer.status !== 'INACTIVE';
    });

    if (idsToDeactivate.length === 0) return;
    if (!window.confirm(`¿Estás seguro de desactivar ${idsToDeactivate.length} cliente(s)?`)) return;

    setIsProcessing(true);
    try {
      await apiFetch(`/v1/customers/bulk-deactivate`, { 
        method: "POST",
        body: JSON.stringify({ ids: idsToDeactivate })
      });
      
      setRefreshTrigger(prev => prev + 1);
      setSelectedIds([]); 
    } catch (err) {
      console.error(err);
      alert(err instanceof ApiError ? err.message : "Ocurrió un error al desactivar los clientes.");
    } finally {
      setIsProcessing(false);
    }
  };

  const handleBulkActivate = async () => {
    const idsToActivate = selectedIds.filter(id => {
      const customer = customers.find(c => c.id === id);
      return customer && customer.status !== 'ACTIVE';
    });

    if (idsToActivate.length === 0) return;
    if (!window.confirm(`¿Estás seguro de reactivar ${idsToActivate.length} cliente(s)?`)) return;

    setIsProcessing(true);
    try {
      await apiFetch(`/v1/customers/bulk-activate`, { 
        method: "POST",
        body: JSON.stringify({ ids: idsToActivate })
      });
      
      setRefreshTrigger(prev => prev + 1);
      setSelectedIds([]); 
    } catch (err) {
      console.error(err);
      alert(err instanceof ApiError ? err.message : "Ocurrió un error al activar los clientes.");
    } finally {
      setIsProcessing(false);
    }
  };

  const TABS = [
    { id: "", label: "Todos" },
    { id: "ACTIVE", label: "Activos" },
    { id: "INACTIVE", label: "Inactivos" },
  ];

  const selectedCustomers = customers.filter(c => selectedIds.includes(c.id));
  const hasActiveSelected = selectedCustomers.some(c => c.status === 'ACTIVE');
  const hasInactiveSelected = selectedCustomers.some(c => c.status === 'INACTIVE');

  return (
    <div className="flex h-full min-h-0 flex-col p-0.1 text-slate-900">
      
      {/* Contenedor principal con esquinas asimétricas */}
      <div className="flex h-full min-h-0 flex-col overflow-hidden bg-white shadow-sm rounded-tl-[40px] rounded-br-[40px]">
        
        {/* Header */}
        <div className="flex shrink-0 flex-col gap-4 px-8 pt-8 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <h1 className="text-2xl font-bold tracking-tight">Clientes</h1>
            <p className="mt-1 text-sm text-slate-500">
              Gestiona la información de tus clientes y controla sus condiciones comerciales.
            </p>
          </div>
          <button
            onClick={() => setIsDrawerOpen(true)}
            className="flex items-center gap-2 rounded-md bg-[var(--app-brand-deep)] px-4 py-2 text-sm font-medium text-white shadow-sm transition-colors hover:bg-[var(--app-brand)]"
          >
            <Plus size={16} />
            Nuevo cliente
          </button>
        </div>

        {/* Tabs */}
        <div className="mt-4 flex shrink-0 gap-6 border-b border-slate-200 px-8">
          {TABS.map((tab) => (
            <button
              key={tab.id}
              onClick={() => { setActiveTab(tab.id); setPage(1); }}
              className={`border-b-2 pb-3 text-sm font-medium transition-colors ${
                activeTab === tab.id
                  ? "border-[var(--app-brand-deep)] text-[var(--app-brand-deep)]"
                  : "border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700"
              }`}
            >
              {tab.label}
            </button>
          ))}
        </div>

        {/* Toolbar (Siempre visible) */}
        <div className="flex shrink-0 items-center justify-between gap-4 border-b border-slate-200 bg-white px-8 py-4 z-20">
          <div className="flex flex-1 items-center gap-3">
            <div className="relative w-full max-w-sm">
              <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
              <input
                type="text"
                placeholder="Buscar por nombre, RUC o documento..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="w-full rounded-md border border-slate-300 bg-white py-1.5 pl-9 pr-9 text-sm transition-colors focus:border-[var(--app-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--app-brand)]"
              />
              {/* Botón X dinámico para el buscador */}
              {search && (
                <button
                  onClick={() => setSearch("")}
                  className="absolute right-2.5 top-1/2 -translate-y-1/2 rounded-full p-0.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 focus:outline-none"
                  aria-label="Limpiar búsqueda"
                >
                  <X size={14} />
                </button>
              )}
            </div>
            
            <button className="flex items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">
              <Filter size={14} />
              Más filtros
            </button>
          </div>

          <div className="flex items-center gap-2">
            <button className="flex items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">
              <Columns size={14} />
              Columnas
            </button>
          </div>
        </div>

        {/* Barra de Acciones Masivas */}
        <AnimatePresence>
          {selectedIds.length > 0 && (
            <motion.div 
              initial={{ height: 0, opacity: 0 }}
              animate={{ height: "auto", opacity: 1 }}
              exit={{ height: 0, opacity: 0 }}
              className="shrink-0 border-b border-slate-200 bg-[var(--app-brand-soft)] z-10 overflow-hidden"
            >
              <div className="flex items-center justify-between gap-4 px-8 py-3">
                <div className="flex items-center gap-2">
                  <span className="flex h-6 w-6 items-center justify-center rounded bg-[var(--app-brand-deep)] text-xs font-bold text-white">
                    {selectedIds.length}
                  </span>
                  <span className="text-sm font-medium text-[var(--app-brand-deep)]">
                    cliente(s) seleccionado(s)
                  </span>
                  
                  {/* BOTÓN X PARA LIMPIAR SELECCIÓN MASIVA */}
                  <button
                    onClick={() => setSelectedIds([])}
                    className="ml-1 rounded-full p-1 text-[var(--app-brand-deep)] transition-colors hover:bg-white/60 focus:outline-none"
                    aria-label="Deseleccionar todos"
                  >
                    <X size={16} />
                  </button>
                </div>
                
                <div className="flex items-center gap-2">
                  <button className="flex items-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium text-[var(--app-brand-deep)] transition-colors hover:bg-white/60">
                    <UserPlus size={14} /> Asignar
                  </button>
                  <button className="flex items-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium text-[var(--app-brand-deep)] transition-colors hover:bg-white/60">
                    <Download size={14} /> Exportar
                  </button>
                  <div className="mx-1 h-4 w-px bg-slate-300" />
                  
                  {hasInactiveSelected && (
                    <button 
                      onClick={handleBulkActivate}
                      disabled={isProcessing}
                      className="flex items-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium text-emerald-600 transition-colors hover:bg-white/60 disabled:opacity-50"
                    >
                      {isProcessing ? (
                        <><Loader2 size={14} className="animate-spin" /> Procesando...</>
                      ) : (
                        <><CheckCircle size={14} /> Activar</>
                      )}
                    </button>
                  )}

                  {hasActiveSelected && (
                    <button 
                      onClick={handleBulkDeactivate}
                      disabled={isProcessing}
                      className="flex items-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium text-red-600 transition-colors hover:bg-white/60 disabled:opacity-50"
                    >
                      {isProcessing ? (
                        <><Loader2 size={14} className="animate-spin" /> Procesando...</>
                      ) : (
                        <><Trash2 size={14} /> Desactivar</>
                      )}
                    </button>
                  )}
                </div>
              </div>
            </motion.div>
          )}
        </AnimatePresence>

        {/* Table Area */}
        <div className="flex-1 min-h-0 bg-white px-2">
          <div className="h-full min-w-0 overflow-auto">
          {error ? (
            <div className="flex flex-col items-center justify-center py-20 text-center">
              <AlertCircle size={32} className="mb-3 text-red-500" />
              <p className="text-sm text-slate-600">{error}</p>
              <button
                onClick={() => setRefreshTrigger(prev => prev + 1)}
                className="mt-4 flex items-center gap-2 text-sm font-medium text-[var(--app-brand-deep)] hover:underline"
              >
                <RefreshCw size={14} /> Reintentar
              </button>
            </div>
          ) : loading ? (
            <div className="divide-y divide-slate-100 px-6">
              {Array.from({ length: 5 }).map((_, i) => (
                <div key={i} className="flex items-center gap-4 py-4">
                  <div className="h-4 w-4 animate-pulse rounded bg-slate-200" />
                  <div className="h-10 w-10 animate-pulse rounded-full bg-slate-200" />
                  <div className="flex-1 space-y-2">
                    <div className="h-4 w-48 animate-pulse rounded bg-slate-200" />
                    <div className="h-3 w-24 animate-pulse rounded bg-slate-200" />
                  </div>
                </div>
              ))}
            </div>
          ) : filtered.length === 0 ? (
            <div className="flex flex-col items-center justify-center py-20 text-center">
              <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-slate-100">
                <Search size={24} className="text-slate-400" />
              </div>
              <h3 className="text-sm font-medium text-slate-900">No se encontraron clientes</h3>
              <p className="mt-1 text-sm text-slate-500">Ajusta tu búsqueda o cambia de pestaña.</p>
            </div>
          ) : (
            <CustomerTable 
              customers={filtered} 
              selectedIds={selectedIds}
              onToggleSelection={handleToggleSelection}
              onSelectAll={handleSelectAll}
              onSelectCustomer={(id) => setViewCustomerId(id)}
            />
          )}
          </div>
        </div>

        {/* Pagination */}
        {!loading && !error && (
          <div className="shrink-0">
            <Pagination 
              currentPage={page} 
              lastPage={lastPage} 
              total={total}
              perPage={perPage}
              onPageChange={setPage} 
              onPerPageChange={handlePerPageChange}
            />
          </div>
        )}

        <CustomerDrawer 
          isOpen={isDrawerOpen} 
          onClose={() => setIsDrawerOpen(false)} 
          onSuccess={() => setRefreshTrigger(prev => prev + 1)}
        />

        <CustomerOverviewDrawer 
          customerId={viewCustomerId} 
          onClose={() => setViewCustomerId(null)} 
          onUpdate={() => setRefreshTrigger(prev => prev + 1)}
        />
      </div>
    </div>
  );
}