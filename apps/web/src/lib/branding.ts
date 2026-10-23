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

/** Tras guardar la marca: lo siguiente que se genere ya la usa. */
export function setBranding(branding: Branding) {
  cache = Promise.resolve(branding);
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

// Superficie del tema oscuro (--app-surface): base para los tonos de la marca en oscuro.
const DARK_SURFACE: Rgb = [24, 43, 53];
const css = (rgb: Rgb) => `rgb(${rgb.map(Math.round).join(" ")})`;

/**
 * Variables de color de la marca para una zona de la pantalla (el portal del cliente): las mismas
 * que usan las piezas de interfaz (--p-brand…, --p-accent…), en claro (--bl-…) y en oscuro
 * (--bd-…). La regla `.tenant-brand` de globals.css elige las del tema activo.
 */
export function brandVars(primaryHex: string): Record<string, string> {
  const primary = hexToRgb(primaryHex);
  const accent = readableOnWhite(primaryHex);
  const accentHover = accent.map((v) => v * 0.85) as Rgb;
  const darkBrand = lightOn(primary, DARK_SURFACE, 4.5);
  return {
    "--bl-brand": css(primary),
    "--bl-brand-text": css(accent),
    "--bl-brand-soft": css(tint(primaryHex, 0.12)),
    "--bl-brand-soft-text": css(accent.map((v) => v * 0.78) as Rgb),
    "--bl-brand-line": css(tint(primaryHex, 0.32)),
    "--bd-brand": css(darkBrand),
    "--bd-brand-text": css(lightOn(primary, DARK_SURFACE, 6.5)),
    "--bd-brand-soft": css(mix(primary, DARK_SURFACE, 0.3)),
    "--bd-brand-soft-text": css(lightOn(mix(primary, [255, 255, 255], 0.35), mix(primary, DARK_SURFACE, 0.3), 7)),
    "--bd-brand-line": css(mix(primary, DARK_SURFACE, 0.5)),
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
