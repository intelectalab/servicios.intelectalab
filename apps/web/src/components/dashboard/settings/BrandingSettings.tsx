"use client";

import { useEffect, useRef, useState, type FormEvent } from "react";
import { FileDown, ImageUp, Lock, Trash2 } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { useAccess } from "@/lib/access-context";
import { fieldErrors, firstError } from "@/lib/crm";
import { DEFAULT_PRIMARY, contrast, hexToRgb, readableOnWhite, rgbToHex, setBranding, textOn, tint, type Branding } from "@/lib/branding";
import { downloadDocumentPdf } from "@/lib/document-pdf";
import { Btn, Card, Chip, Field, FormGrid, INPUT, LoadError, Modal, Notice, PageHead, Screen, btnClass, deniedText, useToasts } from "@/components/ui/proto";

type Form = { primary_color: string; secondary_color: string; address: string; phone: string; email: string; website: string };

const toForm = (branding: Branding): Form => ({
  primary_color: branding.primary_color,
  secondary_color: branding.secondary_color ?? "",
  address: branding.address ?? "",
  phone: branding.phone ?? "",
  email: branding.email ?? "",
  website: branding.website ?? "",
});

const HEX = /^#[0-9A-F]{6}$/i;
const css = (rgb: [number, number, number]) => `rgb(${rgb.join(" ")})`;

/** Muestra de color que se elige con un clic. */
function Swatch({ hex, selected, disabled, onPick }: { hex: string; selected: boolean; disabled: boolean; onPick: () => void }) {
  return (
    <button
      type="button"
      onClick={onPick}
      disabled={disabled}
      title={hex}
      aria-pressed={selected}
      aria-label={`Usar ${hex}`}
      className={`grid h-7 w-7 place-items-center rounded-md border-2 transition-transform disabled:cursor-not-allowed ${selected ? "scale-105 border-[color:var(--app-text)]" : "border-transparent hover:scale-105"}`}
      style={{ background: hex }}
    />
  );
}

/** Color con su selector y su código; las muestras del logo se eligen con un clic. */
function ColorField({
  label, value, palette, optional, disabled, error, onChange,
}: { label: string; value: string; palette: string[]; optional?: boolean; disabled: boolean; error?: string; onChange: (hex: string) => void }) {
  return (
    <Field label={label} error={error}>
      <div className="grid gap-2">
        <div className="flex items-center gap-2">
          <input
            type="color"
            aria-label={`${label}: selector`}
            value={HEX.test(value) ? value : DEFAULT_PRIMARY}
            disabled={disabled}
            onChange={(e) => onChange(e.target.value.toUpperCase())}
            className="h-10 w-12 cursor-pointer rounded-[9px] border border-[color:var(--p-field-border)] bg-[var(--app-surface)] p-1 disabled:cursor-not-allowed"
          />
          <input
            className={`${INPUT} max-w-[130px] font-mono uppercase`}
            value={value}
            maxLength={7}
            placeholder={optional ? "Sin color" : "#1B8FC9"}
            disabled={disabled}
            onChange={(e) => onChange(e.target.value.toUpperCase())}
            aria-label={`${label}: código`}
          />
          {optional && value && !disabled && (
            <button type="button" onClick={() => onChange("")} className="app-muted cursor-pointer text-xs underline-offset-2 hover:underline">Quitar</button>
          )}
        </div>
        {palette.length > 0 && (
          <div className="flex flex-wrap items-center gap-1.5">
            <small className="app-muted mr-1 text-xs">Del logo:</small>
            {palette.map((hex) => <Swatch key={hex} hex={hex} selected={hex === value} disabled={disabled} onPick={() => onChange(hex)} />)}
          </div>
        )}
      </div>
    </Field>
  );
}

