"use client";

import { useEffect, useState, useCallback } from "react";
import { motion } from "framer-motion";
import { useAuthStore } from "@/stores/auth-store";
import { Invoices } from "@/lib/api";
import type { Invoice } from "@/lib/types";
import { formatCurrency } from "@/lib/types";
import PageHeader from "@/components/ui/PageHeader";
import DataTable, { Column } from "@/components/ui/DataTable";
import StatusBadge from "@/components/ui/StatusBadge";
import KPICard from "@/components/ui/KPICard";
import { Receipt, Clock, CheckCircle } from "lucide-react";
import { useI18n } from "@/lib/i18n";
import { usePagination } from "@/lib/pagination";

export default function SalesPage() {
  const { t, locale } = useI18n();
  const { token, business } = useAuthStore();
  const businessId = business?.id;
  const [rows, setRows] = useState<Invoice[]>([]);
  const [loading, setLoading] = useState(true);
  const [statusFilter, setStatusFilter] = useState<string>("all");
  const [total, setTotal] = useState(0);
  const { page, perPage, setPage, resetPage, changePageSize } = usePagination();

  const columns: Column[] = [
    { key: "invoice_number", label: t("invoices.invoice_num") },
    { key: "customer", label: t("invoices.customer"), render: (_v, row) => ((row as unknown as Invoice).customer?.name ?? t("invoices.walk_in")) as React.ReactNode },
    { key: "net_amount", label: t("common.amount"), type: "currency" },
    {
      key: "payment_status",
      label: t("payments.status"),
      render: (v) => <StatusBadge status={String(v)} />,
    },
    { key: "created_at", label: t("common.date"), type: "date" },
  ];

  const fetchData = useCallback(() => {
    if (!token || !businessId) return;
    setLoading(true);
    const params: Record<string, string | number> = { page, per_page: perPage };
    if (statusFilter !== "all") params.payment_status = statusFilter;
    Invoices.list(token, businessId, params)
      .then((r) => {
        setRows(r.data);
        setTotal(r.total);
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [token, businessId, statusFilter, page, perPage]);

  useEffect(() => {
    const timer = setTimeout(fetchData, 300);
    return () => clearTimeout(timer);
  }, [fetchData]);

  const totalRevenue = rows.filter((r) => r.payment_status === "paid").reduce((s, r) => s + r.net_amount, 0);
  const totalUnpaid = rows.filter((r) => r.payment_status === "unpaid" || r.payment_status === "partial").reduce((s, r) => s + r.net_amount, 0);

  return (
    <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="space-y-6">
      <PageHeader title={t("sales.title")} subtitle={t("sales.subtitle")} />

      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        <KPICard label={t("invoices.subtotal")} value={formatCurrency(totalRevenue, locale)} icon={CheckCircle} accent="emerald" />
        <KPICard label={t("invoices.total")} value={String(rows.length)} icon={Receipt} accent="violet" />
        <KPICard label={t("invoices.payments")} value={formatCurrency(totalUnpaid, locale)} icon={Clock} accent="amber" />
      </div>

      <div className="flex gap-2">
        {["all", "paid", "unpaid", "partial"].map((s) => (
          <button key={s} onClick={() => { setStatusFilter(s); resetPage(); }}
            className={`px-3 py-1.5 rounded-lg text-sm transition-colors ${
              statusFilter === s ? "bg-primary/20 text-primary-light border border-primary/30" : "text-muted hover:bg-card-hover"
            }`}>
            {s === "all" ? t("common.all") : s.charAt(0).toUpperCase() + s.slice(1)}
          </button>
        ))}
      </div>
      <DataTable columns={columns} data={rows as unknown as Record<string, unknown>[]} loading={loading} emptyMessage={t("sales.empty")} emptyIcon={Receipt} pagination={{ total, page, perPage, onPageChange: setPage, onPerPageChange: changePageSize }} />
    </motion.div>
  );
}
