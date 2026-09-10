"use client";

import { useState, type FormEvent } from "react";
import { motion, AnimatePresence } from "framer-motion";
import { Mail, Loader2, MailCheck } from "lucide-react";
import AuthShell, { item, UnderlineLink, NAVY, NAVY_HOVER, TEAL_DARK } from "./AuthShell";

export default function ForgotPasswordForm() {
  const [email, setEmail] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [sent, setSent] = useState(false);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError("");
    setLoading(true);

    try {
      const base = process.env.NEXT_PUBLIC_API_URL ?? "";
      // Nota: este endpoint aún no existe en tu routes/api.php — ver mensaje debajo del código.
      const res = await fetch(`${base}/v1/forgot-password`, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ email }),
      });

      const data = await res.json().catch(() => null);

      if (!res.ok) {
        const message = data?.errors?.email?.[0] || data?.message || "No se pudo enviar el enlace.";
        throw new Error(message);
      }

      setSent(true);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Ocurrió un error. Intenta de nuevo.");
    } finally {
      setLoading(false);
    }
  }

  return (
    <AuthShell
      title={sent ? "Revisa tu correo" : "¿Olvidaste tu contraseña?"}
      subtitle={
        sent
          ? `Enviamos un enlace para restablecer tu contraseña a ${email}.`
          : "Ingresa tu correo y te enviaremos un enlace para restablecerla."
      }
      footer={
        <>
          ¿Recordaste tu contraseña?{" "}
          <UnderlineLink href="/login" className="font-semibold" style={{ color: NAVY }}>
            Inicia sesión
          </UnderlineLink>
        </>
      }
    >
      <AnimatePresence mode="wait">
        {sent ? (
          <motion.div
            key="sent"
            initial={{ opacity: 0, y: 8 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0 }}
            transition={{ duration: 0.3 }}
            className="mt-7 flex flex-col items-center text-center"
          >
            <span className="flex h-12 w-12 items-center justify-center rounded-full bg-[#80CED7]/15">
              <MailCheck size={22} className="text-[#4FA9B4]" />
            </span>
            <button
              type="button"
              onClick={() => setSent(false)}
              className="mt-6 text-[13.5px] font-semibold text-[#5C6F81] transition-colors hover:text-[#0F1F2E]"
            >
              ¿No te llegó? Intentar con otro correo
            </button>
          </motion.div>
        ) : (
          <motion.form
            key="form"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            onSubmit={handleSubmit}
            noValidate
            className="mt-7"
          >
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
                  Enviando…
                </>
              ) : (
                "Enviar enlace"
              )}
            </motion.button>
          </motion.form>
        )}
      </AnimatePresence>
    </AuthShell>
  );
}
