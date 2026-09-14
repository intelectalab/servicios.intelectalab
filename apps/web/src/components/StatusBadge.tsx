const STATUS_CONFIG: Record<string, { bg: string; text: string; label: string; dot: string }> = {
  ACTIVE: { bg: "bg-emerald-50", text: "text-emerald-700", label: "Activo", dot: "bg-emerald-500" },
  INACTIVE: { bg: "bg-slate-100", text: "text-slate-700", label: "Inactivo", dot: "bg-slate-500" },
  SUSPENDED: { bg: "bg-amber-50", text: "text-amber-700", label: "Suspendido", dot: "bg-amber-500" },
};

export function StatusBadge({ status }: { status: string }) {
  const config = STATUS_CONFIG[status] || STATUS_CONFIG.INACTIVE;

  return (
    <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ${config.bg} ${config.text}`}>
      <span className={`h-1.5 w-1.5 rounded-full ${config.dot}`} />
      {config.label}
    </span>
  );
}