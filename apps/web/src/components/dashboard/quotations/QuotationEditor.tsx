"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { ArrowLeft, Plus, X } from "lucide-react";
import { ApiError, apiFetch } from "@/lib/api";
import { FREQUENCIES, IGV_RATE, customerDocument, customerName, isoDateFromNow, money, setFlash } from "@/lib/commercial";
import { Btn, Card, Field, FormGrid, INPUT, Kv, LoadError, PageHead, Screen, TD, TH, useToasts } from "@/components/ui/proto";
import type { Customer, PaginatedResponse } from "@/types/customer";
import type { Frequency, Quotation, Service } from "@/types/commercial";

// Días de validez con los que nace una cotización.
const DEFAULT_VALIDITY_DAYS = 15;
// Tope de clientes y servicios que se cargan en los selectores.
const SELECT_LIMIT = 200;

type Line = {
  key: number;
  service_id: string | null;
  name: string;
  unit: string | null;
  frequency: Frequency | null;
  quantity: number;
  unit_price: number;
  discount_pct: number;
};

const round2 = (value: number) => Math.round(value * 100) / 100;

/** Mismo cálculo que hace la API al guardar: (cantidad × precio − descuento) + IGV. */
function lineAmounts(line: Line) {
  const gross = line.quantity * line.unit_price;
  const discount = round2((gross * line.discount_pct) / 100);
  const base = gross - discount;
  const tax = round2((base * IGV_RATE) / 100);
  return { gross, discount, base, tax, total: base + tax };
}

const NUM_INPUT = "app-surface app-text h-8 w-[76px] rounded-md border border-[#CFD5DE] px-2 text-right outline-none focus:border-[#1B8FC9]";

