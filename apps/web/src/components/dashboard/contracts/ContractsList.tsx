"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { apiFetch } from "@/lib/api";
import { customerDocument, customerName, excelTone, money, shortDate, stateOf } from "@/lib/commercial";
import { CONTRACT_STATES, slaLabel, validity } from "@/lib/contracts";
import { exportCsv, exportXlsx, fetchAllPages, type ExportColumn } from "@/lib/export-table";
import {
  Badge, Card, Chip, DataTable, ExportButtons, FilterSelect, Filters, LoadError, PAGE_SIZES, PageHead, Pager, Screen,
  SearchInput, SelectionBar, useRowSelection, useToasts, type Column,
  applySort, type SortState,
} from "@/components/ui/proto";
import type { PaginatedResponse } from "@/types/customer";
import type { Contract } from "@/types/commercial";

const COLUMNS: Column<Contract>[] = [
  {
    label: "Contrato", sortKey: "number",
    render: (contract) => (
      <div className="min-w-[150px]">
        <strong className="font-semibold">{contract.contract_number}</strong>
        <small className="app-muted block text-xs">desde {contract.quotation?.quotation_number ?? "—"}</small>
      </div>
    ),
  },
  { label: "Cliente", sortKey: "customer", render: (contract) => customerName(contract.customer) },
  { label: "Vigencia", sortKey: "start_date", render: (contract) => <span className="whitespace-nowrap">{validity(contract)}</span> },
  { label: "SLA", sortKey: "sla", render: (contract) => <Chip tone={contract.sla_level === "PREMIUM" ? "accent" : "neutral"}>{slaLabel(contract.sla_level)}</Chip> },
  { label: "Valor", sortKey: "total", num: true, render: (contract) => money(contract.total_value, contract.currency) },
  {
    label: "Estado", sortKey: "status",
    render: (contract) => {
      const [label, tone] = stateOf(CONTRACT_STATES, contract.status);
      return <Badge tone={tone}>{label}</Badge>;
    },
  },
];

const EXPORT_COLUMNS: ExportColumn<Contract>[] = [
  { label: "Contrato", value: (contract) => contract.contract_number, width: 18, kind: "center" },
  { label: "Cotización", value: (contract) => contract.quotation?.quotation_number ?? "", width: 18, kind: "center" },
  { label: "Cliente", value: (contract) => customerName(contract.customer), width: 36 },
  { label: "Documento", value: (contract) => customerDocument(contract.customer), width: 20 },
  { label: "Inicio", value: (contract) => (contract.start_date ? shortDate(contract.start_date) : ""), width: 15, kind: "center" },
  { label: "Fin", value: (contract) => (contract.end_date ? shortDate(contract.end_date) : ""), width: 15, kind: "center" },
  { label: "SLA", value: (contract) => slaLabel(contract.sla_level), width: 12, kind: "center" },
  { label: "Moneda", value: (contract) => contract.currency, width: 10, kind: "center" },
  { label: "Valor", value: (contract) => Number(contract.total_value), width: 16, kind: "money", currency: (contract) => contract.currency },
  {
    label: "Estado",
    value: (contract) => stateOf(CONTRACT_STATES, contract.status)[0],
    width: 14,
    kind: "status",
    tone: (contract) => excelTone(stateOf(CONTRACT_STATES, contract.status)[1]),
  },
];

const contractKey = (contract: Contract) => contract.id;

export default function ContractsList() {
  const router = useRouter();
  const { toast, toasts } = useToasts();
  const [exporting, setExporting] = useState(false);

  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [reload, setReload] = useState(0);
  const [sort, setSort] = useState<SortState>(null);
  const { selection, selectedRows, clear: clearSelection } = useRowSelection(contractKey);

  const [data, setData] = useState<PaginatedResponse<Contract> | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

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
      applySort(params, sort);

      try {
        const response = await apiFetch<PaginatedResponse<Contract>>(`/v1/contracts?${params}`);
        if (!cancelled) {
          setData(response);
          setError("");
        }
      } catch {
        if (!cancelled) setError("No se pudieron cargar los contratos.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [page, perPage, search, status, sort, reload]);

  /** Exporta las filas marcadas o, sin `picked`, todas las del filtro y búsqueda actuales. */
  async function runExport(format: "xlsx" | "csv", picked?: Contract[]) {
    setExporting(true);
    try {
      const params = new URLSearchParams();
      if (search) params.set("search", search);
      if (status) params.set("status", status);
      const rows = picked ?? (await fetchAllPages<Contract>("/v1/contracts", applySort(params, sort)));
      if (format === "xlsx") await exportXlsx("contratos", "Contratos", EXPORT_COLUMNS, rows);
      else exportCsv("contratos", EXPORT_COLUMNS, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el listado.", "danger");
    } finally {
      setExporting(false);
    }
  }

  return (
    <Screen>
      <PageHead title="Contratos" />

      <Filters>
        <SearchInput value={searchInput} onChange={setSearchInput} placeholder="Buscar contrato o cliente" />
        <FilterSelect
          label="Estado"
          value={status}
          onChange={(value) => {
            setStatus(value);
            setPage(1);
          }}
          options={CONTRACT_STATES.map(([key, label]) => [key, label])}
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
              tableId="contracts" sort={sort} onSort={(next) => { setSort(next); setPage(1); }} refreshing={loading}
              cols={COLUMNS}
              rows={data?.data ?? []}
              rowKey={contractKey}
              selection={selection}
              onRowClick={(contract) => router.push(`/contracts/${contract.id}`)}
              loading={loading && !data}
              empty="No hay contratos con estos filtros."
            />
            {data && (
              <Pager
                loading={loading}
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
