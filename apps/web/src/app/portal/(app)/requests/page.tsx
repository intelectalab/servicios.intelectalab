"use client";

import { SectionGuard } from "@/components/portal/PortalShell";
import { PortalRequests } from "@/components/portal/PortalCommercial";

export default function PortalRequestsPage() {
  return <SectionGuard section="requests"><PortalRequests /></SectionGuard>;
}
