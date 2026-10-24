import { apiFetch } from "@/lib/api";

/** Marca de la empresa (Configuración → Marca de la empresa), tal como la entrega la API. */
export type Branding = {
  name: string;
  trade_name: string | null;
  tax_identifier: string | null;
  /** Logo limpio como data URL PNG, o null si la empresa no subió uno. */
  logo: string | null;
  has_logo: boolean;
  logo_width: number | null;
  logo_height: number | null;
  primary_color: string;
  secondary_color: string | null;
  /** Colores principales detectados en el logo. */
  palette: string[];
  address: string | null;
  phone: string | null;
  email: string | null;
  website: string | null;
  customized: boolean;
  updated_at: string | null;
};

export type Rgb = [number, number, number];

export const DEFAULT_PRIMARY = "#1B8FC9";
const INK = "#1D1D1B";

let cache: Promise<Branding | null> | null = null;

/**
 * La marca de la empresa de la sesión, leída una vez por pestaña. En el portal de clientes sale
 * de su propia ruta (las rutas internas no le responden). Si no se puede leer, los documentos
 * salen con los colores de siempre.
 */
export function loadBranding(): Promise<Branding | null> {
  const portal = typeof window !== "undefined" && /^\/portal(\/|$)/.test(window.location.pathname);
  cache ??= apiFetch<Branding>(portal ? "/v1/portal/branding" : "/v1/branding").catch(() => {
    cache = null;
    return null;
  });
  return cache;
}

const BRANDING_EVENT = "erp-branding-change";

/** Tras guardar la marca: lo siguiente que se genere ya la usa, y la barra lateral se actualiza al instante. */
export function setBranding(branding: Branding) {
  cache = Promise.resolve(branding);
  if (typeof window !== "undefined") {
    window.dispatchEvent(new CustomEvent<Branding>(BRANDING_EVENT, { detail: branding }));
  }
}

export function subscribeToBranding(onChange: (branding: Branding) => void) {
  const handler = (event: Event) => onChange((event as CustomEvent<Branding>).detail);
  window.addEventListener(BRANDING_EVENT, handler);
  return () => window.removeEventListener(BRANDING_EVENT, handler);
}

export function hexToRgb(hex: string): Rgb {
  const clean = /^#[0-9a-f]{6}$/i.test(hex) ? hex.slice(1) : DEFAULT_PRIMARY.slice(1);
  return [parseInt(clean.slice(0, 2), 16), parseInt(clean.slice(2, 4), 16), parseInt(clean.slice(4, 6), 16)];
}

export function rgbToHex([r, g, b]: Rgb) {
  return `#${[r, g, b].map((v) => Math.round(Math.max(0, Math.min(255, v))).toString(16).padStart(2, "0")).join("").toUpperCase()}`;
}

/** Luminancia relativa (WCAG). */
function luminance([r, g, b]: Rgb) {
  const lin = (v: number) => {
    const c = v / 255;
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
  };
  return 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
}

export function contrast(a: Rgb, b: Rgb) {
  const [l1, l2] = [luminance(a), luminance(b)].sort((x, y) => y - x);
  return (l1 + 0.05) / (l2 + 0.05);
}

/** Texto que se lee sobre un fondo de ese color: blanco o la tinta del documento. */
export function textOn(hex: string): Rgb {
  const bg = hexToRgb(hex);
  return contrast(bg, [255, 255, 255]) >= contrast(bg, hexToRgb(INK)) ? [255, 255, 255] : hexToRgb(INK);
}

/** El color, oscurecido lo justo para leerse como texto o línea sobre blanco (contraste 4.5:1). */
export function readableOnWhite(hex: string): Rgb {
  let rgb = hexToRgb(hex);
  for (let i = 0; i < 20 && contrast(rgb, [255, 255, 255]) < 4.5; i++) rgb = rgb.map((v) => v * 0.88) as Rgb;
  return rgb;
}

/** Mezcla de dos colores: `amount` = cuánto del primero. */
export function mix(a: Rgb, b: Rgb, amount: number): Rgb {
  return a.map((v, i) => Math.round(v * amount + b[i] * (1 - amount))) as Rgb;
}

/** Aclara el color hacia el blanco lo justo para leerse sobre ese fondo oscuro. */
function lightOn(rgb: Rgb, bg: Rgb, ratio: number): Rgb {
  let color = rgb;
  for (let i = 0; i < 20 && contrast(color, bg) < ratio; i++) color = mix(color, [255, 255, 255], 0.85);
  return color;
}

// Superficie base del tema oscuro (gris pizarra neutro, para teñirse suavemente con la marca sin chocar con cian).
const DARK_BASE: Rgb = [22, 26, 34];
const DARK_PAGE_BASE: Rgb = [14, 17, 23];
const DARK_MUTED_BASE: Rgb = [32, 37, 48];
const DARK_BORDER_BASE: Rgb = [52, 60, 76];
const css = (rgb: Rgb) => `rgb(${rgb.map(Math.round).join(" ")})`;

