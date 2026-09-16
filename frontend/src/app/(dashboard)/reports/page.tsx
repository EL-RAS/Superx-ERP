"use client";

import { useState, useCallback } from "react";
import { motion } from "framer-motion";
import { useAuthStore } from "@/stores/auth-store";
import { fetchProfitAndLoss, fetchBalanceSheet, fetchCashFlow, fetchSalesSummary, fetchStockValuation, fetchSupplierAging, fetchTrialBalance } from "@/lib/api";
import type { ProfitAndLoss, BalanceSheet, CashFlow, SalesSummary, StockValuation, SupplierAging, TrialBalance } from "@/lib/types";
import { formatCurrency } from "@/lib/types";
import { downloadCSV, downloadExcel } from "@/lib/export";
import type { ExportRow } from "@/lib/export";
import PageHeader from "@/components/ui/PageHeader";
import {
  BarChart2, Loader2, TrendingUp, TrendingDown, DollarSign, Printer, FileDown, FileSpreadsheet,
  FileText, Boxes, Percent, Users, HandCoins, Receipt, Package, CheckCircle, XCircle,
} from "lucide-react";
import { useI18n } from "@/lib/i18n";

type Tab = "pnl" | "balance_sheet" | "cash_flow" | "trial_balance" | "sales_summary" | "stock_valuation" | "supplier_aging";

type Section = { accounts: { code: string; name: string; debit: number; credit: number; balance: number }[]; total: number };

