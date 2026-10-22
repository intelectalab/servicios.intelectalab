"use client";

import { useEffect, useMemo, useState } from "react";
import { Plus } from "lucide-react";
import { apiFetch } from "@/lib/api";
import type { PortalRoleInfo, PortalUserRow } from "@/lib/portal";
import { Btn, Card, DataTable, LoadError } from "@/components/ui/proto";
import { PortalTitle, usePortal } from "./PortalShell";
import { PortalExport } from "./PortalExport";
import { PORTAL_USER_EXPORT, PortalUserModals, portalUserColumns } from "./PortalUserTable";

type Team = { users: PortalUserRow[]; roles: PortalRoleInfo[]; max_users: number };
type Action = { kind: "role" | "status" | "resend"; row: PortalUserRow; status?: "ACTIVE" | "INACTIVE" };

/** Portal → Usuarios: el Portal Admin invita a su equipo, cambia su rol o desactiva su acceso. */
export default function PortalUsers() {
  const { me, toast } = usePortal();
  const [team, setTeam] = useState<Team | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [invite, setInvite] = useState(false);
  const [action, setAction] = useState<Action | null>(null);

  useEffect(() => {
    let cancelled = false;
    apiFetch<Team>("/v1/portal/users")
      .then((data) => { if (!cancelled) { setTeam(data); setError(""); } })
      .catch(() => { if (!cancelled) setError("No se pudieron cargar los usuarios."); });
    return () => { cancelled = true; };
  }, [reload]);

  const cols = useMemo(() => portalUserColumns({ backoffice: false, busy: false, onAction: setAction }), []);
  const current = team?.users.filter((user) => user.status !== "INACTIVE").length ?? 0;

  return (
    <>
      <PortalTitle>Usuarios de {me.customer.name}</PortalTitle>
      <Card flush>
        <div className="flex flex-wrap items-center justify-between gap-3 px-5 pb-2 pt-4">
          <p className="app-muted text-[13px]">{team ? `${current} de ${team.max_users} usuarios` : ""}</p>
          <Btn onClick={() => setInvite(true)} disabled={!team || current >= team.max_users} title={team && current >= team.max_users ? `Tu empresa alcanzó el máximo de ${team.max_users} usuarios del portal.` : undefined}>
            <Plus size={15} /> Invitar usuario
          </Btn>
        </div>
        {error ? <LoadError text={error} onRetry={() => setReload((n) => n + 1)} /> : (
          <>
            <DataTable dense cols={cols} rows={team?.users ?? []} rowKey={(row) => row.id} loading={!team} empty="Sin usuarios." />
            <PortalExport file="usuarios-portal" title="Usuarios del portal" columns={PORTAL_USER_EXPORT(false)} rows={team?.users ?? []} />
          </>
        )}
      </Card>

      {team && (
        <Card title="Roles disponibles">
          <ul className="grid gap-2.5">
            {team.roles.map((role) => (
              <li key={role.code}>
                <strong className="font-semibold">{role.name}</strong>
                <small className="app-muted block text-xs">{role.sections.join(" · ")}</small>
              </li>
            ))}
          </ul>
        </Card>
      )}

      {team && (invite || action) && (
        <PortalUserModals
          key={action ? `${action.kind}-${action.row.id}` : "invite"}
          base="/v1/portal/users"
          roles={team.roles}
          maxUsers={team.max_users}
          invite={invite}
          action={action}
          toast={toast}
          onClose={() => { setInvite(false); setAction(null); }}
          onDone={() => { setInvite(false); setAction(null); setReload((n) => n + 1); }}
        />
      )}
    </>
  );
}