/**
 * Variables de color de la marca para el ERP y el portal del cliente: las mismas
 * que usan las piezas de interfaz (--p-brand…, --p-accent…, --app-page, --app-surface…),
 * en claro (--bl-…) y en oscuro (--bd-…). La regla `.tenant-brand` de globals.css elige las del tema activo.
 */
export function brandVars(primaryHex: string): Record<string, string> {
  const primary = hexToRgb(primaryHex);
  const accent = readableOnWhite(primaryHex);
  const accentHover = accent.map((v) => v * 0.85) as Rgb;
  const darkSurface = mix(primary, DARK_BASE, 0.08);
  const darkPage = mix(primary, DARK_PAGE_BASE, 0.06);
  const darkSurfaceMuted = mix(primary, DARK_MUTED_BASE, 0.11);
  const darkBorder = mix(primary, DARK_BORDER_BASE, 0.16);
  const darkBrand = lightOn(primary, darkSurface, 4.5);
  // Barra lateral teñida con el color de la empresa (oscurecido para garantizar contraste con texto blanco).
  const sideTop = accent;
  const sideMid = accent.map((v) => v * 0.85) as Rgb;
  const sideBot = accent.map((v) => v * 0.63) as Rgb;
  const darkTop = mix(accent, DARK_BASE, 0.68);
  const darkMid = mix(accent, DARK_BASE, 0.52);
  const darkBot = mix(accent, DARK_PAGE_BASE, 0.36);
  return {
    "--bl-brand": css(primary),
    "--bl-brand-text": css(accent),
    "--bl-brand-soft": css(tint(primaryHex, 0.12)),
    "--bl-brand-soft-text": css(accent.map((v) => v * 0.78) as Rgb),
    "--bl-brand-line": css(tint(primaryHex, 0.32)),
    "--bl-page": css(tint(primaryHex, 0.055)),
    "--bl-surface-muted": css(tint(primaryHex, 0.035)),
    "--bl-border": css(mix(primary, [212, 218, 226], 0.16)),
    "--bl-chart-1": css(accent),
    "--bl-chart-2": css(accent.map((v) => v * 0.68) as Rgb),
    "--bd-brand": css(darkBrand),
    "--bd-brand-text": css(lightOn(primary, darkSurface, 6.5)),
    "--bd-brand-soft": css(mix(primary, darkSurface, 0.26)),
    "--bd-brand-soft-text": css(lightOn(mix(primary, [255, 255, 255], 0.35), mix(primary, darkSurface, 0.26), 7)),
    "--bd-brand-line": css(mix(primary, darkSurface, 0.45)),
    "--bd-page": css(darkPage),
    "--bd-surface": css(darkSurface),
    "--bd-surface-muted": css(darkSurfaceMuted),
    "--bd-border": css(darkBorder),
    "--bd-field-border": css(mix(primary, [74, 84, 100], 0.18)),
    "--bd-muted": css(mix(primary, [168, 178, 192], 0.12)),
    "--bd-toast-bg": css(mix(primary, [38, 44, 56], 0.18)),
    "--bd-toast-line": css(mix(primary, [86, 98, 118], 0.25)),
    "--bd-chart-1": css(darkBrand),
    "--bd-chart-2": css(mix(darkBrand, DARK_BASE, 0.65)),
    "--bl-side-bg": `linear-gradient(180deg, ${css(sideTop)} 0%, ${css(sideMid)} 22%, ${css(sideBot)} 100%)`,
    "--bl-side-flyout": `linear-gradient(180deg, ${css(sideMid)} 0%, ${css(sideBot)} 100%)`,
    "--bl-side-ring": css(sideMid),
    "--bd-side-bg": `linear-gradient(180deg, ${css(darkTop)} 0%, ${css(darkMid)} 22%, ${css(darkBot)} 100%)`,
    "--bd-side-flyout": `linear-gradient(180deg, ${css(darkMid)} 0%, ${css(darkBot)} 100%)`,
    "--bd-side-active": `rgb(${accent.map(Math.round).join(" ")} / 0.55)`,
    "--bd-side-group-active": `rgb(${accent.map(Math.round).join(" ")} / 0.38)`,
    "--bd-side-ring": css(darkMid),
    "--p-accent": css(accent),
    "--p-accent-hover": css(accentHover),
    "--p-on-accent": css(textOn(rgbToHex(accent))),
  };
}

/** Un tono muy suave del color (mezclado con blanco), para fondos de recuadros y filas. */
export function tint(hex: string, amount: number): Rgb {
  const rgb = hexToRgb(hex);
  return rgb.map((v) => Math.round(255 - (255 - v) * amount)) as Rgb;
}
