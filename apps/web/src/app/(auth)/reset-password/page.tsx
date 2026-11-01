import { Suspense } from "react";
import ResetPasswordForm from "@/components/auth/ResetPasswordForm";
import { fetchLoginBrand } from "@/lib/login-brand-server";

export default async function ResetPasswordPage({ searchParams }: { searchParams: Promise<{ empresa?: string }> }) {
  // Desde la invitación o el ingreso de una empresa: su marca llega con la página.
  const { empresa } = await searchParams;
  const brand = empresa ? await fetchLoginBrand(empresa) : undefined;
  return (
    <Suspense fallback={null}>
      <ResetPasswordForm initialBrand={brand} />
    </Suspense>
  );
}
