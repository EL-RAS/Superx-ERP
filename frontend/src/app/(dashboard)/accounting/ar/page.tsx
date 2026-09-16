"use client";

import { useState, useCallback, useEffect } from "react";
import { motion } from "framer-motion";
import { useAuthStore } from "@/stores/auth-store";
import { fetchAccountsReceivable, collectInvoicePayment } from "@/lib/api";
import type { AccountsReceivable, AccountReceivableInvoice } from "@/lib/types";
import { formatCurrency } from "@/lib/types";
import PageHeader from "@/components/ui/PageHeader";
import SlideOver from "@/components/ui/SlideOver";
import Pagination from "@/components/ui/Pagination";
import { usePagination } from "@/lib/pagination";
import { useI18n } from "@/lib/i18n";
import {
  HandCoins, Loader2, Users, Receipt, RefreshCw, ArrowRightLeft,
} from "lucide-react";

const PAYMENT_METHODS = ["cash", "card", "bank_transfer", "check", "mobile"] as const;

export default function AccountsReceivablePage() {
  const { t, locale } = useI18n();
  const { token, business } = useAuthStore();
  const [data, setData] = useState<AccountsReceivable | null>(null);
  const [loading, setLoading] = useState(false);
  const [selected, setSelected] = useState<AccountsReceivable["customers"][number] | null>(null);
  const [collectTarget, setCollectTarget] = useState<{
    customer: AccountsReceivable["customers"][number];
    invoice: AccountReceivableInvoice;
  } | null>(null);
  const [form, setForm] = useState({ amount: "", method: "cash", reference_number: "", notes: "" });
  const [saving, setSaving] = useState(false);
  const [toast, setToast] = useState<{ msg: string; type: "success" | "error" } | null>(null);
  const { page, perPage, setPage, changePageSize } = usePagination();

  const customers = data?.customers ?? [];
  const visibleCustomers = customers.slice((page - 1) * perPage, page * perPage);

  const showToast = (msg: string, type: "success" | "error" = "success") => {
    setToast({ msg, type });
    setTimeout(() => setToast(null), 3000);
  };

  const load = useCallback(async () => {
    if (!token || !business) return;
    setLoading(true);
    try {
      const result = await fetchAccountsReceivable(token, business.id);
      setData(result);
    } catch {
      showToast(t("common.error"), "error");
    } finally {
      setLoading(false);
    }
  }, [token, business, t]);

  useEffect(() => {
    const timer = setTimeout(load, 300);
    return () => clearTimeout(timer);
  }, [load]);

  const openCollect = (customer: AccountsReceivable["customers"][number], invoice: AccountReceivableInvoice) => {
    setCollectTarget({ customer, invoice });
    setForm({ amount: String(invoice.balance), method: "cash", reference_number: "", notes: "" });
  };

  const submitCollect = async () => {
    if (!token || !business || !collectTarget) return;
    const amount = parseFloat(form.amount);
    if (!amount || amount <= 0) {
      showToast(t("accounting.ar.failed"), "error");
      return;
    }
    setSaving(true);
    try {
      await collectInvoicePayment(token, business.id, collectTarget.invoice.id, {
        amount,
        method: form.method,
        reference_number: form.reference_number || undefined,
        notes: form.notes || undefined,
      });
      showToast(t("accounting.ar.success"));
      setCollectTarget(null);
      await load();
    } catch (err) {
      showToast(err instanceof Error ? err.message : t("accounting.ar.failed"), "error");
    } finally {
      setSaving(false);
    }
  };

  const totalOpenInvoices = data?.customers.reduce((n, c) => n + c.open_invoices_count, 0) ?? 0;

  return (
    <motion.div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.4 }}>
      {toast && (
        <div className={`fixed top-4 end-4 z-[60] px-4 py-2.5 rounded-xl text-sm font-medium shadow-lg transition-all ${
          toast.type === "success" ? "bg-emerald-500/20 text-emerald-400 border border-emerald-500/30" : "bg-red-500/20 text-red-400 border border-red-500/30"
        }`}>{toast.msg}</div>
      )}

      <PageHeader
        title={t("accounting.ar.title")}
        subtitle={t("accounting.ar.subtitle")}
        actions={
          <button onClick={load} disabled={loading}
            className="flex items-center gap-2 px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground hover:border-border-hover transition-colors disabled:opacity-40">
            {loading ? <Loader2 className="w-4 h-4 animate-spin" /> : <RefreshCw className="w-4 h-4" />}
            {t("common.refresh")}
          </button>
        }
      />

      <div className="grid grid-cols-3 gap-4 mb-6">
        <div className="glass rounded-xl p-5">
          <div className="flex items-center gap-2 mb-2"><HandCoins className="w-4 h-4 text-red-400" /><span className="text-xs text-muted">{t("accounting.ar.total_outstanding")}</span></div>
          <p className="text-2xl font-bold text-red-400">{formatCurrency(data?.total_outstanding ?? 0, locale)}</p>
        </div>
        <div className="glass rounded-xl p-5">
          <div className="flex items-center gap-2 mb-2"><Users className="w-4 h-4 text-primary-light" /><span className="text-xs text-muted">{t("accounting.ar.customers")}</span></div>
          <p className="text-2xl font-bold">{data?.customers.length ?? 0}</p>
        </div>
        <div className="glass rounded-xl p-5">
          <div className="flex items-center gap-2 mb-2"><Receipt className="w-4 h-4 text-primary-light" /><span className="text-xs text-muted">{t("accounting.ar.open_invoices")}</span></div>
          <p className="text-2xl font-bold">{totalOpenInvoices}</p>
        </div>
      </div>

      {loading && !data ? (
        <div className="flex items-center justify-center py-20"><Loader2 className="w-6 h-6 animate-spin text-muted" /></div>
      ) : data && data.customers.length > 0 ? (
        <div className="glass rounded-xl overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-border bg-card/60">
                  <th className="px-4 py-3 text-start text-xs font-medium text-muted">{t("accounting.ar.customer")}</th>
                  <th className="px-4 py-3 text-start text-xs font-medium text-muted">{t("accounting.ar.phone")}</th>
                  <th className="px-4 py-3 text-end text-xs font-medium text-muted">{t("accounting.ar.open_invoices")}</th>
                  <th className="px-4 py-3 text-end text-xs font-medium text-muted">{t("accounting.ar.balance")}</th>
                  <th className="px-4 py-3 text-end text-xs font-medium text-muted" />
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {visibleCustomers.map((c) => (
                  <tr key={c.customer_id} onClick={() => setSelected(c)} className="hover:bg-accent-dim cursor-pointer transition-colors">
                    <td className="px-4 py-3 text-foreground font-medium">{c.name}</td>
                    <td className="px-4 py-3 text-muted">{c.phone ?? "—"}</td>
                    <td className="px-4 py-3 text-end text-foreground">{c.open_invoices_count}</td>
                    <td className="px-4 py-3 text-end text-red-400 font-semibold">{formatCurrency(c.outstanding, locale)}</td>
                    <td className="px-4 py-3 text-end"><ArrowRightLeft className="w-4 h-4 text-muted inline" /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <Pagination total={customers.length} page={page} perPage={perPage} onPageChange={setPage} onPerPageChange={changePageSize} />
        </div>
      ) : (
        <div className="glass rounded-2xl p-12 text-center">
          <HandCoins className="w-12 h-12 text-muted mx-auto mb-4" />
          <h3 className="text-lg font-medium text-foreground mb-2">{t("accounting.ar.empty")}</h3>
          <p className="text-sm text-muted">{t("accounting.ar.empty_desc")}</p>
        </div>
      )}

      <SlideOver
        open={!!selected}
        onClose={() => setSelected(null)}
        title={selected ? t("accounting.ar.invoices_for", { name: selected.name }) : ""}
        width="max-w-2xl"
      >
        {selected && (
          <div className="space-y-3">
            <div className="flex items-center justify-between text-sm">
              <span className="text-muted">{t("accounting.ar.total_outstanding")}</span>
              <span className="text-red-400 font-bold text-lg">{formatCurrency(selected.outstanding, locale)}</span>
            </div>
            {selected.invoices.map((inv) => (
              <div key={inv.id} className="glass rounded-xl p-4">
                <div className="flex items-center justify-between mb-3">
                  <div>
                    <p className="text-foreground font-medium">{inv.invoice_number}</p>
                    <p className="text-xs text-muted">{new Date(inv.date).toLocaleDateString(locale === "ar" ? "ar-JO" : "en-JO", { year: "numeric", month: "short", day: "numeric" })}</p>
                  </div>
                  <span className={`text-xs font-medium px-2 py-1 rounded-full border ${
                    inv.payment_status === "paid" ? "bg-emerald-500/20 text-emerald-400 border-emerald-500/30"
                    : inv.payment_status === "partial" ? "bg-yellow-500/20 text-yellow-400 border-yellow-500/30"
                    : "bg-red-500/20 text-red-400 border-red-500/30"
                  }`}>{inv.payment_status}</span>
                </div>
                <div className="grid grid-cols-3 gap-3 text-sm mb-3">
                  <div><p className="text-xs text-muted">{t("accounting.ar.net_amount")}</p><p className="text-foreground">{formatCurrency(inv.net_amount, locale)}</p></div>
                  <div><p className="text-xs text-muted">{t("accounting.ar.paid")}</p><p className="text-foreground">{formatCurrency(inv.paid, locale)}</p></div>
                  <div><p className="text-xs text-muted">{t("accounting.ar.balance")}</p><p className="text-red-400 font-semibold">{formatCurrency(inv.balance, locale)}</p></div>
                </div>
                {inv.balance > 0 && (
                  <button onClick={() => openCollect(selected, inv)}
                    className="w-full flex items-center justify-center gap-2 px-4 py-2 bg-primary hover:bg-primary-light text-foreground rounded-lg text-sm font-medium transition-colors">
                    <HandCoins className="w-4 h-4" /> {t("accounting.ar.collect")}
                  </button>
                )}
              </div>
            ))}
            {selected.invoices.length === 0 && (
              <p className="text-sm text-muted text-center py-8">{t("accounting.ar.no_invoices")}</p>
            )}
          </div>
        )}
      </SlideOver>

      {collectTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          <div className="fixed inset-0 bg-black/60 backdrop-blur-sm" onClick={() => !saving && setCollectTarget(null)} />
          <motion.div
            initial={{ opacity: 0, scale: 0.96 }}
            animate={{ opacity: 1, scale: 1 }}
            className="relative w-full max-w-md glass rounded-2xl p-6"
          >
            <h3 className="text-lg font-semibold text-foreground mb-1">{t("accounting.ar.collect_payment")}</h3>
            <p className="text-sm text-muted mb-5">
              {collectTarget.customer.name} · {collectTarget.invoice.invoice_number}
            </p>
            <div className="space-y-4">
              <div>
                <label className="text-xs text-muted mb-1 block">{t("accounting.ar.amount")}</label>
                <input type="number" step="0.01" min="0.01" value={form.amount}
                  onChange={(e) => setForm((p) => ({ ...p, amount: e.target.value }))}
                  className="w-full px-3 py-2 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors" />
              </div>
              <div>
                <label className="text-xs text-muted mb-1 block">{t("accounting.ar.method")}</label>
                <select value={form.method}
                  onChange={(e) => setForm((p) => ({ ...p, method: e.target.value }))}
                  className="w-full px-3 py-2 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors">
                  {PAYMENT_METHODS.map((m) => (
                    <option key={m} value={m}>{t(`accounting.ar.${m === "bank_transfer" ? "bank" : m}`)}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className="text-xs text-muted mb-1 block">{t("accounting.ar.reference")}</label>
                <input type="text" placeholder={t("accounting.ar.reference_placeholder")} value={form.reference_number}
                  onChange={(e) => setForm((p) => ({ ...p, reference_number: e.target.value }))}
                  className="w-full px-3 py-2 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors" />
              </div>
              <div>
                <label className="text-xs text-muted mb-1 block">{t("accounting.ar.notes")}</label>
                <textarea rows={2} value={form.notes}
                  onChange={(e) => setForm((p) => ({ ...p, notes: e.target.value }))}
                  className="w-full px-3 py-2 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors" />
              </div>
              <div className="flex gap-3 pt-1">
                <button onClick={() => setCollectTarget(null)} disabled={saving}
                  className="flex-1 px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground hover:border-border-hover transition-colors disabled:opacity-40">
                  {t("common.cancel")}
                </button>
                <button onClick={submitCollect} disabled={saving || !form.amount || parseFloat(form.amount) <= 0}
                  className="flex-1 flex items-center justify-center gap-2 px-4 py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors disabled:opacity-40">
                  {saving ? <Loader2 className="w-4 h-4 animate-spin" /> : <HandCoins className="w-4 h-4" />}
                  {saving ? t("accounting.ar.saving") : t("accounting.ar.collect")}
                </button>
              </div>
            </div>
          </motion.div>
        </div>
      )}
    </motion.div>
  );
}
