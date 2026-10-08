"use client";

import { useEffect, useState, type FormEvent } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { ArrowLeft, Plus } from "lucide-react";
import { ApiError, apiFetch } from "@/lib/api";
import {
  BILLING_MODES, FREQUENCIES, QUOTATION_STATES, SERVICE_STATES, historyLabel, isoDateFromNow, money, shortDate, stateOf,
} from "@/lib/commercial";
import {
  Badge, Btn, Card, Chip, DataTable, Field, INPUT, Kv, LoadError, Modal, PageHead, Screen, StateTrack, Timeline,
  useToasts, type Column,
} from "@/components/ui/proto";
import { SortableBoard } from "@/components/ui/sortable-cards";
import ServiceFormModal from "./ServiceFormModal";
import type { Service, ServiceCategory, ServicePrice, ServiceStatus } from "@/types/commercial";

type Transition = { path: string; label: string; done: string; danger?: boolean };

// Transiciones posibles desde cada estado, en el orden en que se muestran.
const TRANSITIONS: Record<ServiceStatus, Transition[]> = {
  DRAFT: [{ path: "activate", label: "Activar", done: "Activar: listo" }],
  ACTIVE: [{ path: "deactivate", label: "Inactivar", done: "Inactivar: listo", danger: true }],
  INACTIVE: [
    { path: "activate", label: "Reactivar", done: "Reactivar: listo" },
    { path: "archive", label: "Archivar", done: "Archivar: listo", danger: true },
  ],
  ARCHIVED: [],
};

function firstError(err: unknown, fallback: string) {
  if (err instanceof ApiError) return (err.errors && Object.values(err.errors)[0]?.[0]) || err.message;
  return fallback;
}

function PriceModal({ serviceId, onClose, onSaved }: { serviceId: string; onClose: () => void; onSaved: () => void }) {
  const [amount, setAmount] = useState("");
  const [validFrom, setValidFrom] = useState(isoDateFromNow(0));
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (!(Number(amount) > 0)) {
      setError("Precio inválido.");
      return;
    }

    setSaving(true);
    setError("");
    try {
      await apiFetch(`/v1/services/${serviceId}/prices`, {
        method: "POST",
        body: JSON.stringify({ amount: Number(amount), valid_from: validFrom || null }),
      });
      onSaved();
    } catch (err) {
      setError(firstError(err, "No se pudo crear la versión de precio."));
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal title="Nueva versión de precio" size="sm" onClose={onClose}>
      <form onSubmit={submit}>
        <div className="grid gap-3">
          <Field label="Nuevo precio (S/, sin IGV)" required>
            <input className={INPUT} type="number" min="0" step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} required autoFocus />
          </Field>
          <Field label="Vigente desde">
            <input className={INPUT} type="date" value={validFrom} onChange={(e) => setValidFrom(e.target.value)} />
          </Field>
        </div>
        <p className="app-muted mt-3 text-[12.5px]">
          La versión vigente se cierra con fecha de fin. Las cotizaciones y contratos ya emitidos conservan su snapshot.
        </p>
        {error && <p className="mt-2 text-[12.5px] text-[color:var(--p-danger-text)]">{error}</p>}
        <div className="mt-4 flex justify-end gap-2">
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn type="submit" disabled={saving}>{saving ? "Creando…" : "Crear versión"}</Btn>
        </div>
      </form>
    </Modal>
  );
}

