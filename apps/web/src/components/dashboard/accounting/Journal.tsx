"use client";

import { useEffect, useMemo, useState } from "react";
import { BookOpen, CalendarDays, CheckCheck, Plus, Trash2, Zap } from "lucide-react";
import { ApiError, apiFetch, apiFetchLater } from "@/lib/api";
import { excelTone, money, shortDate, stateOf } from "@/lib/commercial";
import { JOURNAL_STATES, amountOrBlank } from "@/lib/accounting";
import { firstError } from "@/lib/crm";
import { exportCsv, exportXlsx, fetchAllPages, recordExport, type ExportColumn } from "@/lib/export-table";
import {
  Badge, Btn, Card, Chip, DataTable, ExportButtons, Field, FilterSelect, Filters, INPUT, Kpi, Kpis, Kv, LoadError, Modal, Notice, PAGE_SIZES, PageHead,
  Pager, Screen, SearchInput, SelectionBar, applySort, useRowSelection, useToasts, type Column, type SortState,
} from "@/components/ui/proto";
import { PickSelect } from "@/components/ui/pick-select";
import { ReasonModal } from "@/components/dashboard/crm/CrmModals";
import { ColumnFilterBar, addColumnFilter, applyFilters, useServerColumnFilters } from "@/components/dashboard/ColumnFilters";
import type { PaginatedResponse } from "@/types/customer";
import type { JournalEntry, JournalEntryDetail, JournalLine, JournalOptions, JournalSummary } from "@/types/accounting";

type Toast = ReturnType<typeof useToasts>["toast"];

const entryKey = (entry: JournalEntry) => entry.id;

const EXPORT_COLUMNS: ExportColumn<JournalEntry>[] = [
  { label: "Asiento", value: (entry) => entry.entry_number, width: 18, kind: "center" },
  { label: "Fecha", value: (entry) => shortDate(entry.entry_date), width: 14, kind: "center" },
  { label: "Periodo", value: (entry) => entry.period, width: 11, kind: "center" },
  { label: "Glosa", value: (entry) => entry.description, width: 48 },
  { label: "Origen", value: (entry) => entry.source_label, width: 18 },
  { label: "Documento", value: (entry) => entry.source_number ?? "", width: 20, kind: "center" },
  { label: "Cuentas", value: (entry) => entry.accounts ?? "", width: 40 },
  { label: "Importe", value: (entry) => entry.amount, width: 15, kind: "money" },
  {
    label: "Estado", value: (entry) => stateOf(JOURNAL_STATES, entry.status)[0], width: 16, kind: "status",
    tone: (entry) => excelTone(stateOf(JOURNAL_STATES, entry.status)[1]),
  },
];

const LINE_EXPORT: ExportColumn<JournalLine>[] = [
  { label: "Cuenta", value: (line) => line.account_code, width: 12, kind: "center" },
  { label: "Nombre de la cuenta", value: (line) => line.account_name, width: 44 },
  { label: "Nota", value: (line) => line.note ?? "", width: 30 },
  { label: "Centro de costo", value: (line) => line.cost_center ?? "", width: 26 },
  { label: "Tercero", value: (line) => line.party ?? "", width: 30 },
  { label: "Debe", value: (line) => line.debit || "", width: 14, kind: "number" },
  { label: "Haber", value: (line) => line.credit || "", width: 14, kind: "number" },
];

type TopFilters = { search: string; period: string; source: string };

function withTopFilters(params: URLSearchParams, top: TopFilters) {
  if (top.search) params.set("search", top.search);
  if (top.period) params.set("period", top.period);
  if (top.source) params.set("source", top.source);
  return params;
}

