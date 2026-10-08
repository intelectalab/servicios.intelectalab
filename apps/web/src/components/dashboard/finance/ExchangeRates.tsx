"use client";

import { useEffect, useState, type FormEvent } from "react";
import { Coins, FileText, Plus, Receipt, TrendingUp } from "lucide-react";
import { ApiError, apiFetch } from "@/lib/api";
import { isoDateFromNow, shortDate } from "@/lib/commercial";
import { exportCsv, exportXlsx, fetchAllPages, type ExportColumn } from "@/lib/export-table";
import {
  Btn, Card, Chip, DataTable, ExportButtons, Field, INPUT, Kpi, Kpis, LoadError, Modal, PAGE_SIZES, PageHead, Pager, Screen,
  SelectionBar, useRowSelection, useToasts, type Column,
  applySort, type SortState,
} from "@/components/ui/proto";
import type { PaginatedResponse } from "@/types/customer";
import type { ExchangeRate, ExchangeRateSummary } from "@/types/commercial";

const rate3 = (value: string | number) => Number(value).toFixed(3);

const COLUMNS: Column<ExchangeRate>[] = [
  { label: "Fecha", sortKey: "date", render: (rate) => shortDate(rate.rate_date) },
  { label: "Compra", sortKey: "buy", num: true, render: (rate) => rate3(rate.buy) },
  { label: "Venta", sortKey: "sell", num: true, render: (rate) => rate3(rate.sell) },
  { label: "Fuente", sortKey: "source", render: (rate) => <Chip tone={rate.source === "Manual" ? "warning" : "neutral"}>{rate.source}</Chip> },
];

const EXPORT_COLUMNS: ExportColumn<ExchangeRate>[] = [
  { label: "Fecha", value: (rate) => shortDate(rate.rate_date), width: 16, kind: "center" },
  { label: "Moneda", value: (rate) => rate.currency, width: 10, kind: "center" },
  { label: "Compra", value: (rate) => rate3(rate.buy), width: 12, kind: "center" },
  { label: "Venta", value: (rate) => rate3(rate.sell), width: 12, kind: "center" },
  { label: "Fuente", value: (rate) => rate.source, width: 16 },
];

const rateKey = (rate: ExchangeRate) => rate.id;

function RateModal({ onClose, onSaved }: { onClose: () => void; onSaved: () => void }) {
  const today = isoDateFromNow(0);
  const [date, setDate] = useState(today);
  const [buy, setBuy] = useState("");
  const [sell, setSell] = useState("");
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (!(Number(buy) > 0) || !(Number(sell) > 0)) {
      setError("Compra y venta deben ser mayores que cero.");
      return;
    }

    setSaving(true);
    setError("");
    try {
      await apiFetch("/v1/exchange-rates", {
        method: "POST",
        body: JSON.stringify({ date, buy: Number(buy), sell: Number(sell) }),
      });
      onSaved();
    } catch (err) {
      setError(
        err instanceof ApiError
          ? (err.errors && Object.values(err.errors)[0]?.[0]) || err.message
          : "No se pudo registrar el tipo de cambio.",
      );
      setSaving(false);
    }
  }

  return (
    <Modal title="Registrar tipo de cambio (USD)" size="sm" onClose={onClose}>
      <form onSubmit={submit}>
        <div className="grid gap-3">
          <Field label="Fecha" required>
            <input className={INPUT} type="date" max={today} value={date} onChange={(e) => setDate(e.target.value)} required />
          </Field>
          <Field label="Compra" required>
            <input className={INPUT} type="number" min="0" step="0.001" value={buy} onChange={(e) => setBuy(e.target.value)} required autoFocus />
          </Field>
          <Field label="Venta" required>
            <input className={INPUT} type="number" min="0" step="0.001" value={sell} onChange={(e) => setSell(e.target.value)} required />
          </Field>
        </div>
        {error && <p className="mt-2 text-[12.5px] text-[color:var(--p-danger-text)]">{error}</p>}
        <div className="mt-4 flex justify-end gap-2">
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn type="submit" disabled={saving}>{saving ? "Guardando…" : "Guardar"}</Btn>
        </div>
      </form>
    </Modal>
  );
}

