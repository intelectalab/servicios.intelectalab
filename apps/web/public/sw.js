// App del técnico sin señal: guarda la pantalla /campo y los archivos de la web la primera vez que
// se abre con conexión, para poder abrirla después sin señal. Los datos (la ruta del día y lo que
// el técnico hace) ya los guarda la propia app en el teléfono; aquí nunca se guarda la API ni
// otras pantallas del sistema.
const CACHE = "erp-campo-v1";

self.addEventListener("install", () => self.skipWaiting());

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((key) => key.startsWith("erp-campo-") && key !== CACHE).map((key) => caches.delete(key))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener("fetch", (event) => {
  const request = event.request;
  if (request.method !== "GET") return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin || url.pathname.startsWith("/api/")) return;

  // La pantalla del técnico: primero la red (siempre la versión al día); sin señal, la guardada.
  if (request.mode === "navigate" && url.pathname.startsWith("/campo")) {
    event.respondWith(
      fetch(request)
        .then((response) => {
          if (response.ok) {
            const copy = response.clone();
            caches.open(CACHE).then((cache) => cache.put("/campo", copy));
          }
          return response;
        })
        .catch(() => caches.match("/campo").then((cached) => cached || Response.error())),
    );
    return;
  }

  // Archivos de la web con nombre único por versión (JS, CSS, fuentes, íconos): primero lo guardado.
  if (url.pathname.startsWith("/_next/static/") || url.pathname.startsWith("/fonts/") || url.pathname.startsWith("/icons/")) {
    event.respondWith(
      caches.match(request).then((cached) => cached || fetch(request).then((response) => {
        if (response.ok) {
          const copy = response.clone();
          caches.open(CACHE).then((cache) => cache.put(request, copy));
        }
        return response;
      })),
    );
  }
});
