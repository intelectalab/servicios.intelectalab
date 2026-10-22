"use client";

import { SectionGuard } from "@/components/portal/PortalShell";
import { PortalDocuments } from "@/components/portal/PortalFinance";

export default function PortalDocumentsPage() {
  return <SectionGuard section="documents"><PortalDocuments /></SectionGuard>;
}
