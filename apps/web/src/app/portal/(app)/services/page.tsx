"use client";

import { SectionGuard } from "@/components/portal/PortalShell";
import { PortalServices } from "@/components/portal/PortalCommercial";

export default function PortalServicesPage() {
  return <SectionGuard section="services"><PortalServices /></SectionGuard>;
}
