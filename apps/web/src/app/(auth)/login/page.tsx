import LoginForm from "@/components/auth/LoginForm";

export default function LoginPage() {
  // Con dos dominios la página pública vive en SITE_URL; con uno solo, en la raíz de este.
  const site = process.env.SITE_URL?.replace(/\/+$/, "") ?? "";
  return <LoginForm homeUrl={`${site}/`} plansUrl={`${site}/#plans`} />;
}
