import PortalActivateForm from "@/components/portal/PortalActivateForm";
import { fetchLoginBrand } from "@/lib/login-brand-server";

export default async function PortalActivatePage({ searchParams }: { searchParams: Promise<{ empresa?: string }> }) {
  // Desde la invitación o el ingreso de una empresa (?empresa=…): su marca llega con la página.
  const { empresa } = await searchParams;
  const brand = empresa ? await fetchLoginBrand(empresa) : null;
  return <PortalActivateForm brand={brand} />;
}
