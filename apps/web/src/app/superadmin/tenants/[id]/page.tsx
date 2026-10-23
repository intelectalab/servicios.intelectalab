"use client";

import { useParams } from "next/navigation";
import { TenantDetail } from "@/components/superadmin/tenants/TenantDetail";

export default function SuperAdminTenantPage() {
  const { id } = useParams<{ id: string }>();
  return <TenantDetail key={id} id={id} />;
}
