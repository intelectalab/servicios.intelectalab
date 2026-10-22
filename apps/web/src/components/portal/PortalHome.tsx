"use client";

import { useEffect, useState, type ReactNode } from "react";
import Link from "next/link";
import { AlertCircle, Bell, FileSignature, FileText, LifeBuoy, Receipt, Repeat } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { money } from "@/lib/commercial";
import { WORK_ORDER_STATES } from "@/lib/operations";
import { stateOf } from "@/lib/commercial";
import { dateTimeText, relTime, type PortalHome as HomeData } from "@/lib/portal";
import { Badge, Card, LoadError } from "@/components/ui/proto";
import { PortalTitle, usePortal } from "./PortalShell";

const SHADOW = "shadow-[0_1px_2px_rgba(16,24,40,.05),0_2px_8px_rgba(16,24,40,.06)]";

// La .plain-list del prototipo: filas separadas por una línea y el detalle debajo, en gris.
const LIST_ITEM = "flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-[color:var(--app-border)] py-[7px] last:border-b-0";
const LIST_SMALL = "app-muted w-full text-xs";

/** Tarjeta-enlace del inicio (el .p-tile del prototipo): borde izquierdo de color, ícono, texto y número. */
function Tile({ href, label, value, icon, tone }: { href: string; label: string; value: ReactNode; icon: ReactNode; tone?: "warn" | "info" }) {
  const border = tone === "warn" ? "border-l-[#B26E00]" : tone === "info" ? "border-l-[#1B8FC9]" : "border-l-[color:var(--p-brand-line)]";
  return (
    <Link href={href} className={`app-surface grid gap-1.5 rounded-xl border-l-4 p-4 transition-transform hover:-translate-y-px ${border} ${SHADOW}`}>
      <span className="grid h-[34px] w-[34px] place-items-center rounded-[10px] bg-[var(--p-brand-soft)] text-[color:var(--p-brand-soft-text)]">{icon}</span>
      <span className="app-muted text-[13px]">{label}</span>
      <strong className="text-[26px] font-semibold leading-none tabular-nums">{value}</strong>
    </Link>
  );
}

export default function PortalHome() {
  const { me, can } = usePortal();
  const [data, setData] = useState<HomeData | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);

  useEffect(() => {
    let cancelled = false;
    apiFetch<HomeData>("/v1/portal/home")
      .then((response) => { if (!cancelled) { setData(response); setError(""); } })
      .catch(() => { if (!cancelled) setError("No se pudo cargar el inicio."); });
    return () => { cancelled = true; };
  }, [reload]);

  const firstName = me.name.split(" ")[0];
  const balance = data?.balance?.length
    ? data.balance.map((row) => money(row.amount, row.currency)).join(" · ")
    : money(0);

  return (
    <>
      <PortalTitle>Hola, {firstName}</PortalTitle>
      {error ? (
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
      ) : !data ? (
        <div className="mb-4 grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(175px,1fr))]" aria-busy="true">
          {[0, 1, 2, 3].map((n) => <div key={n} className="app-surface h-[118px] animate-pulse rounded-xl" />)}
        </div>
      ) : (
        <>
          <div className="mb-4 grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(175px,1fr))]">
            {can("quotes") && (
              <Tile href="/portal/quotations" label="Cotizaciones por aprobar" value={data.quotations_pending ?? 0} icon={<FileText size={18} />} tone={data.quotations_pending ? "info" : undefined} />
            )}
            {can("invoices") && (
              <Tile href="/portal/invoices" label="Saldo pendiente" value={<span className="text-[22px]">{balance}</span>} icon={<Receipt size={18} />} tone={data.overdue ? "warn" : undefined} />
            )}
            {can("support") && <Tile href="/portal/support" label="Tickets abiertos" value={data.open_tickets ?? 0} icon={<LifeBuoy size={18} />} />}
            {can("services") && (
              <Tile href="/portal/services" label="Servicios por renovar (60 días)" value={data.renewals ?? 0} icon={<Repeat size={18} />} tone={data.renewals ? "warn" : undefined} />
            )}
            {can("sign") && (
              <Tile href="/portal/signatures" label="Contratos por firmar" value={data.signatures_pending ?? 0} icon={<FileSignature size={18} />} tone={data.signatures_pending ? "info" : undefined} />
            )}
          </div>

          {data.notices.length > 0 && (
            <Card title="Avisos">
              <ul>
                {data.notices.map((notice) => (
                  <li key={notice.id} className={LIST_ITEM}>
                    <Bell size={15} className="shrink-0 text-[color:var(--p-brand-text)]" aria-hidden="true" />
                    <span>{notice.body}</span>
                    {notice.link && <Link href={notice.link} className="font-medium text-[color:var(--p-brand-text)] hover:underline">Ver</Link>}
                    <small className={LIST_SMALL}>{relTime(notice.created_at)}</small>
                  </li>
                ))}
              </ul>
            </Card>
          )}

          {can("services") && data.work_orders.length > 0 && (
            <Card title="Trabajos en curso">
              <ul>
                {data.work_orders.map((order) => {
                  const [label, tone] = stateOf(WORK_ORDER_STATES, order.status);
                  return (
                    <li key={order.id} className={LIST_ITEM}>
                      <strong className="font-semibold">{order.title}</strong>
                      <Badge tone={tone}>{label}</Badge>
                      <small className={LIST_SMALL}>Programado {dateTimeText(order.scheduled_start)}</small>
                    </li>
                  );
                })}
              </ul>
            </Card>
          )}

          {data.overdue && (
            <div className="mb-3.5 flex items-start gap-2.5 rounded-xl border border-[color:var(--p-notice-line)] bg-[var(--p-notice-bg)] px-3.5 py-[11px] text-[color:var(--p-notice-text)]">
              <AlertCircle size={18} className="mt-px shrink-0" aria-hidden="true" />
              <div>
                Tienes comprobantes vencidos. <Link href="/portal/invoices" className="font-semibold underline">Revisa tus facturas y pagos</Link>.
              </div>
            </div>
          )}
        </>
      )}
    </>
  );
}
