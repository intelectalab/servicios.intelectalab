"use client";

import { SectionGuard } from "@/components/portal/PortalShell";
import PortalUsers from "@/components/portal/PortalUsers";

export default function PortalUsersPage() {
  return <SectionGuard section="users"><PortalUsers /></SectionGuard>;
}