export default function ExchangeRates() {
  const { toast, toasts } = useToasts();

  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [reload, setReload] = useState(0);
  const [sort, setSort] = useState<SortState>(null);
  const [modal, setModal] = useState(false);
  const [exporting, setExporting] = useState(false);
  const { selection, selectedRows, clear: clearSelection } = useRowSelection(rateKey);

  const [data, setData] = useState<PaginatedResponse<ExchangeRate> | null>(null);
  const [summary, setSummary] = useState<ExchangeRateSummary | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    let cancelled = false;
    apiFetch<ExchangeRateSummary>("/v1/exchange-rates/summary")
      .then((response) => { if (!cancelled) setSummary(response); })
      .catch(() => { /* los indicadores quedan en blanco; el historial avisa si la API falla */ });
    return () => { cancelled = true; };
  }, [reload]);

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setLoading(true);
      try {
        const params = applySort(new URLSearchParams({ per_page: String(perPage), page: String(page) }), sort);
        const response = await apiFetch<PaginatedResponse<ExchangeRate>>(`/v1/exchange-rates?${params}`);
        if (!cancelled) {
          setData(response);
          setError("");
        }
      } catch {
        if (!cancelled) setError("No se pudo cargar el historial de tipo de cambio.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [page, perPage, sort, reload]);

  async function runExport(format: "xlsx" | "csv", picked?: ExchangeRate[]) {
    setExporting(true);
    try {
      const rows = picked ?? (await fetchAllPages<ExchangeRate>("/v1/exchange-rates", applySort(new URLSearchParams(), sort)));
      if (format === "xlsx") await exportXlsx("tipo-de-cambio", "Tipo de cambio (USD)", EXPORT_COLUMNS, rows);
      else exportCsv("tipo-de-cambio", EXPORT_COLUMNS, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el historial.", "danger");
    } finally {
      setExporting(false);
    }
  }

  const current = summary?.current ?? null;
  const shown = (value: number) => (summary ? value : "—");

  return (
    <Screen>
      <PageHead
        title="Monedas y tipo de cambio"
        actions={
          <Btn variant="secondary" onClick={() => setModal(true)} permission="billing.manage">
            <Plus size={16} />
            <span>Registrar tipo de cambio</span>
          </Btn>
        }
      />

      <Kpis>
        <Kpi
          label="USD venta (hoy)"
          value={current ? rate3(current.sell) : "—"}
          sub={current && `compra ${rate3(current.buy)} · ${current.source}`}
          icon={<Coins size={24} />}
          tone="success"
        />
        <Kpi
          label="Variación 30 días"
          value={summary?.variation_30 != null ? `${summary.variation_30.toFixed(2)} %` : "—"}
          icon={<TrendingUp size={24} />}
        />
        <Kpi label="Cotizaciones en USD" value={shown(summary?.quotations_usd ?? 0)} icon={<FileText size={24} />} />
        <Kpi label="Comprobantes en USD" value={shown(summary?.invoices_usd ?? 0)} icon={<Receipt size={24} />} tone="warning" />
      </Kpis>

      <Card title="Historial (USD)" flush>
        {error ? (
          <LoadError text={error} onRetry={() => setReload((n) => n + 1)} />
        ) : (
          <div className="pt-3">
            <SelectionBar
              count={selectedRows.length}
              busy={exporting}
              onExport={(format) => runExport(format, selectedRows)}
              onClear={clearSelection}
            />
            <DataTable
              tableId="exchange-rates" sort={sort} onSort={(next) => { setSort(next); setPage(1); }} refreshing={loading}
              cols={COLUMNS}
              rows={data?.data ?? []}
              rowKey={rateKey}
              selection={selection}
              loading={loading && !data}
              dense
              empty="Aún no hay tipos de cambio registrados."
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
          </div>
        )}
      </Card>

      {modal && (
        <RateModal
          onClose={() => setModal(false)}
          onSaved={() => {
            setModal(false);
            setPage(1);
            setReload((n) => n + 1);
            toast("Tipo de cambio registrado");
          }}
        />
      )}
      {toasts}
    </Screen>
  );
}
