import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // En desarrollo el sistema se abre en app.localhost:3000 (SITE_URL / APP_URL en .env.local).
  allowedDevOrigins: ["app.localhost"],
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
