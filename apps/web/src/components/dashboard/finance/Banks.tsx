"use client";

import { useEffect, useState } from "react";
import { ArrowLeftRight, Coins, Download, Plus, TriangleAlert, Upload, Wallet } from "lucide-react";
import { apiFetch, apiFetchLater } from "@/lib/api";
import { excelTone, money, shortDate, stateOf } from "@/lib/commercial";
import { dateTime, firstError } from "@/lib/crm";
import { ACCOUNT_KINDS, LINE_STATES, MOVEMENT_ORIGINS, originLabel } from "@/lib/finance";
import { useAccess } from "@/lib/access-context";
import { exportCsv, exportXlsx, recordExport, fetchAllPages, type ExportColumn } from "@/lib/export-table";
import {
  Badge, Btn, Card, Chip, DataTable, ExportButtons, FilterSelect, Filters, INPUT, Kpi, Kpis, Kv, LoadError, PAGE_SIZES, PageHead, Pager, Screen,
  SearchInput, SelectionBar, TD, TH, Tabs, useRowSelection, useToasts, type Column,
  applySort, type SortState,
} from "@/components/ui/proto";
import { PickSelect } from "@/components/ui/pick-select";
import { ColumnFilterBar, addColumnFilter, applyFilters, useServerColumnFilters } from "@/components/dashboard/ColumnFilters";
import { ImportModal } from "@/components/dashboard/ImportModal";
import { APP_HEADERS } from "@/lib/auth";
import {
  AccountFormModal, CashCountModal, CashExpenseDetailModal, CashExpenseModal, RefillModal, TransferModal,
} from "@/components/dashboard/finance/BankModals";
import type { PaginatedResponse } from "@/types/customer";
import type { BankAccount, BankAccountsResponse, BankMovement, CashCount, CashExpense, Reconciliation, StatementLine, TreasurySummary } from "@/types/finance";

type Tab = "ACC" | "MOV" | "REC" | "CASH";
type ToastFn = (text: string, tone?: "success" | "danger" | "warning", sub?: string) => void;

const TABLE = "proto-tbl w-full border-collapse text-[13px]";
const SKELETON = <div className="h-40 animate-pulse rounded bg-[var(--app-surface-muted)]" aria-busy="true" />;

/** Barra de avance: `full` cuando ya no queda nada pendiente. */
function Progress({ pct, low = false }: { pct: number; low?: boolean }) {
  const tone = low ? "bg-[var(--p-danger-text)]" : pct >= 100 ? "bg-[var(--p-success)]" : "bg-[#1B8FC9]";
  return (
    <span className="mt-1 block h-2 overflow-hidden rounded-full bg-[var(--app-surface-muted)]">
      <span className={`block h-full rounded-full transition-[width] duration-300 ${tone}`} style={{ width: `${Math.max(0, Math.min(100, pct))}%` }} />
    </span>
  );
}

function AccountPicker({ accounts, value, onChange }: { accounts: BankAccount[]; value: string; onChange: (id: string) => void }) {
  return (
    <div className="w-full sm:w-[280px]">
      <PickSelect
        className={INPUT} ariaLabel="Cuenta" searchable={accounts.length > 8} value={value} onChange={onChange}
        options={accounts.map((account): [string, string] => [account.id, `${account.name} (${account.currency})${account.status === "INACTIVE" ? " · inactiva" : ""}`])}
      />
    </div>
  );
}

// ---------------------------------------------------------------------------- Movimientos

const movementKey = (movement: BankMovement) => movement.id;

const MOVEMENT_EXPORT: ExportColumn<BankMovement>[] = [
  { label: "Fecha", value: (movement) => shortDate(movement.date), width: 14, kind: "center" },
  { label: "Descripción", value: (movement) => movement.description, width: 46 },
  { label: "N.º de operación", value: (movement) => movement.op_number ?? "", width: 18, kind: "center" },
  { label: "Origen", value: (movement) => originLabel(movement.ref_type), width: 16, kind: "center" },
  { label: "Ingreso", value: (movement) => (movement.amount > 0 ? movement.amount : ""), width: 14, kind: "number" },
  { label: "Egreso", value: (movement) => (movement.amount < 0 ? -movement.amount : ""), width: 14, kind: "number" },
  { label: "Saldo", value: (movement) => movement.running ?? "", width: 14, kind: "number" },
  { label: "Conciliado", value: (movement) => (movement.reconciled ? "Sí" : "Pendiente"), width: 13, kind: "status", tone: (movement) => (movement.reconciled ? "success" : "warning") },
];

