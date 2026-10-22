"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { ApiError, apiFetch } from "@/lib/api";
import { excelTone, shortDate, stateOf } from "@/lib/commercial";
import { CONTRACT_STATES, slaLabel } from "@/lib/contracts";
import { CONTRACTED_STATES } from "@/lib/operations";
import type { ExportColumn } from "@/lib/export-table";
import {
  PORTAL_REQUEST_STATES, type PortalContract, type PortalContractedService, type PortalRequestRow,
} from "@/lib/portal";
import { Badge, Btn, Card, Chip, DataTable, Field, INPUT, LoadError, SearchSelect, type Column } from "@/components/ui/proto";
import { PortalTitle, usePortal } from "./PortalShell";
import { PortalExport } from "./PortalExport";

function errorText(err: unknown, fallback: string) {
  return err instanceof ApiError ? (err.errors && Object.values(err.errors)[0]?.[0]) || err.message : fallback;
}

const CONTRACT_EXPORT: ExportColumn<PortalContract>[] = [
  { label: "Contrato", value: (row) => row.contract_number, width: 18, kind: "center" },
  { label: "Inicio", value: (row) => shortDate(row.start_date), width: 15, kind: "center" },
  { label: "Fin", value: (row) => shortDate(row.end_date), width: 15, kind: "center" },
  { label: "Nivel SLA", value: (row) => (row.sla_level ? slaLabel(row.sla_level) : ""), width: 14, kind: "center" },
  { label: "Estado", value: (row) => stateOf(CONTRACT_STATES, row.status)[0], width: 14, kind: "status", tone: (row) => excelTone(stateOf(CONTRACT_STATES, row.status)[1]) },
];

const SERVICE_EXPORT: ExportColumn<PortalContractedService>[] = [
  { label: "Servicio", value: (row) => row.service_name, width: 34 },
  { label: "Contrato", value: (row) => row.contract_number, width: 18, kind: "center" },
  { label: "Estado", value: (row) => stateOf(CONTRACTED_STATES, row.status)[0], width: 14, kind: "status", tone: (row) => excelTone(stateOf(CONTRACTED_STATES, row.status)[1]) },
  { label: "Renovación", value: (row) => (row.renewal_date ? shortDate(row.renewal_date) : ""), width: 16, kind: "center" },
];

export function PortalServices() {
  const { can, toast } = usePortal();
  const [data, setData] = useState<{ contracts: PortalContract[]; services: PortalContractedService[] } | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [busy, setBusy] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    apiFetch<{ contracts: PortalContract[]; services: PortalContractedService[] }>("/v1/portal/services")
      .then((response) => { if (!cancelled) { setData(response); setError(""); } })
      .catch(() => { if (!cancelled) setError("No se pudieron cargar tus servicios."); });
    return () => { cancelled = true; };
  }, [reload]);

  async function renew(service: PortalContractedService) {
    setBusy(service.id);
    try {
      await apiFetch(`/v1/portal/services/${service.id}/renewal`, { method: "POST" });
      toast("Solicitud de renovación enviada");
      setReload((n) => n + 1);
    } catch (err) {
      toast(errorText(err, "No se pudo enviar la solicitud."), "danger");
    } finally {
      setBusy(null);
    }
  }

  const contractCols: Column<PortalContract>[] = [
    { label: "Contrato", render: (row) => <strong className="font-semibold">{row.contract_number}</strong> },
    { label: "Vigencia", render: (row) => `${shortDate(row.start_date)} → ${shortDate(row.end_date)}` },
    { label: "Nivel SLA", render: (row) => (row.sla_level ? <Chip>{slaLabel(row.sla_level)}</Chip> : "—") },
    { label: "Estado", render: (row) => { const [label, tone] = stateOf(CONTRACT_STATES, row.status); return <Badge tone={tone}>{label}</Badge>; } },
  ];

  const serviceCols: Column<PortalContractedService>[] = [
    {
      label: "Servicio",
      render: (row) => (
        <div className="min-w-[160px]">
          <strong className="font-semibold">{row.service_name}</strong>
          <small className="app-muted block text-xs">{row.contract_number}</small>
        </div>
      ),
    },
    { label: "Estado", render: (row) => { const [label, tone] = stateOf(CONTRACTED_STATES, row.status); return <Badge tone={tone}>{label}</Badge>; } },
    {
      label: "Renovación",
      render: (row) => (row.renewal_date ? (
        <span className="inline-flex flex-wrap items-center gap-1.5">{shortDate(row.renewal_date)}{row.renewal_soon && <Chip tone="warning">Próxima</Chip>}</span>
      ) : "—"),
    },
    {
      label: "",
      render: (row) => (row.recurring && can("requests") ? (
        <div className="flex sm:justify-end">
          {row.renewal_requested ? (
            <Chip tone="info">Renovación solicitada</Chip>
          ) : (
            <Btn variant="ghost" size="sm" disabled={!row.can_renew || busy === row.id} title={row.can_renew ? undefined : "Este servicio ya no se ofrece"} onClick={() => renew(row)}>
              Solicitar renovación
            </Btn>
          )}
        </div>
      ) : null),
    },
  ];

  return (
    <>
      <PortalTitle>Mis servicios</PortalTitle>
      {error ? <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card> : (
        <>
          <Card title="Contratos" flush>
            <div className="pt-2">
              <DataTable dense cols={contractCols} rows={data?.contracts ?? []} rowKey={(row) => row.id} loading={!data} empty="No tienes contratos." />
            </div>
            <PortalExport file="contratos" title="Contratos" columns={CONTRACT_EXPORT} rows={data?.contracts ?? []} />
          </Card>
          <Card title="Servicios activos" flush>
            <div className="pt-2">
              <DataTable dense cols={serviceCols} rows={data?.services ?? []} rowKey={(row) => row.id} loading={!data} empty="No tienes servicios contratados." />
            </div>
            <PortalExport file="servicios" title="Servicios" columns={SERVICE_EXPORT} rows={data?.services ?? []} />
          </Card>
        </>
      )}
    </>
  );
}

