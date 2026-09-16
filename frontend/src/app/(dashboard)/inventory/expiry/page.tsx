"use client";

import { useEffect, useState, useMemo } from "react";
import { motion } from "framer-motion";
import { useAuthStore } from "@/stores/auth-store";
import { fetchExpiryAlerts } from "@/lib/api";
import type { ProductBatch } from "@/lib/types";
import { useI18n } from "@/lib/i18n";
import { usePagination } from "@/lib/pagination";
import PageHeader from "@/components/ui/PageHeader";
import DataTable, { type Column } from "@/components/ui/DataTable";
import KPICard from "@/components/ui/KPICard";
import { AlertTriangle, Package, Clock } from "lucide-react";

function daysUntil(dateStr: string | null): number {
  if (!dateStr) return Infinity;
  const now = new Date();
  now.setHours(0, 0, 0, 0);
  const exp = new Date(dateStr);
  return Math.ceil((exp.getTime() - now.getTime()) / (1000 * 60 * 60 * 24));
}

export default function ExpiryAlertsPage() {
  const { token, business, config } = useAuthStore();
  const { t } = useI18n();
  const [tab, setTab] = useState<"expiring" | "expired">("expiring");
  const [expiring, setExpiring] = useState<ProductBatch[]>([]);
  const [expired, setExpired] = useState<ProductBatch[]>([]);
  const [loading, setLoading] = useState(true);
  const [total, setTotal] = useState(0);
  const { page, perPage, setPage, resetPage, changePageSize } = usePagination();
  const expiryDays = Number((config?.settings as Record<string, unknown> | undefined)?.expiry_warning_days ?? 30) || 30;

  useEffect(() => {
    if (!token || !business) return;
    const timer = setTimeout(() => {
      setLoading(true);
      Promise.all([
        fetchExpiryAlerts(token, business.id, { days: expiryDays, page, per_page: perPage }),
        fetchExpiryAlerts(token, business.id, { days: 0, page, per_page: perPage }),
      ])
        .then(([e30, e0]) => {
          setExpiring(e30.data);
          setExpired(e0.data);
          setTotal(tab === "expired" ? e0.total : e30.total);
        })
        .catch(() => {})
        .finally(() => setLoading(false));
    }, 300);
    return () => clearTimeout(timer);
  }, [token, business, tab, page, perPage, expiryDays]);

  const activeRows = tab === "expiring" ? expiring : expired;
  const totalExpiringValue = useMemo(() => expiring.reduce((s, b) => s + (b.quantity - b.quantity_sold) * (b.cost_per_unit ?? 0), 0), [expiring]);
  const totalExpiredValue = useMemo(() => expired.reduce((s, b) => s + (b.quantity - b.quantity_sold) * (b.cost_per_unit ?? 0), 0), [expired]);

  const columns: Column[] = [
    {
      key: "product",
      label: t("common.product"),
      render: (v) => (v as ProductBatch["product"])?.name ?? "—",
    },
    { key: "batch_number", label: t("expiry.batch") },
    { key: "quantity", label: t("expiry.qty"), type: "number" },
    { key: "expiry_date", label: t("common.expiry_date"), type: "date" },
    { key: "cost_per_unit", label: t("common.cost"), type: "currency" },
    {
      key: "expiry_date",
      label: t("expiry.days_left"),
      render: (v) => {
        const d = daysUntil(v as string | null);
        if (d === Infinity) return <span className="text-muted">—</span>;
        const color = d < 0 ? "text-red-400 font-bold" : d <= 7 ? "text-yellow-400 font-semibold" : "text-green-400";
        return <span className={color}>{d < 0 ? t("expiry.expired_ago", { days: String(Math.abs(d)) }) : t("expiry.days_short", { days: String(d) })}</span>;
      },
    },
  ];

  const getRowBg = (row: Record<string, unknown>) => {
    const d = daysUntil(row.expiry_date as string | null);
    if (d < 0) return "bg-red-500/5";
    if (d <= 7) return "bg-yellow-500/5";
    return "";
  };

  return (
    <motion.div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.4 }} className="space-y-6">
      <PageHeader title={t("expiry.title")} subtitle={t("expiry.subtitle")} />

      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <KPICard icon={Clock} label={t("expiry.expiring_soon")} value={expiring.length} accent="amber" />
        <KPICard icon={AlertTriangle} label={t("expiry.expired")} value={expired.length} accent="red" />
        <KPICard icon={Package} label={t("expiry.value_at_risk")} value={totalExpiringValue + totalExpiredValue} format="currency" accent="violet" />
      </div>

      <div className="flex gap-2">
        {(["expiring", "expired"] as const).map((key) => (
          <button
            key={key}
            onClick={() => { setTab(key); resetPage(); }}
            className={`px-4 py-2 rounded-xl text-sm font-medium transition-colors ${tab === key ? "bg-primary text-foreground" : "bg-card/80 border border-border text-muted hover:text-foreground hover:bg-card-hover"}`}
          >
            {key === "expiring" ? `${t("expiry.expiring_soon")} (${expiring.length})` : `${t("expiry.expired")} (${expired.length})`}
          </button>
        ))}
      </div>

      <DataTable
        columns={columns}
        data={activeRows.map((b) => ({ ...b, product_name: b.product?.name ?? "—" }) as unknown as Record<string, unknown>)}
        loading={loading}
        emptyMessage={t("expiry.empty")}
        emptyIcon={AlertTriangle}
        rowClassName={getRowBg}
        pagination={{ total, page, perPage, onPageChange: setPage, onPerPageChange: changePageSize }}
      />
    </motion.div>
  );
}
