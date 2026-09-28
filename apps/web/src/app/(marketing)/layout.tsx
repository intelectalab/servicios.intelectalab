"use client";

import { useRouter } from "next/navigation";
import Image from "next/image";
import { Phone, Mail, MapPin, Send } from "lucide-react";

export default function MarketingLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter();

  const scrollToPlans = () => {
    const element = document.getElementById("plans");
    element?.scrollIntoView({ behavior: "smooth" });
  };

  return (
    <div className="min-h-screen bg-slate-50 text-slate-900 flex flex-col font-sans">
      {/* Header */}
      <header className="sticky top-0 z-40 h-20 bg-white/90 backdrop-blur-md border-b border-slate-200 px-4 sm:px-8 flex items-center justify-between shadow-2xs">
        <div className="flex items-center cursor-pointer" onClick={() => window.scrollTo({ top: 0, behavior: 'smooth' })}>
          <Image src="/logo.png" alt="Logo ERP" width={130} height={38} className="object-contain h-8 sm:h-9 w-auto" />
        </div>
        <div className="flex items-center gap-3 sm:gap-6">
          <button
            onClick={scrollToPlans}
            className="hidden sm:inline-block text-sm font-semibold text-slate-700 hover:text-[var(--app-brand-deep)] transition-colors cursor-pointer"
          >
            Planes
          </button>
          <button
            onClick={() => router.push("/login")}
            className="px-3 sm:px-4 py-2 text-xs sm:text-sm font-semibold rounded-xl border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 transition-colors shadow-2xs cursor-pointer"
          >
            Iniciar sesión
          </button>
          <button
            onClick={scrollToPlans}
            className="px-3 sm:px-4 py-2 text-xs sm:text-sm font-semibold rounded-xl bg-[var(--app-brand-deep)] text-white shadow-sm hover:bg-[var(--app-brand)] transition-colors cursor-pointer"
          >
            30 días gratis
          </button>
        </div>
      </header>

      {/* Main Content */}
      <main className="flex-1">{children}</main>

      {/* Footer */}
      <footer className="bg-[#0b3c5d] text-white pt-16 pb-8 border-t border-slate-800">
        <div className="max-w-7xl mx-auto px-8 grid grid-cols-1 md:grid-cols-3 gap-12 pb-12 border-b border-white/10">
          
          {/* Columna 1: Logo y descripción + Redes con Hover Elegante */}
          <div className="space-y-4 flex flex-col items-center">
            <div className="rounded-xl w-fit">
              <Image src="/logo-blanco.png" alt="Logo" width={160} height={55}/>
            </div>
            <p className="text-m text-slate-300 leading-relaxed max-w-xs text-center">
              Especialistas en Diseño Web, Desarrollo de Software
            </p>
            <div className="flex items-center gap-3 pt-2">
              {/* Facebook */}
              <a href="https://www.facebook.com/gscreativas.pe" target="_blank" rel="noreferrer" className="w-9 h-9 rounded-full bg-white/10 flex items-center justify-center hover:bg-white hover:text-[#0b3c5d] transition-all transform hover:scale-110 cursor-pointer shadow-xs">
                <svg className="w-4 h-4 fill-current" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
              </a>
              {/* Instagram */}
              <a href="https://www.instagram.com/gscreativas.pe/" target="_blank" rel="noreferrer" className="w-9 h-9 rounded-full bg-white/10 flex items-center justify-center hover:bg-white hover:text-[#0b3c5d] transition-all transform hover:scale-110 cursor-pointer shadow-xs">
                <svg className="w-4 h-4 fill-current" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>
              </a>
              {/* LinkedIn */}
              <a href="https://www.linkedin.com/company/gscreativas/" target="_blank" rel="noreferrer" className="w-9 h-9 rounded-full bg-white/10 flex items-center justify-center hover:bg-white hover:text-[#0b3c5d] transition-all transform hover:scale-110 cursor-pointer shadow-xs">
                <svg className="w-4 h-4 fill-current" viewBox="0 0 24 24"><path d="M19 0h-14c-2.761 0-5 2.239-5 5v14c0 2.761 2.239 5 5 5h14c2.762 0 5-2.239 5-5v-14c0-2.761-2.238-5-5-5zm-11 19h-3v-11h3v11zm-1.5-12.268c-.966 0-1.75-.79-1.75-1.764s.784-1.764 1.75-1.764 1.75.79 1.75 1.764-.783 1.764-1.75 1.764zm13.5 12.268h-3v-5.604c0-3.368-4-3.113-4 0v5.604h-3v-11h3v1.765c1.396-2.586 7-2.777 7 2.476v6.759z"/></svg>
              </a>
            </div>
          </div>

          {/* Columna 2: Contacto con Hover Interactivo */}
          <div className="space-y-4">
            <h4 className="text-sm font-bold uppercase tracking-wider text-blue-200">Contacto</h4>
            <ul className="space-y-3 text-sm text-slate-200">
              <li className="flex items-center gap-2.5 group">
                <div className="p-2 rounded-lg bg-white/5 group-hover:bg-white/20 transition-colors">
                  <Phone size={15} className="text-blue-300" />
                </div>
                <span className="group-hover:text-white transition-colors">957 269 431</span>
              </li>
              <li className="flex items-center gap-2.5 group">
                <div className="p-2 rounded-lg bg-white/5 group-hover:bg-white/20 transition-colors">
                  <Mail size={15} className="text-blue-300" />
                </div>
                <span className="group-hover:text-white transition-colors">informes@gscreativas.com</span>
              </li>
              <li className="flex items-center gap-2.5 group">
                <div className="p-2 rounded-lg bg-white/5 group-hover:bg-white/20 transition-colors">
                  <MapPin size={15} className="text-blue-300" />
                </div>
                <span className="group-hover:text-white transition-colors">Av. las Almendras 286 Los Olivos - Lima - Perú</span>
              </li>
            </ul>
          </div>

          {/* Columna 3: Boletín de Noticias */}
          <div className="space-y-4">
            <h4 className="text-sm font-bold uppercase tracking-wider text-blue-200">Boletín de Noticias</h4>
            <p className="text-sm text-slate-300">Suscríbete para recibir nuestras últimas novedades y actualizaciones.</p>
            <div className="space-y-2">
              <input
                type="email"
                placeholder="Correo Electrónico"
                className="w-full px-4 py-2.5 rounded-lg bg-white/10 border border-white/20 text-xs text-white placeholder-slate-400 focus:outline-none focus:border-white transition-all"
              />
              <button className="w-full py-2.5 rounded-lg bg-[var(--app-brand-deep)] hover:bg-[var(--app-brand)] text-white font-bold text-xs transition-all transform hover:-translate-y-0.5 shadow-sm cursor-pointer flex items-center justify-center gap-2">
                <Send size={14} /> SUSCRIBIRSE
              </button>
            </div>
          </div>

        </div>

        {/* Copyright y Enlaces Legales */}
        <div className="max-w-7xl mx-auto px-8 pt-6 flex flex-col md:flex-row items-center justify-between text-sm text-slate-400 gap-4">
          <p>Copyright © {new Date().getFullYear()} Grupo de Soluciones Creativas</p>
          <div className="flex items-center gap-6">
            <a href="#" className="hover:text-white transition-colors cursor-pointer">Políticas de Privacidad</a>
            <a href="#" className="hover:text-white transition-colors cursor-pointer">Términos y Condiciones</a>
            <a href="#" className="hover:text-white transition-colors cursor-pointer">Libro de Reclamaciones</a>
          </div>
        </div>
      </footer>
    </div>
  );
}