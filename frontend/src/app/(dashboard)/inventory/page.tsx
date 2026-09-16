"use client";

import { useEffect, useState } from "react";
import { motion } from "framer-motion";
import { useAuthStore } from "@/stores/auth-store";
import { Products } from "@/lib/api";
import { useI18n } from "@/lib/i18n";
import { usePagination } from "@/lib/pagination";
import PageHeader from "@/components/ui/PageHeader";
import DataTable, { Column } from "@/components/ui/DataTable";
import { Package, Search } from "lucide-react";

export default function InventoryPage() {
  const { token, business, config } = useAuthStore();
  const { t } = useI18n();
  const businessId = business?.id;
  const [rows, setRows] = useState<Record<string, unknown>[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState("");
  const [total, setTotal] = useState(0);
  const { page, perPage, setPage, resetPage, changePageSize } = usePagination();

  const defaultColumns: Column[] = [
    { key: "name", label: t("common.name") },
    { key: "sku", label: t("common.sku") },
    { key: "price", label: t("common.price"), type: "currency" },
    { key: "cost", label: t("common.cost"), type: "currency" },
    { key: "is_active", label: t("common.status"), type: "boolean" },
  ];

  const columns = (config?.product_table_columns as Column[] | undefined) ?? defaultColumns;

  const columnsWithStatus: Column[] = [
    ...columns,
    {
      key: "stock_status",
      label: t("inventory.stock_status"),
      render: (_v, row) => {
        const r = row as Record<string, unknown>;
        const qty = Number(r.stock_quantity ?? 0);
        const min = Number(r.min_stock ?? 0);
        const expired = r.is_fully_expired === true;
        if (expired)
          return (
            <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-600/20 text-red-400 border border-red-500/30">
              {t("pos.expired") ?? "Expired"}
            </span>
          );
        if (qty === 0)
          return (
            <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-500/20 text-red-400 border border-red-500/30">
              {t("inventory.out_of_stock") ?? "Out of Stock"}
            </span>
          );
        if (min > 0 && qty <= min)
          return (
            <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-500/20 text-amber-400 border border-amber-500/30">
              {t("inventory.low_stock") ?? "Low Stock"}
            </span>
          );
        return (
          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">
            {t("inventory.in_stock") ?? "In Stock"}
          </span>
        );
      },
    },
  ];

  useEffect(() => {
    if (!token || !businessId) return;
    const timer = setTimeout(() => {
      setLoading(true);
      const params: Record<string, string | number> = { page, per_page: perPage };
      if (search) params.search = search;
      Products.list(token, businessId, params)
        .then((r) => {
          setRows(r.data as unknown as Record<string, unknown>[]);
          setTotal(r.total);
        })
        .catch(() => {})
        .finally(() => setLoading(false));
    }, 300);
    return () => clearTimeout(timer);
  }, [token, businessId, search, page, perPage]);

  return (
    <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="space-y-6">
      <PageHeader title={t("inventory.title")} subtitle={t("inventory.subtitle")} />
      <div className="relative max-w-sm">
        <Search className="absolute start-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted" />
        <input
          value={search}
          onChange={(e) => { setSearch(e.target.value); resetPage(); }}
          placeholder={t("inventory.search")}
          className="w-full ps-10 pe-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover"
        />
      </div>
      <DataTable columns={columnsWithStatus} data={rows} loading={loading} emptyMessage={t("inventory.empty")} emptyIcon={Package} pagination={{ total, page, perPage, onPageChange: setPage, onPerPageChange: changePageSize }} />
    </motion.div>
  );
}
