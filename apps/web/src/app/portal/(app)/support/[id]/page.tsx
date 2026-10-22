"use client";

import { useParams } from "next/navigation";
import { SectionGuard } from "@/components/portal/PortalShell";
import { PortalTicketDetail } from "@/components/portal/PortalSupport";

export default function PortalTicketPage() {
  const { id } = useParams<{ id: string }>();
  return <SectionGuard section="support"><PortalTicketDetail id={id} /></SectionGuard>;
}
