import LoginForm from "@/components/auth/LoginForm";
import { fetchLoginBrand } from "@/lib/login-brand-server";

/** Ingreso con la marca de una empresa: /e/{nombre-corto}. Si el enlace no existe, es el ingreso general. */
export default async function CompanyLoginPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const site = process.env.SITE_URL?.replace(/\/+$/, "") ?? "";
  // La marca llega con la página: sin parpadeo de colores al cargar.
  const brand = await fetchLoginBrand(slug);
  return <LoginForm slug={slug.toLowerCase()} initialBrand={brand} homeUrl={`${site}/`} plansUrl={`${site}/#plans`} />;
}
