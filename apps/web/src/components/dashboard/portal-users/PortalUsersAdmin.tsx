"use client";

import { useEffect, useMemo, useState } from "react";
import { Globe, Megaphone, Plus, Send, ShieldCheck, Users } from "lucide-react";
import { ApiError, apiFetch, apiFetchLater } from "@/lib/api";
import { excelTone, shortDate, stateOf } from "@/lib/commercial";
import { exportCsv, exportXlsx, fetchAllPages, recordExport, type ExportColumn } from "@/lib/export-table";
import { NOTICE_STATES, PORTAL_USER_STATES, type PortalNotice, type PortalRoleInfo, type PortalUserRow } from "@/lib/portal";
import type { PaginatedResponse } from "@/types/customer";
import {
  Badge, Btn, Card, DataTable, ExportButtons, Field, FilterSelect, Filters, INPUT, Kpi, Kpis, LoadError, Modal, PAGE_SIZES, PageHead, Pager,
  Screen, SearchInput, SearchSelect, SelectionBar, Tabs, applySort, useRowSelection, useToasts, type Column, type SortState,
} from "@/components/ui/proto";
import { ColumnFilterBar, addColumnFilter, applyFilters, useServerColumnFilters } from "@/components/dashboard/ColumnFilters";
import { PORTAL_USER_EXPORT, PortalUserModals, portalUserColumns } from "@/components/portal/PortalUserTable";

type Summary = { active: number; invited: number; customers: number; mfa: number };
type Options = { customers: { id: string; name: string; users: number }[]; roles: PortalRoleInfo[]; max_users: number };
type Action = { kind: "role" | "status" | "resend"; row: PortalUserRow; status?: "ACTIVE" | "INACTIVE" };

const EXPORT = PORTAL_USER_EXPORT(true);
const rowKey = (row: PortalUserRow) => row.id;

/**
 * Usuarios del portal de clientes (Administración). Cada cliente administra sus propios usuarios
 * desde el portal (Portal Admin); desde aquí la empresa los ve, invita en nombre del cliente,
 * cambia el rol o desactiva accesos, y publica los avisos del portal.
 */
