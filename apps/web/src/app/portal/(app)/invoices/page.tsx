"use client";

import { SectionGuard } from "@/components/portal/PortalShell";
import { PortalInvoices } from "@/components/portal/PortalFinance";

export default function PortalInvoicesPage() {
  return <SectionGuard section="invoices"><PortalInvoices /></SectionGuard>;
}
