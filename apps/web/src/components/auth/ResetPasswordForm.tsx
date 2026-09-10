"use client";

import { useState, type FormEvent } from "react";
import { useSearchParams, useRouter } from "next/navigation";
import { motion } from "framer-motion";
import { Lock, Eye, EyeOff, Loader2, CheckCircle2 } from "lucide-react";
import AuthShell, { item, UnderlineLink, NAVY, NAVY_HOVER, TEAL_DARK } from "./AuthShell";

export default function ResetPasswordForm() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const token = searchParams.get("token") ?? "";
  const email = searchParams.get("email") ?? "";

  const [password, setPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [done, setDone] = useState(false);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError("");

    if (password !== confirmPassword) {
      setError("Las contraseñas no coinciden.");
      return;
    }
    if (password.length < 8) {
      setError("La contraseña debe tener al menos 8 caracteres.");
      return;
    }

    setLoading(true);
    try {
      const base = process.env.NEXT_PUBLIC_API_URL ?? "";
      // Nota: este endpoint aún no existe en tu routes/api.php — ver mensaje debajo del código.
      const res = await fetch(`${base}/v1/reset-password`, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({
          token,
          email,
          password,
          password_confirmation: confirmPassword,
        }),
      });

      const data = await res.json().catch(() => null);

      if (!res.ok) {
        const message = data?.message || "No se pudo restablecer la contraseña.";
        throw new Error(message);
      }

      setDone(true);
      setTimeout(() => router.push("/login"), 2000);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Ocurrió un error. Intenta de nuevo.");
    } finally {
      setLoading(false);
    }
  }

  if (!token || !email) {
    return (
      <AuthShell
        title="Enlace inválido"
        subtitle="Este enlace para restablecer tu contraseña no es válido o ya expiró."
        footer={
          <UnderlineLink href="/forgot-password" className="font-semibold" style={{ color: NAVY }}>
            Solicitar uno nuevo
          </UnderlineLink>
        }
      >
        <div />
      </AuthShell>
    );
  }

  if (done) {
    return (
      <AuthShell title="Contraseña actualizada" subtitle="Te estamos redirigiendo para que inicies sesión.">
        <div className="mt-7 flex flex-col items-center text-center">
          <span className="flex h-12 w-12 items-center justify-center rounded-full bg-[#80CED7]/15">
            <CheckCircle2 size={22} className="text-[#4FA9B4]" />
          </span>
        </div>
      </AuthShell>
    );
  }

  return (
    <AuthShell
      title="Crea una nueva contraseña"
      subtitle={`Para la cuenta ${email}.`}
      footer={
        <UnderlineLink href="/login" className="font-semibold" style={{ color: NAVY }}>
          Volver a iniciar sesión
        </UnderlineLink>
      }
    >
      <form onSubmit={handleSubmit} noValidate className="mt-7">
        <motion.div variants={item}>
          <label htmlFor="password" className="mb-1.5 block text-[13px] font-semibold">
            Nueva contraseña
          </label>
          <div className="relative">
            <Lock
              size={17}
              className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-[#5C6F81]"
            />
            <input
              id="password"
              type={showPassword ? "text" : "password"}
              required
              autoComplete="new-password"
              placeholder="Mínimo 8 caracteres"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              className="w-full rounded-xl border-[1.5px] border-[#E1E7EC] bg-[#F6F8FA] py-2.5 pl-[42px] pr-11 text-[15px] text-[#0F1F2E] transition-all placeholder:text-[#5C6F81]/60 focus:border-[color:var(--teal-dark)] focus:bg-white focus:shadow-[0_0_0_4px_rgba(128,206,215,0.19)] focus:outline-none"
              style={{ ["--teal-dark" as string]: TEAL_DARK }}
            />
            <button
              type="button"
              onClick={() => setShowPassword((s) => !s)}
              aria-label={showPassword ? "Ocultar contraseña" : "Mostrar contraseña"}
              className="absolute right-3 top-1/2 flex -translate-y-1/2 text-[#5C6F81] transition-colors hover:text-[#0F1F2E]"
            >
              {showPassword ? <EyeOff size={18} /> : <Eye size={18} />}
            </button>
          </div>
        </motion.div>

        <motion.div variants={item} className="mt-[18px]">
          <label htmlFor="confirmPassword" className="mb-1.5 block text-[13px] font-semibold">
            Confirmar contraseña
          </label>
          <div className="relative">
            <Lock
              size={17}
              className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-[#5C6F81]"
            />
            <input
              id="confirmPassword"
              type={showPassword ? "text" : "password"}
              required
              autoComplete="new-password"
              placeholder="Repite la contraseña"
              value={confirmPassword}
              onChange={(e) => setConfirmPassword(e.target.value)}
              className="w-full rounded-xl border-[1.5px] border-[#E1E7EC] bg-[#F6F8FA] py-2.5 pl-[42px] pr-3.5 text-[15px] text-[#0F1F2E] transition-all placeholder:text-[#5C6F81]/60 focus:border-[color:var(--teal-dark)] focus:bg-white focus:shadow-[0_0_0_4px_rgba(128,206,215,0.19)] focus:outline-none"
              style={{ ["--teal-dark" as string]: TEAL_DARK }}
            />
          </div>
        </motion.div>

        {error && (
          <motion.div
            initial={{ opacity: 0, height: 0 }}
            animate={{ opacity: 1, height: "auto" }}
            className="mt-4 rounded-lg border border-[#C4453B]/20 bg-[#FCEAE8] px-3 py-2.5 text-[13.5px] text-[#C4453B]"
          >
            {error}
          </motion.div>
        )}

        <motion.button
          variants={item}
          type="submit"
          disabled={loading}
          aria-busy={loading}
          whileHover={loading ? undefined : { scale: 1.02 }}
          whileTap={loading ? undefined : { scale: 0.98 }}
          transition={{ duration: 0.15 }}
          className="mt-6 flex w-full items-center justify-center gap-2 rounded-xl py-3 text-[15px] font-semibold text-white shadow-[0_4px_14px_-4px_rgba(0,35,71,0.35)] transition-shadow hover:shadow-[0_10px_24px_-8px_rgba(0,35,71,0.4)] disabled:cursor-not-allowed disabled:opacity-70"
          style={{ background: `linear-gradient(180deg, ${NAVY_HOVER} 0%, ${NAVY} 100%)` }}
        >
          {loading ? (
            <>
              <Loader2 size={16} className="animate-spin" />
              Guardando…
            </>
          ) : (
            "Restablecer contraseña"
          )}
        </motion.button>
      </form>
    </AuthShell>
  );
}
