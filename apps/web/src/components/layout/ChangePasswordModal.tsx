"use client";

import { useState, type FormEvent } from "react";
import { createPortal } from "react-dom";
import { CheckCircle2, Eye, EyeOff, Loader2, X } from "lucide-react";
import { apiFetch, ApiError } from "@/lib/api";
import { poppins } from "@/lib/fonts";

const FIELD =
  "app-surface app-text w-full min-h-[40px] rounded-[9px] border border-[color:var(--app-border)] px-3 py-2 pr-10 text-[13.5px] outline-none transition-[border-color,box-shadow] focus:border-[#1B8FC9] focus:shadow-[0_0_0_3px_rgb(27_143_201/0.18)]";

/** Se monta solo mientras está abierto, así el formulario arranca siempre vacío. */
export default function ChangePasswordModal({ onClose }: { onClose: () => void }) {
  const [current, setCurrent] = useState("");
  const [password, setPassword] = useState("");
  const [show, setShow] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [done, setDone] = useState(false);

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    setError("");

    if (password.length < 8 || !/[a-z]/.test(password) || !/[A-Z]/.test(password) || !/\d/.test(password)) {
      setError("La nueva contraseña debe tener mínimo 8 caracteres, mayúsculas, minúsculas y un número.");
      return;
    }

    setSaving(true);
    try {
      await apiFetch("/v1/me/password", {
        method: "POST",
        body: JSON.stringify({ current_password: current, password }),
      });
      setDone(true);
    } catch (err) {
      const first = err instanceof ApiError && err.errors ? Object.values(err.errors)[0]?.[0] : undefined;
      setError(first || (err instanceof Error ? err.message : "No se pudo actualizar la contraseña."));
    } finally {
      setSaving(false);
    }
  }

  if (typeof document === "undefined") return null;

  return createPortal(
    <div
      className={`${poppins.className} fixed inset-0 z-[300] flex items-center justify-center bg-[rgb(16_24_40/0.5)] p-4`}
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) onClose();
      }}
    >
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="change-password-title"
        className="app-surface app-border w-full max-w-[420px] rounded-xl border shadow-[0_20px_50px_rgba(8,25,55,0.28)]"
      >
        <div className="app-border flex items-center justify-between border-b px-5 py-4">
          <h2 id="change-password-title" className="app-text text-[16px] font-semibold">Cambiar contraseña</h2>
          <button
            type="button"
            onClick={onClose}
            aria-label="Cerrar"
            className="app-muted flex h-8 w-8 cursor-pointer items-center justify-center rounded-lg hover:bg-[var(--app-surface-muted)]"
          >
            <X size={17} />
          </button>
        </div>

        {done ? (
          <div className="px-5 py-8 text-center">
            <CheckCircle2 size={32} className="mx-auto text-[color:var(--p-success-text)]" />
            <p className="app-text mt-3 text-[14px] font-medium">Contraseña actualizada</p>
            <p className="app-muted mt-1 text-[12.5px]">Úsala la próxima vez que inicies sesión.</p>
            <button
              type="button"
              onClick={onClose}
              className="mt-5 h-10 cursor-pointer rounded-[9px] bg-[#1B8FC9] px-5 text-[13.5px] font-semibold text-white transition-colors hover:bg-[#1679AB]"
            >
              Entendido
            </button>
          </div>
        ) : (
          <form onSubmit={handleSubmit} noValidate className="grid gap-3.5 px-5 py-5">
            <label className="grid gap-1.5">
              <span className="app-muted text-[12.5px] font-medium">Contraseña actual</span>
              <input
                type={show ? "text" : "password"}
                required
                autoFocus
                autoComplete="current-password"
                value={current}
                onChange={(event) => setCurrent(event.target.value)}
                className={FIELD}
              />
            </label>

            <label className="grid gap-1.5">
              <span className="app-muted text-[12.5px] font-medium">Nueva contraseña</span>
              <span className="relative block">
                <input
                  type={show ? "text" : "password"}
                  required
                  autoComplete="new-password"
                  value={password}
                  onChange={(event) => setPassword(event.target.value)}
                  className={FIELD}
                />
                <button
                  type="button"
                  onClick={() => setShow((value) => !value)}
                  aria-label={show ? "Ocultar contraseñas" : "Mostrar contraseñas"}
                  className="app-muted absolute right-1 top-1/2 flex h-8 w-8 -translate-y-1/2 cursor-pointer items-center justify-center rounded-md"
                >
                  {show ? <EyeOff size={16} /> : <Eye size={16} />}
                </button>
              </span>
              <small className="app-muted text-[12px]">Mínimo 8 caracteres, mayúsculas, minúsculas y un número.</small>
            </label>

            {error && (
              <p role="alert" className="rounded-[9px] border border-[#D93A40]/25 bg-[var(--p-danger-soft)] px-3 py-2.5 text-[13px] text-[color:var(--p-danger-strong-text)]">
                {error}
              </p>
            )}

            <div className="mt-1 flex justify-end gap-2">
              <button
                type="button"
                onClick={onClose}
                className="app-border app-text h-10 cursor-pointer rounded-[9px] border px-4 text-[13.5px] font-medium hover:bg-[var(--app-surface-muted)]"
              >
                Cancelar
              </button>
              <button
                type="submit"
                disabled={saving || !current || !password}
                className="flex h-10 cursor-pointer items-center gap-2 rounded-[9px] bg-[#1B8FC9] px-4 text-[13.5px] font-semibold text-white transition-colors hover:bg-[#1679AB] disabled:cursor-not-allowed disabled:opacity-60"
              >
                {saving && <Loader2 size={15} className="animate-spin" />}
                Actualizar contraseña
              </button>
            </div>
          </form>
        )}
      </div>
    </div>,
    document.body,
  );
}
