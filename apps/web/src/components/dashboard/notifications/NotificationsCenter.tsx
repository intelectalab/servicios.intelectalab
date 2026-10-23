"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { CheckCheck } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { exportCsv, exportXlsx, fetchAllPages, type ExportColumn } from "@/lib/export-table";
import { markAllRead, markRead, onNotificationsChanged, relTime, type AppNotification } from "@/lib/notifications";
import {
  Btn, Card, Empty, ExportButtons, Filters, LoadError, PAGE_SIZES, PageHead, Pager, Screen, SearchInput, Tabs, useToasts,
} from "@/components/ui/proto";
import type { PaginatedResponse } from "@/types/customer";

type Status = "all" | "unread" | "read";
type Page = PaginatedResponse<AppNotification> & { unread: number };

const dateTime = (value: string) => new Date(value).toLocaleString("es-PE", { dateStyle: "medium", timeStyle: "short" });

const EXPORT_COLUMNS: ExportColumn<AppNotification>[] = [
  { label: "Fecha", value: (row) => dateTime(row.created_at), width: 22, kind: "center" },
  { label: "Notificación", value: (row) => row.title, width: 34 },
  { label: "Detalle", value: (row) => row.body, width: 60 },
  { label: "Estado", value: (row) => (row.read ? "Leída" : "Sin leer"), width: 14, kind: "status", tone: (row) => (row.read ? "neutral" : "info") },
  { label: "Leída el", value: (row) => (row.read_at ? dateTime(row.read_at) : ""), width: 22, kind: "center" },
];

/** Centro de notificaciones (P-SYS-01): las de la persona, con filtro, búsqueda y exportar al pie. */
export default function NotificationsCenter() {
  const router = useRouter();
  const { toast, toasts } = useToasts();
  const [status, setStatus] = useState<Status>("all");
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[1]);
  const [data, setData] = useState<Page | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [busy, setBusy] = useState(false);
  const [exporting, setExporting] = useState(false);

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, 350);
    return () => window.clearTimeout(timer);
  }, [searchInput]);

  // Lo marcado desde la campana también se ve aquí.
  useEffect(() => onNotificationsChanged(() => setReload((n) => n + 1)), []);

  useEffect(() => {
    let cancelled = false;
    async function load() {
      setLoading(true);
      const params = new URLSearchParams({ status, page: String(page), per_page: String(perPage) });
      if (search) params.set("q", search);
      try {
        const response = await apiFetch<Page>(`/v1/me/notifications?${params}`);
        if (cancelled) return;
        setData(response);
        setError("");
      } catch {
        if (!cancelled) setError("No se pudieron cargar tus notificaciones.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [status, search, page, perPage, reload]);

  async function open(item: AppNotification) {
    if (!item.read) await markRead(item.id).catch(() => undefined);
    if (item.link) router.push(item.link);
  }

  async function readAll() {
    setBusy(true);
    try {
      const { marked } = await markAllRead();
      toast(marked ? `${marked} ${marked === 1 ? "notificación marcada" : "notificaciones marcadas"} como leídas` : "No había notificaciones sin leer");
    } catch {
      toast("No se pudieron marcar como leídas.", "danger");
    } finally {
      setBusy(false);
    }
  }

  async function runExport(format: "xlsx" | "csv") {
    setExporting(true);
    try {
      const params = new URLSearchParams({ status });
      if (search) params.set("q", search);
      const rows = await fetchAllPages<AppNotification>("/v1/me/notifications", params);
      if (format === "xlsx") await exportXlsx("notificaciones", "Notificaciones", EXPORT_COLUMNS, rows);
      else exportCsv("notificaciones", EXPORT_COLUMNS, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar.", "danger");
    } finally {
      setExporting(false);
    }
  }

  const unread = data?.unread ?? 0;

  return (
    <Screen>
      <PageHead
        title="Notificaciones"
        actions={
          <Btn variant="ghost" onClick={readAll} disabled={busy || unread === 0}>
            <CheckCheck size={16} />
            <span>Marcar todas como leídas</span>
          </Btn>
        }
      />

      <Tabs
        tabs={[["all", "Todas", null], ["unread", "Sin leer", unread], ["read", "Leídas", null]]}
        value={status}
        onChange={(value) => {
          setStatus(value as Status);
          setPage(1);
        }}
      />

      <Filters>
        <SearchInput value={searchInput} onChange={setSearchInput} placeholder="Buscar en tus notificaciones" />
      </Filters>

      {error ? (
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
      ) : (
        <Card flush>
          {loading && !data ? (
            <div className="space-y-3 p-5" aria-busy="true">
              {[0, 1, 2, 3].map((n) => <div key={n} className="h-5 animate-pulse rounded bg-[var(--app-surface-muted)]" />)}
            </div>
          ) : !data?.data.length ? (
            <Empty text={search || status !== "all" ? "No hay notificaciones con estos filtros." : "No tienes notificaciones."} />
          ) : (
            <ul className={loading ? "opacity-60 transition-opacity" : ""}>
              {data.data.map((item) => (
                <li key={item.id}>
                  <button
                    type="button"
                    onClick={() => open(item)}
                    className={`flex w-full cursor-pointer gap-3 border-b border-[color:var(--app-border)] px-5 py-3.5 text-left transition-colors hover:bg-[var(--app-surface-muted)] ${item.read ? "" : "bg-[var(--p-brand-soft)]/50"}`}
                  >
                    <span aria-hidden="true" className={`mt-[7px] h-2 w-2 shrink-0 rounded-full ${item.read ? "bg-transparent" : "bg-[#1B8FC9]"}`} />
                    <span className="grid min-w-0 flex-1 gap-0.5">
                      <strong className={`text-[13.5px] ${item.read ? "font-medium" : "font-semibold"}`}>{item.title}</strong>
                      <span className="app-muted text-[13px] [overflow-wrap:anywhere]">{item.body}</span>
                      <small className="app-muted text-[11.5px]" title={dateTime(item.created_at)}>
                        {relTime(item.created_at)}{item.read ? "" : " · sin leer"}
                      </small>
                    </span>
                  </button>
                </li>
              ))}
            </ul>
          )}
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
              left={<ExportButtons total={data.total} busy={exporting} onExport={runExport} />}
            />
          )}
        </Card>
      )}
      {toasts}
    </Screen>
  );
}
