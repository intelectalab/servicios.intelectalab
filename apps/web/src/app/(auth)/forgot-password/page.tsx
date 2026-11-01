import { Suspense } from "react";
import ForgotPasswordForm from "@/components/auth/ForgotPasswordForm";
import { fetchLoginBrand } from "@/lib/login-brand-server";

export default async function ForgotPasswordPage({ searchParams }: { searchParams: Promise<{ empresa?: string }> }) {
  // Desde el ingreso de una empresa: su marca llega con la página.
  const { empresa } = await searchParams;
  const brand = empresa ? await fetchLoginBrand(empresa) : undefined;
  return (
    <Suspense fallback={null}>
      <ForgotPasswordForm initialBrand={brand} />
    </Suspense>
  );
}