export default function PortalUsersAdmin() {
  const { toast, toasts } = useToasts();
  const [tab, setTab] = useState("users");
  const [summary, setSummary] = useState<Summary | null>(null);
  const [options, setOptions] = useState<Options | null>(null);
  const [notices, setNotices] = useState<PortalNotice[] | null>(null);

  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [sort, setSort] = useState<SortState>(null);
  const [reload, setReload] = useState(0);
  const columnFilters = useServerColumnFilters(() => setPage(1));
  const { selection, selectedRows, clear: clearSelection } = useRowSelection(rowKey);
  const [data, setData] = useState<PaginatedResponse<PortalUserRow> | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [exporting, setExporting] = useState(false);

  const [invite, setInvite] = useState(false);
  const [action, setAction] = useState<Action | null>(null);
  const [publishing, setPublishing] = useState(false);
  const [withdrawing, setWithdrawing] = useState<PortalNotice | null>(null);

  useEffect(() => {
    const timer = window.setTimeout(() => { setSearch(searchInput.trim()); setPage(1); }, 350);
    return () => window.clearTimeout(timer);
  }, [searchInput]);

  useEffect(() => {
    let cancelled = false;
    async function load() {
      setLoading(true);
      const params = new URLSearchParams({ per_page: String(perPage), page: String(page) });
      if (search) params.set("search", search);
      if (status) params.set("status", status);
      applySort(applyFilters(params, columnFilters.param), sort);
      try {
        const response = await apiFetch<PaginatedResponse<PortalUserRow>>(`/v1/portal-users?${params}`);
        if (!cancelled) { setData(response); setError(""); }
      } catch {
        if (!cancelled) setError("No se pudieron cargar los usuarios del portal.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [page, perPage, search, status, columnFilters.param, sort, reload]);

  // Indicadores, clientes y avisos van después del listado.
  useEffect(() => {
    apiFetchLater<Summary>("/v1/portal-users/summary").then(setSummary).catch(() => setSummary(null));
    apiFetchLater<Options>("/v1/portal-users/options").then(setOptions).catch(() => setOptions(null));
    apiFetchLater<PortalNotice[]>("/v1/portal-notices").then(setNotices).catch(() => setNotices([]));
  }, [reload]);

  const cols = useMemo(
    () => portalUserColumns({ backoffice: true, permission: "portal_users.manage", busy: false, onAction: setAction }),
    [],
  );

  async function runExport(format: "xlsx" | "csv", picked?: PortalUserRow[]) {
    setExporting(true);
    try {
      const params = new URLSearchParams();
      if (search) params.set("search", search);
      if (status) params.set("status", status);
      const rows = picked ?? (await fetchAllPages<PortalUserRow>("/v1/portal-users", applySort(columnFilters.apply(params), sort)));
      await recordExport("portal_users", format, rows.length, Boolean(picked));
      if (format === "xlsx") await exportXlsx("usuarios-portal", "Usuarios del portal", EXPORT, rows);
      else exportCsv("usuarios-portal", EXPORT, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el listado.", "danger");
    } finally {
      setExporting(false);
    }
  }

  const done = () => { setInvite(false); setAction(null); setReload((n) => n + 1); };

  return (
    <Screen>
      <PageHead
        title="Usuarios del portal de clientes"
        actions={tab === "users"
          ? <Btn permission="portal_users.create" onClick={() => setInvite(true)} disabled={!options}><Plus size={15} /> Invitar usuario</Btn>
          : <Btn permission="portal_users.manage" onClick={() => setPublishing(true)} disabled={!options}><Megaphone size={15} /> Publicar aviso</Btn>}
      />

      <Kpis>
        <Kpi label="Activos" value={summary?.active ?? "—"} icon={<Users size={20} />} tone="success" />
        <Kpi label="Invitaciones pendientes" value={summary?.invited ?? "—"} icon={<Send size={20} />} tone="warning" />
        <Kpi label="Clientes con portal" value={summary?.customers ?? "—"} icon={<Globe size={20} />} />
        <Kpi label="Con verificación en dos pasos" value={summary?.mfa ?? "—"} icon={<ShieldCheck size={20} />} />
      </Kpis>

      <Tabs
        tabs={[["users", "Usuarios", data?.total ?? null], ["notices", "Avisos", notices ? notices.filter((notice) => notice.status === "ACTIVE").length : null]]}
        value={tab}
        onChange={setTab}
      />

      {tab === "users" ? (
        <>
          <Filters>
            <SearchInput value={searchInput} onChange={setSearchInput} placeholder="Buscar usuario, correo o cliente" />
            <FilterSelect label="Estado" value={status} onChange={(value) => { setStatus(value); setPage(1); }} options={PORTAL_USER_STATES.map(([key, label]) => [key, label])} />
            <ColumnFilterBar
              filters={columnFilters}
              hasOtherFilters={Boolean(searchInput || status)}
              onClearAll={() => { setSearchInput(""); setSearch(""); setStatus(""); setPage(1); }}
            />
          </Filters>

          <Card flush>
            {error ? <LoadError text={error} onRetry={() => setReload((n) => n + 1)} /> : (
              <>
                <SelectionBar permission="portal_users.export" count={selectedRows.length} busy={exporting} onExport={(format) => runExport(format, selectedRows)} onClear={clearSelection} />
                <DataTable
                  tableId="portal-users" sort={sort} onSort={(next) => { setSort(next); setPage(1); }} refreshing={loading}
                  onFilter={(column) => addColumnFilter(columnFilters, column)}
                  cols={cols}
                  rows={data?.data ?? []}
                  rowKey={rowKey}
                  selection={selection}
                  loading={loading && !data}
                  empty={search || status ? "No hay registros con estos filtros." : "Sin usuarios del portal."}
                />
                {data && (
                  <Pager
                    loading={loading} page={data.current_page} lastPage={data.last_page} total={data.total} perPage={data.per_page}
                    onPage={setPage} onPerPage={(size) => { setPerPage(size); setPage(1); }}
                    left={<ExportButtons permission="portal_users.export" total={data.total} busy={exporting} onExport={(format) => runExport(format)} />}
                  />
                )}
              </>
            )}
          </Card>
        </>
      ) : (
        <NoticesTable notices={notices} onWithdraw={setWithdrawing} toast={toast} />
      )}

      {options && (invite || action) && (
        <PortalUserModals
          key={action ? `${action.kind}-${action.row.id}` : "invite"}
          base="/v1/portal-users"
          roles={options.roles}
          customers={options.customers}
          maxUsers={options.max_users}
          invite={invite}
          action={action}
          toast={toast}
          onClose={() => { setInvite(false); setAction(null); }}
          onDone={done}
        />
      )}
      {publishing && options && (
        <PublishNotice customers={options.customers} onClose={() => setPublishing(false)} onDone={() => { setPublishing(false); toast("Aviso publicado en el portal"); setReload((n) => n + 1); }} />
      )}
      {withdrawing && (
        <WithdrawNotice notice={withdrawing} onClose={() => setWithdrawing(null)} onDone={() => { setWithdrawing(null); toast("Aviso retirado"); setReload((n) => n + 1); }} toast={toast} />
      )}
      {toasts}
    </Screen>
  );
}

const NOTICE_EXPORT: ExportColumn<PortalNotice>[] = [
  { label: "Aviso", value: (row) => row.body, width: 60 },
  { label: "Para", value: (row) => row.customer_name ?? "Todos los clientes", width: 30 },
  { label: "Publicado", value: (row) => shortDate(row.created_at), width: 15, kind: "center" },
  { label: "Vence", value: (row) => (row.expires_at ? shortDate(row.expires_at) : "Sin vencimiento"), width: 16, kind: "center" },
  { label: "Estado", value: (row) => stateOf(NOTICE_STATES, row.status)[0], width: 14, kind: "status", tone: (row) => excelTone(stateOf(NOTICE_STATES, row.status)[1]) },
];

function NoticesTable({ notices, onWithdraw, toast }: { notices: PortalNotice[] | null; onWithdraw: (notice: PortalNotice) => void; toast: ReturnType<typeof useToasts>["toast"] }) {
  const [exporting, setExporting] = useState(false);
  const cols: Column<PortalNotice>[] = [
    {
      label: "Aviso",
      render: (row) => (
        <div className="min-w-[220px] max-w-[460px]">
          <span>{row.body}</span>
          {row.link && <small className="app-muted block text-xs">Enlace: {row.link}</small>}
        </div>
      ),
    },
    { label: "Para", render: (row) => row.customer_name ?? "Todos los clientes" },
    { label: "Publicado", render: (row) => <div>{shortDate(row.created_at)}<small className="app-muted block text-xs">{row.author ?? ""}</small></div> },
    { label: "Vence", render: (row) => (row.expires_at ? shortDate(row.expires_at) : "Sin vencimiento") },
    { label: "Estado", render: (row) => { const [label, tone] = stateOf(NOTICE_STATES, row.status); return <Badge tone={tone}>{label}</Badge>; } },
    {
      label: "",
      render: (row) => (row.status === "ACTIVE" ? (
        <div className="flex sm:justify-end"><Btn variant="danger-ghost" size="sm" permission="portal_users.manage" onClick={() => onWithdraw(row)}>Retirar</Btn></div>
      ) : null),
    },
  ];

  async function run(format: "xlsx" | "csv") {
    if (!notices) return;
    setExporting(true);
    try {
      // Como las demás exportaciones: la API comprueba el permiso y la deja en auditoría.
      await recordExport("portal_users", format, notices.length, true);
      if (format === "xlsx") await exportXlsx("avisos-portal", "Avisos del portal", NOTICE_EXPORT, notices);
      else exportCsv("avisos-portal", NOTICE_EXPORT, notices);
      toast(`${notices.length} ${notices.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar la tabla.", "danger");
    } finally {
      setExporting(false);
    }
  }

  return (
    <Card flush>
      <DataTable cols={cols} rows={notices ?? []} rowKey={(row) => row.id} loading={!notices} empty="Sin avisos publicados." />
      {notices && notices.length > 0 && (
        <div className="flex flex-wrap items-center gap-2 border-t border-[color:var(--app-border)] px-3.5 py-2.5">
          <ExportButtons permission="portal_users.export" total={notices.length} busy={exporting} onExport={run} />
          <span className="app-muted text-xs">{notices.length} {notices.length === 1 ? "aviso" : "avisos"}</span>
        </div>
      )}
    </Card>
  );
}

function PublishNotice({ customers, onClose, onDone }: { customers: Options["customers"]; onClose: () => void; onDone: () => void }) {
  const [customerId, setCustomerId] = useState("");
  const [body, setBody] = useState("");
  const [link, setLink] = useState("");
  const [expires, setExpires] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  async function publish() {
    setBusy(true);
    setErrors({});
    try {
      await apiFetch("/v1/portal-notices", {
        method: "POST",
        body: JSON.stringify({ customer_id: customerId || null, body: body.trim(), link: link.trim() || null, expires_at: expires || null }),
      });
      onDone();
    } catch (err) {
      if (err instanceof ApiError && err.errors) setErrors(Object.fromEntries(Object.entries(err.errors).map(([key, list]) => [key, list[0]])));
      else setErrors({ body: err instanceof Error ? err.message : "No se pudo publicar el aviso." });
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal
      title="Publicar aviso en el portal"
      size="sm"
      onClose={onClose}
      footer={<><Btn variant="ghost" onClick={onClose}>Cancelar</Btn><Btn disabled={busy || !body.trim()} onClick={publish}>{busy ? "Publicando…" : "Publicar"}</Btn></>}
    >
      <div className="grid gap-3">
        <Field label="Para" error={errors.customer_id} hint="Vacío: todos los clientes con acceso al portal.">
          <SearchSelect
            value={customerId}
            onChange={setCustomerId}
            placeholder="Todos los clientes"
            options={[{ value: "", label: "Todos los clientes" }, ...customers.map((customer) => ({ value: customer.id, label: customer.name }))]}
          />
        </Field>
        <Field label="Aviso" required error={errors.body} hint={`${body.length}/500`}>
          <textarea className={INPUT} rows={3} value={body} onChange={(e) => setBody(e.target.value)} maxLength={500} autoFocus />
        </Field>
        <Field label="Enlace" error={errors.link} hint="Opcional. Una sección del portal (/portal/invoices) o una dirección https://">
          <input className={INPUT} value={link} onChange={(e) => setLink(e.target.value)} maxLength={500} placeholder="/portal/services" />
        </Field>
        <Field label="Vence" error={errors.expires_at} hint="Opcional. Deja de mostrarse al terminar ese día.">
          <input type="date" className={INPUT} value={expires} onChange={(e) => setExpires(e.target.value)} />
        </Field>
        <p className="app-muted text-xs">En el inicio del portal se ven los tres avisos vigentes más recientes.</p>
      </div>
    </Modal>
  );
}

function WithdrawNotice({ notice, onClose, onDone, toast }: { notice: PortalNotice; onClose: () => void; onDone: () => void; toast: ReturnType<typeof useToasts>["toast"] }) {
  const [busy, setBusy] = useState(false);
  async function withdraw() {
    setBusy(true);
    try {
      await apiFetch(`/v1/portal-notices/${notice.id}/withdraw`, { method: "POST" });
      onDone();
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "No se pudo retirar el aviso.", "danger");
      setBusy(false);
    }
  }
  return (
    <Modal
      title="Retirar aviso"
      size="sm"
      onClose={onClose}
      footer={<><Btn variant="ghost" onClick={onClose}>Cancelar</Btn><Btn variant="danger" disabled={busy} onClick={withdraw}>Retirar</Btn></>}
    >
      <p>El aviso deja de mostrarse en el portal. Queda en el historial como retirado.</p>
      <p className="app-muted mt-2 text-[13px]">“{notice.body}”</p>
    </Modal>
  );
}
