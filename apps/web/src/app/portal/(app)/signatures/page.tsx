"use client";

import { SectionGuard } from "@/components/portal/PortalShell";
import PortalSignatures from "@/components/portal/PortalSignatures";

export default function PortalSignaturesPage() {
  return <SectionGuard section="sign"><PortalSignatures /></SectionGuard>;
}
