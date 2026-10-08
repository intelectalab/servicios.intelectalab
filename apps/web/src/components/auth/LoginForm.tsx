"use client";

import { useState, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { motion } from "framer-motion";
import { Eye, EyeOff, Loader2, Users, Check } from "lucide-react";
import AuthShell, { item, authLabel, authInput, authInputIcon, authButton, authGhostButton, BRAND } from "./AuthShell";
import { setAuthToken } from "@/lib/auth";

type LoginResponse = {
  token?: string;
  user?: { is_super_admin?: boolean };
  mfa_required?: boolean;
  mfa_token?: string;
  message?: string;
  errors?: Record<string, string[]>;
};

async function postJson(path: string, body: unknown): Promise<LoginResponse> {
  const base = process.env.NEXT_PUBLIC_API_URL ?? "";
  const res = await fetch(`${base}${path}`, {
    method: "POST",
    headers: { "Content-Type": "application/json", Accept: "application/json" },
    body: JSON.stringify(body),
  });

  const data: LoginResponse = await res.json();

  if (!res.ok) {
    const firstError = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;
    throw new Error(firstError || data?.message || "No se pudo iniciar sesión.");
  }

  return data;
}

export default function LoginForm() {
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [remember, setRemember] = useState(true);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  // Segundo paso: solo cuando el usuario tiene mfa_enabled.
  const [mfaToken, setMfaToken] = useState<string | null>(null);
  const [code, setCode] = useState("");

  function finishLogin(data: LoginResponse) {
    if (!data.token) throw new Error("No se pudo iniciar sesión.");

    setAuthToken(data.token, data.user?.is_super_admin ? "platform" : "tenant", remember);
    // Verificamos si es superadmin para redirigirlo a su panel global
    if (data.user?.is_super_admin) {
      router.push("/superadmin/plans");
    } else {
      router.push("/actors");
    }
  }

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError("");
    setLoading(true);

    try {
      const data = await postJson("/v1/login", { email, password, remember });

      if (data.mfa_required && data.mfa_token) {
        setMfaToken(data.mfa_token);
        setCode("");
        return;
      }

      finishLogin(data);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Ocurrió un error. Intenta de nuevo.");
    } finally {
      setLoading(false);
    }
  }

  async function handleVerify(e: FormEvent) {
    e.preventDefault();
    setError("");
    setLoading(true);

    try {
      finishLogin(await postJson("/v1/login/mfa", { mfa_token: mfaToken, code }));
    } catch (err) {
      setError(err instanceof Error ? err.message : "Ocurrió un error. Intenta de nuevo.");
    } finally {
      setLoading(false);
    }
  }

  function backToLogin() {
    setMfaToken(null);
    setCode("");
    setPassword("");
    setError("");
  }

  const errorBox = error && (
    <motion.div
      initial={{ opacity: 0, height: 0 }}
      animate={{ opacity: 1, height: "auto" }}
      role="alert"
      className="rounded-[9px] border border-[#D93A40]/25 bg-[var(--p-danger-soft)] px-3 py-2.5 text-[13px] text-[color:var(--p-danger-strong-text)]"
    >
      {error}
    </motion.div>
  );

  if (mfaToken) {
    return (
      <AuthShell
        key="mfa"
        title="Verificación en dos pasos"
        subtitle={
          <>
            Ingrese el código de 6 dígitos que enviamos a <strong className="app-text">{email}</strong>.
          </>
        }
      >
        <form onSubmit={handleVerify} noValidate className="grid gap-3.5">
          <motion.div variants={item}>
            <label htmlFor="code" className={authLabel}>
              Código de verificación
            </label>
            <input
              id="code"
              inputMode="numeric"
              maxLength={6}
              autoComplete="one-time-code"
              autoFocus
              required
              placeholder="••••••"
              value={code}
              onChange={(e) => setCode(e.target.value.replace(/\D/g, ""))}
              className={`${authInput} !pr-3 text-center !text-[20px] font-semibold tracking-[0.6em]`}
            />
          </motion.div>

          {errorBox}

          <motion.button
            variants={item}
            type="submit"
            disabled={loading || code.length !== 6}
            aria-busy={loading}
            className={authButton}
          >
            {loading ? (
              <>
                <Loader2 size={16} className="animate-spin" />
                Verificando…
              </>
            ) : (
              "Verificar"
            )}
          </motion.button>
          <motion.button variants={item} type="button" onClick={backToLogin} className={authGhostButton}>
            Volver
          </motion.button>
        </form>
      </AuthShell>
    );
  }

  return (
    <AuthShell key="login" title="Iniciar sesión" subtitle="Bienvenido(a). Ingrese sus credenciales.">
      <form onSubmit={handleSubmit} noValidate className="grid gap-3.5">
        <motion.div variants={item}>
          <label htmlFor="email" className={authLabel}>
            Usuario (correo)
          </label>
          <div className="relative">
            <input
              id="email"
              type="email"
              required
              autoComplete="username"
              placeholder="nombre@empresa.pe"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              className={authInput}
            />
            <Users size={17} className={authInputIcon} />
          </div>
        </motion.div>

        <motion.div variants={item}>
          <label htmlFor="password" className={authLabel}>
            Contraseña
          </label>
          <div className="relative">
            <input
              id="password"
              type={showPassword ? "text" : "password"}
              required
              autoComplete="current-password"
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
        </motion.div>

        <motion.div variants={item} className="flex flex-wrap items-center justify-between gap-2.5 text-[12.5px]">
          <button
            type="button"
            role="checkbox"
            aria-checked={remember}
            onClick={() => setRemember((r) => !r)}
            className="flex items-center gap-2"
          >
            <span
              className="flex h-4 w-4 shrink-0 items-center justify-center rounded-[4px] border transition-colors"
              style={{
                borderColor: remember ? BRAND : "var(--p-field-border)",
                backgroundColor: remember ? BRAND : "transparent",
              }}
            >
              {remember && <Check size={12} color="#fff" strokeWidth={3} />}
            </span>
            <span className="app-muted">Recordarme</span>
          </button>
          <a href="/forgot-password" className="font-medium text-[color:var(--p-brand-text)] hover:underline">
            ¿Olvidó su contraseña?
          </a>
        </motion.div>

        {errorBox}

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
              Ingresando…
            </>
          ) : (
            "Ingresar"
          )}
        </motion.button>
      </form>
    </AuthShell>
  );
}
