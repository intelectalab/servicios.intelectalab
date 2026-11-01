"use client";

import { useState, type FormEvent } from "react";
import { useSearchParams, useRouter } from "next/navigation";
import { motion } from "framer-motion";
import { Eye, EyeOff, Loader2, CheckCircle2, Info } from "lucide-react";
import AuthShell, { item, authLabel, authInput, authButton, authGhostButton, BRAND, BRAND_SOFT } from "./AuthShell";
import { loginPath, useLoginBrand, type LoginBrand } from "@/lib/login-brand";

export default function ResetPasswordForm({ initialBrand }: { initialBrand?: LoginBrand | null }) {
  const router = useRouter();
  const searchParams = useSearchParams();
  // Llega desde "Recuperar contraseña"; si se abre la página directamente, se pide el correo.
  const emailFromQuery = searchParams.get("email") ?? "";
  // Desde la invitación o el ingreso de una empresa (?empresa=…) se conserva su marca.
  const { brand, ready } = useLoginBrand(searchParams.get("empresa"), initialBrand);
  const company = brand?.slug ?? null;

  const [email, setEmail] = useState(emailFromQuery);
  const [code, setCode] = useState("");
  const [password, setPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [done, setDone] = useState(false);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError("");

    if (password.length < 8 || !/[a-z]/.test(password) || !/[A-Z]/.test(password) || !/\d/.test(password)) {
      setError("La contraseña debe tener mínimo 8 caracteres, mayúsculas, minúsculas y un número.");
      return;
    }

    setLoading(true);
    try {
      const base = process.env.NEXT_PUBLIC_API_URL ?? "";
      const res = await fetch(`${base}/v1/reset-password`, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ token: code, email, password }),
      });

      const data = await res.json().catch(() => null);

      if (!res.ok) {
        const firstError = data?.errors ? (Object.values(data.errors)[0] as string[])?.[0] : undefined;
        throw new Error(firstError || data?.message || "No se pudo guardar la contraseña.");
      }

      setDone(true);
      setTimeout(() => router.push(loginPath(company)), 2000);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Ocurrió un error. Intenta de nuevo.");
    } finally {
      setLoading(false);
    }
  }

  if (done) {
    return (
      <AuthShell brand={brand} title="Contraseña actualizada" subtitle="Te estamos redirigiendo para que inicies sesión.">
        <div className="mt-7 flex flex-col items-center text-center">
          <span className="flex h-12 w-12 items-center justify-center rounded-full" style={{ backgroundColor: BRAND_SOFT }}>
            <CheckCircle2 size={22} color={`var(--p-accent, ${BRAND})`} />
          </span>
        </div>
      </AuthShell>
    );
  }

  return (
    <AuthShell
      brand={brand}
      pending={!ready}
      title="Crear nueva contraseña"
      subtitle="Ingrese el código recibido y su nueva contraseña."
    >
      <form onSubmit={handleSubmit} noValidate className="grid gap-3.5">
        <motion.div
          variants={item}
          className="flex items-start gap-2.5 rounded-[9px] px-3 py-2.5 text-[13px] text-[color:var(--p-brand-soft-text)]"
          style={{ backgroundColor: BRAND_SOFT }}
        >
          <Info size={16} className="mt-0.5 shrink-0" />
          <span>Si el correo existe, enviamos un código de 6 caracteres.</span>
        </motion.div>

        {!emailFromQuery && (
          <motion.div variants={item}>
            <label htmlFor="email" className={authLabel}>
              Correo electrónico
            </label>
            <input
              id="email"
              type="email"
              required
              autoComplete="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              className={`${authInput} !pr-3`}
            />
          </motion.div>
        )}

        <motion.div variants={item}>
          <label htmlFor="code" className={authLabel}>
            Código
          </label>
          <input
            id="code"
            required
            maxLength={6}
            autoComplete="one-time-code"
            autoCapitalize="characters"
            value={code}
            onChange={(e) => setCode(e.target.value.toUpperCase().trim())}
            className={`${authInput} !pr-3 font-semibold tracking-[0.3em]`}
          />
        </motion.div>

        <motion.div variants={item}>
          <label htmlFor="password" className={authLabel}>
            Nueva contraseña
          </label>
          <div className="relative">
            <input
              id="password"
              type={showPassword ? "text" : "password"}
              required
              autoComplete="new-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              className={authInput}
            />
            <button
              type="button"
              onClick={() => setShowPassword((s) => !s)}
              aria-label={showPassword ? "Ocultar contraseña" : "Mostrar contraseña"}
              className="absolute right-1 top-1/2 flex h-[34px] w-[34px] -translate-y-1/2 items-center justify-center rounded-md text-[#8A93A3] transition-colors hover:text-[color:var(--app-text)]"
            >
              {showPassword ? <EyeOff size={17} /> : <Eye size={17} />}
            </button>
          </div>
          <small className="mt-1.5 block text-[12px] text-[#8A93A3]">
            Mínimo 8 caracteres, mayúsculas, minúsculas y un número.
          </small>
        </motion.div>

        {error && (
          <motion.div
            initial={{ opacity: 0, height: 0 }}
            animate={{ opacity: 1, height: "auto" }}
            role="alert"
            className="rounded-[9px] border border-[#D93A40]/25 bg-[var(--p-danger-soft)] px-3 py-2.5 text-[13px] text-[color:var(--p-danger-strong-text)]"
          >
            {error}
          </motion.div>
        )}

        <motion.button
          variants={item}
          type="submit"
          disabled={loading}
          aria-busy={loading}
          className={authButton}
        >
          {loading ? (
            <>
              <Loader2 size={16} className="animate-spin" />
              Guardando…
            </>
          ) : (
            "Guardar contraseña"
          )}
        </motion.button>
        <motion.a variants={item} href={loginPath(company)} className={authGhostButton}>
          Cancelar
        </motion.a>
      </form>
    </AuthShell>
  );
}
