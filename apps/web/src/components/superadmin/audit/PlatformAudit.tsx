"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { apiFetch } from "@/lib/api";
import { dateTimeText } from "@/lib/portal";
import { exportCsv, exportXlsx, type ExportColumn } from "@/lib/export-table";
import { actionLabel, recordPlatformExport } from "@/lib/platform";
import {
  Card, DataTable, ExportButtons, Field, Filters, FilterSelect, INPUT, Kv, LoadError, Modal, PageHead, Pager, Screen, SearchInput,
  SelectionBar, useRowSelection, useToasts, type Column, type SortState,
} from "@/components/ui/proto";
import type { PlatformAuditEntry } from "@/types/platform";

type Page = { data: PlatformAuditEntry[]; total: number; last_page: number; current_page: number };
type Options = { actions: string[]; users: { id: string; name: string | null; email: string | null }[] };

const ENTITIES: Record<string, string> = { Tenant: "Empresa", SaasPlan: "Plan", User: "Usuario", Dashboard: "Consola" };
const entityLabel = (type: string | null) => (type ? ENTITIES[type] ?? type : "—");

const COLS: Column<PlatformAuditEntry>[] = [
  { label: "Fecha", sortKey: "created_at", render: (a) => <span className="whitespace-nowrap tabular-nums">{dateTimeText(a.created_at)}</span> },
  { label: "Actor", sortKey: "user_name", render: (a) => <span><span className="block">{a.user_name ?? "—"}</span><small className="app-muted [overflow-wrap:anywhere]">{a.user_email}</small></span> },
  { label: "Acción", sortKey: "action", render: (a) => <span><span className="block">{actionLabel(a.action)}</span><code className="app-muted text-[11px]">{a.action}</code></span> },
  { label: "Entidad", sortKey: "entity_type", render: (a) => <span>{entityLabel(a.entity_type)}{a.entity_id && <small className="app-muted block font-mono">{a.entity_id.slice(0, 8)}</small>}</span> },
  { label: "Detalle", render: (a) => <code className="app-muted block max-w-[320px] truncate text-[11.5px]">{JSON.stringify(a.after_data ?? a.metadata ?? {})}</code> },
];

const EXPORT: ExportColumn<PlatformAuditEntry>[] = [
  { label: "Fecha", value: (a) => dateTimeText(a.created_at), width: 20 },
  { label: "Actor", value: (a) => a.user_name ?? "", width: 24 },
  { label: "Correo", value: (a) => a.user_email ?? "", width: 28 },
  { label: "Acción", value: (a) => actionLabel(a.action), width: 30 },
  { label: "Código de la acción", value: (a) => a.action, width: 24 },
  { label: "Entidad", value: (a) => entityLabel(a.entity_type), width: 14 },
  { label: "Identificador", value: (a) => a.entity_id ?? "", width: 38 },
  { label: "IP", value: (a) => a.ip_address ?? "", width: 16 },
  { label: "Antes", value: (a) => (a.before_data ? JSON.stringify(a.before_data) : ""), width: 40 },
  { label: "Después", value: (a) => (a.after_data ? JSON.stringify(a.after_data) : ""), width: 40 },
  { label: "Detalle", value: (a) => (a.metadata ? JSON.stringify(a.metadata) : ""), width: 40 },
];

function Json({ value }: { value: unknown }) {
  if (value === null || value === undefined || (typeof value === "object" && !Object.keys(value as object).length)) return <span className="app-muted">—</span>;
  return <pre className="max-h-60 overflow-auto whitespace-pre-wrap rounded-lg bg-[var(--app-surface-muted)] p-2.5 text-[11.5px] [overflow-wrap:anywhere]">{JSON.stringify(value, null, 2)}</pre>;
}

