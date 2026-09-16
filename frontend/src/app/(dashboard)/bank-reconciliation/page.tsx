"use client";

import { useEffect, useState, useCallback } from "react";
import { motion } from "framer-motion";
import { useAuthStore } from "@/stores/auth-store";
import { BankReconciliations, Accounts, autoMatchReconciliation, closeReconciliation, reconcileLine, unreconcileLine, importBankStatement } from "@/lib/api";
import type { BankReconciliation, BankReconciliationLine } from "@/lib/types";
import { formatCurrency } from "@/lib/types";
import { usePagination } from "@/lib/pagination";
import PageHeader from "@/components/ui/PageHeader";
import DataTable from "@/components/ui/DataTable";
import type { Column } from "@/components/ui/DataTable";
import SlideOver from "@/components/ui/SlideOver";
import StatusBadge from "@/components/ui/StatusBadge";
import { Landmark, Plus, Loader2, CheckCircle, Link, X, Upload } from "lucide-react";
import { useI18n } from "@/lib/i18n";

export default function BankReconciliationPage() {
  const { t, locale } = useI18n();
  const { token, business } = useAuthStore();
  const [data, setData] = useState<BankReconciliation[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const { page, perPage, setPage, changePageSize } = usePagination();
  const [accounts, setAccounts] = useState<{ id: number; code: string; name: string; type: string }[]>([]);
  const [selected, setSelected] = useState<BankReconciliation | null>(null);
  const [formOpen, setFormOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [importing, setImporting] = useState(false);
  const [form, setForm] = useState({ account_id: "", statement_date: new Date().toISOString().split("T")[0], statement_balance: "", notes: "" });
  const [toast, setToast] = useState<{ msg: string; type: "success" | "error" } | null>(null);

  const showToast = (msg: string, type: "success" | "error" = "success") => {
    setToast({ msg, type });
    setTimeout(() => setToast(null), 3000);
  };

  const columns: Column[] = [
    { key: "id", label: "#" },
    { key: "account", label: t("bank_recon.account"), render: (v) => ((v as Record<string, unknown>)?.name ?? "\u2014") as React.ReactNode },
    { key: "statement_date", label: t("common.date"), type: "date" },
    { key: "statement_balance", label: t("bank_recon.statement_bal"), type: "currency" },
    { key: "book_balance", label: t("bank_recon.book_bal"), type: "currency" },
    { key: "status", label: t("common.status"), render: (v) => <StatusBadge status={String(v)} /> },
  ];

  const fetchData = useCallback(() => {
    if (!token || !business) return;
    setLoading(true);
    Promise.all([
      BankReconciliations.list(token, business.id, { page, per_page: perPage }),
      Accounts.list(token, business.id, { per_page: 500 }),
    ]).then(([r, a]) => {
      setData(r.data);
      setTotal(r.total);
      setAccounts(a.data.filter((acc: { type: string }) => acc.type === "asset"));
    }).catch(() => {}).finally(() => setLoading(false));
  }, [token, business, page, perPage]);

  useEffect(() => {
    const timer = setTimeout(fetchData, 300);
    return () => clearTimeout(timer);
  }, [fetchData]);

  const openDetail = async (row: Record<string, unknown>) => {
    if (!token || !business) return;
    const rec = row as unknown as BankReconciliation;
    try {
      const full = await BankReconciliations.get(token, business.id, rec.id);
      setSelected(full);
    } catch {}
  };

  const handleCreate = async () => {
    if (!token || !business || !form.account_id || !form.statement_balance) return;
    setSaving(true);
    try {
      await BankReconciliations.create(token, business.id, {
        account_id: parseInt(form.account_id),
        statement_date: form.statement_date,
        statement_balance: parseFloat(form.statement_balance),
        notes: form.notes || undefined,
      });
      showToast(t("bank_recon.created"));
      setFormOpen(false);
      fetchData();
    } catch (e: unknown) {
      showToast(e instanceof Error ? e.message : t("common.error"), "error");
    } finally { setSaving(false); }
  };

  const handleAutoMatch = async (id: number) => {
    if (!token || !business) return;
    try {
      const result = await autoMatchReconciliation(token, business.id, id);
      const msg = (result as unknown as { message?: string }).message || t("bank_recon.matched");
      showToast(msg);
      if (selected?.id === id) {
        const full = await BankReconciliations.get(token, business.id, id);
        setSelected(full);
      }
    } catch (e: unknown) {
      showToast(e instanceof Error ? e.message : t("common.error"), "error");
    }
  };

  const handleClose = async (id: number) => {
    if (!token || !business) return;
    try {
      await closeReconciliation(token, business.id, id);
      showToast(t("bank_recon.closed"));
      setSelected(null);
      fetchData();
    } catch (e: unknown) {
      showToast(e instanceof Error ? e.message : t("common.error"), "error");
    }
  };

  const handleImport = async (e: React.ChangeEvent<HTMLInputElement>) => {
    if (!token || !business || !selected) return;
    const file = e.target.files?.[0];
    e.target.value = "";
    if (!file) return;
    setImporting(true);
    try {
      const result = await importBankStatement(token, business.id, selected.id, file);
      const msg = (result as unknown as { message?: string }).message || t("bank_recon.imported");
      showToast(msg);
      const full = await BankReconciliations.get(token, business.id, selected.id);
      setSelected(full);
      fetchData();
    } catch (err: unknown) {
      showToast(err instanceof Error ? err.message : t("common.error"), "error");
    } finally {
      setImporting(false);
    }
  };

  const handleToggleLine = async (reconId: number, line: BankReconciliationLine) => {
    if (!token || !business) return;
    try {
      if (line.reconciled) {
        await unreconcileLine(token, business.id, reconId, line.id);
      } else {
        await reconcileLine(token, business.id, reconId, line.id);
      }
      const full = await BankReconciliations.get(token, business.id, reconId);
      setSelected(full);
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
        title={t("bank_recon.title")}
        subtitle={t("bank_recon.subtitle")}
        action={
          <button onClick={() => { setForm({ account_id: "", statement_date: new Date().toISOString().split("T")[0], statement_balance: "", notes: "" }); setFormOpen(true); }}
            className="flex items-center gap-2 px-4 py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors">
            <Plus className="w-4 h-4" /> {t("bank_recon.new")}
          </button>
        }
      />

      <DataTable
        columns={columns}
        data={data as unknown as Record<string, unknown>[]}
        loading={loading}
        emptyMessage={t("bank_recon.empty")}
        emptyIcon={Landmark}
        onRowClick={openDetail}
        pagination={{ total, page, perPage, onPageChange: setPage, onPerPageChange: changePageSize }}
      />

      <SlideOver open={formOpen} onClose={() => setFormOpen(false)} title={t("bank_recon.new")}>
        <div className="space-y-4">
          <div>
            <label className="text-sm text-muted mb-1 block">{t("bank_recon.bank_account")}</label>
            <select value={form.account_id} onChange={(e) => setForm((p) => ({ ...p, account_id: e.target.value }))}
              className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors">
              <option value="">{"\u2014"}</option>
              {accounts.map((a) => <option key={a.id} value={a.id}>[{a.code}] {a.name}</option>)}
            </select>
          </div>
          <div>
            <label className="text-sm text-muted mb-1 block">{t("common.date")}</label>
            <input type="date" value={form.statement_date} onChange={(e) => setForm((p) => ({ ...p, statement_date: e.target.value }))}
              className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors" />
          </div>
          <div>
            <label className="text-sm text-muted mb-1 block">{t("bank_recon.statement_bal")}</label>
            <input type="number" step="0.01" value={form.statement_balance} onChange={(e) => setForm((p) => ({ ...p, statement_balance: e.target.value }))}
              className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors" />
          </div>
          <div>
            <label className="text-sm text-muted mb-1 block">{t("common.notes")}</label>
            <textarea value={form.notes} onChange={(e) => setForm((p) => ({ ...p, notes: e.target.value }))} rows={3}
              className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors resize-none" />
          </div>
          <button onClick={handleCreate} disabled={saving || !form.account_id || !form.statement_balance}
            className="flex items-center gap-2 px-6 py-3 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors disabled:opacity-40">
            {saving ? <Loader2 className="w-4 h-4 animate-spin" /> : <CheckCircle className="w-4 h-4" />}
            {saving ? t("common.saving") : t("common.create")}
          </button>
        </div>
      </SlideOver>

      <SlideOver open={!!selected} onClose={() => setSelected(null)} title={t("bank_recon.detail")} width="max-w-3xl">
        {selected && (
          <div className="space-y-5">
            <div className="glass rounded-xl p-4 grid grid-cols-2 gap-4">
              <div>
                <p className="text-xs text-muted">{t("bank_recon.bank_account")}</p>
                <p className="text-sm text-foreground font-medium">[{selected.account?.code}] {selected.account?.name}</p>
              </div>
              <div>
                <p className="text-xs text-muted">{t("common.date")}</p>
                <p className="text-sm text-foreground">{new Date(selected.statement_date).toLocaleDateString(locale === "ar" ? "ar-JO" : "en-JO")}</p>
              </div>
              <div>
                <p className="text-xs text-muted">{t("bank_recon.statement_bal")}</p>
                <p className="text-sm text-foreground font-medium">{formatCurrency(selected.statement_balance, locale)}</p>
              </div>
              <div>
                <p className="text-xs text-muted">{t("bank_recon.book_bal")}</p>
                <p className="text-sm text-foreground font-medium">{formatCurrency(selected.book_balance, locale)}</p>
              </div>
              <div>
                <p className="text-xs text-muted">{t("common.status")}</p>
                <StatusBadge status={selected.status} />
              </div>
              <div>
                <p className="text-xs text-muted">{t("bank_recon.difference")}</p>
                <p className={`text-sm font-medium ${Math.abs(selected.statement_balance - selected.book_balance) < 0.01 ? "text-emerald-400" : "text-red-400"}`}>
                  {formatCurrency(selected.statement_balance - selected.book_balance, locale)}
                </p>
              </div>
            </div>

            {selected.lines && selected.lines.length > 0 && (
              <div>
                <h4 className="text-sm font-medium text-muted mb-3">{t("bank_recon.lines")}</h4>
                <div className="glass rounded-xl overflow-hidden">
                  <table className="w-full">
                    <thead>
                      <tr className="border-b border-border">
                        <th className="px-4 py-3 text-start text-xs font-medium text-muted uppercase">{t("common.date")}</th>
                        <th className="px-4 py-3 text-start text-xs font-medium text-muted uppercase">{t("common.description")}</th>
                        <th className="px-4 py-3 text-end text-xs font-medium text-muted uppercase">{t("journal_entries.debit")}</th>
                        <th className="px-4 py-3 text-end text-xs font-medium text-muted uppercase">{t("common.status")}</th>
                        <th className="px-4 py-3 text-center text-xs font-medium text-muted uppercase">{t("bank_recon.action")}</th>
                      </tr>
                    </thead>
                    <tbody>
                      {selected.lines.map((line) => (
                        <tr key={line.id} className="border-b border-border/30 last:border-0">
                          <td className="px-4 py-3 text-sm text-foreground">
                            {line.statement_date ? new Date(line.statement_date).toLocaleDateString(locale === "ar" ? "ar-JO" : "en-JO") : "\u2014"}
                          </td>
                          <td className="px-4 py-3 text-sm text-muted">{line.description}</td>
                          <td className="px-4 py-3 text-sm text-foreground text-end">{formatCurrency(line.amount, locale)}</td>
                          <td className="px-4 py-3 text-sm">
                            {line.reconciled ? (
                              <span className="text-emerald-400 text-xs font-medium">{t("bank_recon.reconciled")}</span>
                            ) : (
                              <span className="text-muted text-xs">{t("bank_recon.pending")}</span>
                            )}
                          </td>
                          <td className="px-4 py-3 text-center">
                            {selected.status === "draft" && (
                              <button onClick={() => handleToggleLine(selected.id, line)}
                                className={`p-1 rounded transition-colors ${line.reconciled ? "text-amber-400 hover:bg-amber-500/10" : "text-emerald-400 hover:bg-emerald-500/10"}`}>
                                {line.reconciled ? <X className="w-4 h-4" /> : <CheckCircle className="w-4 h-4" />}
                              </button>
                            )}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            )}

            {selected.status === "draft" && (
              <div className="flex flex-wrap gap-3">
                <label className={`flex items-center gap-2 px-4 py-2.5 bg-blue-500/20 hover:bg-blue-500/30 text-blue-400 border border-blue-500/30 rounded-xl text-sm font-medium transition-colors cursor-pointer ${importing ? "opacity-60 pointer-events-none" : ""}`}>
                  {importing ? <Loader2 className="w-4 h-4 animate-spin" /> : <Upload className="w-4 h-4" />}
                  {importing ? t("bank_recon.importing") : t("bank_recon.import")}
                  <input type="file" accept=".csv,.txt" className="hidden" onChange={handleImport} disabled={importing} />
                </label>
                <button onClick={() => handleAutoMatch(selected.id)}
                  className="flex items-center gap-2 px-4 py-2.5 bg-blue-500/20 hover:bg-blue-500/30 text-blue-400 border border-blue-500/30 rounded-xl text-sm font-medium transition-colors">
                  <Link className="w-4 h-4" /> {t("bank_recon.auto_match")}
                </button>
                <button onClick={() => handleClose(selected.id)}
                  className="flex items-center gap-2 px-4 py-2.5 bg-emerald-500/20 hover:bg-emerald-500/30 text-emerald-400 border border-emerald-500/30 rounded-xl text-sm font-medium transition-colors">
                  <CheckCircle className="w-4 h-4" /> {t("bank_recon.close_recon")}
                </button>
              </div>
            )}

            {selected.status === "draft" && (
              <p className="text-xs text-muted">{t("bank_recon.import_hint")}</p>
            )}
          </div>
        )}
      </SlideOver>
    </motion.div>
  );
}
