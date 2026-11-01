"use client";

import { useEffect, useState, type MouseEvent, type ReactNode } from "react";
import { Check, Copy, Eye, History, KeyRound, Plus, RefreshCw, Ban, X } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { excelTone, stateOf } from "@/lib/commercial";
import { dateTime } from "@/lib/crm";
import { useAccess } from "@/lib/access-context";
import {
  ACTION_PERMISSION, CREDENTIAL_FILTER_TYPES, CREDENTIAL_STATES, REVEAL_SECONDS, VAULT_PERMISSIONS, blockedBy, expiresSoon, limaDate, typeLabel,
} from "@/lib/credentials";
import { exportCsv, exportXlsx, fetchAllPages, recordExport, type ExportColumn } from "@/lib/export-table";
import {
  Btn, Card, Chip, DataTable, ExportButtons, FilterSelect, Filters, LoadError, PAGE_SIZES, PageHead, Pager, Screen, SearchInput, SelectionBar,
  applySort, useRowSelection, useToasts, type Column, type SortState,
} from "@/components/ui/proto";
import { ColumnFilterBar, addColumnFilter, applyFilters, useServerColumnFilters } from "@/components/dashboard/ColumnFilters";
import { CredentialActionModal, CredentialCreateModal, CredentialHistoryModal, CredentialStatusBadge } from "@/components/dashboard/credentials/CredentialModals";
import type { PaginatedResponse } from "@/types/customer";
import type { CredentialAction, CredentialRow } from "@/types/credentials";

const credentialKey = (credential: CredentialRow) => credential.id;

// Solo datos generales: el valor nunca se exporta.
const EXPORT_COLUMNS: ExportColumn<CredentialRow>[] = [
  { label: "Credencial", value: (c) => c.name, width: 34 },
  { label: "Tipo", value: (c) => typeLabel(c.type), width: 16 },
  { label: "Pertenece a", value: (c) => c.owner_label ?? "", width: 30 },
  { label: "Versión", value: (c) => `v${c.version}`, width: 10, kind: "center" },
  { label: "Rotada", value: (c) => (c.rotated_at ? dateTime(c.rotated_at) : ""), width: 22, kind: "center" },
  { label: "Expira", value: (c) => (c.expires_at ? limaDate(c.expires_at) : "No vence"), width: 16, kind: "center" },
  {
    label: "Estado", value: (c) => stateOf(CREDENTIAL_STATES, c.status)[0], width: 14, kind: "status",
    tone: (c) => excelTone(stateOf(CREDENTIAL_STATES, c.status)[1]),
  },
  { label: "Creada por", value: (c) => c.creator ?? "", width: 24 },
];

type TopFilters = { search: string; type: string; status: string };

function withTopFilters(params: URLSearchParams, top: TopFilters) {
  if (top.search) params.set("search", top.search);
  if (top.type) params.set("type", top.type);
  if (top.status) params.set("status", top.status);
  return params;
}

const stop = (event: MouseEvent) => event.stopPropagation();