export function PlatformAudit() {
  const { toast, toasts } = useToasts();
  const [page, setPage] = useState<Page | null>(null);
  const [options, setOptions] = useState<Options>({ actions: [], users: [] });
  const [error, setError] = useState(false);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState("");
  const [action, setAction] = useState("");
  const [user, setUser] = useState("");
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [sort, setSort] = useState<SortState>(null);
  const [pageNo, setPageNo] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [open, setOpen] = useState<PlatformAuditEntry | null>(null);
  const [busy, setBusy] = useState(false);
  const { selection, selectedRows, clear } = useRowSelection<PlatformAuditEntry>((a) => a.id);

  const params = useCallback(() => {
    const query = new URLSearchParams();
    if (search.trim()) query.set("q", search.trim());
    if (action) query.set("action", action);
    if (user) query.set("user", user);
    if (from) query.set("from", from);
    if (to) query.set("to", to);
    if (sort) { query.set("sort", sort.key); query.set("dir", sort.dir); }
    return query;
  }, [search, action, user, from, to, sort]);

  const load = useCallback(async () => {
    setLoading(true);
    setError(false);
    try {
      const query = params();
      query.set("page", String(pageNo));
      query.set("per_page", String(perPage));
      setPage(await apiFetch<Page>(`/v1/superadmin/audit?${query}`));
    } catch {
      setError(true);
    } finally {
      setLoading(false);
    }
  }, [params, pageNo, perPage]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- recarga al cambiar filtros, orden o página
    void load();
  }, [load]);

  useEffect(() => {
    apiFetch<Options>("/v1/superadmin/audit/options").then(setOptions).catch(() => undefined);
  }, []);

  const filter = <T,>(setter: (value: T) => void) => (value: T) => { setter(value); setPageNo(1); };

  async function run(format: "xlsx" | "csv", selected: boolean) {
    setBusy(true);
    try {
      let rows = selectedRows;
      if (!selected) {
        rows = [];
        for (let n = 1; ; n++) {
          const query = params();
          query.set("page", String(n));
          query.set("per_page", "200");
          const chunk = await apiFetch<Page>(`/v1/superadmin/audit?${query}`);
          rows.push(...chunk.data);
          if (n >= chunk.last_page) break;
        }
      }
      await recordPlatformExport("platform_audit", format, rows.length, selected);
      if (format === "xlsx") await exportXlsx("auditoria-de-plataforma", "Auditoría de plataforma", EXPORT, rows);
      else exportCsv("auditoria-de-plataforma", EXPORT, rows);
    } catch {
      toast("No se pudo exportar.", "danger");
    } finally {
      setBusy(false);
    }
  }

  return (
    <Screen>
      <PageHead title="Auditoría de plataforma" />
      <Filters>
        <SearchInput value={search} onChange={filter(setSearch)} placeholder="Buscar en acciones, actor, entidad o detalle" />
        <FilterSelect label="Acción" value={action} onChange={filter(setAction)} options={options.actions.map((a) => [a, actionLabel(a)])} />
        <FilterSelect label="Actor" value={user} onChange={filter(setUser)} options={options.users.map((u) => [u.id, u.name ?? u.email ?? u.id])} />
        <label className="relative flex items-center gap-1.5 text-[12.5px]">
          <span className="app-muted">Desde</span>
          <input type="date" value={from} onChange={(e) => filter(setFrom)(e.target.value)} className={`${INPUT} h-10 w-auto`} aria-label="Desde" />
        </label>
        <label className="relative flex items-center gap-1.5 text-[12.5px]">
          <span className="app-muted">Hasta</span>
          <input type="date" value={to} onChange={(e) => filter(setTo)(e.target.value)} className={`${INPUT} h-10 w-auto`} aria-label="Hasta" />
        </label>
      </Filters>
      <Card flush>
        {error ? (
          <LoadError text="No se pudo cargar la auditoría de plataforma." onRetry={() => void load()} />
        ) : (
          <>
            <SelectionBar count={selectedRows.length} busy={busy} onExport={(format) => void run(format, true)} onClear={clear} />
            <DataTable
              tableId="platform-audit" cols={COLS} rows={page?.data ?? []} rowKey={(a) => a.id} selection={selection} loading={!page}
              refreshing={loading && Boolean(page)} sort={sort} onSort={(next) => { setSort(next); setPageNo(1); }}
              onRowClick={setOpen} empty="Sin acciones con estos filtros."
            />
            {page && (
              <Pager
                page={page.current_page} lastPage={page.last_page} total={page.total} perPage={perPage} loading={loading}
                onPage={setPageNo} onPerPage={(size) => { setPerPage(size); setPageNo(1); }}
                left={<ExportButtons total={page.total} busy={busy} onExport={(format) => void run(format, false)} />}
              />
            )}
          </>
        )}
      </Card>

      {open && (
        <Modal title={actionLabel(open.action)} size="lg" onClose={() => setOpen(null)}>
          <Kv rows={[
            ["Fecha", dateTimeText(open.created_at)],
            ["Actor", `${open.user_name ?? "—"}${open.user_email ? ` · ${open.user_email}` : ""}`],
            ["Acción", <code key="a">{open.action}</code>],
            ["Entidad", <span key="e">{entityLabel(open.entity_type)}{open.entity_id && <> · <span className="font-mono text-[12px]">{open.entity_id}</span></>}
              {open.entity_type === "Tenant" && open.entity_id && <> · <Link className="text-[color:var(--p-brand-text)] underline" href={`/superadmin/tenants/${open.entity_id}`}>Ver empresa</Link></>}</span>],
            ["IP", open.ip_address ?? "—"],
            ["Navegador", open.user_agent ?? "—"],
          ]} />
          <div className="mt-3 grid gap-3 md:grid-cols-2">
            <Field label="Antes"><Json value={open.before_data} /></Field>
            <Field label="Después"><Json value={open.after_data} /></Field>
          </div>
          <div className="mt-3"><Field label="Detalle"><Json value={open.metadata} /></Field></div>
        </Modal>
      )}
      {toasts}
    </Screen>
  );
}
