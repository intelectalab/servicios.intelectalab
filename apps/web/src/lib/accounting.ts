import type { Tone } from "@/components/ui/proto";
import { money } from "@/lib/commercial";
import type { AccountType, CostCenterKind } from "@/types/accounting";

export const JOURNAL_STATES: ["POSTED" | "REVERSED", string, Tone][] = [
  ["POSTED", "Contabilizado", "success"],
  ["REVERSED", "Extornado", "muted"],
];

export const PERIOD_STATES: ["OPEN" | "CLOSED", string, Tone][] = [
  ["OPEN", "Abierto", "success"],
  ["CLOSED", "Cerrado", "muted"],
];

export const BUDGET_STATES: ["DRAFT" | "APPROVED" | "CLOSED", string, Tone][] = [
  ["DRAFT", "Borrador", "neutral"],
  ["APPROVED", "Aprobado", "success"],
  ["CLOSED", "Cerrado", "muted"],
];

export const ACCOUNT_TYPES: [AccountType, string][] = [
  ["ASSET", "Activo"],
  ["LIABILITY", "Pasivo"],
  ["EQUITY", "Patrimonio"],
  ["INCOME", "Ingreso"],
  ["EXPENSE", "Gasto"],
];

export const CENTER_KINDS: [CostCenterKind, string][] = [
  ["COST", "Gastos"],
  ["REVENUE", "Ingresos"],
];

/** Importe abreviado de los indicadores, como el prototipo: "S/ 12.3 k". */
export function kfmt(value: number) {
  const n = Number(value) || 0;
  if (Math.abs(n) >= 1e6) return `S/ ${(n / 1e6).toFixed(1)} M`;
  if (Math.abs(n) >= 1e3) return `S/ ${(n / 1e3).toFixed(1)} k`;
  return `S/ ${Math.round(n)}`;
}

/** Montos de los estados financieros: los negativos entre paréntesis. */
export function signedMoney(value: number) {
  // Un cero negativo (−0) se muestra como cero.
  const n = Number(value) || 0;
  return n < 0 ? `(${money(-n)})` : money(n);
}

/** Debe o haber de una línea: vacío si es cero, como el prototipo. */
export const amountOrBlank = (value: number) => (value ? money(value) : "");

/** "2026-10" → "10/26" (eje del gráfico mensual). */
export const monthTick = (period: string) => `${period.slice(5)}/${period.slice(2, 4)}`;

const MONTHS = ["enero", "febrero", "marzo", "abril", "mayo", "junio", "julio", "agosto", "septiembre", "octubre", "noviembre", "diciembre"];

/** "2026-08" → "agosto 2026". */
export const monthName = (period: string) => `${MONTHS[Number(period.slice(5, 7)) - 1] ?? ""} ${period.slice(0, 4)}`;

/** Todos los meses de `first` a `last` (AAAA-MM), sin saltos. */
export function monthsBetween(first: string, last: string) {
  const out: string[] = [];
  let [year, month] = first.split("-").map(Number);
  const [endYear, endMonth] = last.split("-").map(Number);
  for (let guard = 0; guard < 600 && (year < endYear || (year === endYear && month <= endMonth)); guard++) {
    out.push(`${year}-${String(month).padStart(2, "0")}`);
    month += 1;
    if (month > 12) { month = 1; year += 1; }
  }
  return out;
}

/**
 * Meses elegibles en Desde / Hasta: todos, seguidos, desde el más antiguo que se conoce (el primer
 * mes con asientos o el rango con que abrió la pantalla) hasta el más reciente. No dependen de lo que
 * esté elegido en este momento: elegir un mes no hace desaparecer otros.
 */
export function periodChoices(known: (string | null | undefined)[]) {
  const sorted = known.filter((p): p is string => Boolean(p)).sort();
  return sorted.length ? monthsBetween(sorted[0], sorted[sorted.length - 1]) : [];
}
