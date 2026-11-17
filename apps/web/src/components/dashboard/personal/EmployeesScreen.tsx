"use client";

import { useEffect, useState } from "react";
import { takeFlash } from "@/lib/commercial";
import { Screen, Tabs, useToasts } from "@/components/ui/proto";
import EmployeesList from "@/components/dashboard/personal/EmployeesList";
import PositionsList from "@/components/dashboard/personal/PositionsList";

type Tab = "employees" | "positions";

/** Personal → Trabajadores y puestos: dos pestañas sobre la misma pantalla. */
export default function EmployeesScreen() {
  const { toast, toasts } = useToasts();
  const [tab, setTab] = useState<Tab>("employees");

  useEffect(() => {
    const flash = takeFlash();
    if (flash) toast(flash);
  }, [toast]);

  // La pestaña también llega por enlace (?tab=positions).
  useEffect(() => {
    async function fromLink() {
      if (new URLSearchParams(window.location.search).get("tab") === "positions") setTab("positions");
    }
    fromLink();
  }, []);

  const tabs = (
    <Tabs
      tabs={[["employees", "Trabajadores", null], ["positions", "Puestos", null]]}
      value={tab}
      onChange={(value) => setTab(value as Tab)}
    />
  );

  return (
    <Screen>
      {tab === "employees" ? <EmployeesList toast={toast} tabs={tabs} /> : <PositionsList toast={toast} tabs={tabs} />}
      {toasts}
    </Screen>
  );
}
