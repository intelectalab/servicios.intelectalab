import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // En desarrollo el sistema se abre en app.localhost:3000 (SITE_URL / APP_URL en .env.local).
  allowedDevOrigins: ["app.localhost"],
  // En desarrollo (y en las pruebas E2E de GitHub, con API_PROXY_TARGET) la web llama a la API por su
  // mismo origen (/api), como en producción, para que viaje la cookie HttpOnly de la sesión. En el
  // servidor /api lo atiende nginx antes de llegar a Next.
  async rewrites() {
    if (process.env.NODE_ENV === "production" && !process.env.API_PROXY_TARGET) return [];
    return [{ source: "/api/:path*", destination: `${process.env.API_PROXY_TARGET ?? "http://127.0.0.1:8000"}/api/:path*` }];
  },
  // Service worker de la App del técnico: siempre la versión al día y solo con scripts propios.
  async headers() {
    return [
      {
        source: "/sw.js",
        headers: [
          { key: "Content-Type", value: "application/javascript; charset=utf-8" },
          { key: "Cache-Control", value: "no-cache, no-store, must-revalidate" },
          { key: "Content-Security-Policy", value: "default-src 'self'; script-src 'self'" },
        ],
      },
    ];
  },
};

export default nextConfig;
