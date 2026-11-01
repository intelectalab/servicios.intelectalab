import type { LoginBrand } from "@/lib/login-brand";

/**
 * La marca de la empresa, consultada por el servidor web antes de enviar la página: así el
 * navegador recibe la pantalla de ingreso ya con su logo y su color, sin cambiar al cargar.
 * Cada enlace se consulta a la API como mucho una vez por minuto (la API limita las consultas
 * por dirección y todas estas salen del servidor web). Si la API no responde a tiempo, null:
 * la página sale con el ingreso general.
 */
export async function fetchLoginBrand(slug: string | null | undefined): Promise<LoginBrand | null> {
  const clean = (slug ?? "").toLowerCase().trim();
  if (!/^[a-z0-9-]{3,40}$/.test(clean)) return null;
  const base = process.env.NEXT_PUBLIC_API_URL ?? "";
  if (!/^https?:\/\//.test(base)) return null;

  try {
    const res = await fetch(`${base}/v1/public/login-brand/${encodeURIComponent(clean)}`, {
      headers: { Accept: "application/json" },
      next: { revalidate: 60 },
      signal: AbortSignal.timeout(2500),
    });
    return res.ok ? ((await res.json()) as LoginBrand) : null;
  } catch {
    return null;
  }
}
