/**
 * Puente entre el buscador global y las pantallas de listado: el buscador pide
 * abrir un registro y la pantalla que lo muestra (esté ya abierta o se monte
 * después de navegar) recibe el id y abre su ficha.
 */
export type RecordType = "customer" | "supplier" | "employee";

type Listener = (id: string) => void;

const pending = new Map<RecordType, string>();
const listeners = new Map<RecordType, Set<Listener>>();

/** Pide abrir la ficha de un registro; se entrega cuando su pantalla esté escuchando. */
export function requestOpenRecord(type: RecordType, id: string) {
  const active = listeners.get(type);

  if (active?.size) {
    active.forEach((listener) => listener(id));
    return;
  }

  pending.set(type, id);
}

/** La pantalla se suscribe al montar; devuelve la función para dejar de escuchar. */
export function onOpenRecord(type: RecordType, listener: Listener) {
  if (!listeners.has(type)) listeners.set(type, new Set());
  listeners.get(type)!.add(listener);

  const waiting = pending.get(type);
  if (waiting) {
    pending.delete(type);
    listener(waiting);
  }

  return () => {
    listeners.get(type)?.delete(listener);
  };
}
