"use client";

import { useEffect, useRef, useState, type ReactNode } from "react";
import "leaflet/dist/leaflet.css";

const BLUE = "#1B8FC9";

export type MapPoint = { id: string; number: number; latitude: number; longitude: number; title: string; active: boolean };

/**
 * Mapa real de las visitas (calles de OpenStreetMap, con Leaflet): los puntos numerados en el
 * orden sugerido, unidos por la ruta. El mapa necesita internet: sin conexión, o si sus imágenes
 * no cargan, se muestra el dibujo esquemático (`fallback`) con un aviso.
 */
export function FieldMap({ points, offline, fallback }: { points: MapPoint[]; offline: boolean; fallback: ReactNode }) {
  const holder = useRef<HTMLDivElement>(null);
  const [failed, setFailed] = useState(false);
  const key = JSON.stringify(points);
  const usable = !offline && !failed && points.length > 0;

  useEffect(() => {
    if (!usable || !holder.current) return;
    let cancelled = false;
    let dispose = () => {};

    // Leaflet usa `window`: se carga solo en el navegador.
    import("leaflet").then(({ default: L }) => {
      if (cancelled || !holder.current) return;
      const shown: MapPoint[] = JSON.parse(key);
      const map = L.map(holder.current, { zoomControl: true, attributionControl: true, scrollWheelZoom: false });

      let loaded = 0;
      let errors = 0;
      const tiles = L.tileLayer("https://tile.openstreetmap.org/{z}/{x}/{y}.png", {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a>',
      });
      tiles.on("tileload", () => { loaded += 1; });
      // Si ninguna imagen del mapa llega, no hay mapa que mostrar: se pasa al esquemático.
      tiles.on("tileerror", () => {
        errors += 1;
        if (!loaded && errors >= 3 && !cancelled) setFailed(true);
      });
      tiles.addTo(map);

      const line = shown.map((point): [number, number] => [point.latitude, point.longitude]);
      if (line.length > 1) L.polyline(line, { color: BLUE, weight: 3, dashArray: "6 6" }).addTo(map);
      shown.forEach((point) => {
        const icon = L.divIcon({
          className: "",
          iconSize: [26, 26],
          iconAnchor: [13, 13],
          html: `<span style="display:grid;place-items:center;width:26px;height:26px;border-radius:50%;border:2.5px solid #fff;background:${point.active ? "var(--p-warning-text)" : BLUE};color:#fff;font:700 12px/1 inherit;box-shadow:0 1px 4px rgba(0,0,0,.35)">${point.number}</span>`,
        });
        L.marker([point.latitude, point.longitude], { icon, title: point.title, keyboard: false }).addTo(map);
      });

      if (line.length === 1) map.setView(line[0], 15);
      else map.fitBounds(L.latLngBounds(line), { padding: [28, 28], maxZoom: 16 });

      dispose = () => map.remove();
    }).catch(() => { if (!cancelled) setFailed(true); });

    return () => {
      cancelled = true;
      dispose();
    };
  }, [usable, key]);

  if (!points.length) return <>{fallback}</>;

  if (!usable) {
    return (
      <>
        {fallback}
        <p className="app-muted px-4 pt-1.5 text-center text-xs">
          {offline ? "Sin conexión: se muestra el mapa esquemático." : "No se pudo cargar el mapa: se muestra el esquemático."}
          {!offline && (
            <button type="button" onClick={() => setFailed(false)} className="ml-1 cursor-pointer font-medium text-[color:var(--p-brand-text)] hover:underline">Reintentar</button>
          )}
        </p>
      </>
    );
  }

  return (
    <div className="px-3 pt-2.5">
      <div ref={holder} role="img" aria-label="Mapa de visitas del día" className="isolate h-[210px] w-full overflow-hidden rounded-[14px] bg-[var(--app-surface-muted)]" />
    </div>
  );
}
