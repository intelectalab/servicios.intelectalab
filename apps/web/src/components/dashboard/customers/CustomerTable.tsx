import Link from "next/link";
import { motion } from "framer-motion";
import { Building2, User, MoreHorizontal } from "lucide-react";
import { type Customer, getCustomerDisplayName, getCustomerDocument } from "@/types/customer";
import { StatusBadge } from "@/components/StatusBadge";

interface CustomerTableProps {
  customers: Customer[];
  selectedIds: string[];
  onToggleSelection: (id: string) => void;
  onSelectAll: (ids: string[]) => void;
}

function formatCurrency(value: string | number | null) {
  if (value === null || value === undefined) return "—";
  const num = typeof value === "string" ? parseFloat(value) : value;
  if (Number.isNaN(num)) return "—";
  return num.toLocaleString("es-PE", { style: "currency", currency: "PEN" });
}

export function CustomerTable({ customers, selectedIds, onToggleSelection, onSelectAll }: CustomerTableProps) {
  
  const handleSelectAll = () => {
    if (selectedIds.length === customers.length && customers.length > 0) {
      onSelectAll([]); // Deseleccionar todos
    } else {
      onSelectAll(customers.map((c) => c.id)); // Seleccionar todos
    }
  };

  const isAllSelected = customers.length > 0 && selectedIds.length === customers.length;

  return (
    <table className="w-full border-separate border-spacing-0 text-left text-sm">
      <thead className="sticky top-0 z-30 bg-white">
        <tr className="border-y border-slate-200 text-xs font-semibold uppercase tracking-wider text-slate-500">
          <th className="sticky top-0 z-20 w-14 bg-white px-6 py-4">
            <input
              type="checkbox"
              checked={isAllSelected}
              onChange={handleSelectAll}
              className="h-4 w-4 cursor-pointer rounded border-slate-300 text-[var(--app-brand-deep)] transition-colors focus:ring-[var(--app-brand)]"
            />
          </th>
          <th className="sticky top-0 z-20 bg-white px-6 py-4">Cliente</th>
          <th className="sticky top-0 z-20 bg-white px-6 py-4">Documento</th>
          <th className="sticky top-0 z-20 bg-white px-6 py-4">Días de Crédito</th>
          <th className="sticky top-0 z-20 bg-white px-6 py-4">Límite</th>
          <th className="sticky top-0 z-20 bg-white px-6 py-4">Estado</th>
          <th className="sticky top-0 z-20 bg-white px-6 py-4 text-right">Acciones</th>
        </tr>
      </thead>
      <tbody className="divide-y divide-slate-100 bg-white">
        {customers.map((customer) => {
          const isOrg = customer.party.party_type === "ORGANIZATION";
          const isSelected = selectedIds.includes(customer.id);

          return (
            <motion.tr
              key={customer.id}
              initial={{ opacity: 0 }}
              animate={{ opacity: 1 }}
              className={`group transition-colors hover:bg-slate-50 ${
                isSelected ? "bg-slate-50/80" : ""
              }`}
            >
              <td className="px-6 py-4">
                <input
                  type="checkbox"
                  checked={isSelected}
                  onChange={() => onToggleSelection(customer.id)}
                  className={`h-4 w-4 cursor-pointer rounded border-slate-300 text-[var(--app-brand-deep)] transition-opacity duration-200 focus:ring-[var(--app-brand)] ${
                    isSelected ? "opacity-100" : "opacity-0 focus:opacity-100 group-hover:opacity-100"
                  }`}
                />
              </td>
              <td className="px-6 py-4">
                <div className="flex items-center gap-3">
                  <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-blue-50 text-blue-600">
                    {isOrg ? <Building2 size={18} /> : <User size={18} />}
                  </div>
                  <div>
                    <Link
                      href={`/customers/${customer.id}`}
                      className="font-medium text-slate-900 hover:text-[var(--app-brand-deep)] hover:underline"
                    >
                      {getCustomerDisplayName(customer)}
                    </Link>
                    <p className="text-xs text-slate-500">
                      {isOrg ? "Empresa" : "Persona Natural"}
                    </p>
                  </div>
                </div>
              </td>
              <td className="px-6 py-4 text-slate-600">{getCustomerDocument(customer)}</td>
              <td className="px-6 py-4 text-slate-600">{customer.payment_terms_days} días</td>
              <td className="px-6 py-4 font-medium text-slate-700">
                {formatCurrency(customer.credit_limit)}
              </td>
              <td className="px-6 py-4">
                <StatusBadge status={customer.status} />
              </td>
              <td className="px-6 py-4 text-right">
                <button className="text-slate-400 transition-colors hover:text-slate-900">
                  <MoreHorizontal size={18} />
                </button>
              </td>
            </motion.tr>
          );
        })}
      </tbody>
    </table>
  );
}