export default function Journal() {
  const { toast, toasts } = useToasts();
  const [exporting, setExporting] = useState(false);

  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [period, setPeriod] = useState("");
  const [source, setSource] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [reload, setReload] = useState(0);
  const [sort, setSort] = useState<SortState>(null);
  const columnFilters = useServerColumnFilters(() => setPage(1));
  const { selection, selectedRows, clear: clearSelection } = useRowSelection(entryKey);

  const [data, setData] = useState<PaginatedResponse<JournalEntry> | null>(null);
  const [summary, setSummary] = useState<JournalSummary | null>(null);
  const [options, setOptions] = useState<JournalOptions | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [creating, setCreating] = useState(false);
  const [openId, setOpenId] = useState<string | null>(null);

  // Como el prototipo: el enlace de un asiento (desde un comprobante) llega con ?q= y lo busca.
  useEffect(() => {
    async function fromLink() {
      const q = new URLSearchParams(window.location.search).get("q");
      if (q) {
        setSearchInput(q);
        setSearch(q);
      }
    }
    fromLink();
  }, []);

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
      const params = withTopFilters(new URLSearchParams({ per_page: String(perPage), page: String(page) }), { search, period, source });
      applySort(applyFilters(params, columnFilters.param), sort);
      try {
        const response = await apiFetch<PaginatedResponse<JournalEntry>>(`/v1/journal-entries?${params}`);
        if (!cancelled) {
          setData(response);
          setError("");
        }
      } catch {
        if (!cancelled) setError("No se pudo cargar el libro diario.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [page, perPage, search, period, source, columnFilters.param, sort, reload]);

  // Indicadores: después del listado (el del periodo cambia con el filtro).
  useEffect(() => {
    let cancelled = false;
    apiFetchLater<JournalSummary>(`/v1/journal-entries/summary${period ? `?period=${period}` : ""}`)
      .then((next) => { if (!cancelled) setSummary(next); })
      .catch(() => undefined);
    return () => { cancelled = true; };
  }, [period, reload]);

  useEffect(() => {
    let cancelled = false;
    apiFetchLater<JournalOptions>("/v1/journal-entries/options")
      .then((next) => { if (!cancelled) setOptions(next); })
      .catch(() => undefined);
    return () => { cancelled = true; };
  }, [reload]);

  const columns: Column<JournalEntry>[] = [
    {
      label: "Asiento", sortKey: "number", filter: "text",
      render: (entry) => (
        <div>
          <strong className="whitespace-nowrap font-semibold tabular-nums">{entry.entry_number}</strong>
          <small className="app-muted block text-xs">{shortDate(entry.entry_date)}</small>
        </div>
      ),
    },
    {
      label: "Glosa", sortKey: "description", filter: "text",
      render: (entry) => (
        <div className="min-w-[200px] whitespace-normal">
          {entry.description}
          <small className="mt-0.5 block"><Chip>{entry.source_label}</Chip></small>
        </div>
      ),
    },
    { label: "Cuentas", sortKey: "accounts", filter: "text", render: (entry) => <small className="block min-w-[140px] whitespace-normal text-xs tabular-nums">{entry.accounts}</small> },
    { label: "Importe", num: true, sortKey: "amount", filter: "number", render: (entry) => money(entry.amount) },
    {
      label: "Estado", sortKey: "status",
      render: (entry) => <Badge tone={stateOf(JOURNAL_STATES, entry.status)[1]}>{stateOf(JOURNAL_STATES, entry.status)[0]}</Badge>,
    },
  ];

  async function runExport(format: "xlsx" | "csv", picked?: JournalEntry[]) {
    setExporting(true);
    try {
      const rows = picked ?? (await fetchAllPages<JournalEntry>("/v1/journal-entries", applySort(columnFilters.apply(withTopFilters(new URLSearchParams(), { search, period, source })), sort)));
      // La API comprueba el permiso de exportar y deja la exportación en auditoría.
      await recordExport("accounting", format, rows.length, Boolean(picked));
      if (format === "xlsx") await exportXlsx("libro-diario", "Libro diario", EXPORT_COLUMNS, rows);
      else exportCsv("libro-diario", EXPORT_COLUMNS, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el listado.", "danger");
    } finally {
      setExporting(false);
    }
  }

  const shownPeriod = summary?.period ?? (period || "—");

  return (
    <Screen>
      <PageHead
        title="Libro diario"
        actions={
          <Btn onClick={() => setCreating(true)} permission="accounting.create">
            <Plus size={16} />
            <span>Asiento manual</span>
          </Btn>
        }
      />

      <Kpis>
        <Kpi label="Asientos" value={summary ? summary.total : "—"} icon={<BookOpen size={22} />} />
        <Kpi label={`Periodo ${shownPeriod}`} value={summary ? summary.in_period : "—"} sub="asientos" icon={<CalendarDays size={22} />} />
        <Kpi label="Automáticos" value={summary ? summary.automatic : "—"} icon={<Zap size={22} />} />
        <Kpi
          label="Debe = Haber" value={summary ? (summary.balanced ? "Cuadra" : "Revisar") : "—"} icon={<CheckCheck size={22} />}
          tone={summary && !summary.balanced ? "danger" : "success"}
        />
      </Kpis>

      <Filters>
        <SearchInput value={searchInput} onChange={setSearchInput} placeholder="Buscar número, glosa o cuenta" />
        <FilterSelect label="Periodo" value={period} onChange={(value) => { setPeriod(value); setPage(1); }} options={(options?.periods ?? []).map((p): [string, string] => [p, p])} />
        <FilterSelect label="Origen" value={source} onChange={(value) => { setSource(value); setPage(1); }} options={(options?.sources ?? []).map((s): [string, string] => [s.value, s.label])} />
        <ColumnFilterBar
          filters={columnFilters}
          hasOtherFilters={Boolean(searchInput || period || source)}
          onClearAll={() => {
            setSearchInput("");
            setSearch("");
            setPeriod("");
            setSource("");
            setPage(1);
          }}
        />
      </Filters>

      {error ? (
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
      ) : (
        <Card flush>
          <SelectionBar permission="accounting.export" count={selectedRows.length} busy={exporting} onExport={(format) => runExport(format, selectedRows)} onClear={clearSelection} />
          <DataTable
            tableId="journal-entries" sort={sort} onSort={(next) => { setSort(next); setPage(1); }} refreshing={loading}
            onFilter={(column) => addColumnFilter(columnFilters, column)}
            cols={columns}
            rows={data?.data ?? []}
            rowKey={entryKey}
            selection={selection}
            onRowClick={(entry) => setOpenId(entry.id)}
            loading={loading && !data}
            empty="No hay asientos con estos filtros."
          />
          {data && (
            <Pager
              loading={loading} page={data.current_page} lastPage={data.last_page} total={data.total} perPage={data.per_page}
              onPage={setPage} onPerPage={(size) => { setPerPage(size); setPage(1); }}
              left={<ExportButtons permission="accounting.export" total={data.total} busy={exporting} onExport={(format) => runExport(format)} />}
            />
          )}
        </Card>
      )}

      {creating && (
        <ManualEntryModal
          options={options}
          onClose={() => setCreating(false)}
          onSaved={(entry) => {
            setCreating(false);
            toast("Asiento contabilizado", "success", entry.entry_number);
            setReload((n) => n + 1);
            setOpenId(entry.id);
          }}
        />
      )}
      {openId && <JournalEntryModal key={openId} id={openId} toast={toast} onClose={() => setOpenId(null)} onChanged={() => setReload((n) => n + 1)} onOpen={setOpenId} />}
      {toasts}
    </Screen>
  );
}

/** Ficha del asiento: sus datos, sus líneas con los totales y el extorno. */
export function JournalEntryModal({
  id, toast, onClose, onChanged, onOpen,
}: { id: string; toast: Toast; onClose: () => void; onChanged?: () => void; onOpen?: (id: string) => void }) {
  const [entry, setEntry] = useState<JournalEntryDetail | null>(null);
  const [error, setError] = useState("");
  const [reversing, setReversing] = useState(false);
  const [busy, setBusy] = useState(false);
  const [exporting, setExporting] = useState(false);

  useEffect(() => {
    let cancelled = false;
    apiFetch<JournalEntryDetail>(`/v1/journal-entries/${id}`)
      .then((next) => { if (!cancelled) { setEntry(next); setError(""); } })
      .catch((err) => { if (!cancelled) setError(err instanceof ApiError && err.status === 404 ? "El asiento no existe." : "No se pudo cargar el asiento."); });
    return () => { cancelled = true; };
  }, [id]);

  async function reverse(reason: string) {
    setBusy(true);
    try {
      const next = await apiFetch<JournalEntryDetail>(`/v1/journal-entries/${id}/reverse`, { method: "POST", body: JSON.stringify({ reason }) });
      setEntry(next);
      setReversing(false);
      toast("Asiento extornado", "success", next.reversed_by?.number);
      onChanged?.();
    } catch (err) {
      toast(firstError(err, "No se pudo extornar el asiento."), "danger");
    } finally {
      setBusy(false);
    }
  }

  async function exportLines(format: "xlsx" | "csv") {
    if (!entry) return;
    setExporting(true);
    try {
      await recordExport("accounting", format, entry.lines.length);
      const name = `asiento-${entry.entry_number}`;
      if (format === "xlsx") await exportXlsx(name, `Asiento ${entry.entry_number}`, LINE_EXPORT, entry.lines);
      else exportCsv(name, LINE_EXPORT, entry.lines);
    } catch {
      toast("No se pudieron exportar las líneas.", "danger");
    } finally {
      setExporting(false);
    }
  }

  const debit = entry ? entry.lines.reduce((sum, line) => sum + line.debit, 0) : 0;
  const credit = entry ? entry.lines.reduce((sum, line) => sum + line.credit, 0) : 0;
  const jump = (target: { id: string; number: string }) => (
    onOpen ? (
      <button type="button" className="cursor-pointer font-medium text-[color:var(--p-brand-text)] hover:underline" onClick={() => onOpen(target.id)}>{target.number}</button>
    ) : <strong className="font-semibold">{target.number}</strong>
  );
  const TH = "app-muted border-b border-[color:var(--app-border)] px-2.5 py-2 text-left text-[10.5px] font-semibold uppercase tracking-[.06em]";
  const TD = "border-b border-[color:var(--app-border)] px-2.5 py-2 align-top";

  return (
    <>
      <Modal
        title={entry ? `Asiento ${entry.entry_number}` : "Asiento"}
        size="lg"
        onClose={onClose}
        footer={entry && entry.status === "POSTED" && entry.source_type !== "REVERSAL" ? (
          <Btn variant="danger-ghost" permission="accounting.approve" onClick={() => setReversing(true)}>Extornar asiento</Btn>
        ) : undefined}
      >
        {error ? <LoadError text={error} onRetry={onClose} /> : !entry ? (
          <div className="h-40 animate-pulse rounded bg-[var(--app-surface-muted)]" aria-busy="true" />
        ) : (
          <>
            <Kv
              rows={[
                ["Fecha", shortDate(entry.entry_date)],
                ["Periodo", entry.period],
                ["Glosa", entry.description],
                ["Origen", `${entry.source_label}${entry.source_number ? ` · ${entry.source_number}` : ""}`],
                ["Registrado por", entry.created_by ?? "Sistema (asiento automático)"],
                [
                  "Estado",
                  <span key="status" className="inline-flex flex-wrap items-center gap-1.5">
                    <Badge tone={stateOf(JOURNAL_STATES, entry.status)[1]}>{stateOf(JOURNAL_STATES, entry.status)[0]}</Badge>
                    {entry.reversed_by && <>por {jump(entry.reversed_by)}</>}
                  </span>,
                ],
                entry.reversal_of ? ["Extorno de", jump(entry.reversal_of)] : null,
                entry.reason ? ["Motivo del extorno", entry.reason] : null,
              ]}
            />
            <div className="mt-2 overflow-x-auto rounded-lg border border-[color:var(--app-border)]">
              <table className="proto-tbl w-full text-[13px]">
                <thead>
                  <tr>
                    <th className={TH}>Cuenta</th>
                    <th className={TH}>Centro de costo / tercero</th>
                    <th className={`${TH} text-right`}>Debe</th>
                    <th className={`${TH} text-right`}>Haber</th>
                  </tr>
                </thead>
                <tbody>
                  {entry.lines.map((line) => (
                    <tr key={line.id}>
                      <td className={TD} data-label="Cuenta">
                        <strong className="font-semibold tabular-nums">{line.account_code}</strong> <small className="text-xs">{line.account_name}</small>
                        {line.note && <small className="app-muted block text-xs">{line.note}</small>}
                      </td>
                      <td className={TD} data-label="CC / tercero"><small className="text-xs">{[line.cost_center, line.party].filter(Boolean).join(" · ")}</small></td>
                      <td className={`${TD} text-right tabular-nums`} data-label="Debe">{amountOrBlank(line.debit)}</td>
                      <td className={`${TD} text-right tabular-nums`} data-label="Haber">{amountOrBlank(line.credit)}</td>
                    </tr>
                  ))}
                  <tr>
                    <td className={TD} colSpan={2}><strong className="font-semibold">Totales</strong></td>
                    <td className={`${TD} text-right tabular-nums`} data-label="Debe"><strong className="font-semibold">{money(debit)}</strong></td>
                    <td className={`${TD} text-right tabular-nums`} data-label="Haber"><strong className="font-semibold">{money(credit)}</strong></td>
                  </tr>
                </tbody>
              </table>
              <div className="border-t border-[color:var(--app-border)] px-2.5 py-2">
                <ExportButtons permission="accounting.export" total={entry.lines.length} busy={exporting} onExport={exportLines} />
              </div>
            </div>
          </>
        )}
      </Modal>
      {reversing && entry && (
        <ReasonModal
          title="Extornar asiento"
          text="Se genera un asiento inverso en el periodo abierto. El original se conserva como EXTORNADO."
          label="Motivo"
          confirmLabel="Extornar"
          busy={busy}
          onClose={() => setReversing(false)}
          onConfirm={reverse}
        />
      )}
    </>
  );
}

type DraftLine = { account: string; debit: string; credit: string; center: string };

const EMPTY_LINE: DraftLine = { account: "", debit: "", credit: "", center: "" };

const today = () => {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}-${String(now.getDate()).padStart(2, "0")}`;
};

/** Asiento manual: fecha, glosa y sus líneas (cuatro de entrada, como el prototipo; se pueden agregar más). */
function ManualEntryModal({
  options, onClose, onSaved,
}: { options: JournalOptions | null; onClose: () => void; onSaved: (entry: JournalEntryDetail) => void }) {
  const [date, setDate] = useState(today());
  const [description, setDescription] = useState("");
  const [lines, setLines] = useState<DraftLine[]>([EMPTY_LINE, EMPTY_LINE, EMPTY_LINE, EMPTY_LINE]);
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  const accountOptions = useMemo(
    () => [["", "—"] as [string, string], ...(options?.accounts ?? []).map((account): [string, string] => [account.code, `${account.code} · ${account.name}`])],
    [options],
  );
  const centerOptions = useMemo(
    () => [["", "—"] as [string, string], ...(options?.cost_centers ?? []).map((center): [string, string] => [center.id, center.code])],
    [options],
  );
  const debit = lines.reduce((sum, line) => sum + (Number(line.debit) || 0), 0);
  const credit = lines.reduce((sum, line) => sum + (Number(line.credit) || 0), 0);
  const difference = Math.round((debit - credit) * 100) / 100;
  const update = (index: number, patch: Partial<DraftLine>) => setLines((current) => current.map((line, i) => (i === index ? { ...line, ...patch } : line)));

  async function save() {
    if (!description.trim()) {
      setError("Indica la glosa del asiento.");
      return;
    }
    setSaving(true);
    setError("");
    try {
      const entry = await apiFetch<JournalEntryDetail>("/v1/journal-entries", {
        method: "POST",
        body: JSON.stringify({
          date, description: description.trim(),
          lines: lines.map((line) => ({ account: line.account || null, debit: Number(line.debit) || 0, credit: Number(line.credit) || 0, cost_center_id: line.center || null })),
        }),
      });
      onSaved(entry);
    } catch (err) {
      setError(firstError(err, "No se pudo contabilizar el asiento."));
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      title="Asiento manual"
      size="lg"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn disabled={saving || !options} onClick={save}>{saving ? "Contabilizando…" : "Contabilizar"}</Btn>
        </>
      }
    >
      {error && <Notice tone="warn">{error}</Notice>}
      <div className="grid grid-cols-1 gap-x-3.5 gap-y-3 md:grid-cols-3">
        <Field label="Fecha" required>
          <input type="date" className={INPUT} value={date} onChange={(event) => setDate(event.target.value)} />
        </Field>
        <Field label="Glosa" required span={2}>
          <input className={INPUT} value={description} onChange={(event) => setDescription(event.target.value)} maxLength={1000} placeholder="Descripción del asiento" />
        </Field>
      </div>
      <div className="mt-3 grid gap-2.5">
        {lines.map((line, index) => (
          <div key={index} className="grid grid-cols-2 gap-x-3 gap-y-2 border-t border-[color:var(--app-border)] pt-2.5 md:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1.3fr)_auto] md:items-end">
            <div className="col-span-2 md:col-span-1">
              <span className="mb-[5px] block text-[12.5px] font-medium">Cuenta {index + 1}</span>
              <PickSelect className={INPUT} ariaLabel={`Cuenta ${index + 1}`} searchable value={line.account} onChange={(value) => update(index, { account: value })} options={accountOptions} />
            </div>
            <Field label="Debe">
              <input type="number" min="0" step="0.01" className={INPUT} value={line.debit} onChange={(event) => update(index, { debit: event.target.value, credit: event.target.value ? "" : line.credit })} />
            </Field>
            <Field label="Haber">
              <input type="number" min="0" step="0.01" className={INPUT} value={line.credit} onChange={(event) => update(index, { credit: event.target.value, debit: event.target.value ? "" : line.debit })} />
            </Field>
            <div className="col-span-2 md:col-span-1">
              <span className="mb-[5px] block text-[12.5px] font-medium">Centro de costo</span>
              <PickSelect className={INPUT} ariaLabel={`Centro de costo ${index + 1}`} searchable={centerOptions.length > 8} value={line.center} onChange={(value) => update(index, { center: value })} options={centerOptions} />
            </div>
            <div className="col-span-2 flex justify-end md:col-span-1">
              <Btn variant="ghost" size="sm" title="Quitar línea" aria-label={`Quitar línea ${index + 1}`} disabled={lines.length <= 2} onClick={() => setLines((current) => current.filter((_, i) => i !== index))}>
                <Trash2 size={14} />
              </Btn>
            </div>
          </div>
        ))}
      </div>
      <div className="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-[color:var(--app-border)] pt-3">
        <Btn variant="secondary" size="sm" disabled={lines.length >= 40} onClick={() => setLines((current) => [...current, EMPTY_LINE])}>
          <Plus size={14} />
          <span>Agregar línea</span>
        </Btn>
        <span className="text-[13px] tabular-nums">
          Debe <strong className="font-semibold">{money(debit)}</strong> · Haber <strong className="font-semibold">{money(credit)}</strong>
          {difference !== 0 && <> · <span className="text-[color:var(--p-danger-text)]">diferencia {money(Math.abs(difference))}</span></>}
        </span>
      </div>
      <p className="app-muted mt-2 text-[12.5px]">El asiento debe cuadrar (debe = haber) y solo usa cuentas imputables. Queda auditado.</p>
    </Modal>
  );
}
