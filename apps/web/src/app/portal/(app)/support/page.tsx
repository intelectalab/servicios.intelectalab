"use client";

import { SectionGuard } from "@/components/portal/PortalShell";
import { PortalSupportList } from "@/components/portal/PortalSupport";

export default function PortalSupportPage() {
  return <SectionGuard section="support"><PortalSupportList /></SectionGuard>;
}
