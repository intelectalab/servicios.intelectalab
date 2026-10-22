"use client";

import { useParams } from "next/navigation";
import { SectionGuard } from "@/components/portal/PortalShell";
import { PortalQuotationDetail } from "@/components/portal/PortalQuotations";

export default function PortalQuotationPage() {
  const { id } = useParams<{ id: string }>();
  return <SectionGuard section="quotes"><PortalQuotationDetail id={id} /></SectionGuard>;
}
