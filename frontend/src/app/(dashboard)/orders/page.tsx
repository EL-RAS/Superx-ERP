"use client";

import { useEffect, useState, useCallback } from "react";
import { motion } from "framer-motion";
import { useAuthStore } from "@/stores/auth-store";
import { Payments } from "@/lib/api";
import type { Payment } from "@/lib/types";
import { usePagination } from "@/lib/pagination";
import PageHeader from "@/components/ui/PageHeader";
import DataTable from "@/components/ui/DataTable";
import type { Column } from "@/components/ui/DataTable";
import StatusBadge from "@/components/ui/StatusBadge";
import { CreditCard } from "lucide-react";
import { useI18n } from "@/lib/i18n";

export default function OrdersPage() {
  const { t } = useI18n();
  const { token, business } = useAuthStore();
  const [data, setData] = useState<Payment[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const { page, perPage, setPage, changePageSize } = usePagination();

  const columns: Column[] = [
    { key: "payment_number", label: t("payments.payment_num") },
    { key: "amount", label: t("common.amount"), type: "currency" },
    {
      key: "method",
      label: t("payments.method"),
      render: (v) => <StatusBadge status={String(v)} />,
    },
    {
      key: "status",
      label: t("common.status"),
      render: (v) => <StatusBadge status={String(v)} />,
    },
    {
      key: "customer",
      label: t("invoices.customer"),
      render: (_v, row) => ((row as unknown as Payment).customer?.name ?? "-") as React.ReactNode,
    },
    { key: "created_at", label: t("common.date"), type: "date" },
  ];

  const fetchData = useCallback(() => {
    if (!token || !business) return;
    setLoading(true);
    Payments.list(token, business.id, { page, per_page: perPage })
      .then((res) => {
        setData(res.data);
        setTotal(res.total);
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [token, business, page, perPage]);

  useEffect(() => {
    const timer = setTimeout(fetchData, 300);
    return () => clearTimeout(timer);
  }, [fetchData]);

  return (
    <motion.div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.4 }}>
      <PageHeader title={t("orders.title")} subtitle={t("orders.subtitle", { count: String(data.length) })} />
      <DataTable
        columns={columns}
        data={data as unknown as Record<string, unknown>[]}
        loading={loading}
        emptyMessage={t("orders.empty")}
        emptyIcon={CreditCard}
        pagination={{ total, page, perPage, onPageChange: setPage, onPerPageChange: changePageSize }}
      />
    </motion.div>
  );
}