const REQUEST_EXPORT: ExportColumn<PortalRequestRow>[] = [
  { label: "Solicitud", value: (row) => row.request_number, width: 18, kind: "center" },
  { label: "Descripción", value: (row) => row.description ?? row.subject, width: 50 },
  { label: "Servicio", value: (row) => row.service_name ?? "", width: 30 },
  { label: "Fecha", value: (row) => shortDate(row.created_at), width: 15, kind: "center" },
  { label: "Estado", value: (row) => stateOf(PORTAL_REQUEST_STATES, row.status)[0], width: 14, kind: "status", tone: (row) => excelTone(stateOf(PORTAL_REQUEST_STATES, row.status)[1]) },
  { label: "Cotización", value: (row) => row.quotation?.quotation_number ?? "", width: 18, kind: "center" },
];

export function PortalRequests() {
  const { toast } = usePortal();
  const [rows, setRows] = useState<PortalRequestRow[] | null>(null);
  const [catalog, setCatalog] = useState<{ id: string; name: string; code: string; description: string | null }[]>([]);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [serviceId, setServiceId] = useState("");
  const [description, setDescription] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    let cancelled = false;
    apiFetch<PortalRequestRow[]>("/v1/portal/requests")
      .then((data) => { if (!cancelled) { setRows(data); setError(""); } })
      .catch(() => { if (!cancelled) setError("No se pudieron cargar tus solicitudes."); });
    return () => { cancelled = true; };
  }, [reload]);

  useEffect(() => {
    apiFetch<{ id: string; name: string; code: string; description: string | null }[]>("/v1/portal/catalog").then(setCatalog).catch(() => setCatalog([]));
  }, []);

  async function submit() {
    const next: Record<string, string> = {};
    if (!serviceId) next.service_id = "Selecciona el servicio.";
    if (!description.trim()) next.description = "Cuéntanos qué necesitas.";
    setErrors(next);
    if (Object.keys(next).length) return;
    setBusy(true);
    try {
      await apiFetch("/v1/portal/requests", { method: "POST", body: JSON.stringify({ service_id: serviceId, description: description.trim() }) });
      toast("Solicitud enviada: tu ejecutivo preparará una cotización");
      setServiceId("");
      setDescription("");
      setReload((n) => n + 1);
    } catch (err) {
      if (err instanceof ApiError && err.errors) setErrors(Object.fromEntries(Object.entries(err.errors).map(([key, list]) => [key, list[0]])));
      else toast(errorText(err, "No se pudo enviar la solicitud."), "danger");
    } finally {
      setBusy(false);
    }
  }

  const cols: Column<PortalRequestRow>[] = [
    {
      label: "Solicitud",
      render: (row) => (
        <div className="min-w-[180px] max-w-[380px]">
          <strong className="font-semibold">{row.request_number}</strong>
          <small className="app-muted block text-xs">{row.description || row.subject}</small>
        </div>
      ),
    },
    { label: "Servicio", render: (row) => row.service_name ?? "—" },
    { label: "Fecha", render: (row) => shortDate(row.created_at) },
    {
      label: "Estado",
      render: (row) => {
        const [label, tone] = stateOf(PORTAL_REQUEST_STATES, row.status);
        return (
          <div className="grid justify-items-start gap-1">
            <span className="inline-flex flex-wrap items-center gap-1.5">
              <Badge tone={tone}>{label}</Badge>
              {row.quotation && (
                <Link href={`/portal/quotations/${row.quotation.id}`} className="text-xs font-medium text-[color:var(--p-brand-text)] hover:underline">Ver cotización</Link>
              )}
            </span>
            {row.resolution_note && <small className="app-muted max-w-[240px] text-xs">{row.resolution_note}</small>}
          </div>
        );
      },
    },
  ];

  return (
    <>
      <PortalTitle>Solicitudes</PortalTitle>
      <Card title="Solicitar un servicio">
        <div className="grid gap-3">
          <Field label="Servicio" required error={errors.service_id}>
            <SearchSelect
              value={serviceId}
              onChange={setServiceId}
              placeholder="Selecciona un servicio"
              options={catalog.map((service) => ({ value: service.id, label: service.name, hint: service.code }))}
            />
          </Field>
          <Field label="Cuéntanos qué necesitas" required error={errors.description}>
            <textarea className={INPUT} rows={3} value={description} onChange={(e) => setDescription(e.target.value)} maxLength={2000} />
          </Field>
          <p className="app-muted text-xs">Tu ejecutivo te enviará una cotización.</p>
          <div className="flex justify-end">
            <Btn onClick={submit} disabled={busy}>{busy ? "Enviando…" : "Enviar solicitud"}</Btn>
          </div>
        </div>
      </Card>
      <Card title="Mis solicitudes" flush>
        {error ? <LoadError text={error} onRetry={() => setReload((n) => n + 1)} /> : (
          <>
            <div className="pt-2">
              <DataTable cols={cols} rows={rows ?? []} rowKey={(row) => row.id} loading={!rows} empty="Aún no tienes solicitudes." />
            </div>
            <PortalExport file="solicitudes" title="Solicitudes" columns={REQUEST_EXPORT} rows={rows ?? []} />
          </>
        )}
      </Card>
    </>
  );
}