/** Movimientos de una cuenta. `fixed`: dentro de Caja chica, sin selector de cuenta (la caja ya está elegida). */
function MovementsTab({
  accounts, accountId, onAccount, version, toast, fixed = false,
}: { accounts: BankAccount[]; accountId: string; onAccount: (id: string) => void; version: number; toast: ToastFn; fixed?: boolean }) {
  const account = accounts.find((item) => item.id === accountId);
  const currency = account?.currency ?? "PEN";

  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [origin, setOrigin] = useState("");
  const [reconciled, setReconciled] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[1]);
  const [sort, setSort] = useState<SortState>(null);
  const [retry, setRetry] = useState(0);
  const columnFilters = useServerColumnFilters(() => setPage(1));
  const { selection, selectedRows, clear: clearSelection } = useRowSelection(movementKey);
  const [data, setData] = useState<PaginatedResponse<BankMovement> | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [exporting, setExporting] = useState(false);

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, 350);
    return () => window.clearTimeout(timer);
  }, [searchInput]);

  const top = (params: URLSearchParams) => {
    params.set("account_id", accountId);
    if (search) params.set("search", search);
    if (origin) params.set("ref_type", origin);
    if (reconciled) params.set("reconciled", reconciled);
    return params;
  };

  useEffect(() => {
    if (!accountId) return;
    let cancelled = false;

    async function load() {
      setLoading(true);
      const params = new URLSearchParams({ per_page: String(perPage), page: String(page), account_id: accountId });
      if (search) params.set("search", search);
      if (origin) params.set("ref_type", origin);
      if (reconciled) params.set("reconciled", reconciled);
      applySort(applyFilters(params, columnFilters.param), sort);
      try {
        const response = await apiFetch<PaginatedResponse<BankMovement>>(`/v1/bank-movements?${params}`);
        if (!cancelled) {
          setData(response);
          setError("");
        }
      } catch {
        if (!cancelled) setError("No se pudieron cargar los movimientos.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [accountId, page, perPage, search, origin, reconciled, columnFilters.param, sort, version, retry]);

  const columns: Column<BankMovement>[] = [
    { label: "Fecha", sortKey: "date", filter: "date", render: (movement) => <span className="whitespace-nowrap">{shortDate(movement.date)}</span> },
    {
      label: "Descripción", sortKey: "description", filter: "text",
      render: (movement) => (
        <div className="min-w-[200px]">
          {movement.description}
          {movement.op_number && <small className="app-muted block text-xs tabular-nums">op. {movement.op_number}</small>}
        </div>
      ),
    },
    { label: "Origen", sortKey: "origin", filter: { top: "Origen" }, render: (movement) => <Chip>{originLabel(movement.ref_type)}</Chip> },
    { label: "Ingreso", num: true, sortKey: "income", filter: "number", render: (movement) => (movement.amount > 0 ? money(movement.amount, currency) : "") },
    { label: "Egreso", num: true, sortKey: "expense", filter: "number", render: (movement) => (movement.amount < 0 ? money(-movement.amount, currency) : "") },
    { label: "Saldo", num: true, sortKey: "running", filter: "number", render: (movement) => (movement.running == null ? "—" : money(movement.running, currency)) },
    {
      label: "Conciliado", sortKey: "reconciled", filter: { top: "Conciliación" },
      render: (movement) => (movement.reconciled ? <Chip tone="success">Sí</Chip> : <Chip tone="warning">Pendiente</Chip>),
    },
  ];

  async function runExport(format: "xlsx" | "csv", picked?: BankMovement[]) {
    setExporting(true);
    try {
      const rows = picked ?? (await fetchAllPages<BankMovement>("/v1/bank-movements", applySort(columnFilters.apply(top(new URLSearchParams())), sort)));
      // La API comprueba el permiso de exportar y deja la exportación en auditoría.
      await recordExport("treasury", format, rows.length, Boolean(picked));
      const file = `movimientos-${(account?.name ?? "cuenta").toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "")}`;
      if (format === "xlsx") await exportXlsx(file, `Movimientos · ${account?.name ?? ""}`.slice(0, 31), MOVEMENT_EXPORT, rows);
      else exportCsv(file, MOVEMENT_EXPORT, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el listado.", "danger");
    } finally {
      setExporting(false);
    }
  }

  return (
    <>
      <Filters>
        {!fixed && <AccountPicker accounts={accounts} value={accountId} onChange={(id) => { onAccount(id); setPage(1); clearSelection(); }} />}
        <SearchInput value={searchInput} onChange={setSearchInput} placeholder="Buscar descripción u operación" />
        <FilterSelect label="Origen" value={origin} onChange={(value) => { setOrigin(value); setPage(1); }} options={MOVEMENT_ORIGINS} />
        <FilterSelect label="Conciliación" value={reconciled} onChange={(value) => { setReconciled(value); setPage(1); }} options={[["true", "Conciliado"], ["false", "Por conciliar"]]} />
        <ColumnFilterBar
          filters={columnFilters}
          hasOtherFilters={Boolean(searchInput || origin || reconciled)}
          onClearAll={() => {
            setSearchInput("");
            setSearch("");
            setOrigin("");
            setReconciled("");
            setPage(1);
          }}
        />
      </Filters>
      {error ? (
        <Card><LoadError text={error} onRetry={() => setRetry((n) => n + 1)} /></Card>
      ) : (
        <Card title={account ? `${fixed ? "Movimientos de la caja" : account.name} · saldo ${money(account.balance, account.currency)}` : "Movimientos"} flush>
          <SelectionBar permission="treasury.export" count={selectedRows.length} busy={exporting} onExport={(format) => runExport(format, selectedRows)} onClear={clearSelection} />
          <DataTable
            tableId={fixed ? "cash-movements" : "bank-movements"} sort={sort} onSort={(next) => { setSort(next); setPage(1); }} refreshing={loading}
            onFilter={(column) => addColumnFilter(columnFilters, column)}
            cols={columns} rows={data?.data ?? []} rowKey={movementKey} selection={selection}
            loading={loading && !data} empty="No hay movimientos con estos filtros."
          />
          {data && (
            <Pager
              loading={loading} page={data.current_page} lastPage={data.last_page} total={data.total} perPage={data.per_page} onPage={setPage}
              onPerPage={(size) => { setPerPage(size); setPage(1); }}
              left={<ExportButtons permission="treasury.export" total={data.total} busy={exporting} onExport={(format) => runExport(format)} />}
            />
          )}
        </Card>
      )}
    </>
  );
}

// ---------------------------------------------------------------------------- Conciliación

const LINE_EXPORT: ExportColumn<StatementLine>[] = [
  { label: "Fecha", value: (line) => shortDate(line.date), width: 14, kind: "center" },
  { label: "Descripción del banco", value: (line) => line.description ?? "", width: 44 },
  { label: "N.º de operación", value: (line) => line.op_number ?? "", width: 18, kind: "center" },
  { label: "Monto", value: (line) => line.amount, width: 15, kind: "number" },
  { label: "Estado", value: (line) => stateOf(LINE_STATES, line.status)[0], width: 16, kind: "status", tone: (line) => excelTone(stateOf(LINE_STATES, line.status)[1]) },
  { label: "Regla", value: (line) => line.rule ?? "", width: 26 },
  { label: "Movimiento en libros", value: (line) => line.movement_description ?? "", width: 44 },
];

const PENDING_EXPORT: ExportColumn<BankMovement>[] = [
  { label: "Fecha", value: (movement) => shortDate(movement.date), width: 14, kind: "center" },
  { label: "Descripción", value: (movement) => movement.description, width: 46 },
  { label: "N.º de operación", value: (movement) => movement.op_number ?? "", width: 22, kind: "center" },
  { label: "Origen", value: (movement) => originLabel(movement.ref_type), width: 16, kind: "center" },
  { label: "Monto", value: (movement) => movement.amount, width: 14, kind: "number" },
];

const TEMPLATE_NAME = "plantilla_extracto_bancario.xlsx";

/** Descarga la plantilla de Excel del extracto (la misma que ofrece la ventana de importar). */
async function downloadTemplate(accountId: string) {
  const response = await fetch(`${process.env.NEXT_PUBLIC_API_URL ?? ""}/v1/bank-accounts/${accountId}/statements/template`, { headers: APP_HEADERS, credentials: "include" });
  if (!response.ok) throw new Error("No se pudo descargar la plantilla.");
  const url = URL.createObjectURL(await response.blob());
  const link = document.createElement("a");
  link.href = url;
  link.download = TEMPLATE_NAME;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

/** "Importar extracto": la ventana de importación de siempre, eligiendo antes a qué cuenta entra. */
function StatementImport({
  accounts, accountId: initial, onClose, onImported,
}: { accounts: BankAccount[]; accountId: string; onClose: () => void; onImported: (accountId: string) => void }) {
  const usable = accounts.filter((account) => account.status === "ACTIVE" && account.account_type !== "CASH");
  const [accountId, setAccountId] = useState(usable.some((account) => account.id === initial) ? initial : usable[0]?.id ?? "");

  return (
    <ImportModal
      title="Importar extracto bancario"
      endpoint={`/v1/bank-accounts/${accountId}/statements`}
      templateName={TEMPLATE_NAME}
      hint="Columnas fecha, descripcion, monto y operacion; los cargos van en negativo. Se empareja por monto exacto y fecha (±3 días), dando prioridad al número de operación. Las líneas ya importadas en esta cuenta se omiten."
      onClose={onClose}
      onImported={() => onImported(accountId)}
    >
      <label className="flex flex-col gap-[5px]">
        <span className="text-[12.5px] font-medium">Cuenta <em className="not-italic text-[color:var(--p-danger-text)]">*</em></span>
        <PickSelect
          className={INPUT} ariaLabel="Cuenta" searchable={usable.length > 8} value={accountId} onChange={setAccountId}
          options={usable.map((account): [string, string] => [account.id, `${account.name} (${account.currency})`])}
        />
      </label>
    </ImportModal>
  );
}

function ReconcileTab({
  accounts, accountId, onAccount, version, onImport, onChanged, toast,
}: { accounts: BankAccount[]; accountId: string; onAccount: (id: string) => void; version: number; onImport: () => void; onChanged: () => void; toast: ToastFn }) {
  const { can } = useAccess();
  const [data, setData] = useState<Reconciliation | null>(null);
  const [error, setError] = useState("");
  const [retry, setRetry] = useState(0);
  const [busy, setBusy] = useState(false);
  const [exporting, setExporting] = useState(false);
  // Extracto elegido del historial; vale solo para esa cuenta y hasta la próxima importación.
  const [picked, setPicked] = useState<{ account: string; version: number; id: string } | null>(null);
  const statementId = picked && picked.account === accountId && picked.version === version ? picked.id : "";

  /** Exporta las líneas del extracto que se está viendo. */
  async function exportLines(format: "xlsx" | "csv") {
    const shown = data?.statement;
    if (!shown) return;
    setExporting(true);
    try {
      await recordExport("treasury", format, shown.lines.length, false);
      const base = shown.file_name.toLowerCase().replace(/\.[a-z0-9]+$/, "").replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "");
      const name = base.startsWith("extracto") ? base : `extracto-${base}`;
      if (format === "xlsx") await exportXlsx(name, `Extracto ${shown.file_name}`, LINE_EXPORT, shown.lines);
      else exportCsv(name, LINE_EXPORT, shown.lines);
      toast(`${shown.lines.length} ${shown.lines.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el extracto.", "danger");
    } finally {
      setExporting(false);
    }
  }

  /** Descarga el archivo del extracto tal como se importó (enlace temporal). */
  async function downloadFile(id: string) {
    try {
      const { url } = await apiFetch<{ url: string }>(`/v1/bank-statements/${id}/file-url`);
      window.open(url, "_blank", "noopener");
    } catch (err) {
      toast(firstError(err, "No se pudo descargar el archivo."), "danger");
    }
  }

  /** Exporta todos los movimientos en libros sin conciliar de la cuenta (no solo los que la tarjeta muestra). */
  async function exportPending(format: "xlsx" | "csv") {
    setExporting(true);
    try {
      const params = applySort(new URLSearchParams({ account_id: accountId, reconciled: "false" }), { key: "date", dir: "asc" });
      const rows = await fetchAllPages<BankMovement>("/v1/bank-movements", params);
      // La API comprueba el permiso de exportar y deja la exportación en auditoría.
      await recordExport("treasury", format, rows.length, false);
      const name = (accounts.find((item) => item.id === accountId)?.name ?? "cuenta").toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "");
      if (format === "xlsx") await exportXlsx(`pendientes-de-conciliar-${name}`, "Pendientes de conciliar", PENDING_EXPORT, rows);
      else exportCsv(`pendientes-de-conciliar-${name}`, PENDING_EXPORT, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el listado.", "danger");
    } finally {
      setExporting(false);
    }
  }

  useEffect(() => {
    if (!accountId) return;
    let cancelled = false;
    apiFetch<Reconciliation>(`/v1/bank-statements?account_id=${accountId}${statementId ? `&statement_id=${statementId}` : ""}`)
      .then((next) => {
        if (cancelled) return;
        setData(next);
        setError("");
      })
      .catch(() => { if (!cancelled) setError("No se pudo cargar la conciliación."); });
    return () => { cancelled = true; };
  }, [accountId, version, retry, statementId]);

  async function act(line: StatementLine, path: "match" | "resolve", body: unknown, done: string) {
    setBusy(true);
    try {
      await apiFetch(`/v1/bank-statement-lines/${line.id}/${path}`, { method: "POST", body: JSON.stringify(body) });
      toast(done);
    } catch (err) {
      toast(firstError(err, "No se pudo completar la acción."), "danger");
    } finally {
      setBusy(false);
      onChanged();
    }
  }

  const current = data && data.account.id === accountId ? data : null;
  const currency = current?.account.currency ?? "PEN";
  const statement = current?.statement ?? null;
  const cash = accounts.find((item) => item.id === accountId)?.account_type === "CASH";
  const manages = can("treasury.manage");

  return (
    <>
      <Filters><AccountPicker accounts={accounts} value={accountId} onChange={onAccount} /></Filters>
      {error ? (
        <Card><LoadError text={error} onRetry={() => setRetry((n) => n + 1)} /></Card>
      ) : !current ? (
        <Card>{SKELETON}</Card>
      ) : (
        <>
          <div className="grid grid-cols-1 gap-x-4 lg:grid-cols-2">
            <Card title="Resumen de conciliación">
              <Kv
                rows={[
                  ["Saldo en libros", <strong key="book" className="font-semibold tabular-nums">{money(current.book_balance, currency)}</strong>],
                  ["Movimientos en libros sin conciliar", `${current.pending_count} · ${money(current.pending_amount, currency)}`],
                  ["Último extracto", statement ? `${statement.file_name} · ${dateTime(statement.imported_at)}${statement.imported_by ? ` · ${statement.imported_by}` : ""}` : "Aún no se importa"],
                  statement ? ["Líneas del extracto resueltas", <span key="pct" className="block">{statement.resolved_pct} %<Progress pct={statement.resolved_pct} /></span>] : null,
                  current.statements_count > 1 ? ["Extractos importados en esta cuenta", current.statements_count] : null,
                ]}
              />
            </Card>
            <Card title="Importar extracto">
              {cash ? (
                <p className="app-muted">Una caja no tiene extracto: sus movimientos nacen conciliados y se controlan con el arqueo.</p>
              ) : (
                <>
                  <p className="text-[13px]">
                    Sube el extracto del banco en Excel o CSV (columnas <code className="text-xs">fecha, descripcion, monto, operacion</code>). El sistema empareja por monto exacto y fecha (±3 días),
                    dando prioridad al número de operación, y detecta líneas ya importadas.
                  </p>
                  <div className="mt-3 flex flex-wrap gap-2">
                    <Btn onClick={onImport} permission="treasury.create"><Upload size={16} /><span>Importar extracto</span></Btn>
                    <Btn variant="ghost" permission="treasury.create" onClick={() => downloadTemplate(accountId).catch(() => toast("No se pudo descargar la plantilla.", "danger"))}>
                      <Download size={16} />
                      <span>Descargar plantilla</span>
                    </Btn>
                  </div>
                </>
              )}
            </Card>
          </div>

          {statement && (
            <Card title={`Extracto ${statement.file_name}`}>
              {/* Historial y archivo en su propia fila: en teléfono no desbordan la cabecera de la tarjeta. */}
              <div className="mb-3 flex flex-wrap items-center gap-2">
                  {current.statements.length > 1 && (
                    <div className="w-full sm:w-[320px]">
                      <PickSelect
                        className={`${INPUT} min-h-8 py-1 text-xs`} ariaLabel="Ver otro extracto" searchable={current.statements.length > 8} value={statement.id}
                        onChange={(id) => setPicked({ account: accountId, version, id })}
                        options={current.statements.map((item): [string, string] => [item.id, `${item.file_name} · ${dateTime(item.imported_at)} · ${item.lines_count} ${item.lines_count === 1 ? "línea" : "líneas"}`])}
                      />
                    </div>
                  )}
                  <Btn
                    size="sm" variant="ghost" permission="treasury.view" disabled={!statement.has_file}
                    title={statement.has_file ? "Descargar el archivo tal como se importó" : "Este extracto se importó antes de que se guardaran los archivos"}
                    onClick={() => downloadFile(statement.id)}
                  >
                    <Download size={14} />
                    <span>Descargar archivo</span>
                  </Btn>
              </div>
              <div className="proto-tbl-wrap -mx-1 overflow-x-auto">
                <table className={TABLE}>
                  <thead>
                    <tr>
                      <th className={TH}>Fecha</th>
                      <th className={TH}>Descripción del banco</th>
                      <th className={`${TH} text-right`}>Monto</th>
                      <th className={TH}>Estado</th>
                      <th className={TH}>Movimiento en libros</th>
                      <th className={`${TH} relative`}><span className="sr-only">Acciones</span></th>
                    </tr>
                  </thead>
                  <tbody>
                    {statement.lines.map((line) => (
                      <tr key={line.id}>
                        <td className={`${TD} whitespace-nowrap`} data-label="Fecha">{shortDate(line.date)}</td>
                        <td className={TD} data-label="Descripción del banco">
                          {line.description || "—"}
                          <small className="app-muted block text-xs tabular-nums">op. {line.op_number ?? "—"}</small>
                        </td>
                        <td className={`${TD} whitespace-nowrap text-right tabular-nums`} data-label="Monto">{money(line.amount, currency)}</td>
                        <td className={TD} data-label="Estado">
                          <Badge tone={stateOf(LINE_STATES, line.status)[1]}>{stateOf(LINE_STATES, line.status)[0]}</Badge>
                          {line.rule && <small className="app-muted block text-xs">{line.rule}</small>}
                        </td>
                        <td className={TD} data-label="Movimiento en libros">
                          {line.status !== "UNMATCHED" ? (
                            <small>{line.movement_description ?? "—"}</small>
                          ) : line.candidates.length ? (
                            manages ? (
                              <div className="min-w-[200px]">
                                <PickSelect
                                  className={`${INPUT} min-h-8 py-1 text-xs`} ariaLabel="Emparejar" disabled={busy} value=""
                                  onChange={(movementId) => { if (movementId) act(line, "match", { movement_id: movementId }, "Línea conciliada"); }}
                                  options={[["", "Emparejar con…"], ...line.candidates.map((candidate): [string, string] => [candidate.id, `${shortDate(candidate.date)} · ${candidate.description.slice(0, 40)}`])]}
                                />
                              </div>
                            ) : <span className="app-muted text-xs">{line.candidates.length} {line.candidates.length === 1 ? "candidato" : "candidatos"} en libros</span>
                          ) : (
                            <span className="app-muted text-xs">Sin candidato en libros</span>
                          )}
                        </td>
                        <td className={TD} data-label="Acciones">
                          {line.status === "UNMATCHED" && (
                            <div className="flex flex-wrap gap-1.5">
                              {line.amount < 0 ? (
                                <Btn size="sm" variant="secondary" disabled={busy} permission="treasury.manage" onClick={() => act(line, "resolve", { kind: "FEE" }, "Comisión registrada")}>Registrar comisión</Btn>
                              ) : (
                                <Btn size="sm" variant="secondary" disabled={busy} permission="treasury.manage" onClick={() => act(line, "resolve", { kind: "INCOME" }, "Abono por identificar registrado")}>Abono por identificar</Btn>
                              )}
                              <Btn size="sm" variant="ghost" disabled={busy} permission="treasury.manage" onClick={() => act(line, "resolve", { kind: "IGNORE" }, "Línea ignorada")}>Ignorar</Btn>
                            </div>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {statement.unmatched > 0 ? (
                <p className="app-muted mt-2 text-xs">
                  {statement.unmatched} {statement.unmatched === 1 ? "línea" : "líneas"} sin emparejar: regístralas (comisiones, ITF, abonos sin referencia) o emparéjalas a mano.
                </p>
              ) : (
                <p className="mt-2 text-xs text-[color:var(--p-success-text)]">Todas las líneas del extracto están resueltas.</p>
              )}
              <div className="mt-3 flex">
                <ExportButtons permission="treasury.export" total={statement.lines.length} busy={exporting} onExport={exportLines} />
              </div>
            </Card>
          )}

          <Card title="Movimientos en libros pendientes de conciliar">
            {current.pending.length ? (
              <>
                <div className="proto-tbl-wrap -mx-1 overflow-x-auto">
                  <table className={TABLE}>
                    <thead>
                      <tr>
                        <th className={TH}>Fecha</th>
                        <th className={TH}>Descripción</th>
                        <th className={`${TH} text-right`}>Monto</th>
                      </tr>
                    </thead>
                    <tbody>
                      {current.pending.map((movement) => (
                        <tr key={movement.id}>
                          <td className={`${TD} whitespace-nowrap`} data-label="Fecha">{shortDate(movement.date)}</td>
                          <td className={TD} data-label="Descripción">
                            {movement.description}
                            {movement.op_number && <small className="app-muted block text-xs tabular-nums">op. {movement.op_number}</small>}
                          </td>
                          <td className={`${TD} whitespace-nowrap text-right tabular-nums`} data-label="Monto">{money(movement.amount, currency)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
                {current.pending_count > current.pending.length && <p className="app-muted mt-2 text-xs">Se muestran los {current.pending.length} más antiguos de {current.pending_count}.</p>}
                <div className="mt-3 flex">
                  <ExportButtons permission="treasury.export" total={current.pending_count} busy={exporting} onExport={exportPending} />
                </div>
              </>
            ) : (
              <p className="app-muted">Sin pendientes.</p>
            )}
          </Card>
        </>
      )}
    </>
  );
}

// ---------------------------------------------------------------------------- Caja chica

const expenseKey = (expense: CashExpense) => expense.id;

const EXPENSE_EXPORT: ExportColumn<CashExpense>[] = [
  { label: "N.º", value: (expense) => expense.expense_number, width: 16, kind: "center" },
  { label: "Fecha", value: (expense) => shortDate(expense.expense_date), width: 14, kind: "center" },
  { label: "Concepto", value: (expense) => expense.description, width: 40 },
  { label: "Comprobante", value: (expense) => expense.receipt ?? "", width: 22 },
  { label: "Centro de costo", value: (expense) => expense.cost_center ?? "", width: 18 },
  { label: "Cuenta", value: (expense) => expense.expense_account ?? "", width: 18 },
  { label: "Monto", value: (expense) => expense.amount, width: 14, kind: "money" },
];

function CashTab({
  accounts, version, onChanged, toast,
}: { accounts: BankAccount[]; version: number; onChanged: () => void; toast: ToastFn }) {
  const boxes = accounts.filter((account) => account.account_type === "CASH");
  const [picked, setPicked] = useState("");
  const cash = boxes.find((account) => account.id === picked) ?? boxes.find((account) => account.status === "ACTIVE") ?? boxes[0];
  const cashId = cash?.id ?? "";

  const [counts, setCounts] = useState<CashCount[] | null>(null);
  const [data, setData] = useState<PaginatedResponse<CashExpense> | null>(null);
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [sort, setSort] = useState<SortState>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [retry, setRetry] = useState(0);
  const [exporting, setExporting] = useState(false);
  const [modal, setModal] = useState<"expense" | "count" | "refill" | null>(null);
  const [openExpense, setOpenExpense] = useState<CashExpense | null>(null);
  const { selection, selectedRows, clear: clearSelection } = useRowSelection(expenseKey);

  useEffect(() => {
    if (!cashId) return;
    let cancelled = false;

    async function load() {
      setLoading(true);
      try {
        const response = await apiFetch<PaginatedResponse<CashExpense>>(`/v1/cash-expenses?${applySort(new URLSearchParams({ account_id: cashId, per_page: String(perPage), page: String(page) }), sort)}`);
        if (!cancelled) {
          setData(response);
          setError("");
        }
      } catch {
        if (!cancelled) setError("No se pudieron cargar los gastos de caja chica.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [cashId, page, perPage, sort, version, retry]);

  // Los arqueos, después de los gastos.
  useEffect(() => {
    if (!cashId) return;
    let cancelled = false;
    apiFetchLater<CashCount[]>(`/v1/cash-counts?account_id=${cashId}`)
      .then((next) => { if (!cancelled) setCounts(next); })
      .catch(() => undefined);
    return () => { cancelled = true; };
  }, [cashId, version]);

  if (!cash) {
    return <Card><p className="app-muted">Aún no hay una caja registrada. Crea una con “Nueva cuenta” (tipo Caja) para llevar la caja chica, sus gastos y sus arqueos.</p></Card>;
  }

  const currency = cash.currency;
  const fund = cash.fund_amount ?? 0;
  const missing = Math.max(0, Math.round((fund - cash.balance) * 100) / 100);
  const pct = fund > 0 ? Math.round((cash.balance / fund) * 100) : 0;
  const active = cash.status === "ACTIVE";

  const columns: Column<CashExpense>[] = [
    { label: "N.º", sortKey: "number", render: (expense) => <strong className="whitespace-nowrap font-semibold tabular-nums">{expense.expense_number}</strong> },
    { label: "Fecha", sortKey: "date", render: (expense) => <span className="whitespace-nowrap">{shortDate(expense.expense_date)}</span> },
    {
      label: "Concepto", sortKey: "description",
      render: (expense) => (
        <div className="min-w-[180px]">
          {expense.description}
          {expense.receipt && <small className="app-muted block text-xs">{expense.receipt}</small>}
        </div>
      ),
    },
    { label: "Centro de costo", sortKey: "cost_center", render: (expense) => expense.cost_center ?? "—" },
    { label: "Cuenta", sortKey: "account", render: (expense) => expense.expense_account ?? "—" },
    { label: "Monto", num: true, sortKey: "amount", render: (expense) => money(expense.amount, currency) },
  ];

  async function runExport(format: "xlsx" | "csv", rowsPicked?: CashExpense[]) {
    setExporting(true);
    try {
      const rows = rowsPicked ?? (await fetchAllPages<CashExpense>("/v1/cash-expenses", applySort(new URLSearchParams({ account_id: cashId }), sort)));
      await recordExport("treasury", format, rows.length, Boolean(rowsPicked));
      if (format === "xlsx") await exportXlsx("gastos-caja-chica", "Gastos de caja chica", EXPENSE_EXPORT, rows);
      else exportCsv("gastos-caja-chica", EXPENSE_EXPORT, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el listado.", "danger");
    } finally {
      setExporting(false);
    }
  }

  return (
    <>
      {boxes.length > 1 && <Filters><AccountPicker accounts={boxes} value={cashId} onChange={(id) => { setPicked(id); setPage(1); setCounts(null); clearSelection(); }} /></Filters>}
      <div className="grid grid-cols-1 gap-x-4 lg:grid-cols-2">
        <Card title={boxes.length > 1 ? cash.name : "Caja chica"}>
          <Kv
            rows={[
              ["Custodio", cash.custodian?.name ?? "—"],
              ["Fondo fijo", cash.fund_amount == null ? "Sin fondo fijo" : money(fund, currency)],
              ["Saldo", <strong key="balance" className="font-semibold tabular-nums">{money(cash.balance, currency)}</strong>],
              cash.fund_amount == null ? null : ["Por reponer", money(missing, currency)],
              ["Tope por comprobante", money(cash.expense_limit, currency)],
            ]}
          />
          {fund > 0 && <Progress pct={pct} low={pct < 30} />}
          <div className="mt-3 flex flex-wrap gap-2">
            <Btn onClick={() => setModal("expense")} permission="treasury.create" disabled={!active}><Plus size={16} /><span>Registrar gasto</span></Btn>
            <Btn
              variant="secondary" onClick={() => setModal("refill")} permission="treasury.create" disabled={!active || missing < 1}
              title={cash.fund_amount == null ? "Esta caja no tiene fondo fijo" : missing < 1 ? "El fondo está completo" : undefined}
            >
              Reponer fondo
            </Btn>
            <Btn variant="ghost" onClick={() => setModal("count")} permission="treasury.create" disabled={!active}>Arqueo de caja</Btn>
          </div>
        </Card>
        <Card title="Arqueos">
          {!counts ? SKELETON : counts.length ? (
            <div className="proto-tbl-wrap -mx-1 overflow-x-auto">
              <table className={TABLE}>
                <thead>
                  <tr>
                    <th className={TH}>Fecha</th>
                    <th className={`${TH} text-right`}>Libros</th>
                    <th className={`${TH} text-right`}>Contado</th>
                    <th className={`${TH} text-right`}>Diferencia</th>
                    <th className={TH}>Nota</th>
                  </tr>
                </thead>
                <tbody>
                  {counts.map((count) => (
                    <tr key={count.id}>
                      <td className={TD} data-label="Fecha">
                        <span className="whitespace-nowrap">{dateTime(count.at)}</span>
                        {count.by && <small className="app-muted block text-xs">{count.by}</small>}
                      </td>
                      <td className={`${TD} whitespace-nowrap text-right tabular-nums`} data-label="Libros">{money(count.book, currency)}</td>
                      <td className={`${TD} whitespace-nowrap text-right tabular-nums`} data-label="Contado">{money(count.counted, currency)}</td>
                      <td className={`${TD} whitespace-nowrap text-right tabular-nums`} data-label="Diferencia">
                        <strong className={`font-semibold ${Math.abs(count.difference) > 0.009 ? "text-[color:var(--p-danger-text)]" : "text-[color:var(--p-success-text)]"}`}>{money(count.difference, currency)}</strong>
                      </td>
                      <td className={TD} data-label="Nota">{count.note ?? "—"}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <p className="app-muted">Aún no se hizo ningún arqueo.</p>
          )}
        </Card>
      </div>

      {error ? (
        <Card><LoadError text={error} onRetry={() => setRetry((n) => n + 1)} /></Card>
      ) : (
        <Card title="Gastos de caja chica" flush>
          <SelectionBar permission="treasury.export" count={selectedRows.length} busy={exporting} onExport={(format) => runExport(format, selectedRows)} onClear={clearSelection} />
          <DataTable
            tableId="cash-expenses" sort={sort} onSort={(next) => { setSort(next); setPage(1); }} refreshing={loading}
            cols={columns} rows={data?.data ?? []} rowKey={expenseKey} selection={selection} loading={loading && !data} empty="Aún no hay gastos de caja chica."
            onRowClick={setOpenExpense}
          />
          {data && (
            <Pager
              loading={loading} page={data.current_page} lastPage={data.last_page} total={data.total} perPage={data.per_page} onPage={setPage}
              onPerPage={(size) => { setPerPage(size); setPage(1); }}
              left={<ExportButtons permission="treasury.export" total={data.total} busy={exporting} onExport={(format) => runExport(format)} />}
            />
          )}
        </Card>
      )}

      {/* Todo lo que entró y salió de esta caja (apertura, reposiciones, cobros en efectivo, gastos), con su exportación. */}
      <MovementsTab key={cashId} accounts={accounts} accountId={cashId} onAccount={() => undefined} version={version} toast={toast} fixed />

      {openExpense && (
        <CashExpenseDetailModal
          expense={openExpense} currency={currency} onClose={() => setOpenExpense(null)}
          onDone={(entry) => { setOpenExpense(null); toast("Gasto corregido", "success", entry ? `Nuevo asiento ${entry}` : undefined); onChanged(); }}
        />
      )}
      {modal === "expense" && <CashExpenseModal cash={cash} onClose={() => setModal(null)} onDone={(number) => { setModal(null); toast("Gasto registrado", "success", number); onChanged(); }} />}
      {modal === "count" && (
        <CashCountModal
          cash={cash} onClose={() => setModal(null)}
          onDone={(difference) => {
            setModal(null);
            if (Math.abs(difference) > 0.009) toast("Arqueo con diferencia", "warning", `${money(difference, currency)} · queda en auditoría`);
            else toast("Arqueo conforme");
            onChanged();
          }}
        />
      )}
      {modal === "refill" && <RefillModal cash={cash} accounts={accounts} onClose={() => setModal(null)} onDone={() => { setModal(null); toast("Fondo de caja chica repuesto"); onChanged(); }} />}
    </>
  );
}

// ---------------------------------------------------------------------------- Pantalla

export default function Banks() {
  const { toast, toasts } = useToasts();
  const [tab, setTab] = useState<Tab>("ACC");
  const [accounts, setAccounts] = useState<BankAccountsResponse | null>(null);
  const [summary, setSummary] = useState<TreasurySummary | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [picked, setPicked] = useState("");
  const [modal, setModal] = useState<"transfer" | "import" | "account" | null>(null);
  const [editing, setEditing] = useState<BankAccount | null>(null);

  useEffect(() => {
    let cancelled = false;
    apiFetch<BankAccountsResponse>("/v1/bank-accounts")
      .then((next) => {
        if (cancelled) return;
        setAccounts(next);
        setError("");
      })
      .catch(() => { if (!cancelled) setError("No se pudieron cargar las cuentas."); });
    return () => { cancelled = true; };
  }, [reload]);

  // Los indicadores, después de las cuentas.
  useEffect(() => {
    let cancelled = false;
    apiFetchLater<TreasurySummary>("/v1/bank-accounts/summary")
      .then((next) => { if (!cancelled) setSummary(next); })
      .catch(() => undefined);
    return () => { cancelled = true; };
  }, [reload]);

  const list = accounts?.data ?? [];
  // Cuenta elegida en Movimientos y Conciliación; por defecto, la primera.
  const accountId = list.some((account) => account.id === picked) ? picked : list[0]?.id ?? "";
  const changed = () => setReload((n) => n + 1);
  const go = (next: Tab, id: string) => {
    setPicked(id);
    setTab(next);
  };

  return (
    <Screen>
      <PageHead
        title="Bancos y caja"
        actions={
          <>
            <Btn variant="ghost" onClick={() => { setEditing(null); setModal("account"); }} permission="treasury.manage">
              <Plus size={16} />
              <span>Nueva cuenta</span>
            </Btn>
            <Btn variant="secondary" onClick={() => setModal("transfer")} permission="treasury.create" disabled={list.length < 2}>
              <ArrowLeftRight size={16} />
              <span>Transferir entre cuentas</span>
            </Btn>
            <Btn onClick={() => setModal("import")} permission="treasury.create" disabled={!list.some((account) => account.account_type !== "CASH" && account.status === "ACTIVE")}>
              <Upload size={16} />
              <span>Importar extracto</span>
            </Btn>
          </>
        }
      />

      <Kpis>
        <Kpi label="Disponible total (en soles)" value={summary ? money(summary.total_pen) : "—"} sub={summary?.missing_rate ? "sin el saldo en dólares: falta tipo de cambio" : undefined} icon={<Coins size={22} />} tone="success" />
        <Kpi
          label="Movimientos por conciliar" value={summary?.unreconciled ?? "—"} sub={summary ? money(summary.unreconciled_amount) : undefined}
          icon={<TriangleAlert size={22} />} tone={summary && summary.unreconciled > 0 ? "warning" : "success"}
        />
        <Kpi label="Extractos importados" value={summary?.statements ?? "—"} icon={<Upload size={22} />} />
        <Kpi label="Caja chica" value={summary ? money(summary.cash_balance) : "—"} sub={summary ? `fondo ${money(summary.cash_fund)}` : undefined} icon={<Wallet size={22} />} />
      </Kpis>

      <Tabs
        tabs={[["ACC", "Cuentas", null], ["MOV", "Movimientos", null], ["REC", "Conciliación", summary?.unreconciled ?? null], ["CASH", "Caja chica", null]]}
        value={tab}
        onChange={(value) => setTab(value as Tab)}
      />

      {error ? (
        <Card><LoadError text={error} onRetry={changed} /></Card>
      ) : !accounts ? (
        <Card>{SKELETON}</Card>
      ) : tab === "CASH" ? (
        <CashTab accounts={list} version={reload} onChanged={changed} toast={toast} />
      ) : !list.length ? (
        <Card><p className="app-muted">Aún no hay cuentas. Registra la primera con “Nueva cuenta”: un banco, una caja o la cuenta de detracciones del Banco de la Nación.</p></Card>
      ) : tab === "ACC" ? (
        <div className="grid grid-cols-1 gap-x-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          {list.map((account) => (
            <Card key={account.id} className="border-l-4 border-l-[#1B8FC9]">
              <div className="flex items-start gap-2.5">
                <span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-[var(--p-brand-soft)] text-[color:var(--p-brand)]">
                  {account.account_type === "CASH" ? <Wallet size={20} /> : <Coins size={20} />}
                </span>
                <div className="min-w-0">
                  <strong className="block font-semibold [overflow-wrap:anywhere]">{account.name}</strong>
                  <small className="app-muted block text-xs">
                    {ACCOUNT_KINDS[account.account_type]} · {account.currency}{account.ledger_account ? ` · cuenta contable ${account.ledger_account}` : ""}
                  </small>
                </div>
                {account.status === "INACTIVE" && <span className="ml-auto"><Chip tone="muted">Inactiva</Chip></span>}
              </div>
              <div className="mt-3 text-[22px] font-semibold leading-tight tabular-nums">{money(account.balance, account.currency)}</div>
              {account.currency === "USD" && (
                <small className="app-muted block text-xs">{account.balance_pen == null ? "Sin tipo de cambio registrado" : `≈ ${money(account.balance_pen)} al T.C. de hoy`}</small>
              )}
              <div className="mt-2">
                <Kv
                  rows={[
                    account.account_type === "CASH" ? null : ["Banco", account.bank_name ?? "—"],
                    account.account_number ? ["Número", <span key="number" className="tabular-nums">{account.account_number}</span>] : null,
                    account.cci ? ["CCI", <span key="cci" className="tabular-nums">{account.cci}</span>] : null,
                    account.fund_amount != null ? ["Fondo fijo", money(account.fund_amount, account.currency)] : null,
                    account.custodian ? ["Custodio", account.custodian.name] : null,
                    ["Por conciliar", account.pending_count ? <Chip key="pending" tone="warning">{account.pending_count} {account.pending_count === 1 ? "movimiento" : "movimientos"}</Chip> : <Chip key="pending" tone="success">Al día</Chip>],
                  ]}
                />
              </div>
              <div className="flex flex-wrap gap-1.5">
                <Btn size="sm" variant="ghost" onClick={() => go("MOV", account.id)}>Ver movimientos</Btn>
                {account.account_type !== "CASH" && <Btn size="sm" variant="ghost" onClick={() => go("REC", account.id)}>Conciliar</Btn>}
                <Btn size="sm" variant="ghost" permission="treasury.manage" onClick={() => { setEditing(account); setModal("account"); }}>Editar</Btn>
              </div>
            </Card>
          ))}
        </div>
      ) : tab === "MOV" ? (
        <MovementsTab accounts={list} accountId={accountId} onAccount={setPicked} version={reload} toast={toast} />
      ) : (
        <ReconcileTab accounts={list} accountId={accountId} onAccount={setPicked} version={reload} onImport={() => setModal("import")} onChanged={changed} toast={toast} />
      )}

      {modal === "account" && (
        <AccountFormModal
          account={editing} custodians={accounts?.custodians ?? []} onClose={() => setModal(null)}
          onSaved={() => { setModal(null); toast(editing ? "Cuenta actualizada" : "Cuenta creada"); changed(); }}
        />
      )}
      {modal === "transfer" && <TransferModal accounts={list} onClose={() => setModal(null)} onDone={() => { setModal(null); toast("Transferencia registrada"); changed(); }} />}
      {modal === "import" && (
        <StatementImport
          accounts={list} accountId={accountId} onClose={() => setModal(null)}
          // La ventana sigue abierta con su resumen; detrás, la conciliación de esa cuenta ya está al día.
          onImported={(id) => {
            go("REC", id);
            changed();
          }}
        />
      )}
      {toasts}
    </Screen>
  );
}
