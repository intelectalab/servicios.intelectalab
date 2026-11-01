/** Vigencia de la suscripción de la empresa: aviso cuando faltan pocos días. */

/** Días que se avisa antes del vencimiento. */
export const RENEWAL_WARNING_DAYS = 7;

/** Días que faltan hasta la fecha (negativo si ya pasó; 0 si es hoy). */
export function daysUntil(value: string): number {
  return Math.ceil((new Date(value).getTime() - Date.now()) / 86_400_000);
}

/** "venció", "vence hoy", "vence en 3 días". */
export function expiryText(days: number): string {
  if (days < 0) return "venció";
  if (days === 0) return "vence hoy";
  return `vence en ${days} día${days === 1 ? "" : "s"}`;
}

/** Fecha inicial para extender: la vigencia actual o hoy, si ya venció. */
export function extensionBase(endsAt: string | null | undefined): Date {
  const base = endsAt ? new Date(endsAt) : new Date();
  return base.getTime() < Date.now() ? new Date() : base;
}
