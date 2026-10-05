"use client";

import { Suspense, useEffect, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import Image from "next/image";
import { Ban, Clock, Loader2, Lock, Wrench, type LucideIcon } from "lucide-react";
import { apiFetch, ApiError } from "@/lib/api";
import { clearAuthToken } from "@/lib/auth";
import { poppins } from "@/lib/fonts";

// El texto explicativo viene de la API (TenantAccess.php); aquí solo van título e ícono.
const STATES: Record<string, { icon: LucideIcon; title: string; canRetry: boolean }> = {
  SUSPENDED: { icon: Lock, title: "Empresa suspendida", canRetry: true },
  PROVISIONING: { icon: Clock, title: "Empresa en configuración", canRetry: true },
  MAINTENANCE: { icon: Wrench, title: "Empresa en mantenimiento", canRetry: true },
  CLOSED: { icon: Ban, title: "Cuenta cerrada", canRetry: false },
};

const FALLBACK = { icon: Lock, title: "Acceso no disponible", canRetry: true };

type BlockedBody = { code?: string; tenant_status?: string; message?: string };

type ProbeResult = "active" | { status?: string; message: string };

// Pregunta a la API cómo está la empresa: "active" si ya puede operar; si no,
// el estado y el mensaje que devuelve.
async function probe(): Promise<ProbeResult> {
  try {
    await apiFetch("/v1/me");
    return "active";
  } catch (err) {
    const body = err instanceof ApiError ? (err.body as BlockedBody | null) : null;
    if (body?.code === "TENANT_BLOCKED") {
      return { status: body.tenant_status, message: body.message ?? "" };
    }
    return { message: "No pudimos comprobar el acceso de tu empresa. Intenta de nuevo." };
  }
}

function BlockedContent() {
  const router = useRouter();
  const statusFromUrl = useSearchParams().get("status") ?? "";
  const [status, setStatus] = useState(statusFromUrl);
  const [message, setMessage] = useState("");
  const [checking, setChecking] = useState(true);
  const [retried, setRetried] = useState(false);

  function apply(result: ProbeResult) {
    if (result === "active") {
      router.replace("/customers");
      return;
    }
    if (result.status) setStatus(result.status);
    setMessage(result.message);
    setChecking(false);
  }

  useEffect(() => {
    let cancelled = false;

    async function run() {
      const result = await probe();
      if (!cancelled) apply(result);
    }
    run();
    return () => { cancelled = true; };
    // Solo al abrir la pantalla; "Volver a intentar" repite la comprobación.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function handleRetry() {
    setChecking(true);
    apply(await probe());
    setRetried(true);
  }

  async function handleLogout() {
    try {
      await apiFetch("/v1/logout", { method: "POST" });
    } catch {
      // Con la empresa bloqueada la API rechaza la llamada; igual se limpia la sesión local.
    } finally {
      clearAuthToken();
      router.push("/login");
    }
  }

  const { icon: Icon, title, canRetry } = STATES[status] ?? FALLBACK;

  return (
    <main className={`${poppins.className} app-page flex min-h-screen flex-col items-center justify-center gap-6 px-6 py-10`}>
      <div className="relative h-11 w-[200px]">
        <Image src="/logo.png" alt="GsCreativas" fill priority sizes="200px" className="logo-light object-contain" />
        <Image src="/logo-blanco.png" alt="GsCreativas" fill priority sizes="200px" className="logo-dark object-contain" />
      </div>

      <div className="app-surface app-border w-full max-w-[460px] rounded-xl border p-8 text-center shadow-sm">
        <span className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-[#FFF4DB] text-[#B26E00]">
          <Icon size={22} />
        </span>
        <h1 className="app-text mt-4 text-[20px] font-bold tracking-[-0.01em]">{title}</h1>
        <p className="app-muted mt-2 min-h-[42px] text-[13.5px] leading-relaxed" aria-live="polite">
          {message}
        </p>

        {retried && !checking && (
          <p role="status" className="mt-3 rounded-[9px] bg-[#FFF4DB] px-3 py-2.5 text-[13px] text-[#8A5500]">
            El acceso sigue sin estar disponible.
          </p>
        )}

        <div className="mt-6 grid gap-2">
          {canRetry && (
            <button
              type="button"
              onClick={handleRetry}
              disabled={checking}
              className="flex h-[42px] w-full items-center justify-center gap-2 rounded-[9px] bg-[#1B8FC9] text-[14px] font-semibold text-white transition-colors hover:bg-[#1679AB] disabled:opacity-60"
            >
              {checking ? <Loader2 size={16} className="animate-spin" /> : "Volver a intentar"}
            </button>
          )}
          <button
            type="button"
            onClick={handleLogout}
            className="app-muted app-hover flex h-9 w-full items-center justify-center rounded-[9px] text-[13.5px] font-medium transition-colors"
          >
            Cerrar sesión
          </button>
        </div>
      </div>
    </main>
  );
}

export default function BlockedPage() {
  return (
    <Suspense fallback={null}>
      <BlockedContent />
    </Suspense>
  );
}
