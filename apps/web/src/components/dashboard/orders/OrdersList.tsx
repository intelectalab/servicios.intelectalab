"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { apiFetch } from "@/lib/api";
import { customerDocument, customerName, excelTone, shortDate, stateOf, takeFlash } from "@/lib/commercial";
import { ORDER_STATES, orderOrigin } from "@/lib/orders";
import { exportCsv, exportXlsx, fetchAllPages, type ExportColumn } from "@/lib/export-table";
import {
  Badge, Card, DataTable, ExportButtons, FilterSelect, Filters, LoadError, PAGE_SIZES, PageHead, Pager, Screen,
  SearchInput, SelectionBar, useRowSelection, useToasts, type Column,
} from "@/components/ui/proto";
import type { PaginatedResponse } from "@/types/customer";
import type { Order } from "@/types/commercial";

const COLUMNS: Column<Order>[] = [
  {
    label: "Orden",
    render: (order) => (
      <div className="min-w-[150px]">
        <strong className="font-semibold">{order.order_number}</strong>
        {order.notes && <small className="app-muted block text-xs">{order.notes}</small>}
      </div>
    ),
  },
  { label: "Cliente", render: (order) => customerName(order.customer) },
  { label: "Origen", render: (order) => <span className="whitespace-nowrap">{orderOrigin(order)}</span> },
  { label: "Fecha", render: (order) => <span className="whitespace-nowrap">{shortDate(order.order_date)}</span> },
  {
    label: "Estado",
    render: (order) => {
      const [label, tone] = stateOf(ORDER_STATES, order.status);
      return <Badge tone={tone}>{label}</Badge>;
    },
  },
];

const EXPORT_COLUMNS: ExportColumn<Order>[] = [
  { label: "Orden", value: (order) => order.order_number, width: 18, kind: "center" },
  { label: "Cliente", value: (order) => customerName(order.customer), width: 36 },
  { label: "Documento", value: (order) => customerDocument(order.customer), width: 20 },
  { label: "Contrato", value: (order) => order.contract?.contract_number ?? "", width: 18, kind: "center" },
  { label: "Cotización", value: (order) => order.quotation?.quotation_number ?? "", width: 18, kind: "center" },
  { label: "Fecha", value: (order) => shortDate(order.order_date), width: 15, kind: "center" },
  { label: "Moneda", value: (order) => order.currency, width: 10, kind: "center" },
  { label: "Total", value: (order) => Number(order.total), width: 16, kind: "money", currency: (order) => order.currency },
  {
    label: "Estado",
    value: (order) => stateOf(ORDER_STATES, order.status)[0],
    width: 14,
    kind: "status",
    tone: (order) => excelTone(stateOf(ORDER_STATES, order.status)[1]),
  },
  { label: "Notas", value: (order) => order.notes ?? "", width: 38 },
];

const orderKey = (order: Order) => order.id;

export default function OrdersList() {
  const router = useRouter();
  const { toast, toasts } = useToasts();
  const [exporting, setExporting] = useState(false);

  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [reload, setReload] = useState(0);
  const { selection, selectedRows, clear: clearSelection } = useRowSelection(orderKey);

  const [data, setData] = useState<PaginatedResponse<Order> | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  // Aviso de la pantalla anterior ("Orden creada" desde una cotización o un contrato).
  useEffect(() => {
    const flash = takeFlash();
    if (flash) toast(flash);
  }, [toast]);

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, 350);
    return () => window.clearTimeout(timer);
  }, [searchInput]);

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setLoading(true);
      const params = new URLSearchParams({ per_page: String(perPage), page: String(page) });
      if (search) params.set("search", search);
      if (status) params.set("status", status);

      try {
        const response = await apiFetch<PaginatedResponse<Order>>(`/v1/orders?${params}`);
        if (!cancelled) {
          setData(response);
          setError("");
        }
      } catch {
        if (!cancelled) setError("No se pudieron cargar las órdenes.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [page, perPage, search, status, reload]);

  /** Exporta las filas marcadas o, sin `picked`, todas las del filtro y búsqueda actuales. */
  async function runExport(format: "xlsx" | "csv", picked?: Order[]) {
    setExporting(true);
    try {
      const params = new URLSearchParams();
      if (search) params.set("search", search);
      if (status) params.set("status", status);
      const rows = picked ?? (await fetchAllPages<Order>("/v1/orders", params));
      if (format === "xlsx") await exportXlsx("ordenes", "Órdenes", EXPORT_COLUMNS, rows);
      else exportCsv("ordenes", EXPORT_COLUMNS, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el listado.", "danger");
    } finally {
      setExporting(false);
    }
  }

  return (
    <Screen>
      <PageHead title="Órdenes" />

      <Filters>
        <SearchInput value={searchInput} onChange={setSearchInput} placeholder="Buscar…" />
        <FilterSelect
          label="Estado"
          value={status}
          onChange={(value) => {
            setStatus(value);
            setPage(1);
          }}
          options={ORDER_STATES.map(([key, label]) => [key, label])}
        />
      </Filters>

      <Card flush>
        {error ? (
          <LoadError text={error} onRetry={() => setReload((n) => n + 1)} />
        ) : (
          <>
            <SelectionBar
              count={selectedRows.length}
              busy={exporting}
              onExport={(format) => runExport(format, selectedRows)}
              onClear={clearSelection}
            />
            <DataTable
              tableId="orders" cols={COLUMNS}
              rows={data?.data ?? []}
              rowKey={orderKey}
              selection={selection}
              onRowClick={(order) => router.push(`/orders/${order.id}`)}
              loading={loading && !data}
              empty="No hay registros con estos filtros."
            />
            {data && (
              <Pager
                page={data.current_page}
                lastPage={data.last_page}
                total={data.total}
                perPage={data.per_page}
                onPage={setPage}
                onPerPage={(size) => {
                  setPerPage(size);
                  setPage(1);
                }}
                left={<ExportButtons total={data.total} busy={exporting} onExport={(format) => runExport(format)} />}
              />
            )}
          </>
        )}
      </Card>
      {toasts}
    </Screen>
  );
}
