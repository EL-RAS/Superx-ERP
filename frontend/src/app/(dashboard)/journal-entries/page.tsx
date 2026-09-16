"use client";

import { useEffect, useState, useCallback } from "react";
import { motion } from "framer-motion";
import { useAuthStore } from "@/stores/auth-store";
import { JournalEntries, Accounts, postJournalEntry, reverseJournalEntry } from "@/lib/api";
import type { JournalEntry, Account } from "@/lib/types";
import { formatCurrency } from "@/lib/types";
import { usePagination } from "@/lib/pagination";
import PageHeader from "@/components/ui/PageHeader";
import DataTable from "@/components/ui/DataTable";
import type { Column } from "@/components/ui/DataTable";
import SlideOver from "@/components/ui/SlideOver";
import StatusBadge from "@/components/ui/StatusBadge";
import { BookOpen, Plus, X, Loader2, CheckCircle, RotateCcw } from "lucide-react";
import { useI18n } from "@/lib/i18n";

interface LineDraft {
  account_id: string;
  debit: string;
  credit: string;
  description: string;
}

const emptyLine: LineDraft = { account_id: "", debit: "0", credit: "0", description: "" };

export default function JournalEntriesPage() {
  const { t, locale } = useI18n();
  const { token, business } = useAuthStore();
  const [data, setData] = useState<JournalEntry[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const { page, perPage, setPage, resetPage, changePageSize } = usePagination();
  const [selected, setSelected] = useState<JournalEntry | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [accounts, setAccounts] = useState<Account[]>([]);
  const [formOpen, setFormOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [dateFilter, setDateFilter] = useState({ from: "", to: "" });
  const [statusFilter, setStatusFilter] = useState("");
  const [form, setForm] = useState({ date: new Date().toISOString().split("T")[0], description: "" });
  const [lines, setLines] = useState<LineDraft[]>([{ ...emptyLine }, { ...emptyLine }]);
  const [toast, setToast] = useState<{ msg: string; type: "success" | "error" } | null>(null);

  const showToast = (msg: string, type: "success" | "error" = "success") => {
    setToast({ msg, type });
    setTimeout(() => setToast(null), 3000);
  };

  const columns: Column[] = [
    { key: "entry_number", label: t("journal_entries.entry_num") },
    { key: "date", label: t("common.date"), type: "date" },
    { key: "description", label: t("common.description") },
    { key: "total_debit", label: t("journal_entries.total_debit"), type: "currency" },
    { key: "total_credit", label: t("journal_entries.total_credit"), type: "currency" },
    { key: "is_posted", label: t("common.status"), render: (v) => <StatusBadge status={v ? "posted" : "draft"} /> },
  ];

  const fetchData = useCallback(() => {
    if (!token || !business) return;
    setLoading(true);
    const params: Record<string, string | number> = { page, per_page: perPage };
    if (dateFilter.from) params.date_from = dateFilter.from;
    if (dateFilter.to) params.date_to = dateFilter.to;
    if (statusFilter) params.is_posted = statusFilter;
    JournalEntries.list(token, business.id, params)
      .then((res) => {
        setData(res.data);
        setTotal(res.total);
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [token, business, dateFilter, statusFilter, page, perPage]);

  const fetchAccounts = useCallback(() => {
    if (!token || !business) return;
    Accounts.list(token, business.id, { per_page: 500 }).then((res) => setAccounts(res.data)).catch(() => {});
  }, [token, business]);

  useEffect(() => {
    const timer = setTimeout(() => { fetchData(); fetchAccounts(); }, 300);
    return () => clearTimeout(timer);
  }, [fetchData, fetchAccounts]);

  const openDetail = async (row: Record<string, unknown>) => {
    if (!token || !business) return;
    const je = row as unknown as JournalEntry;
    setDetailLoading(true);
    setSelected(je);
    try {
      const full = await JournalEntries.get(token, business.id, je.id);
      setSelected(full);
    } catch {} finally { setDetailLoading(false); }
  };

  const openCreate = () => {
    setForm({ date: new Date().toISOString().split("T")[0], description: "" });
    setLines([{ ...emptyLine }, { ...emptyLine }]);
    setFormOpen(true);
  };

  const updateLine = (idx: number, field: keyof LineDraft, value: string) => {
    setLines((prev) => prev.map((l, i) => (i === idx ? { ...l, [field]: value } : l)));
  };

  const addLine = () => setLines((prev) => [...prev, { ...emptyLine }]);
  const removeLine = (idx: number) => setLines((prev) => prev.filter((_, i) => i !== idx));

  const totalDebit = lines.reduce((s, l) => s + (parseFloat(l.debit) || 0), 0);
  const totalCredit = lines.reduce((s, l) => s + (parseFloat(l.credit) || 0), 0);
  const isBalanced = Math.abs(totalDebit - totalCredit) < 0.01 && totalDebit > 0;

  const handleCreate = async () => {
    if (!token || !business) return;
    const validLines = lines.filter((l) => l.account_id && (parseFloat(l.debit) > 0 || parseFloat(l.credit) > 0));
    if (validLines.length < 2 || !form.description) { showToast(t("journal_entries.error_min_lines"), "error"); return; }
    setSaving(true);
    try {
      await JournalEntries.create(token, business.id, {
        date: form.date,
        description: form.description,
        is_posted: false,
        lines: validLines.map((l) => ({
          account_id: parseInt(l.account_id),
          debit: parseFloat(l.debit) || 0,
          credit: parseFloat(l.credit) || 0,
          description: l.description || undefined,
        })),
      });
      showToast(t("journal_entries.created"));
      setFormOpen(false);
      fetchData();
    } catch (e: unknown) {
      showToast(e instanceof Error ? e.message : t("common.error"), "error");
    } finally { setSaving(false); }
  };

  const handlePost = async (id: number) => {
    if (!token || !business) return;
    try {
      await postJournalEntry(token, business.id, id);
      showToast(t("journal_entries.posted"));
      fetchData();
      if (selected?.id === id) {
        const full = await JournalEntries.get(token, business.id, id);
        setSelected(full);
      }
    } catch (e: unknown) {
      showToast(e instanceof Error ? e.message : t("common.error"), "error");
    }
  };

  const handleReverse = async (id: number) => {
    if (!token || !business) return;
    try {
      await reverseJournalEntry(token, business.id, id);
      showToast(t("journal_entries.reversed"));
      fetchData();
      setSelected(null);
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
        title={t("journal_entries.title")}
        subtitle={t("journal_entries.subtitle", { count: String(data.length) })}
        action={
          <button onClick={openCreate} className="flex items-center gap-2 px-4 py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors">
            <Plus className="w-4 h-4" /> {t("journal_entries.new_entry")}
          </button>
        }
      />

      <div className="flex gap-3 mb-4">
        <input type="date" value={dateFilter.from} onChange={(e) => { setDateFilter((p) => ({ ...p, from: e.target.value })); resetPage(); }}
          className="px-3 py-2 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors" />
        <input type="date" value={dateFilter.to} onChange={(e) => { setDateFilter((p) => ({ ...p, to: e.target.value })); resetPage(); }}
          className="px-3 py-2 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors" />
        <select value={statusFilter} onChange={(e) => { setStatusFilter(e.target.value); resetPage(); }}
          className="px-3 py-2 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors">
          <option value="">{t("common.all")}</option>
          <option value="1">{t("journal_entries.posted")}</option>
          <option value="0">{t("journal_entries.draft")}</option>
        </select>
      </div>

      <DataTable
        columns={columns}
        data={data as unknown as Record<string, unknown>[]}
        loading={loading}
        emptyMessage={t("journal_entries.empty")}
        emptyIcon={BookOpen}
        onRowClick={openDetail}
        pagination={{ total, page, perPage, onPageChange: setPage, onPerPageChange: changePageSize }}
      />

      <SlideOver open={!!selected} onClose={() => setSelected(null)} title={t("journal_entries.detail")} width="max-w-xl">
        {selected && (
          <div className="space-y-6">
            <div className="glass rounded-xl p-4 space-y-3">
              <div className="flex justify-between">
                <span className="text-sm text-muted">{t("journal_entries.entry_num")}</span>
                <span className="text-sm text-foreground font-medium">{selected.entry_number}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-sm text-muted">{t("common.date")}</span>
                <span className="text-sm text-foreground">{new Date(selected.date).toLocaleDateString(locale === "ar" ? "ar-JO" : "en-JO")}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-sm text-muted">{t("common.description")}</span>
                <span className="text-sm text-foreground">{selected.description}</span>
              </div>
              <div className="flex justify-between items-center">
                <span className="text-sm text-muted">{t("common.status")}</span>
                <StatusBadge status={selected.is_posted ? "posted" : "draft"} />
              </div>
            </div>

            <div>
              <h4 className="text-sm font-medium text-muted mb-3">{t("journal_entries.lines")}</h4>
              {selected.lines && selected.lines.length > 0 ? (
                <div className="glass rounded-xl overflow-hidden">
                  <table className="w-full">
                    <thead>
                      <tr className="border-b border-border">
                        <th className="px-4 py-3 text-start text-xs font-medium text-muted uppercase">{t("journal_entries.account")}</th>
                        <th className="px-4 py-3 text-start text-xs font-medium text-muted uppercase">{t("common.description")}</th>
                        <th className="px-4 py-3 text-end text-xs font-medium text-muted uppercase">{t("journal_entries.debit")}</th>
                        <th className="px-4 py-3 text-end text-xs font-medium text-muted uppercase">{t("journal_entries.credit")}</th>
                      </tr>
                    </thead>
                    <tbody>
                      {selected.lines.map((line) => (
                        <tr key={line.id} className="border-b border-border/30 last:border-0">
                          <td className="px-4 py-3 text-sm text-foreground">{line.account?.name ?? `${t("journal_entries.account_num")}${line.account_id}`}</td>
                          <td className="px-4 py-3 text-sm text-muted">{line.description}</td>
                          <td className="px-4 py-3 text-sm text-foreground text-end">{line.debit > 0 ? formatCurrency(line.debit, locale) : "\u2014"}</td>
                          <td className="px-4 py-3 text-sm text-foreground text-end">{line.credit > 0 ? formatCurrency(line.credit, locale) : "\u2014"}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : (
                <p className="text-sm text-muted">{detailLoading ? t("journal_entries.loading") : t("journal_entries.no_lines")}</p>
              )}
            </div>

            <div className="glass rounded-xl p-4 space-y-2">
              <div className="flex justify-between text-base font-semibold">
                <span className="text-muted">{t("journal_entries.total_debit")}</span>
                <span className="text-foreground">{formatCurrency(selected.total_debit, locale)}</span>
              </div>
              <div className="flex justify-between text-base font-semibold">
                <span className="text-muted">{t("journal_entries.total_credit")}</span>
                <span className="text-foreground">{formatCurrency(selected.total_credit, locale)}</span>
              </div>
            </div>

            {!selected.is_posted && (
              <div className="flex gap-3">
                <button onClick={() => handlePost(selected.id)}
                  className="flex items-center gap-2 px-4 py-2.5 bg-emerald-500/20 hover:bg-emerald-500/30 text-emerald-400 border border-emerald-500/30 rounded-xl text-sm font-medium transition-colors">
                  <CheckCircle className="w-4 h-4" /> {t("journal_entries.post")}
                </button>
              </div>
            )}
            {selected.is_posted && (
              <button onClick={() => handleReverse(selected.id)}
                className="flex items-center gap-2 px-4 py-2.5 bg-amber-500/20 hover:bg-amber-500/30 text-amber-400 border border-amber-500/30 rounded-xl text-sm font-medium transition-colors">
                <RotateCcw className="w-4 h-4" /> {t("journal_entries.reverse")}
              </button>
            )}
          </div>
        )}
      </SlideOver>

      <SlideOver open={formOpen} onClose={() => setFormOpen(false)} title={t("journal_entries.new_entry")} width="max-w-3xl">
        <div className="space-y-5">
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="text-sm text-muted mb-1 block">{t("common.date")}</label>
              <input type="date" value={form.date} onChange={(e) => setForm((p) => ({ ...p, date: e.target.value }))}
                className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors" />
            </div>
            <div>
              <label className="text-sm text-muted mb-1 block">{t("common.description")}</label>
              <input value={form.description} onChange={(e) => setForm((p) => ({ ...p, description: e.target.value }))}
                className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors"
                placeholder={t("journal_entries.description_placeholder")} />
            </div>
          </div>

          <div>
            <div className="flex items-center justify-between mb-3">
              <h4 className="text-sm font-medium text-muted">{t("journal_entries.lines")}</h4>
              <button onClick={addLine} className="text-xs text-primary-light hover:text-primary transition-colors">+ {t("invoices.add_item")}</button>
            </div>
            <div className="space-y-3">
              {lines.map((line, idx) => (
                <div key={idx} className="glass rounded-xl p-3">
                  <div className="grid grid-cols-12 gap-2">
                    <div className="col-span-4">
                      <select value={line.account_id} onChange={(e) => updateLine(idx, "account_id", e.target.value)}
                        className="w-full px-3 py-2 bg-card/80 border border-border rounded-lg text-sm text-foreground focus:outline-none focus:border-border-hover">
                        <option value="">{t("journal_entries.select_account")}</option>
                        {accounts.map((a) => <option key={a.id} value={a.id}>[{a.code}] {a.name}</option>)}
                      </select>
                    </div>
                    <div className="col-span-2">
                      <input type="number" min="0" step="0.01" placeholder={t("journal_entries.debit")} value={line.debit}
                        onChange={(e) => updateLine(idx, "debit", e.target.value)}
                        className="w-full px-3 py-2 bg-card/80 border border-border rounded-lg text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover" />
                    </div>
                    <div className="col-span-2">
                      <input type="number" min="0" step="0.01" placeholder={t("journal_entries.credit")} value={line.credit}
                        onChange={(e) => updateLine(idx, "credit", e.target.value)}
                        className="w-full px-3 py-2 bg-card/80 border border-border rounded-lg text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover" />
                    </div>
                    <div className="col-span-3">
                      <input placeholder={t("common.description")} value={line.description}
                        onChange={(e) => updateLine(idx, "description", e.target.value)}
                        className="w-full px-3 py-2 bg-card/80 border border-border rounded-lg text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover" />
                    </div>
                    <div className="col-span-1 flex items-center justify-center">
                      {lines.length > 2 && (
                        <button onClick={() => removeLine(idx)} className="p-1 text-muted hover:text-red-400 transition-colors">
                          <X className="w-4 h-4" />
                        </button>
                      )}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </div>

          <div className="glass rounded-xl p-4 flex justify-between">
            <div className="text-sm">
              <span className="text-muted">{t("journal_entries.total_debit")}: </span>
              <span className="font-medium text-foreground">{formatCurrency(totalDebit, locale)}</span>
            </div>
            <div className="text-sm">
              <span className="text-muted">{t("journal_entries.total_credit")}: </span>
              <span className="font-medium text-foreground">{formatCurrency(totalCredit, locale)}</span>
            </div>
            <div className={`text-sm font-medium ${isBalanced ? "text-emerald-400" : "text-red-400"}`}>
              {isBalanced ? t("journal_entries.balanced") : t("journal_entries.unbalanced")}
            </div>
          </div>

          <div className="flex gap-3">
            <button onClick={handleCreate} disabled={saving || !isBalanced}
              className="flex items-center gap-2 px-6 py-3 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors disabled:opacity-40">
              {saving ? <Loader2 className="w-4 h-4 animate-spin" /> : <CheckCircle className="w-4 h-4" />}
              {saving ? t("common.saving") : t("journal_entries.create_entry")}
            </button>
            <button onClick={() => setFormOpen(false)} disabled={saving} className="px-6 py-3 text-sm text-muted hover:text-foreground transition-colors">
              {t("pos.cancel")}
            </button>
          </div>
        </div>
      </SlideOver>
    </motion.div>
  );
}
