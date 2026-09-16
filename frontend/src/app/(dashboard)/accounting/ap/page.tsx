"use client";

import { useState, useCallback, useEffect } from "react";
import { motion } from "framer-motion";
import { useAuthStore } from "@/stores/auth-store";
import { fetchAccountsPayable, fetchPayableStatement, payPurchaseOrderFromAccounting, payGoodsReceiptFromAccounting } from "@/lib/api";
import type { AccountsPayable, AccountPayableOrder, AccountPayableReceipt, PayableStatement } from "@/lib/types";
import { formatCurrency } from "@/lib/types";
import PageHeader from "@/components/ui/PageHeader";
import SlideOver from "@/components/ui/SlideOver";
import Pagination from "@/components/ui/Pagination";
import { usePagination } from "@/lib/pagination";
import { useI18n } from "@/lib/i18n";
import {
  Wallet, Loader2, Truck, FileText, RefreshCw, ArrowRightLeft, ScrollText,
} from "lucide-react";

const PAYMENT_METHODS = ["cash", "card", "bank_transfer", "check", "mobile"] as const;

export default function AccountsPayablePage() {
  const { t, locale } = useI18n();
  const { token, business } = useAuthStore();
  const [data, setData] = useState<AccountsPayable | null>(null);
  const [loading, setLoading] = useState(false);
  const [selected, setSelected] = useState<AccountsPayable["suppliers"][number] | null>(null);
  const [statement, setStatement] = useState<PayableStatement | null>(null);
  const [statementLoading, setStatementLoading] = useState(false);
  const [payTarget, setPayTarget] = useState<{
    supplier: AccountsPayable["suppliers"][number];
    order: AccountPayableOrder | AccountPayableReceipt;
    isReceipt: boolean;
    reference: string;
  } | null>(null);
  const [form, setForm] = useState({ amount: "", method: "cash", reference_number: "", notes: "" });
  const [saving, setSaving] = useState(false);
  const [toast, setToast] = useState<{ msg: string; type: "success" | "error" } | null>(null);
  const { page, perPage, setPage, changePageSize } = usePagination();

  const suppliers = data?.suppliers ?? [];
  const visibleSuppliers = suppliers.slice((page - 1) * perPage, page * perPage);

  const showToast = (msg: string, type: "success" | "error" = "success") => {
    setToast({ msg, type });
    setTimeout(() => setToast(null), 3000);
  };

  const load = useCallback(async () => {
    if (!token || !business) return;
    setLoading(true);
    try {
      const result = await fetchAccountsPayable(token, business.id);
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

  const openStatement = async (supplierId: number | string) => {
    if (!token || !business) return;
    setStatementLoading(true);
    setStatement(null);
    try {
      setStatement(await fetchPayableStatement(token, business.id, supplierId));
    } catch {
      showToast(t("common.error"), "error");
    } finally {
      setStatementLoading(false);
    }
  };

  const openPay = (
    supplier: AccountsPayable["suppliers"][number],
    order: AccountPayableOrder | AccountPayableReceipt,
    isReceipt: boolean,
  ) => {
    const reference = isReceipt ? (order as AccountPayableReceipt).receipt_number : (order as AccountPayableOrder).order_number;
    setPayTarget({ supplier, order, isReceipt, reference });
    setForm({ amount: String(order.balance), method: "cash", reference_number: "", notes: "" });
  };

  const submitPay = async () => {
    if (!token || !business || !payTarget) return;
    const amount = parseFloat(form.amount);
    if (!amount || amount <= 0) {
      showToast(t("accounting.ap.failed"), "error");
      return;
    }
    setSaving(true);
    try {
      const payload = {
        amount,
        method: form.method,
        reference_number: form.reference_number || undefined,
        notes: form.notes || undefined,
      };
      if (payTarget.isReceipt) {
        await payGoodsReceiptFromAccounting(token, business.id, payTarget.order.id, payload);
      } else {
        await payPurchaseOrderFromAccounting(token, business.id, payTarget.order.id, payload);
      }
      showToast(t("accounting.ap.success"));
      setPayTarget(null);
      await load();
    } catch (err) {
      showToast(err instanceof Error ? err.message : t("accounting.ap.failed"), "error");
    } finally {
      setSaving(false);
    }
  };

  const totalOpenOrders = data?.suppliers.reduce((n, s) => n + s.open_orders_count, 0) ?? 0;
  const totalOpenReceipts = data?.suppliers.reduce((n, s) => n + s.open_receipts_count, 0) ?? 0;

  return (
    <motion.div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.4 }}>
      {toast && (
        <div className={`fixed top-4 end-4 z-[60] px-4 py-2.5 rounded-xl text-sm font-medium shadow-lg transition-all ${
          toast.type === "success" ? "bg-emerald-500/20 text-emerald-400 border border-emerald-500/30" : "bg-red-500/20 text-red-400 border border-red-500/30"
        }`}>{toast.msg}</div>
      )}

      <PageHeader
        title={t("accounting.ap.title")}
        subtitle={t("accounting.ap.subtitle")}
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
          <div className="flex items-center gap-2 mb-2"><Wallet className="w-4 h-4 text-red-400" /><span className="text-xs text-muted">{t("accounting.ap.total_outstanding")}</span></div>
          <p className="text-2xl font-bold text-red-400">{formatCurrency(data?.total_outstanding ?? 0, locale)}</p>
        </div>
        <div className="glass rounded-xl p-5">
          <div className="flex items-center gap-2 mb-2"><Truck className="w-4 h-4 text-primary-light" /><span className="text-xs text-muted">{t("accounting.ap.suppliers")}</span></div>
          <p className="text-2xl font-bold">{data?.suppliers.length ?? 0}</p>
        </div>
        <div className="glass rounded-xl p-5">
          <div className="flex items-center gap-2 mb-2"><FileText className="w-4 h-4 text-primary-light" /><span className="text-xs text-muted">{t("accounting.ap.open_items")}</span></div>
          <p className="text-2xl font-bold">{totalOpenOrders + totalOpenReceipts}</p>
          <p className="text-xs text-muted mt-1">{t("accounting.ap.open_orders")}: {totalOpenOrders} · {t("accounting.ap.receipts")}: {totalOpenReceipts}</p>
        </div>
      </div>

      {loading && !data ? (
        <div className="flex items-center justify-center py-20"><Loader2 className="w-6 h-6 animate-spin text-muted" /></div>
      ) : data && data.suppliers.length > 0 ? (
        <div className="glass rounded-xl overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-border bg-card/60">
                  <th className="px-4 py-3 text-start text-xs font-medium text-muted">{t("accounting.ap.supplier")}</th>
                  <th className="px-4 py-3 text-start text-xs font-medium text-muted">{t("accounting.ap.phone")}</th>
                  <th className="px-4 py-3 text-end text-xs font-medium text-muted">{t("accounting.ap.receipts")}</th>
                  <th className="px-4 py-3 text-end text-xs font-medium text-muted">{t("accounting.ap.balance")}</th>
                  <th className="px-4 py-3 text-end text-xs font-medium text-muted" />
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {visibleSuppliers.map((s) => (
                  <tr key={s.supplier_id} onClick={() => setSelected(s)} className="hover:bg-accent-dim cursor-pointer transition-colors">
                    <td className="px-4 py-3 text-foreground font-medium">{s.name}</td>
                    <td className="px-4 py-3 text-muted">{s.phone ?? "—"}</td>
                    <td className="px-4 py-3 text-end text-foreground">{s.open_receipts_count}</td>
                    <td className="px-4 py-3 text-end text-red-400 font-semibold">{formatCurrency(s.outstanding, locale)}</td>
                    <td className="px-4 py-3 text-end"><ArrowRightLeft className="w-4 h-4 text-muted inline" /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <Pagination total={suppliers.length} page={page} perPage={perPage} onPageChange={setPage} onPerPageChange={changePageSize} />
        </div>
      ) : (
        <div className="glass rounded-2xl p-12 text-center">
          <Wallet className="w-12 h-12 text-muted mx-auto mb-4" />
          <h3 className="text-lg font-medium text-foreground mb-2">{t("accounting.ap.empty")}</h3>
          <p className="text-sm text-muted">{t("accounting.ap.empty_desc")}</p>
        </div>
      )}

      <SlideOver
        open={!!selected}
        onClose={() => setSelected(null)}
        title={selected ? t("accounting.ap.orders_for", { name: selected.name }) : ""}
        width="max-w-2xl"
      >
        {selected && (
          <div className="space-y-3">
            <div className="flex items-center justify-between text-sm mb-2">
              <span className="text-muted">{t("accounting.ap.total_outstanding")}</span>
              <span className="text-red-400 font-bold text-lg">{formatCurrency(selected.outstanding, locale)}</span>
            </div>
            <button
              onClick={() => openStatement(selected.supplier_id)}
              className="w-full flex items-center justify-center gap-2 px-4 py-2 bg-card/80 border border-border rounded-xl text-sm text-foreground hover:border-border-hover transition-colors mb-3">
              <ScrollText className="w-4 h-4" /> {t("accounting.ap.view_statement")}
            </button>
            {selected.orders.map((ord) => (
              <div key={ord.id} className="glass rounded-xl p-4">
                <div className="flex items-center justify-between mb-3">
                  <div>
                    <p className="text-foreground font-medium">{ord.order_number}</p>
                    <p className="text-xs text-muted">{new Date(ord.order_date).toLocaleDateString(locale === "ar" ? "ar-JO" : "en-JO", { year: "numeric", month: "short", day: "numeric" })}</p>
                  </div>
                  <span className={`text-xs font-medium px-2 py-1 rounded-full border ${
                    ord.status === "received" ? "bg-emerald-500/20 text-emerald-400 border-emerald-500/30"
                    : ord.status === "partially_received" ? "bg-yellow-500/20 text-yellow-400 border-yellow-500/30"
                    : "bg-blue-500/20 text-blue-400 border-blue-500/30"
                  }`}>{ord.status.replace(/_/g, " ")}</span>
                </div>
                <div className="grid grid-cols-3 gap-3 text-sm mb-3">
                  <div><p className="text-xs text-muted">{t("accounting.ap.total")}</p><p className="text-foreground">{formatCurrency(ord.total, locale)}</p></div>
                  <div><p className="text-xs text-muted">{t("accounting.ap.paid")}</p><p className="text-foreground">{formatCurrency(ord.paid, locale)}</p></div>
                  <div><p className="text-xs text-muted">{t("accounting.ap.balance")}</p><p className="text-red-400 font-semibold">{formatCurrency(ord.balance, locale)}</p></div>
                </div>
                {ord.balance > 0 && (
                  <button onClick={() => openPay(selected, ord, false)}
                    className="w-full flex items-center justify-center gap-2 px-4 py-2 bg-primary hover:bg-primary-light text-foreground rounded-lg text-sm font-medium transition-colors">
                    <Wallet className="w-4 h-4" /> {t("accounting.ap.pay")}
                  </button>
                )}
              </div>
            ))}
            {selected.orders.length === 0 && selected.receipts.length === 0 && (
              <p className="text-sm text-muted text-center py-8">{t("accounting.ap.no_orders")}</p>
            )}
            {selected.receipts.length > 0 && (
              <>
                <div className="flex items-center gap-2 pt-3 pb-1">
                  <FileText className="w-4 h-4 text-muted" />
                  <span className="text-xs font-medium text-muted uppercase tracking-wide">{t("accounting.ap.receipts")}</span>
                </div>
                {selected.receipts.map((rc) => (
                  <div key={rc.id} className="glass rounded-xl p-4">
                    <div className="flex items-center justify-between mb-3">
                      <div>
                        <p className="text-foreground font-medium">{rc.receipt_number}</p>
                        <p className="text-xs text-muted">{new Date(rc.receipt_date).toLocaleDateString(locale === "ar" ? "ar-JO" : "en-JO", { year: "numeric", month: "short", day: "numeric" })}</p>
                      </div>
                      <span className="text-xs font-medium px-2 py-1 rounded-full border bg-blue-500/20 text-blue-400 border-blue-500/30">{t("accounting.ap.gr")}</span>
                    </div>
                    <div className="grid grid-cols-3 gap-3 text-sm mb-3">
                      <div><p className="text-xs text-muted">{t("accounting.ap.total")}</p><p className="text-foreground">{formatCurrency(rc.total, locale)}</p></div>
                      <div><p className="text-xs text-muted">{t("accounting.ap.paid")}</p><p className="text-foreground">{formatCurrency(rc.paid, locale)}</p></div>
                      <div><p className="text-xs text-muted">{t("accounting.ap.balance")}</p><p className="text-red-400 font-semibold">{formatCurrency(rc.balance, locale)}</p></div>
                    </div>
                    {rc.balance > 0 && (
                      <button onClick={() => openPay(selected, rc, true)}
                        className="w-full flex items-center justify-center gap-2 px-4 py-2 bg-primary hover:bg-primary-light text-foreground rounded-lg text-sm font-medium transition-colors">
                        <Wallet className="w-4 h-4" /> {t("accounting.ap.pay")}
                      </button>
                    )}
                  </div>
                ))}
              </>
            )}
          </div>
        )}
      </SlideOver>

      <SlideOver
        open={!!statement}
        onClose={() => setStatement(null)}
        title={statement ? t("accounting.ap.view_statement") : ""}
        width="max-w-2xl"
      >
        {statementLoading ? (
          <div className="flex items-center justify-center py-20"><Loader2 className="w-6 h-6 animate-spin text-muted" /></div>
        ) : statement ? (
          <div className="space-y-3">
            <div className="glass rounded-xl p-4 flex items-center justify-between">
              <div>
                <p className="text-foreground font-semibold">{statement.supplier.name}</p>
                <p className="text-xs text-muted">{statement.supplier.phone ?? "—"}</p>
              </div>
              <div className="text-end">
                <p className="text-xs text-muted">{statement.account_name} ({statement.account_code})</p>
                <p className="text-red-400 font-bold text-lg">{formatCurrency(statement.total_outstanding, locale)}</p>
              </div>
            </div>
            {statement.orders.map((ord, idx) => (
              <div key={`${ord.kind}-${ord.id ?? ord.receipt_number ?? idx}`} className="glass rounded-xl p-4">
                <div className="flex items-center justify-between mb-2">
                  <div className="flex items-center gap-2">
                    <p className="text-foreground font-medium">{ord.order_number ?? ord.receipt_number}</p>
                    {ord.kind && ord.kind !== "purchase_order" && (
                      <span className="text-[10px] font-medium px-1.5 py-0.5 rounded-full border border-border text-muted uppercase">
                        {ord.kind === "goods_receipt" ? t("accounting.ap.gr") : t("accounting.ap.credit_note")}
                      </span>
                    )}
                  </div>
                  <span className="text-xs text-muted">{ord.order_date ? new Date(ord.order_date).toLocaleDateString(locale === "ar" ? "ar-JO" : "en-JO", { year: "numeric", month: "short", day: "numeric" }) : ""}</span>
                </div>
                <div className="text-sm mb-3 flex justify-between">
                  <span className="text-muted">{t("accounting.ap.balance")}</span>
                  <span className={`font-semibold ${ord.balance < 0 ? "text-emerald-400" : "text-red-400"}`}>{formatCurrency(ord.balance, locale)}</span>
                </div>
                {ord.payments.length > 0 && (
                  <div className="border-t border-border pt-2 mt-2 space-y-1">
                    <p className="text-xs text-muted mb-1">{t("accounting.ap.payments")}</p>
                    {ord.payments.map((p, i) => (
                      <div key={i} className="flex items-center justify-between text-sm">
                        <span className="text-muted">
                          {t("accounting.ap.payment_num")} {p.payment_number} · {new Date(p.date).toLocaleDateString(locale === "ar" ? "ar-JO" : "en-JO", { year: "numeric", month: "short", day: "numeric" })} · {p.method.replace(/_/g, " ")}
                        </span>
                        <span className="text-foreground">{formatCurrency(p.amount, locale)}</span>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            ))}
          </div>
        ) : null}
      </SlideOver>

      {payTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          <div className="fixed inset-0 bg-black/60 backdrop-blur-sm" onClick={() => !saving && setPayTarget(null)} />
          <motion.div
            initial={{ opacity: 0, scale: 0.96 }}
            animate={{ opacity: 1, scale: 1 }}
            className="relative w-full max-w-md glass rounded-2xl p-6"
          >
            <h3 className="text-lg font-semibold text-foreground mb-1">{t("accounting.ap.record_payment")}</h3>
            <p className="text-sm text-muted mb-5">
              {payTarget.supplier.name} · {payTarget.reference}
            </p>
            <div className="space-y-4">
              <div>
                <label className="text-xs text-muted mb-1 block">{t("accounting.ap.amount")}</label>
                <input type="number" step="0.01" min="0.01" value={form.amount}
                  onChange={(e) => setForm((p) => ({ ...p, amount: e.target.value }))}
                  className="w-full px-3 py-2 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors" />
              </div>
              <div>
                <label className="text-xs text-muted mb-1 block">{t("accounting.ap.method")}</label>
                <select value={form.method}
                  onChange={(e) => setForm((p) => ({ ...p, method: e.target.value }))}
                  className="w-full px-3 py-2 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors">
                  {PAYMENT_METHODS.map((m) => (
                    <option key={m} value={m}>{t(`accounting.ap.${m === "bank_transfer" ? "bank" : m}`)}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className="text-xs text-muted mb-1 block">{t("accounting.ap.reference")}</label>
                <input type="text" placeholder={t("accounting.ap.reference_placeholder")} value={form.reference_number}
                  onChange={(e) => setForm((p) => ({ ...p, reference_number: e.target.value }))}
                  className="w-full px-3 py-2 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors" />
              </div>
              <div>
                <label className="text-xs text-muted mb-1 block">{t("accounting.ap.notes")}</label>
                <textarea rows={2} value={form.notes}
                  onChange={(e) => setForm((p) => ({ ...p, notes: e.target.value }))}
                  className="w-full px-3 py-2 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors" />
              </div>
              <div className="flex gap-3 pt-1">
                <button onClick={() => setPayTarget(null)} disabled={saving}
                  className="flex-1 px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground hover:border-border-hover transition-colors disabled:opacity-40">
                  {t("common.cancel")}
                </button>
                <button onClick={submitPay} disabled={saving || !form.amount || parseFloat(form.amount) <= 0}
                  className="flex-1 flex items-center justify-center gap-2 px-4 py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors disabled:opacity-40">
                  {saving ? <Loader2 className="w-4 h-4 animate-spin" /> : <Wallet className="w-4 h-4" />}
                  {saving ? t("accounting.ap.saving") : t("accounting.ap.pay")}
                </button>
              </div>
            </div>
          </motion.div>
        </div>
      )}
    </motion.div>
  );
}