/** Nueva cotización, o edición de una en borrador cuando la ruta trae su id. */
export default function QuotationEditor() {
  const { id } = useParams<{ id?: string }>();
  const router = useRouter();
  const { toast, toasts } = useToasts();

  const [customers, setCustomers] = useState<Customer[]>([]);
  const [services, setServices] = useState<Service[]>([]);
  const [loadError, setLoadError] = useState("");
  const [reload, setReload] = useState(0);
  const [loaded, setLoaded] = useState(false);

  const [customerId, setCustomerId] = useState("");
  const [validUntil, setValidUntil] = useState(isoDateFromNow(DEFAULT_VALIDITY_DAYS));
  const [terms, setTerms] = useState("Precios en soles incluyen IGV. Pago a 30 días.");
  const [lines, setLines] = useState<Line[]>([]);
  const [serviceToAdd, setServiceToAdd] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState<"draft" | "submit" | null>(null);

  useEffect(() => {
    let cancelled = false;

    async function load() {
      try {
        const [customerPage, servicePage, quotation] = await Promise.all([
          apiFetch<PaginatedResponse<Customer>>(`/v1/customers?status=ACTIVE&per_page=${SELECT_LIMIT}`),
          apiFetch<PaginatedResponse<Service>>(`/v1/services?per_page=${SELECT_LIMIT}`),
          id ? apiFetch<Quotation>(`/v1/quotations/${id}`) : Promise.resolve(null),
        ]);
        if (cancelled) return;

        if (quotation && quotation.status !== "DRAFT") {
          setLoadError("Solo las cotizaciones en BORRADOR se pueden editar. Crea una nueva versión.");
          return;
        }

        const customerList = [...customerPage.data];
        if (quotation) {
          // El cliente de la cotización puede no venir en la lista (p. ej. más de 200 clientes).
          if (quotation.customer && !customerList.some((customer) => customer.id === quotation.customer_id)) {
            customerList.push(quotation.customer);
          }
          setCustomerId(quotation.customer_id);
          setValidUntil(quotation.valid_until ? quotation.valid_until.slice(0, 10) : "");
          setTerms(quotation.notes ?? "");
          setLines(
            quotation.items.map((item, index) => {
              const gross = Number(item.quantity) * Number(item.unit_price);
              return {
                key: index + 1,
                service_id: item.service_id,
                name: item.description,
                unit: item.service_snapshot?.unit ?? item.service?.unit ?? null,
                frequency: item.service_snapshot?.frequency ?? null,
                quantity: Number(item.quantity),
                unit_price: Number(item.unit_price),
                discount_pct: gross > 0 ? round2((Number(item.discount) / gross) * 100) : 0,
              };
            }),
          );
        }

        setCustomers(customerList.sort((a, b) => customerName(a).localeCompare(customerName(b))));
        setServices(servicePage.data);
        setLoadError("");
        setLoaded(true);
      } catch (err) {
        if (!cancelled) {
          setLoadError(
            err instanceof ApiError && err.status === 404 ? "Cotización no encontrada." : "No se pudieron cargar los clientes y servicios.",
          );
        }
      }
    }
    load();
    return () => { cancelled = true; };
  }, [id, reload]);

  function addLine() {
    const service = services.find((item) => item.id === serviceToAdd);
    if (!service?.current_price) {
      toast("Selecciona un servicio", "warning");
      return;
    }
    setLines((current) => [
      ...current,
      {
        key: Date.now(),
        service_id: service.id,
        name: service.name,
        unit: service.unit,
        frequency: service.recurrence?.frequency ?? null,
        quantity: 1,
        // Snapshot: se copia el precio vigente al agregar la línea.
        unit_price: Number(service.current_price!.amount),
        discount_pct: 0,
      },
    ]);
    setServiceToAdd("");
  }

  function updateLine(key: number, field: "quantity" | "discount_pct", raw: string) {
    const value = Number(raw);
    if (!Number.isFinite(value)) return;
    setLines((current) =>
      current.map((line) => {
        if (line.key !== key) return line;
        return field === "quantity"
          ? { ...line, quantity: Math.max(0, value) }
          : { ...line, discount_pct: Math.min(100, Math.max(0, value)) };
      }),
    );
  }

  const totals = lines.reduce(
    (sum, line) => {
      const amounts = lineAmounts(line);
      return {
        subtotal: sum.subtotal + amounts.gross,
        discount: sum.discount + amounts.discount,
        base: sum.base + amounts.base,
        tax: sum.tax + amounts.tax,
        total: sum.total + amounts.total,
      };
    },
    { subtotal: 0, discount: 0, base: 0, tax: 0, total: 0 },
  );

  async function save(submit: boolean) {
    const found: Record<string, string> = {};
    if (!customerId) found.customer_id = "Selecciona un cliente.";
    if (!lines.length) found.items = "Agrega al menos una línea.";
    if (lines.some((line) => !(line.quantity > 0))) found.items = "Cantidad inválida en una línea.";
    setErrors(found);
    if (Object.keys(found).length) return;

    setSaving(submit ? "submit" : "draft");
    let saved: Quotation;
    try {
      saved = await apiFetch<Quotation>(id ? `/v1/quotations/${id}` : "/v1/quotations", {
        method: id ? "PUT" : "POST",
        body: JSON.stringify({
          customer_id: customerId,
          currency: "PEN",
          valid_until: validUntil || null,
          notes: terms.trim() || null,
          items: lines.map((line) => ({
            service_id: line.service_id,
            description: line.name,
            quantity: line.quantity,
            unit_price: line.unit_price,
            discount: lineAmounts(line).discount,
            tax_rate: IGV_RATE,
          })),
        }),
      });
    } catch (err) {
      if (err instanceof ApiError && err.errors) {
        const [field, messages] = Object.entries(err.errors)[0];
        if (field === "customer_id" || field.startsWith("items")) {
          setErrors({ [field.startsWith("items") ? "items" : field]: messages[0] });
        } else {
          toast(messages[0], "danger");
        }
      } else {
        toast(err instanceof ApiError ? err.message : "No se pudo guardar la cotización.", "danger");
      }
      setSaving(null);
      return;
    }

    if (submit) {
      try {
        await apiFetch(`/v1/quotations/${saved.id}/submit`, { method: "POST" });
        setFlash("Enviada a aprobación");
      } catch {
        // Ya quedó guardada: se abre en borrador para reintentar el envío desde su ficha.
        setFlash("Cotización guardada en borrador: no se pudo enviar a aprobación");
      }
    } else {
      setFlash("Cotización guardada");
    }
    router.push(`/quotations/${saved.id}`);
  }

  const back = (
    <Link
      href={id ? `/quotations/${id}` : "/quotations"}
      className="mb-3 inline-flex items-center gap-1.5 text-[13px] font-medium text-[#1679AB] hover:underline"
    >
      <ArrowLeft size={15} /> {id ? "Volver a la cotización" : "Cotizaciones"}
    </Link>
  );

  if (loadError) {
    return (
      <Screen>
        {back}
        <Card><LoadError text={loadError} onRetry={() => setReload((n) => n + 1)} /></Card>
      </Screen>
    );
  }

  return (
    <Screen>
      {back}
      <PageHead showTitle title={id ? "Editar cotización" : "Nueva cotización"} />

      <div className="grid grid-cols-1 items-start gap-x-4 lg:grid-cols-[minmax(0,1fr)_300px]">
        <div className="min-w-0">
          <Card title="Cabecera">
            <FormGrid>
              <Field
                label="Cliente"
                required
                span={2}
                error={errors.customer_id}
                hint={<>Solo clientes activos. <Link href="/customers" className="text-[#1679AB] hover:underline">¿No existe? Créalo en Clientes</Link></>}
              >
                <select className={INPUT} value={customerId} onChange={(e) => setCustomerId(e.target.value)} disabled={!loaded}>
                  <option value="">{loaded ? "Seleccionar…" : "Cargando…"}</option>
                  {customers.map((customer) => (
                    <option key={customer.id} value={customer.id}>
                      {customerName(customer)} · {customerDocument(customer)}
                    </option>
                  ))}
                </select>
              </Field>
              <Field label="Válida hasta" error={errors.valid_until}>
                <input className={INPUT} type="date" value={validUntil} onChange={(e) => setValidUntil(e.target.value)} />
              </Field>
              <Field label="Moneda" hint="Moneda funcional">
                <select className={INPUT} value="PEN" disabled>
                  <option value="PEN">Soles (PEN)</option>
                </select>
              </Field>
              <Field label="Condiciones" span={3}>
                <textarea className={INPUT} rows={2} value={terms} onChange={(e) => setTerms(e.target.value)} />
              </Field>
            </FormGrid>
          </Card>

          <Card title="Líneas">
            <div className="-mx-5 overflow-x-auto">
              <table className="w-full border-collapse text-[13px]">
                <thead>
                  <tr>
                    <th className={TH}>Servicio</th>
                    <th className={`${TH} text-right`}>Cant.</th>
                    <th className={`${TH} text-right`}>Precio unit. (snapshot)</th>
                    <th className={`${TH} text-right`}>Desc. %</th>
                    <th className={`${TH} text-right`}>IGV</th>
                    <th className={`${TH} text-right`}>Total</th>
                    <th className={TH} />
                  </tr>
                </thead>
                <tbody>
                  {lines.length ? (
                    lines.map((line) => {
                      const amounts = lineAmounts(line);
                      const catalog = services.find((service) => service.id === line.service_id)?.current_price;
                      const priceMoved = catalog && Number(catalog.amount) !== line.unit_price;
                      return (
                        <tr key={line.key}>
                          <td className={TD}>
                            <strong className="font-semibold">{line.name}</strong>
                            <small className="app-muted block text-xs">
                              {[line.unit, line.frequency && `recurrente ${FREQUENCIES[line.frequency]}`].filter(Boolean).join(" · ")}
                              {priceMoved && (
                                <span className="text-[#B26E00]">
                                  {line.unit || line.frequency ? " · " : ""}precio vigente hoy {money(catalog.amount)}
                                </span>
                              )}
                            </small>
                          </td>
                          <td className={`${TD} text-right`}>
                            <input
                              className={NUM_INPUT}
                              type="number"
                              min="1"
                              step="1"
                              value={line.quantity}
                              onChange={(e) => updateLine(line.key, "quantity", e.target.value)}
                              aria-label="Cantidad"
                            />
                          </td>
                          <td className={`${TD} whitespace-nowrap text-right tabular-nums`}>{money(line.unit_price)}</td>
                          <td className={`${TD} text-right`}>
                            <input
                              className={NUM_INPUT}
                              type="number"
                              min="0"
                              max="100"
                              step="0.5"
                              value={line.discount_pct}
                              onChange={(e) => updateLine(line.key, "discount_pct", e.target.value)}
                              aria-label="Descuento"
                            />
                          </td>
                          <td className={`${TD} whitespace-nowrap text-right tabular-nums`}>{money(amounts.tax)}</td>
                          <td className={`${TD} whitespace-nowrap text-right tabular-nums`}><strong>{money(amounts.total)}</strong></td>
                          <td className={TD}>
                            <button
                              type="button"
                              onClick={() => setLines((current) => current.filter((item) => item.key !== line.key))}
                              aria-label="Quitar línea"
                              className="app-muted grid h-9 w-9 cursor-pointer place-items-center rounded-[10px] hover:bg-[var(--app-surface-muted)]"
                            >
                              <X size={16} />
                            </button>
                          </td>
                        </tr>
                      );
                    })
                  ) : (
                    <tr>
                      <td colSpan={7} className={`${TD} app-muted border-b-0`}>Agrega al menos una línea para poder enviar.</td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
            {errors.items && <p className="mt-2 text-[12.5px] text-[#D93A40]">{errors.items}</p>}

            <div className="mt-3 flex flex-wrap items-end gap-2">
              <div className="flex-[1_1_260px]">
                <Field label="Agregar servicio">
                  <select className={INPUT} value={serviceToAdd} onChange={(e) => setServiceToAdd(e.target.value)} disabled={!loaded}>
                    <option value="">{loaded ? "Seleccionar servicio…" : "Cargando…"}</option>
                    {services.filter((service) => service.status !== "ARCHIVED").map((service) => {
                      const sellable = service.status === "ACTIVE" && service.current_price !== null;
                      const reason = service.status !== "ACTIVE" ? " (no activo: no vendible)" : !service.current_price ? " (no vendible)" : "";
                      return (
                        <option key={service.id} value={service.id} disabled={!sellable}>
                          {service.name} — {service.current_price ? money(service.current_price.amount) : "sin precio"}{reason}
                        </option>
                      );
                    })}
                  </select>
                </Field>
              </div>
              <Btn variant="secondary" onClick={addLine}>
                <Plus size={16} />
                <span>Agregar</span>
              </Btn>
            </div>
          </Card>
        </div>

        <Card className="lg:sticky lg:top-0">
          <Kv
            rows={[
              ["Subtotal", money(totals.subtotal)],
              ["Descuentos", `− ${money(totals.discount)}`],
              ["Base imponible", money(totals.base)],
              [`IGV ${IGV_RATE}%`, money(totals.tax)],
            ]}
          />
          <div className="flex items-baseline justify-between pb-3.5 pt-2.5">
            <span>Total</span>
            <strong className="text-[26px] font-semibold tabular-nums">{money(totals.total)}</strong>
          </div>
          <div className="grid gap-2">
            <Btn block onClick={() => save(false)} disabled={saving !== null || !loaded}>
              {saving === "draft" ? "Guardando…" : "Guardar borrador"}
            </Btn>
            <Btn
              variant="secondary"
              block
              onClick={() => save(true)}
              disabled={saving !== null || !loaded || !lines.length}
              title={lines.length ? undefined : "Necesita al menos una línea"}
            >
              {saving === "submit" ? "Enviando…" : "Guardar y enviar a aprobación"}
            </Btn>
          </div>
          <p className="app-muted mt-3 text-[12.5px]">
            Los precios se copian del precio vigente del catálogo al agregar la línea. Un cambio posterior de catálogo no altera esta cotización.
          </p>
        </Card>
      </div>
      {toasts}
    </Screen>
  );
}