export default function CredentialsList() {
  const { can, loading: accessLoading } = useAccess();
  const { toast, toasts } = useToasts();
  const [exporting, setExporting] = useState(false);

  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [type, setType] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [reload, setReload] = useState(0);
  const [sort, setSort] = useState<SortState>(null);
  const columnFilters = useServerColumnFilters(() => setPage(1));
  const { selection, selectedRows, clear: clearSelection } = useRowSelection(credentialKey);

  const [data, setData] = useState<PaginatedResponse<CredentialRow> | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [creating, setCreating] = useState(false);
  const [acting, setActing] = useState<{ credential: CredentialRow; action: CredentialAction } | null>(null);
  const [historyOf, setHistoryOf] = useState<CredentialRow | null>(null);
  // Valor revelado: se ve en su fila REVEAL_SECONDS segundos y se borra de la memoria de la pantalla.
  const [revealed, setRevealed] = useState<{ id: string; secret: string; left: number } | null>(null);

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, 350);
    return () => window.clearTimeout(timer);
  }, [searchInput]);

  useEffect(() => {
    if (!revealed) return;
    const timer = window.setTimeout(() => setRevealed((current) => (current && current.left > 1 ? { ...current, left: current.left - 1 } : null)), 1000);
    return () => window.clearTimeout(timer);
  }, [revealed]);

  useEffect(() => {
    let cancelled = false;
    async function load() {
      setLoading(true);
      const params = withTopFilters(new URLSearchParams({ per_page: String(perPage), page: String(page) }), { search, type, status });
      applySort(applyFilters(params, columnFilters.param), sort);
      try {
        const response = await apiFetch<PaginatedResponse<CredentialRow>>(`/v1/credentials?${params}`);
        if (!cancelled) {
          setData(response);
          setError("");
        }
      } catch {
        if (!cancelled) setError("No se pudieron cargar las credenciales.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [page, perPage, search, type, status, columnFilters.param, sort, reload]);

  async function runExport(format: "xlsx" | "csv", picked?: CredentialRow[]) {
    setExporting(true);
    try {
      const rows = picked ?? (await fetchAllPages<CredentialRow>("/v1/credentials", applySort(columnFilters.apply(withTopFilters(new URLSearchParams(), { search, type, status })), sort)));
      await recordExport("credentials", format, rows.length, Boolean(picked));
      if (format === "xlsx") await exportXlsx("credenciales", "Credential Vault", EXPORT_COLUMNS, rows);
      else exportCsv("credenciales", EXPORT_COLUMNS, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el listado.", "danger");
    } finally {
      setExporting(false);
    }
  }

  /** Botón de una acción: con candado sin permiso; desactivado con su motivo si el estado no la permite. */
  function actionButton(credential: CredentialRow, action: CredentialAction, label: string, icon: ReactNode, variant: "secondary" | "ghost" | "danger-ghost") {
    const blocked = blockedBy(action, credential.status);
    return (
      <Btn
        size="sm"
        variant={variant}
        permission={ACTION_PERMISSION[action]}
        disabled={Boolean(blocked)}
        title={blocked ?? undefined}
        onClick={(event) => {
          stop(event);
          setActing({ credential, action });
        }}
      >
        {icon}
        <span>{label}</span>
      </Btn>
    );
  }

  const cols: Column<CredentialRow>[] = [
    {
      label: "Credencial", sortKey: "credential", filter: "text",
      render: (c) => (
        <div className="flex min-w-[170px] max-w-[300px] items-start gap-2">
          <KeyRound size={17} className="mt-0.5 shrink-0 text-[color:var(--p-brand)]" aria-hidden="true" />
          <div className="min-w-0">
            <strong className="font-semibold">{c.name}</strong>
            <small className="app-muted block text-xs [overflow-wrap:anywhere]">{typeLabel(c.type)}{c.owner_label ? ` · ${c.owner_label}` : ""}</small>
          </div>
        </div>
      ),
    },
    {
      label: "Valor",
      render: (c) =>
        revealed?.id === c.id ? (
          <span className="inline-flex max-w-[260px] items-center gap-1.5" onClick={stop}>
            <code className="rounded bg-[var(--p-warning-soft)] px-1.5 py-0.5 font-mono text-[12px] text-[color:var(--p-warning-text)] [overflow-wrap:anywhere]">{revealed.secret}</code>
            <small className="app-muted whitespace-nowrap tabular-nums">{revealed.left} s</small>
            <button type="button" onClick={() => setRevealed(null)} className="app-muted grid h-6 w-6 cursor-pointer place-items-center rounded hover:bg-[var(--app-surface-muted)]" aria-label="Ocultar ahora" title="Ocultar ahora">
              <X size={13} />
            </button>
          </span>
        ) : (
          <code className="app-muted font-mono text-[12px] tracking-[2px]" aria-label="Valor oculto">••••••••••••</code>
        ),
    },
    {
      label: "Versión", sortKey: "version", filter: "number",
      render: (c) => (
        <span className="whitespace-nowrap">
          v{c.version}
          {c.rotated_at && <small className="app-muted block text-xs">rotada {limaDate(c.rotated_at)}</small>}
        </span>
      ),
    },
    {
      label: "Expira", sortKey: "expires", filter: "date",
      render: (c) => (
        <span className="inline-flex flex-wrap items-center gap-1.5 whitespace-nowrap">
          {c.expires_at ? limaDate(c.expires_at) : <span className="app-muted">No vence</span>}
          {expiresSoon(c) && <Chip tone="warning">Pronto</Chip>}
        </span>
      ),
    },
    { label: "Estado", sortKey: "status", filter: { top: "Estado" }, render: (c) => <CredentialStatusBadge status={c.status} /> },
    {
      label: "Acciones",
      render: (c) => (
        <div className="flex flex-wrap gap-1.5" onClick={stop}>
          {actionButton(c, "reveal", "Revelar", <Eye size={13} />, "secondary")}
          {actionButton(c, "use", "Usar", <Copy size={13} />, "ghost")}
          {actionButton(c, "rotate", "Rotar", <RefreshCw size={13} />, "ghost")}
          {actionButton(c, "revoke", "Revocar", <Ban size={13} />, "danger-ghost")}
          <Btn size="sm" variant="ghost" onClick={() => setHistoryOf(c)}>
            <History size={13} />
            <span>Historial</span>
          </Btn>
        </div>
      ),
    },
  ];

  const rows = data?.data ?? [];

  return (
    <Screen>
      <PageHead
        title="Credential Vault"
        actions={
          <Btn onClick={() => setCreating(true)} permission="credentials.manage">
            <Plus size={16} />
            <span>Nueva credencial</span>
          </Btn>
        }
      />

      {!accessLoading && (
        <div className="mb-3 flex flex-wrap items-center gap-1.5">
          {VAULT_PERMISSIONS.map(([permission, label]) => (
            <Chip key={permission} tone={can(permission) ? "success" : "muted"}>
              {can(permission) ? <Check size={11} className="mr-1" aria-hidden="true" /> : <X size={11} className="mr-1" aria-hidden="true" />}
              {label}
            </Chip>
          ))}
          <small className="app-muted ml-1 text-xs">permisos de tu rol</small>
        </div>
      )}

      <Filters>
        <SearchInput value={searchInput} onChange={setSearchInput} placeholder="Buscar nombre o a quién pertenece" />
        <FilterSelect label="Tipo" value={type} onChange={(value) => { setType(value); setPage(1); }} options={CREDENTIAL_FILTER_TYPES} />
        <FilterSelect label="Estado" value={status} onChange={(value) => { setStatus(value); setPage(1); }} options={CREDENTIAL_STATES.map(([key, label]) => [key, label])} />
        <ColumnFilterBar
          filters={columnFilters}
          hasOtherFilters={Boolean(searchInput || type || status)}
          onClearAll={() => {
            setSearchInput("");
            setSearch("");
            setType("");
            setStatus("");
            setPage(1);
          }}
        />
      </Filters>

      {error ? (
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
      ) : (
        <Card flush>
          <SelectionBar permission="credentials.export" count={selectedRows.length} busy={exporting} onExport={(format) => runExport(format, selectedRows)} onClear={clearSelection} />
          <DataTable
            tableId="credentials" sort={sort} onSort={(next) => { setSort(next); setPage(1); }} refreshing={loading}
            onFilter={(column) => addColumnFilter(columnFilters, column)}
            cols={cols}
            rows={rows}
            rowKey={credentialKey}
            selection={selection}
            onRowClick={(c) => setHistoryOf(c)}
            loading={loading && !data}
            empty="No hay credenciales con estos filtros."
          />
          {data && (
            <Pager
              loading={loading}
              page={data.current_page}
              lastPage={data.last_page}
              total={data.total}
              perPage={data.per_page}
              onPage={setPage}
              onPerPage={(size) => { setPerPage(size); setPage(1); }}
              left={<ExportButtons permission="credentials.export" total={data.total} busy={exporting} onExport={(format) => runExport(format)} />}
            />
          )}
        </Card>
      )}

      {creating && <CredentialCreateModal toast={toast} onClose={() => setCreating(false)} onSaved={() => setReload((n) => n + 1)} />}
      {acting && (
        <CredentialActionModal
          credential={acting.credential}
          action={acting.action}
          toast={toast}
          onClose={() => setActing(null)}
          onDone={(secret) => {
            if (secret !== undefined) setRevealed({ id: acting.credential.id, secret, left: REVEAL_SECONDS });
            else if (revealed?.id === acting.credential.id) setRevealed(null);
            setReload((n) => n + 1);
          }}
        />
      )}
      {historyOf && <CredentialHistoryModal credential={historyOf} onClose={() => setHistoryOf(null)} />}
      {toasts}
    </Screen>
  );
}
