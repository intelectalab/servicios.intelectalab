"use client";

import { useEffect, useMemo, useState } from "react";
import { apiFetch, apiFetchLater } from "@/lib/api";
import { money, shortDate } from "@/lib/commercial";
import { amountOrBlank, monthName, periodChoices } from "@/lib/accounting";
import { exportCsv, exportXlsx, fetchAllPages, recordExport, type ExportColumn } from "@/lib/export-table";
import {
  Btn, Card, DataTable, ExportButtons, Filters, INPUT, LoadError, PAGE_SIZES, PageHead, Pager, Screen, useToasts, type Column,
} from "@/components/ui/proto";
import { PickSelect } from "@/components/ui/pick-select";
import { JournalEntryModal } from "@/components/dashboard/accounting/Journal";
import type { PaginatedResponse } from "@/types/customer";
import type { JournalOptions, LedgerLine, TrialBalance, TrialRow } from "@/types/accounting";

type LedgerPage = PaginatedResponse<LedgerLine> & { account: { code: string; name: string } };

const TRIAL_EXPORT: ExportColumn<TrialRow>[] = [
  { label: "Cuenta", value: (row) => row.code, width: 12, kind: "center" },
  { label: "Nombre", value: (row) => row.name, width: 46 },
  { label: "Tipo", value: (row) => row.type_label, width: 13 },
  { label: "Debe", value: (row) => row.debit, width: 15, kind: "money" },
  { label: "Haber", value: (row) => row.credit, width: 15, kind: "money" },
  { label: "Saldo deudor", value: (row) => row.debtor || "", width: 15, kind: "number" },
  { label: "Saldo acreedor", value: (row) => row.creditor || "", width: 15, kind: "number" },
];

const LEDGER_EXPORT: ExportColumn<LedgerLine>[] = [
  { label: "Fecha", value: (line) => shortDate(line.entry_date), width: 14, kind: "center" },
  { label: "Asiento", value: (line) => line.entry_number, width: 18, kind: "center" },
  { label: "Glosa", value: (line) => line.description, width: 48 },
  { label: "Debe", value: (line) => line.debit || "", width: 15, kind: "number" },
  { label: "Haber", value: (line) => line.credit || "", width: 15, kind: "number" },
  { label: "Saldo", value: (line) => line.running, width: 15, kind: "money" },
];

const TH = "app-muted whitespace-nowrap border-b border-[color:var(--app-border)] px-3.5 pb-3 pt-3.5 text-left text-[10.5px] font-semibold uppercase tracking-[.06em]";
const TD = "border-b border-[color:var(--app-border)] px-3.5 py-[11px] align-middle";
const NUM = "text-right tabular-nums whitespace-nowrap";

/** Desplegable de la barra de filtros (el propio del sistema, no el del navegador). */
function RangePick({
  label, value, onChange, options, wide = false, searchable,
}: { label: string; value: string; onChange: (value: string) => void; options: [string, string][]; wide?: boolean; searchable?: boolean }) {
  return (
    <div className={`flex-[1_1_140px] sm:flex-none ${wide ? "sm:w-[280px]" : "sm:w-[230px]"}`}>
      <PickSelect className={`${INPUT} h-10`} ariaLabel={label} searchable={searchable ?? options.length > 10} value={value} onChange={onChange} options={options} />
    </div>
  );
}

