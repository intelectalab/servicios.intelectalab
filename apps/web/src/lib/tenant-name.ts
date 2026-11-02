// El nombre de la empresa que muestran la cabecera, el menú y el título de la pestaña. Al cambiar
// el nombre comercial en Configuración, se avisa para que se vea al instante, sin recargar.
const TENANT_NAME_EVENT = "erp-tenant-name-change";

export function announceTenantName(name: string) {
  if (typeof window !== "undefined") window.dispatchEvent(new CustomEvent<string>(TENANT_NAME_EVENT, { detail: name }));
}

export function onTenantName(listener: (name: string) => void): () => void {
  const handler = (event: Event) => listener((event as CustomEvent<string>).detail);
  window.addEventListener(TENANT_NAME_EVENT, handler);
  return () => window.removeEventListener(TENANT_NAME_EVENT, handler);
}