/** Cómo se verá la cabecera de un documento con estos datos (antes de guardarlos). */
function DocumentPreview({ branding, form }: { branding: Branding; form: Form }) {
  const primary = HEX.test(form.primary_color) ? form.primary_color : DEFAULT_PRIMARY;
  const secondary = HEX.test(form.secondary_color) ? form.secondary_color : null;
  const accent = css(readableOnWhite(primary));
  const initials = branding.name.split(" ").filter(Boolean).slice(0, 2).map((word) => word[0]).join("").toUpperCase();
  const contact = [form.phone, form.email, form.website].filter(Boolean).join(" · ");
  return (
    <div className="overflow-hidden rounded-lg border border-[color:var(--app-border)] bg-[#fff] text-[#1D1D1B] shadow-[0_2px_10px_rgba(8,25,55,.08)]" aria-label="Vista previa del documento">
      <div style={{ background: primary, height: 7 }} />
      {secondary && <div style={{ background: secondary, height: 2 }} />}
      <div className="grid grid-cols-[minmax(0,1fr)] gap-3 p-4 text-[11px]">
        <div className="flex items-start justify-between gap-3">
          <div className="flex min-w-0 items-center gap-2.5">
            {branding.logo ? (
              // eslint-disable-next-line @next/next/no-img-element -- data URL del logo limpio, sin optimización de Next.
              <img src={branding.logo} alt={`Logo de ${branding.name}`} className="max-h-10 max-w-[110px] object-contain" />
            ) : (
              <span className="grid h-9 w-9 shrink-0 place-items-center rounded-md text-xs font-bold" style={{ background: primary, color: css(textOn(primary)) }}>{initials}</span>
            )}
            <div className="min-w-0">
              <strong className="block truncate text-[12px]">{branding.name}</strong>
              <span className="block text-[#6E7887]">{[branding.tax_identifier ? `RUC ${branding.tax_identifier}` : null, form.address].filter(Boolean).join(" · ")}</span>
            </div>
          </div>
          <div className="shrink-0 text-right">
            <strong className="block text-[14px]" style={{ color: accent }}>COTIZACIÓN</strong>
            <span className="font-semibold">N.º COT-2026-0001</span>
          </div>
        </div>
        <div className="rounded-md py-2 pl-3 pr-2" style={{ background: css(tint(primary, 0.07)), borderLeft: `3px solid ${primary}` }}>
          <span className="block text-[9px] font-bold uppercase" style={{ color: accent }}>Cliente</span>
          Minera Los Andes S.A.C.
        </div>
        <div className="overflow-hidden rounded-md">
          <div className="grid grid-cols-[1fr_auto] px-2 py-1.5 font-bold" style={{ background: primary, color: css(textOn(primary)) }}>
            <span>Descripción</span><span>Total</span>
          </div>
          <div className="grid grid-cols-[1fr_auto] px-2 py-1.5"><span>Mantenimiento preventivo</span><span>760.00</span></div>
          <div className="grid grid-cols-[1fr_auto] px-2 py-1.5" style={{ background: css(tint(primary, 0.035)) }}><span>Visita técnica</span><span>136.80</span></div>
        </div>
        <div className="ml-auto flex w-full max-w-[220px] justify-between gap-3 rounded px-2 py-1.5 font-bold" style={{ background: primary, color: css(textOn(primary)) }}>
          <span>TOTAL</span><span>S/ 896.80</span>
        </div>
        <div className="border-t pt-1.5" style={{ borderColor: primary }}>
          <strong className="block text-[10px]">{branding.name}{branding.tax_identifier ? ` · RUC ${branding.tax_identifier}` : ""}</strong>
          {contact && <span className="text-[10px] text-[#6E7887]">{contact}</span>}
        </div>
      </div>
    </div>
  );
}