export default function ServiceDetail() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const { toast, toasts } = useToasts();

  const [service, setService] = useState<Service | null>(null);
  const [categories, setCategories] = useState<ServiceCategory[]>([]);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [pricing, setPricing] = useState(false);
  const [editing, setEditing] = useState(false);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    let cancelled = false;

    async function load() {
      try {
        const data = await apiFetch<Service>(`/v1/services/${id}`);
        if (!cancelled) {
          setService(data);
          setError("");
        }
      } catch (err) {
        if (!cancelled) setError(err instanceof ApiError && err.status === 404 ? "Servicio no encontrado." : "No se pudo cargar el servicio.");
      }
    }
    load();
    return () => { cancelled = true; };
  }, [id, reload]);

  async function openEditor() {
    try {
      setCategories(await apiFetch<ServiceCategory[]>("/v1/service-categories"));
      setEditing(true);
    } catch {
      toast("No se pudieron cargar las categorías.", "danger");
    }
  }

  async function run(transition: Transition) {
    setBusy(true);
    try {
      await apiFetch(`/v1/services/${id}/${transition.path}`, { method: "POST" });
      toast(transition.done);
      setReload((n) => n + 1);
    } catch (err) {
      toast(firstError(err, "No se pudo cambiar el estado del servicio."), "danger");
    } finally {
      setBusy(false);
    }
  }

  const back = (
    <Link href="/services" className="mb-3 inline-flex items-center gap-1.5 text-[13px] font-medium text-[color:var(--p-brand-text)] hover:underline">
      <ArrowLeft size={15} /> Catálogo de servicios
    </Link>
  );

  if (error) {
    return (
      <Screen>
        {back}
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
      </Screen>
    );
  }

  if (!service) {
    return (
      <Screen>
        {back}
        <Card><div className="h-40 animate-pulse rounded bg-[var(--app-surface-muted)]" aria-busy="true" /></Card>
      </Screen>
    );
  }

  const prices = service.prices ?? [];
  const usage = service.quotations ?? [];
  const history = service.history ?? [];
  const archived = service.status === "ARCHIVED";
  const transitions = TRANSITIONS[service.status] ?? [];
  const visited = history.flatMap((entry) => (entry.metadata.to ? [entry.metadata.to] : [])).concat("DRAFT");

  const priceColumns: Column<ServicePrice>[] = [
    // La más antigua es la versión 1.
    { label: "Versión", render: (price) => `v${prices.length - prices.indexOf(price)}` },
    { label: "Monto", num: true, render: (price) => money(price.amount, price.currency) },
    { label: "Desde", render: (price) => shortDate(price.valid_from) },
    { label: "Hasta", render: (price) => shortDate(price.valid_to) },
    {
      label: "Estado",
      render: (price) => <Chip tone={price.active ? "success" : "muted"}>{price.active ? "Vigente" : "Vencida"}</Chip>,
    },
  ];

  const usageColumns: Column<NonNullable<Service["quotations"]>[number]>[] = [
    { label: "Cotización", render: (row) => row.quotation_number },
    { label: "Precio snapshot", num: true, render: (row) => money(row.unit_price, row.currency) },
    {
      label: "Estado",
      render: (row) => {
        const [label, tone] = stateOf(QUOTATION_STATES, row.status);
        return <Badge tone={tone}>{label}</Badge>;
      },
    },
  ];

  return (
    <Screen>
      {back}
      <PageHead
        showTitle
        title={service.name}
        sub={[service.code, service.category?.name, BILLING_MODES[service.billing_mode]].filter(Boolean).join(" · ")}
        actions={
          <>
            <Btn variant="secondary" onClick={openEditor} permission="services.manage" disabled={archived} title={archived ? "Un servicio archivado no se edita" : undefined}>
              Editar
            </Btn>
            <Btn onClick={() => setPricing(true)} permission="services.manage" disabled={archived} title={archived ? "Un servicio archivado no cambia de precio" : undefined}>
              <Plus size={16} />
              <span>Nueva versión de precio</span>
            </Btn>
          </>
        }
      />

      <SortableBoard
        storageKey="erp.cards.service-detail"
        zones={[{ className: "grid grid-cols-1 items-start gap-x-4 lg:grid-cols-2", cards: ["lifecycle", "config", "prices", "usage"] }]}
        cards={[
          {
            id: "lifecycle",
            node: (
        <Card title="Ciclo de vida">
          <div className="grid gap-2.5">
            <StateTrack states={SERVICE_STATES} current={service.status} visited={visited} />
            {transitions.length ? (
              <div className="flex flex-wrap gap-1.5">
                {transitions.map((transition) => (
                  <Btn
                    key={transition.path}
                    variant={transition.danger ? "danger-ghost" : "secondary"}
                    onClick={() => run(transition)}
                    permission="services.manage"
                    disabled={busy}
                  >
                    {transition.label}
                  </Btn>
                ))}
              </div>
            ) : (
              <p className="app-muted text-[12.5px]">Sin transiciones disponibles desde este estado.</p>
            )}
          </div>
          <Timeline
            items={history.map((entry, index) => ({
              key: `${entry.at}-${index}`,
              head: entry.metadata.to ? (
                <>
                  <Badge tone={stateOf(SERVICE_STATES, entry.metadata.to)[1]}>{stateOf(SERVICE_STATES, entry.metadata.to)[0]}</Badge>
                  {entry.metadata.from && (
                    <span className="app-muted text-[12.5px]">desde {stateOf(SERVICE_STATES, entry.metadata.from)[0]}</span>
                  )}
                </>
              ) : (
                <span className="font-medium">{historyLabel(entry)}</span>
              ),
              meta: `${entry.user ?? "Sistema"} · ${new Date(entry.at).toLocaleString("es-PE", { dateStyle: "medium", timeStyle: "short" })}`,
              note: entry.action === "service.price.create" && entry.metadata.amount ? money(entry.metadata.amount) : entry.metadata.note,
            }))}
          />
        </Card>
            ),
          },
          {
            id: "config",
            node: (
        <Card title="Configuración">
          <Kv
            rows={[
              ["Unidad", service.unit || "—"],
              ["Modalidad", BILLING_MODES[service.billing_mode]],
              ["Recurrencia", service.recurrence ? `Cada ${service.recurrence.interval_value} · ${FREQUENCIES[service.recurrence.frequency]}` : "No recurrente"],
              ["Garantía", service.warranty_months ? `${service.warranty_months} meses` : "Sin garantía"],
              ["Afecto a IGV", "Sí (18%)"],
              ["Descripción", service.description || "—"],
            ]}
          />
        </Card>
            ),
          },
          {
            id: "prices",
            className: "col-span-full",
            node: (
      <Card title="Versiones de precio (historial, no se sobrescribe)" flush>
        <div className="pt-3 sm:px-1.5 sm:pb-2.5">
          <DataTable cols={priceColumns} rows={prices} rowKey={(price) => price.id} dense empty="Este servicio aún no tiene precio." />
        </div>
      </Card>
            ),
          },
          {
            id: "usage",
            className: "col-span-full",
            node: (
      <Card title={`Usado en ${usage.length} cotización(es)`} flush={usage.length > 0}>
        {usage.length ? (
          <>
            <p className="app-muted px-5 pt-3 text-[12.5px]">
              Cada cotización guarda el precio que tenía el servicio al cotizar (snapshot).
            </p>
            <div className="pt-1 sm:px-1.5 sm:pb-2.5">
              <DataTable
                cols={usageColumns}
                rows={usage}
                rowKey={(row) => `${row.quotation_id}-${usage.indexOf(row)}`}
                onRowClick={(row) => router.push(`/quotations/${row.quotation_id}`)}
                dense
              />
            </div>
          </>
        ) : (
          <p className="app-muted">Aún no se cotiza.</p>
        )}
      </Card>
            ),
          },
        ]}
      />

      {editing && (
        <ServiceFormModal
          service={service}
          categories={categories}
          onClose={() => setEditing(false)}
          onCategoryCreated={(created) => setCategories((list) => [...list, created])}
          onSaved={() => {
            setEditing(false);
            toast("Servicio guardado");
            setReload((n) => n + 1);
          }}
        />
      )}
      {pricing && (
        <PriceModal
          serviceId={service.id}
          onClose={() => setPricing(false)}
          onSaved={() => {
            setPricing(false);
            toast("Nueva versión de precio vigente");
            setReload((n) => n + 1);
          }}
        />
      )}
      {toasts}
    </Screen>
  );
}
