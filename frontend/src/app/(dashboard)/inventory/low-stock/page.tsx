"use client";

import { useEffect, useState, useMemo, useCallback } from "react";
import { motion } from "framer-motion";
import { useRouter } from "next/navigation";
import { useAuthStore } from "@/stores/auth-store";
import { fetchLowStock } from "@/lib/api";
import type { Product } from "@/lib/types";
import { formatCurrency } from "@/lib/types";
import { useI18n } from "@/lib/i18n";
import { usePagination } from "@/lib/pagination";
import PageHeader from "@/components/ui/PageHeader";
import DataTable, { type Column } from "@/components/ui/DataTable";
import KPICard from "@/components/ui/KPICard";
import { AlertTriangle, Package, TrendingDown, ShoppingCart, Plus } from "lucide-react";

const toQty = (v: unknown) => Number(v ?? 0) || 0;

export default function LowStockPage() {
  const { token, business, config } = useAuthStore();
  const { t, locale } = useI18n();
  const router = useRouter();
  const sensitivity = (config?.settings as Record<string, unknown> | undefined)?.low_stock_sensitivity as string | undefined;
  const [products, setProducts] = useState<Product[]>([]);
  const [loading, setLoading] = useState(true);
  const [total, setTotal] = useState(0);
  const { page, perPage, setPage, resetPage, changePageSize } = usePagination();
  const [counters, setCounters] = useState({ out_of_stock: 0, low_stock: 0, total: 0 });
  const [reorderCost, setReorderCost] = useState(0);

  const fetchData = useCallback(() => {
    if (!token || !business) return;
    setLoading(true);
    fetchLowStock(token, business.id, { page, per_page: perPage })
      .then((res) => {
        setProducts(res.data);
        setTotal(res.total);
        if (res.counters) setCounters(res.counters);
        if (res.reorder_cost != null) setReorderCost(res.reorder_cost);
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [token, business, page, perPage]);

  useEffect(() => {
    const timer = setTimeout(fetchData, 300);
    return () => clearTimeout(timer);
  }, [fetchData]);

  const outOfStock = useMemo(() => products.filter((p) => toQty(p.stock_quantity) === 0), [products]);
  const lowStock = useMemo(() => products.filter((p) => toQty(p.stock_quantity) > 0), [products]);

  const [tab, setTab] = useState<"all" | "out" | "low">("all");
  const displayProducts = tab === "out" ? outOfStock : tab === "low" ? lowStock : products;

  const columns: Column[] = [
    {
      key: "name",
      label: t("common.product"),
      render: (v, row) => (
        <div>
          <p className="text-sm font-medium text-foreground">{v as string}</p>
          {row.sku ? <p className="text-xs text-muted">{String(row.sku)}</p> : null}
        </div>
      ),
    },
    {
      key: "stock_quantity",
      label: t("pos.stock"),
      type: "number",
      render: (v) => {
        const qty = toQty(v);
        const color = qty === 0 ? "text-red-400 font-bold" : "text-amber-400 font-semibold";
        return <span className={color}>{qty}</span>;
      },
    },
    {
      key: "min_stock",
      label: t("inventory.min_stock") ?? "Min Stock",
      type: "number",
    },
    {
      key: "cost",
      label: t("inventory.unit_cost") ?? "Unit Cost",
      type: "currency",
      render: (v) => formatCurrency(Number(v ?? 0), locale),
    },
    {
      key: "reorder_cost",
      label: t("inventory.reorder_cost") ?? "Reorder Cost",
      type: "currency",
      render: (_v, row) =>
        formatCurrency(Math.max(0, toQty(row.min_stock) - toQty(row.stock_quantity)) * toQty(row.cost), locale),
    },
    {
      key: "category",
      label: t("common.category"),
    },
    {
      key: "stock_status",
      label: t("common.status"),
      render: (v, row) => {
        const qty = toQty(row.stock_quantity);
        if (qty === 0) return <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-500/20 text-red-400 border border-red-500/30">{t("low_stock.out_of_stock")}</span>;
        return <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-500/20 text-amber-400 border border-amber-500/30">{t("low_stock.low_stock_items")}</span>;
      },
    },
    {
      key: "actions",
      label: t("common.actions"),
      render: (_v, row) => {
        const p = row as unknown as Product;
        const suggestedQty = (toQty(p.min_stock) * 2) || 20;
        const params = new URLSearchParams({
          product_id: String(p.id),
          suggested_quantity: String(suggestedQty),
        });
        if (p.preferred_supplier_id) params.set("supplier_id", String(p.preferred_supplier_id));
        return (
          <button
            onClick={(e) => {
              e.stopPropagation();
              router.push(`/purchases/new?${params.toString()}`);
            }}
            className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-primary hover:bg-primary-light text-foreground rounded-lg text-xs font-medium transition-colors"
          >
            <Plus className="w-3.5 h-3.5" /> {t("low_stock.create_po")}
          </button>
        );
      },
    },
  ];

  const getRowBg = (row: Record<string, unknown>) => {
    if (toQty(row.stock_quantity) === 0) return "bg-red-500/5";
    return "bg-amber-500/5";
  };

  return (
    <motion.div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.4 }} className="space-y-6">
      <PageHeader title={t("inventory.low_stock") ?? "Low Stock Alerts"} subtitle={t("inventory.low_stock_desc") ?? "Products at or below min stock"} />

      {sensitivity === "strict" && (
        <div className="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-medium bg-amber-500/15 text-amber-400 border border-amber-500/30">
          <AlertTriangle className="w-3.5 h-3.5" />
          {t("low_stock.strict_sensitivity") ?? "Strict sensitivity: alerting at 1.5× min stock"}
        </div>
      )}

      <div className="grid grid-cols-1 sm:grid-cols-4 gap-4">
        <KPICard icon={Package} label={t("low_stock.low_stock_items")} value={counters.low_stock} accent="amber" />
        <KPICard icon={AlertTriangle} label={t("low_stock.out_of_stock")} value={counters.out_of_stock} accent="red" />
        <KPICard icon={TrendingDown} label={t("low_stock.total_items")} value={counters.total} accent="violet" />
        <KPICard icon={ShoppingCart} label={t("low_stock.reorder_cost") ?? "Estimated Reorder Cost"} value={reorderCost} format="currency" accent="blue" />
      </div>

      <div className="flex gap-2">
        {(["all", "out", "low"] as const).map((key) => (
          <button
            key={key}
            onClick={() => { setTab(key); resetPage(); }}
            className={`px-4 py-2 rounded-xl text-sm font-medium transition-colors ${tab === key ? "bg-primary text-foreground" : "bg-card/80 border border-border text-muted hover:text-foreground hover:bg-card-hover"}`}
          >
            {key === "all" ? t("low_stock.all_tab", { count: String(counters.total) })
              : key === "out" ? t("low_stock.out_tab", { count: String(counters.out_of_stock) })
              : t("low_stock.low_tab", { count: String(counters.low_stock) })}
          </button>
        ))}
      </div>

      <DataTable
        columns={columns}
        data={displayProducts.map((p) => ({ ...p }) as unknown as Record<string, unknown>)}
        loading={loading}
        emptyMessage={t("inventory.no_low_stock") ?? "No low stock items found"}
        emptyIcon={Package}
        rowClassName={getRowBg}
        pagination={{ total, page, perPage, onPageChange: setPage, onPerPageChange: changePageSize }}
      />
    </motion.div>
  );
}
