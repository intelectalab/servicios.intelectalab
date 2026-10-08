import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // En desarrollo el sistema se abre en app.localhost:3000 (SITE_URL / APP_URL en .env.local).
  allowedDevOrigins: ["app.localhost"],
};

export default nextConfig;