export default function Ledger() {
  const { toast, toasts } = useToasts();
  const [options, setOptions] = useState<JournalOptions | null>(null);
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  // Rango con que abrió la pantalla (el que elige la API): fija los extremos de la lista de meses.
  const [bounds, setBounds] = useState<[string, string] | null>(null);
  const [center, setCenter] = useState("");
  const [balance, setBalance] = useState<TrialBalance | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [exporting, setExporting] = useState(false);

  const [account, setAccount] = useState("");
  const [ledger, setLedger] = useState<LedgerPage | null>(null);
  const [ledgerLoading, setLedgerLoading] = useState(false);
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[1]);
  const [openId, setOpenId] = useState<string | null>(null);

  const range = useMemo(() => {
    const params = new URLSearchParams();
    if (from) params.set("from", from);
    if (to) params.set("to", to);
    if (center) params.set("cost_center_id", center);
    return params;
  }, [from, to, center]);

  useEffect(() => {
    let cancelled = false;
    async function load() {
      setLoading(true);
      try {
        const next = await apiFetch<TrialBalance>(`/v1/ledger/trial-balance?${range}`);
        if (cancelled) return;
        setBalance(next);
        setError("");
        // La primera vez, el rango que eligió la API (desde el primer periodo hasta hoy).
        setFrom((current) => current || next.from);
        setTo((current) => current || next.to);
        setBounds((current) => current ?? [next.from, next.to]);
      } catch {
        if (!cancelled) setError("No se pudo cargar el balance de comprobación.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [range, reload]);

  useEffect(() => {
    let cancelled = false;
    apiFetchLater<JournalOptions>("/v1/journal-entries/options")
      .then((next) => { if (!cancelled) setOptions(next); })
      .catch(() => undefined);
    return () => { cancelled = true; };
  }, []);

  useEffect(() => {
    if (!account) return;
    let cancelled = false;
    async function load() {
      setLedgerLoading(true);
      const params = new URLSearchParams(range);
      params.set("page", String(page));
      params.set("per_page", String(perPage));
      try {
        const next = await apiFetch<LedgerPage>(`/v1/ledger/accounts/${account}?${params}`);
        if (!cancelled) setLedger(next);
      } catch {
        if (!cancelled) toast("No se pudo cargar el mayor de la cuenta.", "danger");
      } finally {
        if (!cancelled) setLedgerLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [account, range, page, perPage, toast]);

  // Meses elegibles: todos, sin saltos, desde el primer mes con asientos hasta el mes en curso.
  const periods = useMemo(() => periodChoices([...(options?.periods ?? []), ...(bounds ?? [])]), [options, bounds]);

  async function exportBalance(format: "xlsx" | "csv") {
    if (!balance) return;
    setExporting(true);
    try {
      await recordExport("accounting", format, balance.rows.length);
      const totals: TrialRow = { code: "", name: "Totales", type: "ASSET", type_label: "", ...balance.totals };
      const rows = [...balance.rows, totals];
      if (format === "xlsx") await exportXlsx("balance-de-comprobacion", `Balance de comprobación ${balance.from} a ${balance.to}`, TRIAL_EXPORT, rows);
      else exportCsv("balance-de-comprobacion", TRIAL_EXPORT, rows);
    } catch {
      toast("No se pudo exportar el balance.", "danger");
    } finally {
      setExporting(false);
    }
  }

  async function exportLedger(format: "xlsx" | "csv") {
    if (!account) return;
    setExporting(true);
    try {
      const rows = await fetchAllPages<LedgerLine>(`/v1/ledger/accounts/${account}`, new URLSearchParams(range));
      await recordExport("accounting", format, rows.length);
      const name = `mayor-${account}`;
      if (format === "xlsx") await exportXlsx(name, `Mayor de la cuenta ${account}`, LEDGER_EXPORT, rows);
      else exportCsv(name, LEDGER_EXPORT, rows);
    } catch {
      toast("No se pudo exportar el mayor.", "danger");
    } finally {
      setExporting(false);
    }
  }

  // El mayor que se muestra es el de la cuenta elegida (al cambiarla, hasta que llegue el nuevo).
  const shownLedger = ledger && ledger.account.code === account ? ledger : null;

  const ledgerColumns: Column<LedgerLine>[] = [
    { label: "Fecha", render: (line) => <span className="whitespace-nowrap">{shortDate(line.entry_date)}</span> },
    {
      label: "Asiento",
      render: (line) => (
        <button
          type="button" className="cursor-pointer whitespace-nowrap font-medium tabular-nums text-[color:var(--p-brand-text)] hover:underline"
          onClick={(event) => { event.stopPropagation(); setOpenId(line.entry_id); }}
        >
          {line.entry_number}
        </button>
      ),
    },
    { label: "Glosa", render: (line) => <span className="block min-w-[180px] whitespace-normal">{line.description}</span> },
    { label: "Debe", num: true, render: (line) => amountOrBlank(line.debit) },
    { label: "Haber", num: true, render: (line) => amountOrBlank(line.credit) },
    { label: "Saldo", num: true, render: (line) => money(line.running) },
  ];

  const pick = (code: string) => {
    setAccount(code);
    setPage(1);
  };

  return (
    <Screen>
      <PageHead title="Mayor y balance de comprobación" />

      <Filters>
        {/* "Desde" no pasa de "Hasta" ni "Hasta" queda antes de "Desde". */}
        <RangePick label="Desde" value={from} onChange={(value) => { setFrom(value); setPage(1); }} options={periods.filter((p) => !to || p <= to).map((p): [string, string] => [p, `Desde ${monthName(p)}`])} />
        <RangePick label="Hasta" value={to} onChange={(value) => { setTo(value); setPage(1); }} options={[...periods].reverse().filter((p) => !from || p >= from).map((p): [string, string] => [p, `Hasta ${monthName(p)}`])} />
        <RangePick
          label="Centro de costo" wide searchable value={center} onChange={(value) => { setCenter(value); setPage(1); }}
          options={[["", "Todos los centros de costo"], ...(options?.cost_centers ?? []).map((c): [string, string] => [c.id, `${c.code} · ${c.name}`])]}
        />
      </Filters>

      {account && (
        <Card
          title={`Mayor de la cuenta ${account} · ${shownLedger?.account.name ?? ""}`}
          actions={<Btn variant="ghost" size="sm" onClick={() => setAccount("")}>Cerrar mayor</Btn>}
          flush
        >
          <DataTable cols={ledgerColumns} rows={shownLedger?.data ?? []} rowKey={(line) => line.id} loading={!shownLedger} dense empty="La cuenta no tiene movimientos en el rango." onRowClick={(line) => setOpenId(line.entry_id)} />
          {shownLedger && shownLedger.total > 0 ? (
            <Pager
              loading={ledgerLoading} page={shownLedger.current_page} lastPage={shownLedger.last_page} total={shownLedger.total} perPage={shownLedger.per_page}
              onPage={setPage} onPerPage={(size) => { setPerPage(size); setPage(1); }}
              left={<ExportButtons permission="accounting.export" total={shownLedger.total} busy={exporting} onExport={exportLedger} />}
            />
          ) : null}
        </Card>
      )}

      {/* Como el balance no se pagina, sus botones de exportar van arriba (excepción pedida para esta tabla). */}
      <Card
        title="Balance de comprobación"
        flush
        actions={balance && !error ? <ExportButtons permission="accounting.export" total={balance.rows.length} busy={exporting} onExport={exportBalance} /> : undefined}
      >
        {error ? <LoadError text={error} onRetry={() => setReload((n) => n + 1)} /> : !balance ? (
          <div className="m-5 h-32 animate-pulse rounded bg-[var(--app-surface-muted)]" aria-busy="true" />
        ) : (
          <>
            <div className={`overflow-x-auto px-1 ${loading ? "opacity-60" : ""}`}>
              <table className="proto-tbl w-full text-[13px]">
                <thead>
                  <tr>
                    <th className={TH}>Cuenta</th>
                    <th className={TH}>Tipo</th>
                    <th className={`${TH} text-right`}>Debe</th>
                    <th className={`${TH} text-right`}>Haber</th>
                    <th className={`${TH} text-right`}>Saldo deudor</th>
                    <th className={`${TH} text-right`}>Saldo acreedor</th>
                  </tr>
                </thead>
                <tbody>
                  {balance.rows.length === 0 && (
                    <tr><td colSpan={6} className={`${TD} app-muted text-center`}>No hay movimientos en el rango.</td></tr>
                  )}
                  {balance.rows.map((row) => (
                    <tr
                      key={row.code} tabIndex={0} role="button" aria-label={`Ver el mayor de la cuenta ${row.code}`}
                      onClick={() => pick(row.code)} onKeyDown={(event) => { if (event.key === "Enter") pick(row.code); }}
                      className={`cursor-pointer hover:bg-[var(--app-surface-muted)] ${row.code === account ? "bg-[var(--p-brand-soft)]" : ""}`}
                    >
                      <td className={TD} data-label="Cuenta"><strong className="font-semibold tabular-nums">{row.code}</strong> {row.name}</td>
                      <td className={TD} data-label="Tipo">{row.type_label}</td>
                      <td className={`${TD} ${NUM}`} data-label="Debe">{money(row.debit)}</td>
                      <td className={`${TD} ${NUM}`} data-label="Haber">{money(row.credit)}</td>
                      <td className={`${TD} ${NUM}`} data-label="Deudor">{amountOrBlank(row.debtor)}</td>
                      <td className={`${TD} ${NUM}`} data-label="Acreedor">{amountOrBlank(row.creditor)}</td>
                    </tr>
                  ))}
                  <tr>
                    <td className={TD} colSpan={2}><strong className="font-semibold">Totales</strong></td>
                    <td className={`${TD} ${NUM}`} data-label="Debe"><strong className="font-semibold">{money(balance.totals.debit)}</strong></td>
                    <td className={`${TD} ${NUM}`} data-label="Haber"><strong className="font-semibold">{money(balance.totals.credit)}</strong></td>
                    <td className={`${TD} ${NUM}`} data-label="Deudor"><strong className="font-semibold">{money(balance.totals.debtor)}</strong></td>
                    <td className={`${TD} ${NUM}`} data-label="Acreedor"><strong className="font-semibold">{money(balance.totals.creditor)}</strong></td>
                  </tr>
                </tbody>
              </table>
            </div>
            {balance.cost_center_id ? (
              // Con un centro de costo solo entran las líneas imputadas a él: sus sumas no tienen por qué cuadrar.
              <p className="app-muted px-5 pt-3 text-[12.5px]">Filtrado por centro de costo: solo las líneas imputadas a ese centro, por eso el debe y el haber no se comparan.</p>
            ) : (
              <p className={`px-5 pt-3 text-[12.5px] ${balance.balanced ? "text-[color:var(--p-success-text)]" : "text-[color:var(--p-danger-text)]"}`}>
                {balance.balanced ? "El balance cuadra: sumas del debe y del haber iguales." : "Diferencia entre debe y haber: revisar."}
              </p>
            )}
            <div className="pb-4" />
          </>
        )}
      </Card>

      {openId && <JournalEntryModal key={openId} id={openId} toast={toast} onClose={() => setOpenId(null)} onChanged={() => setReload((n) => n + 1)} onOpen={setOpenId} />}
      {toasts}
    </Screen>
  );
}
