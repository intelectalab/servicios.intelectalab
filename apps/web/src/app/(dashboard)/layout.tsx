import AppShell from "@/components/layout/AppShell";

export default function DashboardLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  // Planes de la página pública: con dos dominios viven en SITE_URL; con uno solo, en la raíz de este.
  const site = process.env.SITE_URL?.replace(/\/+$/, "") ?? "";
  return <AppShell plansUrl={`${site}/#plans`}>{children}</AppShell>;
}
