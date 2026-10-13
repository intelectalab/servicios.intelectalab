import type { MetadataRoute } from "next";

// Permite instalar el sistema en el teléfono ("Agregar a pantalla de inicio"): abre a pantalla
// completa, directo en la App del técnico. Quien no sea técnico entra igual y usa el menú.
export default function manifest(): MetadataRoute.Manifest {
  return {
    name: "ERP SaaS",
    short_name: "ERP Campo",
    description: "App del técnico: visitas del día, checklist y evidencias.",
    start_url: "/campo",
    scope: "/",
    display: "standalone",
    orientation: "portrait",
    background_color: "#ffffff",
    theme_color: "#1B8FC9",
    icons: [
      { src: "/icons/app-192.png", sizes: "192x192", type: "image/png" },
      { src: "/icons/app-512.png", sizes: "512x512", type: "image/png" },
    ],
  };
}
