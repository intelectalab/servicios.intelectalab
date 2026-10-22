"use client";

import { SectionGuard } from "@/components/portal/PortalShell";
import { PortalQuotationsList } from "@/components/portal/PortalQuotations";

export default function PortalQuotationsPage() {
  return <SectionGuard section="quotes"><PortalQuotationsList /></SectionGuard>;
}