/** Configuración → Marca de la empresa: logo, colores y datos del pie de sus documentos. */
export default function BrandingSettings() {
  const { toast, toasts } = useToasts();
  const { can, loading: accessLoading } = useAccess();
  const canManage = !accessLoading && can("tenant.manage");
  const fileRef = useRef<HTMLInputElement>(null);
  const [branding, setData] = useState<Branding | null>(null);
  const [form, setForm] = useState<Form | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [busy, setBusy] = useState<"logo" | "save" | "remove" | "pdf" | null>(null);
  const [previewUrl, setPreviewUrl] = useState<string | null>(null);

  function closePreview() {
    if (previewUrl) URL.revokeObjectURL(previewUrl);
    setPreviewUrl(null);
  }

  useEffect(() => {
    let cancelled = false;
    async function load() {
      try {
        const data = await apiFetch<Branding>("/v1/branding");
        if (cancelled) return;
        setData(data);
        setForm(toForm(data));
        setError("");
      } catch {
        if (!cancelled) setError("No se pudo cargar la marca de la empresa.");
      }
    }
    load();
    return () => { cancelled = true; };
  }, [reload]);

  function applied(data: Branding, message: string) {
    setData(data);
    setForm(toForm(data));
    setErrors({});
    setBranding(data);
    toast(message);
  }

  async function upload(file: File | undefined) {
    if (!file) return;
    if (file.size > 2 * 1024 * 1024) {
      toast("El logo no puede pesar más de 2 MB.", "danger");
      return;
    }
    setBusy("logo");
    try {
      const body = new FormData();
      body.append("logo", file);
      const data = await apiFetch<Branding>("/v1/branding/logo", { method: "POST", body });
      applied(data, data.palette.length ? "Logo guardado: elegimos sus colores principales" : "Logo guardado");
    } catch (err) {
      toast(firstError(err, "No se pudo subir el logo."), "danger");
    } finally {
      setBusy(null);
      if (fileRef.current) fileRef.current.value = "";
    }
  }

  async function removeLogo() {
    setBusy("remove");
    try {
      applied(await apiFetch<Branding>("/v1/branding/logo", { method: "DELETE" }), "Logo quitado");
    } catch (err) {
      toast(firstError(err, "No se pudo quitar el logo."), "danger");
    } finally {
      setBusy(null);
    }
  }

  async function save(event: FormEvent) {
    event.preventDefault();
    if (!form) return;
    setBusy("save");
    try {
      const data = await apiFetch<Branding>("/v1/branding", {
        method: "PUT",
        body: JSON.stringify({ ...form, secondary_color: form.secondary_color || null }),
      });
      applied(data, "Marca guardada");
    } catch (err) {
      setErrors(fieldErrors(err));
      toast(firstError(err, "No se pudo guardar la marca."), "danger");
    } finally {
      setBusy(null);
    }
  }

  /** PDF de ejemplo con lo que está en pantalla (aunque aún no se haya guardado). */
  async function samplePdf() {
    if (!branding || !form) return;
    setBusy("pdf");
    const draft: Branding = {
      ...branding,
      primary_color: HEX.test(form.primary_color) ? form.primary_color.toUpperCase() : branding.primary_color,
      secondary_color: HEX.test(form.secondary_color) ? form.secondary_color.toUpperCase() : null,
      address: form.address || null, phone: form.phone || null, email: form.email || null, website: form.website || null,
    };
    setBranding(draft);
    try {
      // Se muestra en una ventana con su botón de descarga: si se descargara solo al terminar
      // (unos segundos después del clic), el navegador bloquearía la segunda descarga de la página.
      const blob = await downloadDocumentPdf({
        file: "Ejemplo_documento",
        title: "COTIZACIÓN",
        number: "COT-2026-0001",
        watermark: "EJEMPLO",
        left: [["Cliente", "Minera Los Andes S.A.C."], ["Documento", "RUC 20512345671"], ["Dirección", "Av. Industrial 450, Lima"]],
        right: [["Fecha de emisión", new Date().toLocaleDateString("es-PE")], ["Válida hasta", "30 días"], ["Moneda", "Soles"]],
        head: ["#", "Descripción", "Unidad", "Cant.", "P. unitario", "Total c/IGV"],
        rows: [
          ["1", "Mantenimiento preventivo de equipos", "servicio", "2", "380.00", "760.00"],
          ["2", "Visita técnica de diagnóstico", "visita", "1", "115.93", "136.80"],
        ],
        numCols: [3, 4, 5],
        totals: [["Subtotal", "S/ 760.00"], ["IGV 18%", "S/ 136.80"], ["TOTAL", "S/ 896.80", true]],
        notes: [["Condiciones", "Documento de ejemplo para revisar el diseño con la marca de la empresa."]],
        signatures: (companyName) => ["Aceptación del cliente", `Por ${companyName}`],
      }, { asBlob: true });
      if (blob) setPreviewUrl(URL.createObjectURL(blob));
    } catch {
      toast("No se pudo generar el PDF de ejemplo.", "danger");
    } finally {
      // Lo que no se guardó no se queda para los documentos reales.
      setBranding(branding);
      setBusy(null);
    }
  }

  if (error) return <Screen><PageHead title="Configuración" /><Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card></Screen>;
  if (!branding || !form) {
    return <Screen><PageHead title="Configuración" /><div className="h-64 animate-pulse rounded-xl bg-[var(--app-surface-muted)]" aria-busy="true" /></Screen>;
  }

  const primaryOk = HEX.test(form.primary_color);
  const lightPrimary = primaryOk && contrast(hexToRgb(form.primary_color), [255, 255, 255]) < 3;
  const set = (patch: Partial<Form>) => setForm({ ...form, ...patch });
  const readOnly = !canManage ? (
    <span title={deniedText("tenant.manage")}><Chip tone="neutral"><Lock size={11} className="mr-1" aria-hidden="true" />Solo lectura</Chip></span>
  ) : undefined;

  return (
    <Screen>
      <PageHead
        title="Configuración"
        actions={
          <Btn variant="secondary" onClick={samplePdf} disabled={busy !== null}>
            <FileDown size={16} />
            <span>{busy === "pdf" ? "Generando…" : "PDF de ejemplo"}</span>
          </Btn>
        }
      />

      <div className="grid items-start gap-[18px] xl:grid-cols-[minmax(0,7fr)_minmax(0,5fr)]">
        <div className="min-w-0">
          <Card title="Logo de la empresa" actions={readOnly}>
            <div className="flex flex-wrap items-center gap-4">
              <div className="grid h-[110px] w-[220px] shrink-0 place-items-center rounded-lg border border-dashed border-[color:var(--app-border)] bg-[repeating-conic-gradient(#f1f4f8_0%_25%,#ffffff_0%_50%)] bg-[length:16px_16px] p-3">
                {branding.logo ? (
                  // eslint-disable-next-line @next/next/no-img-element -- data URL del logo limpio.
                  <img src={branding.logo} alt={`Logo de ${branding.name}`} className="max-h-full max-w-full object-contain" />
                ) : (
                  <span className="app-muted text-center text-xs">Sin logo: los documentos llevan las iniciales de la empresa</span>
                )}
              </div>
              <div className="grid min-w-0 flex-1 gap-2">
                <p className="app-muted text-[12.5px]">PNG, JPG o WEBP de hasta 2 MB. Mejor con fondo transparente y horizontal.</p>
                <div className="flex flex-wrap gap-2">
                  {/* `relative`: el campo oculto se coloca respecto del botón y no agrega franjas a la página. */}
                  <label className="relative">
                    <input
                      ref={fileRef} type="file" accept="image/png,image/jpeg,image/webp" className="sr-only"
                      onChange={(e) => upload(e.target.files?.[0])} disabled={!canManage || busy !== null}
                    />
                    <Btn permission="tenant.manage" disabled={busy !== null} onClick={() => fileRef.current?.click()}>
                      <ImageUp size={16} />
                      <span>{busy === "logo" ? "Subiendo…" : branding.has_logo ? "Cambiar logo" : "Subir logo"}</span>
                    </Btn>
                  </label>
                  {branding.has_logo && (
                    <Btn variant="danger-ghost" permission="tenant.manage" disabled={busy !== null} onClick={removeLogo}>
                      <Trash2 size={15} />
                      <span>{busy === "remove" ? "Quitando…" : "Quitar logo"}</span>
                    </Btn>
                  )}
                </div>
              </div>
            </div>
          </Card>

          <form onSubmit={save}>
            <Card title="Colores de los documentos" actions={readOnly}>
              <FormGrid>
                <ColorField
                  label="Color principal" value={form.primary_color} palette={branding.palette} disabled={!canManage}
                  error={errors.primary_color} onChange={(hex) => set({ primary_color: hex })}
                />
                <ColorField
                  label="Color secundario" value={form.secondary_color} palette={branding.palette} optional disabled={!canManage}
                  error={errors.secondary_color} onChange={(hex) => set({ secondary_color: hex })}
                />
              </FormGrid>
              {lightPrimary && (
                <div className="mt-3">
                  <Notice tone="warn">
                    Es un color claro: en los títulos y las líneas se usará una versión más oscura ({rgbToHex(readableOnWhite(form.primary_color))}) para que se lea.
                  </Notice>
                </div>
              )}
            </Card>

            <Card title="Pie de los documentos" actions={readOnly}>
              <FormGrid>
                <Field label="Dirección" error={errors.address}>
                  <input className={INPUT} value={form.address} maxLength={300} disabled={!canManage} onChange={(e) => set({ address: e.target.value })} />
                </Field>
                <Field label="Teléfono" error={errors.phone}>
                  <input className={INPUT} value={form.phone} maxLength={40} inputMode="tel" disabled={!canManage} onChange={(e) => set({ phone: e.target.value })} />
                </Field>
                <Field label="Correo" error={errors.email}>
                  <input className={INPUT} value={form.email} maxLength={160} type="email" disabled={!canManage} onChange={(e) => set({ email: e.target.value })} />
                </Field>
                <Field label="Sitio web" error={errors.website}>
                  <input className={INPUT} value={form.website} maxLength={160} placeholder="www.miempresa.pe" disabled={!canManage} onChange={(e) => set({ website: e.target.value })} />
                </Field>
              </FormGrid>
              <div className="mt-4 flex flex-wrap justify-end gap-2">
                <Btn variant="secondary" disabled={busy !== null || !canManage} onClick={() => { setForm(toForm(branding)); setErrors({}); }}>Descartar cambios</Btn>
                <Btn type="submit" permission="tenant.manage" disabled={busy !== null || !primaryOk}>{busy === "save" ? "Guardando…" : "Guardar"}</Btn>
              </div>
            </Card>
          </form>
        </div>

        <Card title="Vista previa">
          <DocumentPreview branding={branding} form={form} />
          <p className="app-muted mt-3 text-[12.5px]">
            Se aplica a los PDF (cotizaciones, contratos, órdenes, comprobantes, recibos, estados de cuenta, asientos y órdenes de compra), a los Excel exportados, al portal de clientes y a los correos que reciben tus clientes.
          </p>
        </Card>
      </div>
      {previewUrl && (
        <Modal
          title="PDF de ejemplo"
          size="lg"
          onClose={closePreview}
          footer={
            <>
              <Btn variant="secondary" onClick={closePreview}>Cerrar</Btn>
              <a href={previewUrl} download="Ejemplo_documento.pdf" className={btnClass("primary")}>
                <FileDown size={16} />
                <span>Descargar PDF</span>
              </a>
            </>
          }
        >
          <iframe src={previewUrl} title="Vista previa del PDF de ejemplo" className="h-[70vh] w-full rounded-lg border border-[color:var(--app-border)] bg-[#fff]" />
        </Modal>
      )}
      {toasts}
    </Screen>
  );
}
