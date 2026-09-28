import { useState, useMemo } from "react";

export interface ActiveFilter {
  id: string;
  label: string;
  type: "text" | "number" | "select" | "date";
  operator: string;
  value: string;
}

export function useColumnFilters<T>(
  items: T[], 
  getValueFn: (item: T, columnId: string) => string
) {
  const [showAdvancedFilters, setShowAdvancedFilters] = useState(false);
  const [activeFilters, setActiveFilters] = useState<ActiveFilter[]>([]);

  const handleAddFilter = (columnId: string, label: string, type: "text" | "number" | "select" | "date") => {
    if (activeFilters.some(f => f.id === columnId)) return;

    const defaultValue = columnId === "status" ? "ACTIVE" : (type === "select" ? "false" : "");
    const defaultOperator = columnId === "status" ? "equals" : type === "date" ? "all" : (type === "select" ? "equals" : "contains");

    setActiveFilters(prev => [
      ...prev,
      {
        id: columnId,
        label,
        type,
        operator: defaultOperator,
        value: defaultValue,
      }
    ]);
    setShowAdvancedFilters(true);
  };

  const handleRemoveFilter = (columnId: string) => {
    setActiveFilters(prev => prev.filter(f => f.id !== columnId));
  };

  const handleUpdateFilter = (columnId: string, updates: Partial<{ operator: string; value: string }>) => {
    setActiveFilters(prev => prev.map(f => f.id === columnId ? { ...f, ...updates } : f));
  };

  const filteredItems = useMemo(() => {
    if (activeFilters.length === 0) return items;

    return items.filter((item) => {
      return activeFilters.every((filter) => {
        const targetValue = getValueFn(item, filter.id);

        // Lógica de filtrado por fechas
        if (filter.type === "date") {
          if (filter.operator === "all") return true;
          if (!targetValue) return false;
          
          const itemDateStr = targetValue.split("T")[0];
          if (!itemDateStr) return false;

          const now = new Date();
          const year = now.getFullYear();
          const month = String(now.getMonth() + 1).padStart(2, '0');
          const day = String(now.getDate()).padStart(2, '0');
          const todayStr = `${year}-${month}-${day}`;

          if (filter.operator === "today") {
            return itemDateStr === todayStr;
          }
          if (filter.operator === "yesterday") {
            const yesterdayDate = new Date(now);
            yesterdayDate.setDate(now.getDate() - 1);
            const yYear = yesterdayDate.getFullYear();
            const yMonth = String(yesterdayDate.getMonth() + 1).padStart(2, '0');
            const yDay = String(yesterdayDate.getDate()).padStart(2, '0');
            const yesterdayStr = `${yYear}-${yMonth}-${yDay}`;
            return itemDateStr === yesterdayStr;
          }
          if (filter.operator === "this_week") {
            const firstDayOfWeek = new Date(now);
            firstDayOfWeek.setDate(now.getDate() - now.getDay());
            const firstDayStr = firstDayOfWeek.toISOString().split("T")[0];
            return itemDateStr >= firstDayStr && itemDateStr <= todayStr;
          }
          if (filter.operator === "last_week") {
            const prevWeekStart = new Date(now);
            prevWeekStart.setDate(now.getDate() - now.getDay() - 7);
            const prevWeekEnd = new Date(now);
            prevWeekEnd.setDate(now.getDate() - now.getDay() - 1);
            return itemDateStr >= prevWeekStart.toISOString().split("T")[0] && 
                   itemDateStr <= prevWeekEnd.toISOString().split("T")[0];
          }
          if (filter.operator === "last_month") {
            const targetDate = new Date(targetValue);
            return targetDate.getMonth() === now.getMonth() - 1 && targetDate.getFullYear() === now.getFullYear();
          }
          if (filter.operator === "year_start") {
            const yearStartStr = `${now.getFullYear()}-01-01`;
            return itemDateStr >= yearStartStr && itemDateStr <= todayStr;
          }
          if (filter.operator === "range") {
            const [desde, hasta] = filter.value.split("|");
            if (desde && itemDateStr < desde) return false;
            if (hasta && itemDateStr > hasta) return false;
            return true;
          }
          return true;
        }

        const val = filter.value.toLowerCase();
        const tVal = targetValue.toLowerCase();
        if (!val && filter.type !== "select") return true;

        switch (filter.operator) {
          case "contains":
            return tVal.includes(val);
          case "equals":
            return tVal === val;
          case "greater":
            return Number(tVal) > Number(val);
          case "less":
            return Number(tVal) < Number(val);
          default:
            return true;
        }
      });
    });
  }, [items, activeFilters, getValueFn]);

  return {
    showAdvancedFilters,
    setShowAdvancedFilters,
    activeFilters,
    setActiveFilters,
    handleAddFilter,
    handleRemoveFilter,
    handleUpdateFilter,
    filteredItems,
  };
}