function ReportTable({ headers, rows }: { headers: string[]; rows: (string | number)[][] }) {
  return (
    <div className="glass rounded-xl overflow-hidden">
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-border bg-card/60">
              {headers.map((h, i) => (
                <th key={i} className="px-4 py-3 text-start text-xs font-medium text-muted whitespace-nowrap">{h}</th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-border">
            {rows.map((r, i) => (
              <tr key={i}>
                {r.map((c, j) => (
                  <td key={j} className="px-4 py-2.5 text-foreground whitespace-nowrap">{c}</td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

export default function ReportsPage() {
  const { t, locale } = useI18n();
  const { token, business } = useAuthStore();
  const [tab, setTab] = useState<Tab>("pnl");
  const [dateFilter, setDateFilter] = useState({ from: new Date(new Date().getFullYear(), 0, 1).toISOString().split("T")[0], to: new Date().toISOString().split("T")[0] });
  const [pnl, setPnl] = useState<ProfitAndLoss | null>(null);
  const [bs, setBs] = useState<BalanceSheet | null>(null);
  const [cf, setCf] = useState<CashFlow | null>(null);
  const [tb, setTb] = useState<TrialBalance | null>(null);
  const [ss, setSs] = useState<SalesSummary | null>(null);
  const [sv, setSv] = useState<StockValuation | null>(null);
  const [sa, setSa] = useState<SupplierAging | null>(null);
  const [loading, setLoading] = useState(false);
  const [toast, setToast] = useState<{ msg: string; type: "success" | "error" } | null>(null);

  const showToast = (msg: string, type: "success" | "error" = "success") => {
    setToast({ msg, type });
    setTimeout(() => setToast(null), 3000);
  };

  const loadReport = useCallback(async () => {
    if (!token || !business) return;
    setLoading(true);
    try {
      if (tab === "pnl") {
        const r = await fetchProfitAndLoss(token, business.id, { date_from: dateFilter.from, date_to: dateFilter.to });
        setPnl(r);
      } else if (tab === "balance_sheet") {
        const r = await fetchBalanceSheet(token, business.id, { date_to: dateFilter.to });
        setBs(r);
      } else if (tab === "cash_flow") {
        const r = await fetchCashFlow(token, business.id, { date_from: dateFilter.from, date_to: dateFilter.to });
        setCf(r);
      } else if (tab === "trial_balance") {
        const r = await fetchTrialBalance(token, business.id, { date_from: dateFilter.from, date_to: dateFilter.to });
        setTb(r);
      } else if (tab === "sales_summary") {
        const r = await fetchSalesSummary(token, business.id, { date_from: dateFilter.from, date_to: dateFilter.to });
        setSs(r);
      } else if (tab === "stock_valuation") {
        const r = await fetchStockValuation(token, business.id);
        setSv(r);
      } else if (tab === "supplier_aging") {
        const r = await fetchSupplierAging(token, business.id);
        setSa(r);
      }
    } catch {
      showToast(t("common.error"), "error");
    } finally { setLoading(false); }
  }, [token, business, tab, dateFilter, t]);

  const financialTabs: { key: Tab; label: string }[] = [
    { key: "pnl", label: t("reports.profit_loss") },
    { key: "balance_sheet", label: t("reports.balance_sheet") },
    { key: "cash_flow", label: t("reports.cash_flow") },
    { key: "trial_balance", label: t("reports.trial_balance") },
  ];

  const operationalTabs: { key: Tab; label: string }[] = [
    { key: "sales_summary", label: t("reports.sales_summary") },
    { key: "stock_valuation", label: t("reports.stock_valuation") },
    { key: "supplier_aging", label: t("reports.supplier_aging") },
  ];

  const reportTitle = {
    pnl: t("reports.profit_loss"),
    balance_sheet: t("reports.balance_sheet"),
    cash_flow: t("reports.cash_flow"),
    trial_balance: t("reports.trial_balance"),
    sales_summary: t("reports.sales_summary"),
    stock_valuation: t("reports.stock_valuation"),
    supplier_aging: t("reports.supplier_aging"),
  }[tab];

  const formatDateRange = (iso: string) => {
    const [y, m, d] = iso.split("-").map(Number);
    if (!y || !m || !d) return iso;
    return new Date(y, m - 1, d).toLocaleDateString(locale === "ar" ? "ar" : "en-US", { year: "numeric", month: "2-digit", day: "2-digit" });
  };

  const fmt = (n: number) => n.toFixed(2);

  const reportRangeLabel = () => {
    if (tab === "stock_valuation" && sv) return t("reports.as_of", { date: formatDateRange(sv.generated_at) });
    if (tab === "supplier_aging" && sa) return t("reports.as_of", { date: formatDateRange(sa.as_of) });
    return t("reports.from_to", { from: formatDateRange(dateFilter.from), to: formatDateRange(dateFilter.to) });
  };

  const reportRows = (): ExportRow[] => {
    if (tab === "pnl" && pnl) {
      const rows: ExportRow[] = [[t("common.code"), t("common.account"), t("common.debit"), t("common.credit"), t("common.balance")]];
      rows.push(["", t("reports.revenue"), "", "", ""]);
      for (const a of pnl.revenue.accounts) rows.push([a.code, a.name, fmt(a.debit), fmt(a.credit), fmt(a.balance)]);
      rows.push(["", t("reports.total_revenue"), "", "", fmt(pnl.revenue.total)]);
      rows.push(["", t("reports.expenses"), "", "", ""]);
      for (const a of pnl.expense.accounts) rows.push([a.code, a.name, fmt(a.debit), fmt(a.credit), fmt(a.balance)]);
      rows.push(["", t("reports.total_expenses"), "", "", fmt(pnl.expense.total)]);
      rows.push(["", t("reports.net_income"), "", "", fmt(pnl.net_income)]);
      return rows;
    }
    if (tab === "balance_sheet" && bs) {
      const rows: ExportRow[] = [[t("common.code"), t("common.account"), t("common.debit"), t("common.credit"), t("common.balance")]];
      const sections: [string, Section][] = [
        [t("reports.assets"), bs.asset],
        [t("reports.liabilities"), bs.liability],
        [t("reports.equity"), { ...bs.equity, total: bs.equity.total + bs.equity.retained_earnings }],
      ];
      for (const [label, section] of sections) {
        rows.push(["", label, "", "", ""]);
        for (const a of section.accounts) rows.push([a.code, a.name, fmt(a.debit), fmt(a.credit), fmt(a.balance)]);
        rows.push(["", `${label} ${t("common.total")}`, "", "", fmt(section.total)]);
      }
      return rows;
    }
    if (tab === "cash_flow" && cf) {
      return [
        [t("common.section"), t("common.amount")],
        [t("reports.operating"), fmt(cf.operating)],
        [t("reports.investing"), fmt(cf.investing)],
        [t("reports.financing"), fmt(cf.financing)],
        [t("reports.net_cash_flow"), fmt(cf.net_cash_flow)],
      ];
    }
    if (tab === "trial_balance" && tb) {
      const rows: ExportRow[] = [[t("common.code"), t("common.account"), t("common.type"), t("common.debit"), t("common.credit"), t("common.balance")]];
      for (const a of tb.accounts) rows.push([a.code, a.name, a.type, fmt(a.debit), fmt(a.credit), fmt(a.balance)]);
      rows.push([t("reports.common_total"), "", "", fmt(tb.total_debit), fmt(tb.total_credit), ""]);
      return rows;
    }
    if (tab === "sales_summary" && ss) {
      const rows: ExportRow[] = [[t("reports.product"), t("reports.sku"), t("reports.category"), t("reports.qty_sold"), t("reports.revenue"), t("reports.cogs"), t("reports.profit"), t("reports.margin")]];
      for (const a of ss.by_product) rows.push([a.name, a.sku ?? "", a.category ?? t("reports.uncategorized"), fmt(a.quantity), fmt(a.revenue), fmt(a.cogs), fmt(a.profit), `${fmt(a.margin)}%`]);
      rows.push(["", "", "", fmt(ss.summary.total_quantity), fmt(ss.summary.total_revenue), fmt(ss.summary.total_cogs), fmt(ss.summary.gross_profit), `${fmt(ss.summary.gross_margin)}%`]);
      rows.push(["", t("reports.by_category"), "", "", "", "", "", ""]);
      for (const a of ss.by_category) rows.push(["", a.category ?? t("reports.uncategorized"), "", fmt(a.quantity), fmt(a.revenue), fmt(a.cogs), fmt(a.profit), `${fmt(a.margin)}%`]);
      return rows;
    }
    if (tab === "stock_valuation" && sv) {
      const rows: ExportRow[] = [[t("reports.product"), t("reports.sku"), t("reports.unit"), t("reports.category"), t("reports.on_hand"), t("reports.unit_cost"), t("reports.value")]];
      for (const a of sv.products) rows.push([a.name, a.sku ?? "", a.unit, a.category ?? t("reports.uncategorized"), fmt(a.quantity), fmt(a.cost_per_unit), fmt(a.value)]);
      rows.push([t("reports.common_total"), "", "", "", fmt(sv.summary.total_units), "", fmt(sv.summary.total_value)]);
      return rows;
    }
    if (tab === "supplier_aging" && sa) {
      const rows: ExportRow[] = [[t("reports.supplier"), t("reports.phone"), t("reports.orders_count"), t("reports.current"), t("reports.days_30"), t("reports.days_60"), t("reports.days_90"), t("reports.balance")]];
      for (const s of sa.suppliers) rows.push([s.name, s.phone ?? "", s.orders_count, fmt(s.buckets.current), fmt(s.buckets.d30), fmt(s.buckets.d60), fmt(s.buckets.d90), fmt(s.outstanding)]);
      rows.push([t("reports.common_total"), "", "", "", "", "", "", fmt(sa.total_outstanding)]);
      rows.push([t("reports.open_orders"), "", "", "", "", "", "", "", ""]);
      rows.push([t("reports.supplier"), t("reports.order_number"), t("reports.order_date"), t("reports.due_date"), t("reports.status"), t("reports.total"), t("reports.paid"), t("reports.balance"), t("reports.days_overdue")]);
      for (const s of sa.suppliers) for (const o of s.orders) rows.push([s.name, o.order_number, o.order_date, o.due_date ?? "", o.status, fmt(o.total), fmt(o.paid), fmt(o.balance), `${o.days_past_due}d`]);
      return rows;
    }
    return [];
  };

  const reportFileBase = () => {
    const name = {
      pnl: "profit-and-loss",
      balance_sheet: "balance-sheet",
      cash_flow: "cash-flow",
      trial_balance: "trial-balance",
      sales_summary: "sales-summary",
      stock_valuation: "stock-valuation",
      supplier_aging: "supplier-aging",
    }[tab];
    if (tab === "stock_valuation" && sv) return `${name}-${sv.generated_at}`;
    if (tab === "supplier_aging" && sa) return `${name}-${sa.as_of}`;
    return `${name}-${dateFilter.from}-${dateFilter.to}`;
  };

  const exportCsv = () => {
    const rows = reportRows();
    if (!rows.length) return;
    downloadCSV(`${reportFileBase()}.csv`, rows);
    showToast(t("reports.export_csv"));
  };

  const exportExcel = () => {
    const rows = reportRows();
    if (!rows.length) return;
    downloadExcel(`${reportFileBase()}.xls`, rows);
    showToast(t("reports.export_excel"));
  };

  const esc = (s: string) => s.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");

  const downloadPdf = () => {
    const rows = reportRows();
    if (!rows.length) return;
    const win = window.open("", "_blank");
    if (!win) {
      showToast(t("reports.pdf_blocked"), "error");
      return;
    }
    const headerCells = rows[0].map((c) => `<th>${esc(String(c))}</th>`).join("");
    const bodyRows = rows.slice(1).map((r) => `<tr>${r.map((c) => `<td>${esc(String(c ?? ""))}</td>`).join("")}</tr>`).join("");
    const html = `<!doctype html><html><head><meta charset="utf-8"><title>${esc(reportTitle)}</title>
<style>
  body{font-family: system-ui, -apple-system, "Segoe UI", sans-serif; margin:32px; color:#111;}
  header{text-align:center; margin-bottom:24px;}
  header h1{margin:0; font-size:22px;} header h2{margin:6px 0 0; font-size:16px;} header p{margin:6px 0 0; font-size:13px; color:#444;}
  table{width:100%; border-collapse:collapse; font-size:13px;}
  th,td{border:1px solid #ccc; padding:6px 10px; text-align:left; white-space:nowrap;}
  th{background:#f3f3f3;}
  @media print { body{margin:0;} }
</style></head><body>
<header><h1>${esc(business?.name ?? "")}</h1><h2>${esc(reportTitle)}</h2><p>${esc(reportRangeLabel())}</p></header>
<table><thead><tr>${headerCells}</tr></thead><tbody>${bodyRows}</tbody></table>
<script>window.onload=function(){window.focus();setTimeout(function(){window.print();},300);};<\/script>
</body></html>`;
    win.document.open();
    win.document.write(html);
    win.document.close();
    showToast(t("reports.download_pdf"));
  };

  const hasReport = (tab === "pnl" && pnl) || (tab === "balance_sheet" && bs) || (tab === "cash_flow" && cf) || (tab === "trial_balance" && tb) || (tab === "sales_summary" && ss) || (tab === "stock_valuation" && sv) || (tab === "supplier_aging" && sa);

  const showFrom = tab === "pnl" || tab === "cash_flow" || tab === "sales_summary";
  const showTo = tab !== "stock_valuation" && tab !== "supplier_aging";

  return (
    <motion.div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.4 }}>
      {toast && (
        <div className={`fixed top-4 end-4 z-[60] px-4 py-2.5 rounded-xl text-sm font-medium shadow-lg transition-all ${
          toast.type === "success" ? "bg-emerald-500/20 text-emerald-400 border border-emerald-500/30" : "bg-red-500/20 text-red-400 border border-red-500/30"
        }`}>{toast.msg}</div>
      )}

      <div className="print:hidden">
        <PageHeader title={t("reports.title")} subtitle={t("reports.subtitle")} />
      </div>

      <div className="space-y-2 mb-6 print:hidden">
        <div className="flex items-center gap-3">
          <span className="text-xs font-semibold text-muted uppercase tracking-wider w-40 shrink-0">{t("reports.financial_group")}</span>
          <div className="flex gap-1 p-1 glass rounded-xl flex-wrap">
            {financialTabs.map((tb) => (
              <button key={tb.key} onClick={() => setTab(tb.key)}
                className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${tab === tb.key ? "bg-primary text-foreground" : "text-muted hover:text-foreground"}`}>
                {tb.label}
              </button>
            ))}
          </div>
        </div>
        <div className="flex items-center gap-3">
          <span className="text-xs font-semibold text-muted uppercase tracking-wider w-40 shrink-0">{t("reports.operational_group")}</span>
          <div className="flex gap-1 p-1 glass rounded-xl flex-wrap">
            {operationalTabs.map((tb) => (
              <button key={tb.key} onClick={() => setTab(tb.key)}
                className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${tab === tb.key ? "bg-primary text-foreground" : "text-muted hover:text-foreground"}`}>
                {tb.label}
              </button>
            ))}
          </div>
        </div>
      </div>

      <div className="flex gap-3 mb-6 items-end print:hidden">
        {showFrom && (
          <div>
            <label className="text-xs text-muted mb-1 block">{t("trial_balance.from")}</label>
            <input type="date" value={dateFilter.from} onChange={(e) => setDateFilter((p) => ({ ...p, from: e.target.value }))}
              className="px-3 py-2 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors" />
          </div>
        )}
        {showTo && (
          <div>
            <label className="text-xs text-muted mb-1 block">{t("trial_balance.to")}</label>
            <input type="date" value={dateFilter.to} onChange={(e) => setDateFilter((p) => ({ ...p, to: e.target.value }))}
              className="px-3 py-2 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors" />
          </div>
        )}
        <button onClick={loadReport} disabled={loading}
          className="flex items-center gap-2 px-5 py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors disabled:opacity-40">
          {loading ? <Loader2 className="w-4 h-4 animate-spin" /> : <BarChart2 className="w-4 h-4" />}
          {loading ? t("common.loading") : t("reports.generate")}
        </button>
      </div>

      {hasReport && !loading && (
        <div className="flex justify-end gap-2 mb-4 print:hidden">
          <button onClick={() => window.print()}
            className="flex items-center gap-2 px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground hover:border-border-hover transition-colors">
            <Printer className="w-4 h-4" /> {t("reports.print")}
          </button>
          <button onClick={downloadPdf}
            className="flex items-center gap-2 px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground hover:border-border-hover transition-colors">
            <FileText className="w-4 h-4" /> {t("reports.download_pdf")}
          </button>
          <button onClick={exportCsv}
            className="flex items-center gap-2 px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground hover:border-border-hover transition-colors">
            <FileDown className="w-4 h-4" /> {t("reports.export_csv")}
          </button>
          <button onClick={exportExcel}
            className="flex items-center gap-2 px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground hover:border-border-hover transition-colors">
            <FileSpreadsheet className="w-4 h-4" /> {t("reports.export_excel")}
          </button>
        </div>
      )}

      {hasReport ? (
        <div className="print-area">
          <div className="hidden print:block text-center mb-8">
            <h1 className="text-2xl font-bold">{business?.name || ""}</h1>
            <h2 className="text-lg font-semibold mt-1">{reportTitle}</h2>
            <p className="text-sm mt-1">{reportRangeLabel()}</p>
            <div className="border-b mt-4 mb-6" />
          </div>

          {tab === "pnl" && pnl && (
            <div className="space-y-6">
              <div className="grid grid-cols-3 gap-4">
                <div className="glass rounded-xl p-5">
                  <div className="flex items-center gap-2 mb-2"><TrendingUp className="w-4 h-4 text-emerald-400" /><span className="text-xs text-muted">{t("reports.total_revenue")}</span></div>
                  <p className="text-2xl font-bold text-emerald-400">{formatCurrency(pnl.revenue.total, locale)}</p>
                </div>
                <div className="glass rounded-xl p-5">
                  <div className="flex items-center gap-2 mb-2"><TrendingDown className="w-4 h-4 text-red-400" /><span className="text-xs text-muted">{t("reports.total_expenses")}</span></div>
                  <p className="text-2xl font-bold text-red-400">{formatCurrency(pnl.expense.total, locale)}</p>
                </div>
                <div className="glass rounded-xl p-5">
                  <div className="flex items-center gap-2 mb-2"><DollarSign className="w-4 h-4 text-primary-light" /><span className="text-xs text-muted">{t("reports.net_income")}</span></div>
                  <p className={`text-2xl font-bold ${pnl.net_income >= 0 ? "text-emerald-400" : "text-red-400"}`}>{formatCurrency(pnl.net_income, locale)}</p>
                </div>
              </div>

              <div className="grid grid-cols-2 gap-6">
                <div className="glass rounded-xl p-5">
                  <h4 className="text-sm font-medium text-muted mb-4">{t("reports.revenue")}</h4>
                  <div className="space-y-2">
                    {pnl.revenue.accounts.map((a, i) => (
                      <div key={i} className="flex justify-between text-sm">
                        <span className="text-foreground">[{a.code}] {a.name}</span>
                        <span className="text-emerald-400 font-medium">{formatCurrency(a.balance, locale)}</span>
                      </div>
                    ))}
                  </div>
                </div>
                <div className="glass rounded-xl p-5">
                  <h4 className="text-sm font-medium text-muted mb-4">{t("reports.expenses")}</h4>
                  <div className="space-y-2">
                    {pnl.expense.accounts.map((a, i) => (
                      <div key={i} className="flex justify-between text-sm">
                        <span className="text-foreground">[{a.code}] {a.name}</span>
                        <span className="text-red-400 font-medium">{formatCurrency(a.balance, locale)}</span>
                      </div>
                    ))}
                  </div>
                </div>
              </div>
            </div>
          )}

          {tab === "balance_sheet" && bs && (
            <div className="space-y-6">
              <div className="grid grid-cols-3 gap-4">
                <div className="glass rounded-xl p-5">
                  <p className="text-xs text-muted mb-1">{t("reports.total_assets")}</p>
                  <p className="text-2xl font-bold text-foreground">{formatCurrency(bs.asset.total, locale)}</p>
                </div>
                <div className="glass rounded-xl p-5">
                  <p className="text-xs text-muted mb-1">{t("reports.total_liabilities")}</p>
                  <p className="text-2xl font-bold text-foreground">{formatCurrency(bs.liability.total, locale)}</p>
                </div>
                <div className="glass rounded-xl p-5">
                  <p className="text-xs text-muted mb-1">{t("reports.total_equity")}</p>
                  <p className="text-2xl font-bold text-foreground">{formatCurrency(bs.equity.total + bs.equity.retained_earnings, locale)}</p>
                </div>
              </div>

              <div className="grid grid-cols-3 gap-6">
                {[
                  { title: t("reports.assets"), accounts: bs.asset.accounts, total: bs.asset.total },
                  { title: t("reports.liabilities"), accounts: bs.liability.accounts, total: bs.liability.total },
                  { title: t("reports.equity"), accounts: bs.equity.accounts, total: bs.equity.total + bs.equity.retained_earnings },
                ].map((section) => (
                  <div key={section.title} className="glass rounded-xl p-5">
                    <h4 className="text-sm font-medium text-muted mb-4">{section.title}</h4>
                    <div className="space-y-2">
                      {section.accounts.map((a, i) => (
                        <div key={i} className="flex justify-between text-sm">
                          <span className="text-foreground">[{a.code}] {a.name}</span>
                          <span className="text-foreground font-medium">{formatCurrency(a.balance, locale)}</span>
                        </div>
                      ))}
                    </div>
                  </div>
                ))}
              </div>
            </div>
          )}

          {tab === "cash_flow" && cf && (
            <div className="space-y-6">
              <div className="grid grid-cols-4 gap-4">
                {[
                  { label: t("reports.operating"), value: cf.operating, color: "emerald" },
                  { label: t("reports.investing"), value: cf.investing, color: "blue" },
                  { label: t("reports.financing"), value: cf.financing, color: "violet" },
                  { label: t("reports.net_cash_flow"), value: cf.net_cash_flow, color: cf.net_cash_flow >= 0 ? "emerald" : "red" },
                ].map((item) => (
                  <div key={item.label} className="glass rounded-xl p-5">
                    <p className="text-xs text-muted mb-1">{item.label}</p>
                    <p className={`text-2xl font-bold text-${item.color}-400`}>{formatCurrency(item.value, locale)}</p>
                  </div>
                ))}
              </div>

              <div className="glass rounded-xl p-6">
                <h4 className="text-sm font-medium text-muted mb-4">{t("reports.cash_flow_breakdown")}</h4>
                <div className="space-y-4">
                  {[
                    { label: t("reports.operating"), value: cf.operating },
                    { label: t("reports.investing"), value: cf.investing },
                    { label: t("reports.financing"), value: cf.financing },
                  ].map((item) => (
                    <div key={item.label} className="flex justify-between items-center">
                      <span className="text-sm text-foreground">{item.label}</span>
                      <div className="flex-1 mx-4 h-2 bg-border rounded-full overflow-hidden">
                        <div className={`h-full rounded-full ${item.value >= 0 ? "bg-emerald-400" : "bg-red-400"}`}
                          style={{ width: `${Math.min(100, Math.abs(item.value) / Math.max(Math.abs(cf.operating), Math.abs(cf.investing), Math.abs(cf.financing), 1) * 100)}%` }} />
                      </div>
                      <span className={`text-sm font-medium ${item.value >= 0 ? "text-emerald-400" : "text-red-400"}`}>{formatCurrency(item.value, locale)}</span>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          )}

          {tab === "trial_balance" && tb && (
            <div className="space-y-6">
              <div className="grid grid-cols-3 gap-4">
                <div className="glass rounded-xl p-5">
                  <p className="text-xs text-muted mb-1">{t("journal_entries.total_debit")}</p>
                  <p className="text-2xl font-bold text-foreground">{formatCurrency(tb.total_debit, locale)}</p>
                </div>
                <div className="glass rounded-xl p-5">
                  <p className="text-xs text-muted mb-1">{t("journal_entries.total_credit")}</p>
                  <p className="text-2xl font-bold text-foreground">{formatCurrency(tb.total_credit, locale)}</p>
                </div>
                <div className="glass rounded-xl p-5">
                  <p className="text-xs text-muted mb-1">{t("trial_balance.status")}</p>
                  <div className="flex items-center gap-2">
                    {tb.is_balanced ? (
                      <><CheckCircle className="w-5 h-5 text-emerald-400" /><span className="text-xl font-bold text-emerald-400">{t("trial_balance.balanced")}</span></>
                    ) : (
                      <><XCircle className="w-5 h-5 text-red-400" /><span className="text-xl font-bold text-red-400">{t("trial_balance.unbalanced")}</span></>
                    )}
                  </div>
                </div>
              </div>

              <div className="space-y-3">
                <ReportTable
                  headers={[t("common.code"), t("common.name"), t("common.type"), t("journal_entries.debit"), t("journal_entries.credit"), t("common.balance")]}
                  rows={tb.accounts.map((a) => [
                    a.code,
                    a.name,
                    a.type,
                    formatCurrency(a.debit, locale),
                    formatCurrency(a.credit, locale),
                    formatCurrency(a.balance, locale),
                  ])}
                />
              </div>
            </div>
          )}

          {tab === "sales_summary" && ss && (
            <div className="space-y-6">
              <div className="grid grid-cols-3 gap-4">
                <div className="glass rounded-xl p-5">
                  <div className="flex items-center gap-2 mb-2"><Receipt className="w-4 h-4 text-primary-light" /><span className="text-xs text-muted">{t("reports.invoice_count")}</span></div>
                  <p className="text-2xl font-bold">{ss.summary.invoice_count}</p>
                </div>
                <div className="glass rounded-xl p-5">
                  <div className="flex items-center gap-2 mb-2"><Boxes className="w-4 h-4 text-primary-light" /><span className="text-xs text-muted">{t("reports.total_quantity")}</span></div>
                  <p className="text-2xl font-bold">{fmt(ss.summary.total_quantity)}</p>
                </div>
                <div className="glass rounded-xl p-5">
                  <div className="flex items-center gap-2 mb-2"><TrendingUp className="w-4 h-4 text-emerald-400" /><span className="text-xs text-muted">{t("reports.net_revenue")}</span></div>
                  <p className="text-2xl font-bold text-emerald-400">{formatCurrency(ss.summary.net_revenue, locale)}</p>
                  <p className="mt-1 text-[11px] text-muted">{t("reports.gross_revenue")}: {formatCurrency(ss.summary.gross_revenue, locale)} | {t("reports.tax_amount")}: {formatCurrency(ss.summary.tax_amount, locale)}</p>
                </div>
                <div className="glass rounded-xl p-5">
                  <div className="flex items-center gap-2 mb-2"><TrendingDown className="w-4 h-4 text-red-400" /><span className="text-xs text-muted">{t("reports.cogs")}</span></div>
                  <p className="text-2xl font-bold text-red-400">{formatCurrency(ss.summary.total_cogs, locale)}</p>
                </div>
                <div className="glass rounded-xl p-5">
                  <div className="flex items-center gap-2 mb-2"><DollarSign className="w-4 h-4 text-primary-light" /><span className="text-xs text-muted">{t("reports.gross_profit")}</span></div>
                  <p className={`text-2xl font-bold ${ss.summary.gross_profit >= 0 ? "text-emerald-400" : "text-red-400"}`}>{formatCurrency(ss.summary.gross_profit, locale)}</p>
                </div>
                <div className="glass rounded-xl p-5">
                  <div className="flex items-center gap-2 mb-2"><Percent className="w-4 h-4 text-primary-light" /><span className="text-xs text-muted">{t("reports.gross_margin")}</span></div>
                  <p className="text-2xl font-bold">{fmt(ss.summary.gross_margin)}%</p>
                </div>
              </div>

              <div className="grid grid-cols-2 gap-6">
                <div className="space-y-3">
                  <h4 className="text-sm font-medium text-muted">{t("reports.by_product")}</h4>
                  <ReportTable
                    headers={[t("reports.product"), t("reports.qty_sold"), t("reports.revenue"), t("reports.cogs"), t("reports.profit"), t("reports.margin")]}
                    rows={ss.by_product.map((a) => [
                      a.name,
                      fmt(a.quantity),
                      formatCurrency(a.revenue, locale),
                      formatCurrency(a.cogs, locale),
                      formatCurrency(a.profit, locale),
                      `${fmt(a.margin)}%`,
                    ])}
                  />
                </div>
                <div className="space-y-3">
                  <h4 className="text-sm font-medium text-muted">{t("reports.by_category")}</h4>
                  <ReportTable
                    headers={[t("reports.category"), t("reports.qty_sold"), t("reports.revenue"), t("reports.cogs"), t("reports.profit"), t("reports.margin")]}
                    rows={ss.by_category.map((a) => [
                      a.category ?? t("reports.uncategorized"),
                      fmt(a.quantity),
                      formatCurrency(a.revenue, locale),
                      formatCurrency(a.cogs, locale),
                      formatCurrency(a.profit, locale),
                      `${fmt(a.margin)}%`,
                    ])}
                  />
                </div>
              </div>
            </div>
          )}

          {tab === "stock_valuation" && sv && (
            <div className="space-y-6">
              <div className="grid grid-cols-3 gap-4">
                <div className="glass rounded-xl p-5">
                  <div className="flex items-center gap-2 mb-2"><Package className="w-4 h-4 text-primary-light" /><span className="text-xs text-muted">{t("reports.product_count")}</span></div>
                  <p className="text-2xl font-bold">{sv.summary.product_count}</p>
                </div>
                <div className="glass rounded-xl p-5">
                  <div className="flex items-center gap-2 mb-2"><Boxes className="w-4 h-4 text-primary-light" /><span className="text-xs text-muted">{t("reports.total_units")}</span></div>
                  <p className="text-2xl font-bold">{fmt(sv.summary.total_units)}</p>
                </div>
                <div className="glass rounded-xl p-5">
                  <div className="flex items-center gap-2 mb-2"><DollarSign className="w-4 h-4 text-primary-light" /><span className="text-xs text-muted">{t("reports.inventory_value")}</span></div>
                  <p className="text-2xl font-bold text-emerald-400">{formatCurrency(sv.summary.total_value, locale)}</p>
                </div>
              </div>

              <div className="space-y-3">
                <h4 className="text-sm font-medium text-muted">{reportTitle}</h4>
                <ReportTable
                  headers={[t("reports.product"), t("reports.sku"), t("reports.unit"), t("reports.category"), t("reports.on_hand"), t("reports.unit_cost"), t("reports.value")]}
                  rows={sv.products.map((a) => [
                    a.name,
                    a.sku ?? "",
                    a.unit,
                    a.category ?? t("reports.uncategorized"),
                    fmt(a.quantity),
                    formatCurrency(a.cost_per_unit, locale),
                    formatCurrency(a.value, locale),
                  ])}
                />
              </div>
            </div>
          )}

          {tab === "supplier_aging" && sa && (
            <div className="space-y-6">
              <div className="grid grid-cols-3 gap-4">
                <div className="glass rounded-xl p-5">
                  <div className="flex items-center gap-2 mb-2"><HandCoins className="w-4 h-4 text-red-400" /><span className="text-xs text-muted">{t("reports.total_outstanding")}</span></div>
                  <p className="text-2xl font-bold text-red-400">{formatCurrency(sa.total_outstanding, locale)}</p>
                </div>
                <div className="glass rounded-xl p-5">
                  <div className="flex items-center gap-2 mb-2"><Users className="w-4 h-4 text-primary-light" /><span className="text-xs text-muted">{t("reports.supplier")}</span></div>
                  <p className="text-2xl font-bold">{sa.suppliers.length}</p>
                </div>
                <div className="glass rounded-xl p-5">
                  <div className="flex items-center gap-2 mb-2"><FileText className="w-4 h-4 text-primary-light" /><span className="text-xs text-muted">{t("reports.open_orders")}</span></div>
                  <p className="text-2xl font-bold">{sa.suppliers.reduce((n, s) => n + s.orders_count, 0)}</p>
                </div>
              </div>

              <div className="space-y-3">
                <h4 className="text-sm font-medium text-muted">{t("reports.supplier_aging")}</h4>
                <ReportTable
                  headers={[t("reports.supplier"), t("reports.phone"), t("reports.orders_count"), t("reports.current"), t("reports.days_30"), t("reports.days_60"), t("reports.days_90"), t("reports.balance")]}
                  rows={sa.suppliers.map((s) => [
                    s.name,
                    s.phone ?? "",
                    s.orders_count,
                    formatCurrency(s.buckets.current, locale),
                    formatCurrency(s.buckets.d30, locale),
                    formatCurrency(s.buckets.d60, locale),
                    formatCurrency(s.buckets.d90, locale),
                    formatCurrency(s.outstanding, locale),
                  ])}
                />
              </div>

              <div className="space-y-3">
                <h4 className="text-sm font-medium text-muted">{t("reports.open_orders")}</h4>
                <ReportTable
                  headers={[t("reports.supplier"), t("reports.order_number"), t("reports.order_date"), t("reports.due_date"), t("reports.status"), t("reports.total"), t("reports.paid"), t("reports.balance"), t("reports.days_overdue")]}
                  rows={sa.suppliers.flatMap((s) => s.orders.map((o) => [
                    s.name,
                    o.order_number,
                    formatDateRange(o.order_date),
                    o.due_date ? formatDateRange(o.due_date) : "",
                    o.status,
                    formatCurrency(o.total, locale),
                    formatCurrency(o.paid, locale),
                    formatCurrency(o.balance, locale),
                    o.days_past_due > 0 ? `${o.days_past_due}d` : "",
                  ]))}
                />
              </div>
            </div>
          )}
        </div>
      ) : null}

      {!pnl && !bs && !cf && !tb && !ss && !sv && !sa && !loading && (
        <div className="glass rounded-2xl p-12 text-center print:hidden">
          <BarChart2 className="w-12 h-12 text-muted mx-auto mb-4" />
          <h3 className="text-lg font-medium text-foreground mb-2">{t("reports.select_report")}</h3>
          <p className="text-sm text-muted">{t("reports.select_report_desc")}</p>
        </div>
      )}
    </motion.div>
  );
}
