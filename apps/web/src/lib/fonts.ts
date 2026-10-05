import { Poppins } from "next/font/google";

/** Tipografía del prototipo; por ahora la usan las pantallas de acceso y la de bloqueo. */
export const poppins = Poppins({ subsets: ["latin"], weight: ["400", "500", "600", "700"], display: "swap" });
