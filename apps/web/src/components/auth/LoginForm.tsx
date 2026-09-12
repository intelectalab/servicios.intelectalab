"use client";

import { useState, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { motion } from "framer-motion";
import { Eye, EyeOff, Loader2, Mail, Lock, Check } from "lucide-react";
import AuthShell, { item, UnderlineLink, NAVY, NAVY_HOVER, TEAL_DARK } from "./AuthShell";
import { setAuthToken } from "@/lib/auth";

export default function LoginForm() {
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [remember, setRemember] = useState(true);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError("");
    setLoading(true);

    try {
      const base = process.env.NEXT_PUBLIC_API_URL ?? "";
      const res = await fetch(`${base}/v1/login`, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ email, password }),
      });

      const data = await res.json();

      if (!res.ok) {
        const message =
          data?.errors?.email?.[0] || data?.message || "No se pudo iniciar sesión.";
        throw new Error(message);
      }

      setAuthToken(data.token);
      router.push("/customers");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Ocurrió un error. Intenta de nuevo.");
    } finally {
      setLoading(false);
    }
  }

  return (
    <AuthShell
      title="Bienvenido de nuevo"
      subtitle="Ingresa tus credenciales para continuar."
      footer={
        <>
          ¿Problemas para acceder?{" "}
          <UnderlineLink href="#" className="font-semibold" style={{ color: NAVY }}>
            Contacta a soporte
          </UnderlineLink>
        </>
      }
    >
      <form onSubmit={handleSubmit} noValidate className="mt-7">
        <motion.div variants={item}>
          <label htmlFor="email" className="mb-1.5 block text-[13px] font-semibold">
            Correo electrónico
          </label>
          <div className="relative">
            <Mail
              size={17}
              className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-[#5C6F81]"
            />
            <input
              id="email"
              type="email"
              required
              autoComplete="email"
              placeholder="tu@empresa.com"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              className="w-full rounded-xl border-[1.5px] border-[#E1E7EC] bg-[#F6F8FA] py-2.5 pl-[42px] pr-3.5 text-[15px] text-[#0F1F2E] transition-all placeholder:text-[#5C6F81]/60 focus:border-[color:var(--teal-dark)] focus:bg-white focus:shadow-[0_0_0_4px_rgba(27,146,201,0.19)] focus:outline-none"
              style={{ ["--teal-dark" as string]: TEAL_DARK }}
            />
          </div>
        </motion.div>

        <motion.div variants={item} className="mb-1.5 mt-[18px] flex items-baseline justify-between">
          <label htmlFor="password" className="block text-[13px] font-semibold">
            Contraseña
          </label>
          <UnderlineLink href="/forgot-password" className="text-[13px] text-[#5C6F81] transition-colors hover:text-[#0C74A6]">
            ¿Olvidaste tu contraseña?
          </UnderlineLink>
        </motion.div>
        <motion.div variants={item} className="relative">
          <Lock
            size={17}
            className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-[#5C6F81]"
          />
          <input
            id="password"
            type={showPassword ? "text" : "password"}
            required
            autoComplete="current-password"
            placeholder="••••••••"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            className="w-full rounded-xl border-[1.5px] border-[#E1E7EC] bg-[#F6F8FA] py-2.5 pl-[42px] pr-11 text-[15px] text-[#0F1F2E] transition-all focus:border-[#1B92C9] focus:bg-white focus:shadow-[0_0_0_4px_rgba(27,146,201,0.19)] focus:outline-none"
          />
          <button
            type="button"
            onClick={() => setShowPassword((s) => !s)}
            aria-label={showPassword ? "Ocultar contraseña" : "Mostrar contraseña"}
            className="absolute right-3 top-1/2 flex -translate-y-1/2 text-[#5C6F81] transition-colors hover:text-[#0F1F2E]"
          >
            {showPassword ? <EyeOff size={18} /> : <Eye size={18} />}
          </button>
        </motion.div>

        <motion.button
          variants={item}
          type="button"
          role="checkbox"
          aria-checked={remember}
          onClick={() => setRemember((r) => !r)}
          className="mt-[18px] flex items-center gap-2.5"
          whileTap={{ scale: 0.97 }}
        >
          <span
            className="flex h-[18px] w-[18px] shrink-0 items-center justify-center rounded-[5px] border-[1.5px] transition-colors"
            style={{
              borderColor: remember ? NAVY : "#E1E7EC",
              backgroundColor: remember ? NAVY : "transparent",
            }}
          >
            {remember && <Check size={13} color="#fff" strokeWidth={3} />}
          </span>
          <span className="text-sm text-[#5C6F81]">Mantener sesión iniciada</span>
        </motion.button>

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
          className="mt-6 flex w-full items-center justify-center gap-2 rounded-xl py-3 text-[15px] font-semibold text-white shadow-[0_4px_14px_-4px_rgba(12,116,166,0.35)] transition-shadow hover:shadow-[0_10px_24px_-8px_rgba(12,116,166,0.4)] disabled:cursor-not-allowed disabled:opacity-70"
          style={{ background: `linear-gradient(180deg, ${NAVY_HOVER} 0%, ${NAVY} 100%)` }}
        >
          {loading ? (
            <>
              <Loader2 size={16} className="animate-spin" />
              Ingresando…
            </>
          ) : (
            "Iniciar sesión"
          )}
        </motion.button>
      </form>
    </AuthShell>
  );
}
