"use client";

import { useEffect, useState, useCallback } from "react";
import { motion } from "framer-motion";
import { useAuthStore } from "@/stores/auth-store";
import { FiscalYears, closeFiscalYear } from "@/lib/api";
import type { FiscalYear } from "@/lib/types";
import { usePagination } from "@/lib/pagination";
import PageHeader from "@/components/ui/PageHeader";
import DataTable from "@/components/ui/DataTable";
import type { Column } from "@/components/ui/DataTable";
import SlideOver from "@/components/ui/SlideOver";
import StatusBadge from "@/components/ui/StatusBadge";
import { Calendar, Plus, Loader2, CheckCircle, Lock, Unlock } from "lucide-react";
import { useI18n } from "@/lib/i18n";

export default function FiscalYearsPage() {
  const { t, locale } = useI18n();
  const { token, business } = useAuthStore();
  const [data, setData] = useState<FiscalYear[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const { page, perPage, setPage, changePageSize } = usePagination();
  const [selected, setSelected] = useState<FiscalYear | null>(null);
  const [formOpen, setFormOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [form, setForm] = useState({ name: "", start_date: "", end_date: "" });
  const [toast, setToast] = useState<{ msg: string; type: "success" | "error" } | null>(null);

  const showToast = (msg: string, type: "success" | "error" = "success") => {
    setToast({ msg, type });
    setTimeout(() => setToast(null), 3000);
  };

  const columns: Column[] = [
    { key: "name", label: t("fiscal_year.name") },
    { key: "start_date", label: t("fiscal_year.start"), type: "date" },
    { key: "end_date", label: t("fiscal_year.end"), type: "date" },
    { key: "is_closed", label: t("common.status"), render: (v) => <StatusBadge status={v ? "closed" : "open"} /> },
  ];

  const fetchData = useCallback(() => {
    if (!token || !business) return;
    setLoading(true);
    FiscalYears.list(token, business.id, { page, per_page: perPage })
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

  const handleCreate = async () => {
    if (!token || !business || !form.name || !form.start_date || !form.end_date) return;
    setSaving(true);
    try {
      await FiscalYears.create(token, business.id, form);
      showToast(t("fiscal_year.created"));
      setFormOpen(false);
      fetchData();
    } catch (e: unknown) {
      showToast(e instanceof Error ? e.message : t("common.error"), "error");
    } finally { setSaving(false); }
  };

  const handleClose = async (id: number) => {
    if (!token || !business) return;
    try {
      await closeFiscalYear(token, business.id, id);
      showToast(t("fiscal_year.closed"));
      setSelected(null);
      fetchData();
    } catch (e: unknown) {
      showToast(e instanceof Error ? e.message : t("common.error"), "error");
    }
  };

  return (
    <motion.div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.4 }}>
      {toast && (
        <div className={`fixed top-4 end-4 z-[60] px-4 py-2.5 rounded-xl text-sm font-medium shadow-lg transition-all ${
          toast.type === "success" ? "bg-emerald-500/20 text-emerald-400 border border-emerald-500/30" : "bg-red-500/20 text-red-400 border border-red-500/30"
        }`}>{toast.msg}</div>
      )}

      <PageHeader
        title={t("fiscal_year.title")}
        subtitle={t("fiscal_year.subtitle")}
        action={
          <button onClick={() => { setForm({ name: "", start_date: "", end_date: "" }); setFormOpen(true); }}
            className="flex items-center gap-2 px-4 py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors">
            <Plus className="w-4 h-4" /> {t("fiscal_year.new")}
          </button>
        }
      />

      <DataTable
        columns={columns}
        data={data as unknown as Record<string, unknown>[]}
        loading={loading}
        emptyMessage={t("fiscal_year.empty")}
        emptyIcon={Calendar}
        onRowClick={(row) => setSelected(row as unknown as FiscalYear)}
        pagination={{ total, page, perPage, onPageChange: setPage, onPerPageChange: changePageSize }}
      />

      <SlideOver open={formOpen} onClose={() => setFormOpen(false)} title={t("fiscal_year.new")}>
        <div className="space-y-4">
          <div>
            <label className="text-sm text-muted mb-1 block">{t("fiscal_year.name")}</label>
            <input value={form.name} onChange={(e) => setForm((p) => ({ ...p, name: e.target.value }))}
              placeholder={t("fiscal_year.name_placeholder")}
              className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors" />
          </div>
          <div>
            <label className="text-sm text-muted mb-1 block">{t("fiscal_year.start")}</label>
            <input type="date" value={form.start_date} onChange={(e) => setForm((p) => ({ ...p, start_date: e.target.value }))}
              className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors" />
          </div>
          <div>
            <label className="text-sm text-muted mb-1 block">{t("fiscal_year.end")}</label>
            <input type="date" value={form.end_date} onChange={(e) => setForm((p) => ({ ...p, end_date: e.target.value }))}
              className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors" />
          </div>
          <button onClick={handleCreate} disabled={saving || !form.name || !form.start_date || !form.end_date}
            className="flex items-center gap-2 px-6 py-3 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors disabled:opacity-40">
            {saving ? <Loader2 className="w-4 h-4 animate-spin" /> : <CheckCircle className="w-4 h-4" />}
            {saving ? t("common.saving") : t("common.create")}
          </button>
        </div>
      </SlideOver>

      <SlideOver open={!!selected} onClose={() => setSelected(null)} title={t("fiscal_year.detail")}>
        {selected && (
          <div className="space-y-5">
            <div className="glass rounded-xl p-4 space-y-3">
              <div className="flex justify-between">
                <span className="text-sm text-muted">{t("fiscal_year.name")}</span>
                <span className="text-sm text-foreground font-medium">{selected.name}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-sm text-muted">{t("fiscal_year.start")}</span>
                <span className="text-sm text-foreground">{new Date(selected.start_date).toLocaleDateString(locale === "ar" ? "ar-JO" : "en-JO")}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-sm text-muted">{t("fiscal_year.end")}</span>
                <span className="text-sm text-foreground">{new Date(selected.end_date).toLocaleDateString(locale === "ar" ? "ar-JO" : "en-JO")}</span>
              </div>
              <div className="flex justify-between items-center">
                <span className="text-sm text-muted">{t("common.status")}</span>
                <div className="flex items-center gap-2">
                  {selected.is_closed ? <Lock className="w-4 h-4 text-amber-400" /> : <Unlock className="w-4 h-4 text-emerald-400" />}
                  <StatusBadge status={selected.is_closed ? "closed" : "open"} />
                </div>
              </div>
              {selected.closed_at && (
                <div className="flex justify-between">
                  <span className="text-sm text-muted">{t("fiscal_year.closed_at")}</span>
                  <span className="text-sm text-foreground">{new Date(selected.closed_at).toLocaleDateString(locale === "ar" ? "ar-JO" : "en-JO")}</span>
                </div>
              )}
              {selected.metadata && (selected.metadata as Record<string, unknown>).total_revenue !== undefined && (
                <>
                  <div className="flex justify-between">
                    <span className="text-sm text-muted">{t("reports.total_revenue")}</span>
                    <span className="text-sm text-emerald-400 font-medium">{((selected.metadata as Record<string, unknown>).total_revenue as number).toLocaleString()}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-sm text-muted">{t("reports.total_expenses")}</span>
                    <span className="text-sm text-red-400 font-medium">{((selected.metadata as Record<string, unknown>).total_expense as number).toLocaleString()}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-sm text-muted">{t("reports.net_income")}</span>
                    <span className="text-sm text-foreground font-medium">{((selected.metadata as Record<string, unknown>).net_income as number).toLocaleString()}</span>
                  </div>
                </>
              )}
            </div>

            {!selected.is_closed && (
              <button onClick={() => handleClose(selected.id)}
                className="flex items-center gap-2 px-6 py-3 bg-amber-500/20 hover:bg-amber-500/30 text-amber-400 border border-amber-500/30 rounded-xl text-sm font-medium transition-colors">
                <Lock className="w-4 h-4" /> {t("fiscal_year.close_year")}
              </button>
            )}
          </div>
        )}
      </SlideOver>
    </motion.div>
  );
}
