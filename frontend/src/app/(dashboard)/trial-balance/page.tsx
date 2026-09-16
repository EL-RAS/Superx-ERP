"use client";

import { useState, useCallback } from "react";
import { motion } from "framer-motion";
import { useAuthStore } from "@/stores/auth-store";
import { fetchTrialBalance } from "@/lib/api";
import type { TrialBalance as TrialBalanceType, TrialBalanceAccount } from "@/lib/types";
import { formatCurrency } from "@/lib/types";
import PageHeader from "@/components/ui/PageHeader";
import DataTable from "@/components/ui/DataTable";
import type { Column } from "@/components/ui/DataTable";
import { Scale, Loader2, CheckCircle, XCircle } from "lucide-react";
import { useI18n } from "@/lib/i18n";

export default function TrialBalancePage() {
  const { t, locale } = useI18n();
  const { token, business } = useAuthStore();
  const [data, setData] = useState<TrialBalanceType | null>(null);
  const [loading, setLoading] = useState(false);
  const [dateFilter, setDateFilter] = useState({ from: "", to: new Date().toISOString().split("T")[0] });
  const [toast, setToast] = useState<{ msg: string; type: "success" | "error" } | null>(null);

  const showToast = (msg: string, type: "success" | "error" = "success") => {
    setToast({ msg, type });
    setTimeout(() => setToast(null), 3000);
  };

  const loadReport = useCallback(async () => {
    if (!token || !business) return;
    setLoading(true);
    try {
      const params: { date_to: string; date_from?: string } = { date_to: dateFilter.to };
      if (dateFilter.from) params.date_from = dateFilter.from;
      const result = await fetchTrialBalance(token, business.id, params);
      setData(result);
    } catch {
      showToast(t("common.error"), "error");
    } finally { setLoading(false); }
  }, [token, business, dateFilter]);

  const columns: Column[] = [
    { key: "code", label: t("common.code") },
    { key: "name", label: t("common.name") },
    { key: "type", label: t("common.type") },
    { key: "debit", label: t("journal_entries.debit"), type: "currency" },
    { key: "credit", label: t("journal_entries.credit"), type: "currency" },
    { key: "balance", label: t("common.balance"), type: "currency" },
  ];

  return (
    <motion.div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.4 }}>
      {toast && (
        <div className={`fixed top-4 end-4 z-[60] px-4 py-2.5 rounded-xl text-sm font-medium shadow-lg transition-all ${
          toast.type === "success" ? "bg-emerald-500/20 text-emerald-400 border border-emerald-500/30" : "bg-red-500/20 text-red-400 border border-red-500/30"
        }`}>{toast.msg}</div>
      )}

      <PageHeader title={t("trial_balance.title")} subtitle={t("trial_balance.subtitle")} />

      <div className="flex gap-3 mb-6 items-end">
        <div>
          <label className="text-xs text-muted mb-1 block">{t("trial_balance.from")}</label>
          <input type="date" value={dateFilter.from} onChange={(e) => setDateFilter((p) => ({ ...p, from: e.target.value }))}
            className="px-3 py-2 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors" />
        </div>
        <div>
          <label className="text-xs text-muted mb-1 block">{t("trial_balance.to")}</label>
          <input type="date" value={dateFilter.to} onChange={(e) => setDateFilter((p) => ({ ...p, to: e.target.value }))}
            className="px-3 py-2 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors" />
        </div>
        <button onClick={loadReport} disabled={loading}
          className="flex items-center gap-2 px-5 py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors disabled:opacity-40">
          {loading ? <Loader2 className="w-4 h-4 animate-spin" /> : <Scale className="w-4 h-4" />}
          {loading ? t("common.loading") : t("trial_balance.generate")}
        </button>
      </div>

      {data && (
        <>
          <div className="grid grid-cols-3 gap-4 mb-6">
            <div className="glass rounded-xl p-4">
              <p className="text-xs text-muted mb-1">{t("journal_entries.total_debit")}</p>
              <p className="text-xl font-bold text-foreground">{formatCurrency(data.total_debit, locale)}</p>
            </div>
            <div className="glass rounded-xl p-4">
              <p className="text-xs text-muted mb-1">{t("journal_entries.total_credit")}</p>
              <p className="text-xl font-bold text-foreground">{formatCurrency(data.total_credit, locale)}</p>
            </div>
            <div className="glass rounded-xl p-4">
              <p className="text-xs text-muted mb-1">{t("trial_balance.status")}</p>
              <div className="flex items-center gap-2">
                {data.is_balanced ? (
                  <><CheckCircle className="w-5 h-5 text-emerald-400" /><span className="text-lg font-bold text-emerald-400">{t("trial_balance.balanced")}</span></>
                ) : (
                  <><XCircle className="w-5 h-5 text-red-400" /><span className="text-lg font-bold text-red-400">{t("trial_balance.unbalanced")}</span></>
                )}
              </div>
            </div>
          </div>

          <DataTable columns={columns} data={data.accounts as unknown as Record<string, unknown>[]} loading={false}
            emptyMessage={t("trial_balance.no_accounts")} emptyIcon={Scale} />
        </>
      )}
    </motion.div>
  );
}
