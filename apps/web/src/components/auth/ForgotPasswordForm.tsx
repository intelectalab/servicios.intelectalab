"use client";

import { useState, type FormEvent } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { motion } from "framer-motion";
import { Send, Loader2 } from "lucide-react";
import AuthShell, { item, authLabel, authInput, authInputIcon, authButton, authGhostButton } from "./AuthShell";
import { loginPath, useLoginBrand, withCompany, type LoginBrand } from "@/lib/login-brand";

export default function ForgotPasswordForm({ initialBrand }: { initialBrand?: LoginBrand | null }) {
  const router = useRouter();
  // Desde el ingreso de una empresa (?empresa=…) se conserva su marca (ya consultada por el servidor).
  const { brand, ready } = useLoginBrand(useSearchParams().get("empresa"), initialBrand);
  const company = brand?.slug ?? null;
  const [email, setEmail] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError("");
    setLoading(true);

    try {
      const base = process.env.NEXT_PUBLIC_API_URL ?? "";
      const res = await fetch(`${base}/v1/forgot-password`, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ email }),
      });

      const data = await res.json().catch(() => null);

      if (!res.ok) {
        const message = data?.errors?.email?.[0] || data?.message || "No se pudo enviar el código.";
        throw new Error(message);
      }

      // Siguiente paso: ingresar el código recibido y la nueva contraseña.
      router.push(withCompany(`/reset-password?email=${encodeURIComponent(email)}`, company));
    } catch (err) {
      setError(err instanceof Error ? err.message : "Ocurrió un error. Intenta de nuevo.");
      setLoading(false);
    }
  }

  return (
    <AuthShell
      brand={brand}
      pending={!ready}
      title="Recuperar contraseña"
      subtitle="Le enviaremos un código para crear una nueva contraseña."
    >
      <form onSubmit={handleSubmit} noValidate className="grid gap-3.5">
        <motion.div variants={item}>
          <label htmlFor="email" className={authLabel}>
            Correo electrónico
          </label>
          <div className="relative">
            <input
              id="email"
              type="email"
              required
              autoComplete="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              className={authInput}
            />
            <Send size={17} className={authInputIcon} />
          </div>
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
              Enviando…
            </>
          ) : (
            "Enviar código"
          )}
        </motion.button>
        <motion.a variants={item} href={loginPath(company)} className={authGhostButton}>
          Volver al inicio de sesión
        </motion.a>
      </form>
    </AuthShell>
  );
}
