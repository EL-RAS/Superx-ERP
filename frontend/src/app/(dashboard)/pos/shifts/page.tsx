"use client";

import { useEffect, useState, useCallback } from "react";
import { createPortal } from "react-dom";
import { AnimatePresence, motion } from "framer-motion";
import { useAuthStore } from "@/stores/auth-store";
import { Shifts, fetchCurrentShift, fetchLastClosedShift, closeShift, fetchZReport, fetchZReports } from "@/lib/api";
import type { Shift, ZReport } from "@/lib/types";
import { formatCurrency, CURRENCY_EN, CURRENCY_AR } from "@/lib/types";
import { useI18n } from "@/lib/i18n";
import { usePagination } from "@/lib/pagination";
import PageHeader from "@/components/ui/PageHeader";
import DataTable from "@/components/ui/DataTable";
import type { Column } from "@/components/ui/DataTable";
import {
  ArrowRightLeft,
  CheckCircle2,
  Clock,
  CreditCard,
  FileText,
  Printer,
  TrendingDown,
  TrendingUp,
  X,
} from "lucide-react";

function varianceClass(v: number): string {
  if (Math.abs(v) < 0.005) return "bg-emerald-500/10 text-emerald-400 border border-emerald-500/30";
  if (v < 0) return "bg-red-500/10 text-red-400 border border-red-500/30";
  return "bg-amber-500/10 text-amber-400 border border-amber-500/30";
}

function varianceBadge(v: number): string | null {
  if (Math.abs(v) < 0.005) return null;
  return v < 0 ? "shifts.variance_shortage" : "shifts.variance_surplus";
}

function isValidOpenShift(s: Shift | null): s is Shift {
  return (
    !!s &&
    typeof s === "object" &&
    s.status === "open" &&
    !!s.started_at &&
    !Number.isNaN(new Date(s.started_at).getTime())
  );
}

interface ZReportDialogProps {
  open: boolean;
  report: ZReport | null;
  onClose: () => void;
  onPrint: () => void;
}

function ZReportDialog({ open, report, onClose, onPrint }: ZReportDialogProps) {
  const { t, locale } = useI18n();
  const [mounted, setMounted] = useState(false);

  useEffect(() => {
    const raf = requestAnimationFrame(() => setMounted(true));
    return () => cancelAnimationFrame(raf);
  }, []);

  useEffect(() => {
    document.body.classList.toggle("print-z-report", open);
    return () => document.body.classList.remove("print-z-report");
  }, [open]);

  if (!mounted || !report) return null;

  const rows: { label: string; value: React.ReactNode; currency?: boolean }[] = [
    { label: t("shifts.shift_number"), value: report.shift?.shift_number ?? "—" },
    { label: t("shifts.cashier"), value: report.user?.name ?? "—" },
    { label: t("shifts.started_at"), value: report.started_at ? new Date(report.started_at).toLocaleString() : "—" },
    { label: t("shifts.ended_at"), value: report.ended_at ? new Date(report.ended_at).toLocaleString() : "—" },
    { label: t("shifts.opening_balance"), value: report.opening_balance, currency: true },
    { label: t("shifts.total_sales"), value: report.total_sales, currency: true },
    { label: t("shifts.total_refunds"), value: report.total_refunds, currency: true },
    { label: t("shifts.total_transactions"), value: Number(report.total_transactions) || 0 },
    { label: t("shifts.expected_cash"), value: report.expected_cash, currency: true },
    { label: t("shifts.actual_cash"), value: report.actual_cash, currency: true },
  ];

  return createPortal(
    <AnimatePresence>
      {open && (
        <>
          <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            onClick={onClose}
            className="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 zreport-overlay"
          />
          <div data-zreport-portal className="fixed inset-0 flex items-center justify-center z-50 p-4 zreport-container">
            <motion.div
              initial={{ opacity: 0, scale: 0.95 }}
              animate={{ opacity: 1, scale: 1 }}
              exit={{ opacity: 0, scale: 0.95 }}
              className="glass rounded-2xl w-full max-w-xl max-h-[90vh] flex flex-col overflow-hidden zreport-panel"
            >
              <div className="flex items-center justify-between px-5 py-4 border-b border-border print-hide">
                <div className="min-w-0">
                  <h3 className="text-base font-semibold text-foreground truncate">
                    {t("shifts.z_report")} · {report.report_number}
                  </h3>
                  <p className="text-xs text-muted truncate">
                    {report.shift?.shift_number} · {report.user?.name ?? "—"}
                  </p>
                </div>
                <div className="flex items-center gap-2">
                  <button
                    onClick={onPrint}
                    className="flex items-center gap-2 px-4 py-2 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors"
                  >
                    <Printer className="w-4 h-4" /> {t("common.print")}
                  </button>
                  <button
                    onClick={onClose}
                    aria-label={t("common.close")}
                    className="p-2 rounded-xl text-muted hover:text-foreground hover:bg-card-hover transition-colors"
                  >
                    <X className="w-5 h-5" />
                  </button>
                </div>
              </div>

              <div id="z-report-print" className="overflow-y-auto p-5">
                <div className="bg-white text-black rounded-xl p-6 shadow-sm" style={{ color: "#000" }}>
                  <div className="text-center border-b border-gray-200 pb-4 mb-4">
                    <h2 className="text-xl font-bold">{t("shifts.z_report")}</h2>
                    <p className="text-sm text-gray-500 mt-1">{report.report_number}</p>
                  </div>
                  <div className="space-y-2.5">
                    {rows.map((row) => (
                      <div key={row.label} className="flex justify-between text-sm">
                        <span className="text-gray-600">{row.label}</span>
                        <span className="font-semibold">
                          {row.currency ? formatCurrency(Number(row.value) || 0, locale) : row.value}
                        </span>
                      </div>
                    ))}
                    <div className="flex justify-between items-center text-sm pt-1">
                      <span className="text-gray-600">{t("shifts.variance")}</span>
                      <span className="flex items-center gap-2">
                        <span className="font-semibold">{formatCurrency(Number(report.variance) || 0, locale)}</span>
                        {varianceBadge(Number(report.variance) || 0) && (
                          <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${varianceClass(Number(report.variance) || 0)}`}>
                            {t(varianceBadge(Number(report.variance) || 0)!)}
                          </span>
                        )}
                      </span>
                    </div>
                  </div>

                  {report.payment_breakdown && Object.keys(report.payment_breakdown).length > 0 && (
                    <div className="mt-4 pt-4 border-t border-gray-200">
                      <h4 className="text-sm font-semibold mb-2">{t("shifts.payment_breakdown")}</h4>
                      {Object.entries(report.payment_breakdown as Record<string, number>).map(([method, amount]) => (
                        <div key={method} className="flex justify-between text-sm">
                          <span className="text-gray-600 capitalize">{method.replace(/_/g, " ")}</span>
                          <span className="font-semibold">{formatCurrency(amount, locale)}</span>
                        </div>
                      ))}
                    </div>
                  )}

                  {report.top_products && report.top_products.length > 0 && (
                    <div className="mt-4 pt-4 border-t border-gray-200">
                      <h4 className="text-sm font-semibold mb-2">{t("shifts.top_products")}</h4>
                      {report.top_products.map((p) => (
                        <div key={`${p.product_id}-${p.name}`} className="flex justify-between items-center text-sm">
                          <span className="text-gray-700">{p.name}</span>
                          <span className="text-gray-500">{p.quantity} · {formatCurrency(p.total, locale)}</span>
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              </div>
            </motion.div>
          </div>
        </>
      )}
    </AnimatePresence>,
    document.body
  );
}

export default function ShiftManagementPage() {
  const { t, locale } = useI18n();
  const { token, business, user, config } = useAuthStore();
  const [currentShift, setCurrentShift] = useState<Shift | null>(null);
  const [reports, setReports] = useState<ZReport[]>([]);
  const [loading, setLoading] = useState(true);
  const [openingCash, setOpeningCash] = useState("");
  const [prevClosedActual, setPrevClosedActual] = useState(0);
  const [actualCash, setActualCash] = useState("");
  const [cardTotal, setCardTotal] = useState("");
  const [creating, setCreating] = useState(false);
  const [closing, setClosing] = useState(false);
  const [viewing, setViewing] = useState<ZReport | null>(null);
  const [toast, setToast] = useState<{ msg: string; type: "success" | "error" } | null>(null);
  const [total, setTotal] = useState(0);
  const { page, perPage, setPage, changePageSize } = usePagination();

  const current = isValidOpenShift(currentShift) ? currentShift : null;

  const showToast = (msg: string, type: "success" | "error" = "success") => {
    setToast({ msg, type });
    setTimeout(() => setToast(null), 3000);
  };

  const loadShift = useCallback(() => {
    if (!token || !business) return;
    fetchCurrentShift(token, business.id)
      .then((s) => setCurrentShift(isValidOpenShift(s) ? s : null))
      .catch(() => setCurrentShift(null));
  }, [token, business]);

  const loadReports = useCallback(() => {
    if (!token || !business) return;
    setLoading(true);
    fetchZReports(token, business.id, { page, per_page: perPage })
      .then((res) => {
        setReports(res.data);
        setTotal(res.total);
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [token, business, page, perPage]);

  useEffect(() => {
    const timer = setTimeout(loadShift, 0);
    return () => clearTimeout(timer);
  }, [loadShift]);

  useEffect(() => {
    const timer = setTimeout(loadReports, 300);
    return () => clearTimeout(timer);
  }, [loadReports]);

  useEffect(() => {
    if (!token || !business) return;
    fetchLastClosedShift(token, business.id)
      .then((last) => {
        const base = parseFloat(String(last?.actual_cash ?? "0")) || 0;
        setPrevClosedActual(base);
        if (base > 0) setOpeningCash(base.toFixed(2));
      })
      .catch(() => {});
  }, [token, business]);

  const handleStartShift = async () => {
    if (!token || !business || !openingCash) return;
    setCreating(true);
    try {
      const amount = Number(openingCash);
      const carried = Math.min(prevClosedActual, amount);
      const shift = await Shifts.create(token, business.id, {
        opening_balance: amount,
        carried_balance: carried,
        user_id: user?.id,
      });
      setCurrentShift(isValidOpenShift(shift) ? shift : null);
      setOpeningCash("");
      showToast(t("shifts.started"));
      loadShift();
    } catch {
      showToast(t("shifts.start_failed"), "error");
    } finally {
      setCreating(false);
    }
  };

  const handleCloseShift = async () => {
    if (!token || !business || !current || !actualCash) return;
    setClosing(true);
    try {
      const breakdown: Record<string, number> = {};
      if (actualCash) breakdown.cash = Number(actualCash);
      if (cardTotal) breakdown.card = Number(cardTotal);
      const payload: { actual_cash: number; payment_breakdown?: Record<string, number> } = { actual_cash: Number(actualCash) };
      if (Object.values(breakdown).some((v) => v > 0)) {
        payload.payment_breakdown = breakdown;
      }
      const closed = await closeShift(token, business.id, current.id, payload);
      setCurrentShift(null);
      const res = await fetchZReport(token, business.id, closed.id);
      setViewing(res.z_report as unknown as ZReport);
      setActualCash("");
      setCardTotal("");
      showToast(t("shifts.closed"));
      loadReports();
    } catch {
      showToast(t("shifts.close_failed"), "error");
    } finally {
      setClosing(false);
    }
  };

  const totals = reports.reduce(
    (acc, r) => ({
      sales: acc.sales + (Number(r.total_sales) || 0),
      refunds: acc.refunds + (Number(r.total_refunds) || 0),
      transactions: acc.transactions + (Number(r.total_transactions) || 0),
      variance: acc.variance + (Number(r.variance) || 0),
    }),
    { sales: 0, refunds: 0, transactions: 0, variance: 0 }
  );

  const columns: Column[] = [
    { key: "shift_number", label: t("shifts.shift_number") },
    {
      key: "cashier",
      label: t("shifts.cashier"),
      render: (_v, row) => (row as unknown as ZReport)?.user?.name ?? "—",
    },
    { key: "started_at", label: t("shifts.started_at"), type: "date" },
    { key: "ended_at", label: t("shifts.ended_at"), type: "date" },
    { key: "opening_balance", label: t("shifts.opening_balance"), type: "currency" },
    { key: "expected_cash", label: t("shifts.expected_cash"), type: "currency" },
    { key: "actual_cash", label: t("shifts.actual_cash"), type: "currency" },
    {
      key: "variance",
      label: t("shifts.variance"),
      render: (v) => {
        const num = Number(v) || 0;
        const abs = Math.abs(num);
        return (
          <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium ${varianceClass(num)}`}>
            {abs < 0.005 ? <CheckCircle2 className="w-3 h-3" /> : num < 0 ? <TrendingDown className="w-3 h-3" /> : <TrendingUp className="w-3 h-3" />}
            {formatCurrency(num, locale)}
          </span>
        );
      },
    },
    {
      key: "actions",
      label: "",
      render: (_v, row) => {
        const r = row as unknown as ZReport;
        return (
          <button
            onClick={(e) => {
              e.stopPropagation();
              const full = reports.find((x) => x.id === r.id) ?? r;
              setViewing(full);
            }}
            className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium text-muted hover:text-foreground hover:bg-card-hover border border-border transition-colors"
          >
            <Printer className="w-3.5 h-3.5" /> {t("shifts.z_report")}
          </button>
        );
      },
    },
  ];

  const openDetail = (row: Record<string, unknown>) => {
    const r = row as unknown as ZReport;
    const full = reports.find((x) => x.id === r.id) ?? r;
    setViewing(full);
  };

  const inputClass =
    "w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors";

  const currencySymbol = locale === "ar" ? CURRENCY_AR : (config?.currency || CURRENCY_EN);

  return (
    <motion.div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.4 }}>
      {toast && (
        <div className={`fixed top-4 end-4 z-[60] px-4 py-2.5 rounded-xl text-sm font-medium shadow-lg transition-all ${
          toast.type === "success" ? "bg-emerald-500/20 text-emerald-400 border border-emerald-500/30" : "bg-red-500/20 text-red-400 border border-red-500/30"
        }`}>{toast.msg}</div>
      )}

      <PageHeader title={t("shifts.title")} subtitle={t("shifts.history_subtitle")} />

      {/* ─── Active Shift Action Card ─────────────────────────────── */}
      <div className="glass rounded-2xl p-6 mb-6">
        <div className="flex items-center gap-3 mb-4">
          <div className="p-2.5 rounded-xl bg-violet-500/10">
            <ArrowRightLeft className="w-5 h-5 text-violet-400" />
          </div>
          <div>
            <h3 className="text-base font-semibold text-foreground">
              {current ? t("shifts.current") : t("shifts.no_shift")}
            </h3>
            <p className="text-xs text-muted">{current ? current.shift_number : t("shift.no_open_desc")}</p>
            {current && (
              <p className="text-xs text-muted mt-0.5">
                {t("shifts.cashier")}:{" "}
                <span className="font-medium text-foreground">{current.user?.name ?? user?.name ?? "—"}</span>
              </p>
            )}
          </div>
          {current && (
            <span className="ms-auto inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">
              {t("shift.open")}
            </span>
          )}
        </div>

        {current ? (
          <>
            <div className="grid grid-cols-2 sm:grid-cols-5 gap-4 mb-6">
              {[
                {
                  label: t("shifts.started_at"),
                  value: current.started_at ? new Date(current.started_at).toLocaleString() : "—",
                },
                { label: t("shifts.opening_balance"), value: formatCurrency(current.opening_balance ?? 0, locale) },
                { label: t("shifts.total_sales"), value: formatCurrency(current.total_sales ?? 0, locale) },
                { label: t("shifts.total_transactions"), value: String(current.total_transactions ?? 0) },
                { label: t("shifts.expected_cash"), value: formatCurrency(current.expected_cash ?? 0, locale) },
              ].map((f) => (
                <div key={f.label} className="rounded-xl bg-card/50 p-3">
                  <p className="text-xs text-muted">{f.label}</p>
                  <p className="text-sm font-semibold text-foreground mt-1">{f.value}</p>
                </div>
              ))}
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 items-end">
              <div>
                <label className="text-xs text-muted mb-1 block">{t("shifts.actual_cash_drawer")}</label>
                <div className="relative">
                  <span className="absolute start-3 top-1/2 -translate-y-1/2 text-muted text-xs font-semibold">{currencySymbol}</span>
                  <input type="number" placeholder="0.00" value={actualCash}
                    onChange={(e) => setActualCash(e.target.value)}
                    className={`${inputClass} ps-11`} />
                </div>
              </div>
              <div>
                <label className="text-xs text-muted mb-1 block">{t("shifts.actual_card_terminal")}</label>
                <div className="relative">
                  <CreditCard className="w-4 h-4 absolute start-3 top-1/2 -translate-y-1/2 text-muted" />
                  <input type="number" placeholder="0.00" value={cardTotal}
                    onChange={(e) => setCardTotal(e.target.value)}
                    className={`${inputClass} ps-9`} />
                </div>
              </div>
              <button onClick={handleCloseShift} disabled={closing || !actualCash}
                className="w-full sm:w-auto px-6 py-3 bg-red-500/20 hover:bg-red-500/30 text-red-400 border border-red-500/30 rounded-xl text-sm font-medium transition-colors disabled:opacity-50">
                {closing ? t("common.processing") : t("shifts.close_shift")}
              </button>
            </div>
          </>
        ) : (
          <div className="max-w-md">
            <div className="space-y-3">
              {prevClosedActual > 0 && (
                <p className="text-xs text-emerald-400 bg-emerald-500/10 border border-emerald-500/30 rounded-xl px-3 py-2">
                  {t("shift.handover")}: {formatCurrency(prevClosedActual, locale)}
                </p>
              )}
              <div className="relative">
                <span className="absolute start-3 top-1/2 -translate-y-1/2 text-muted text-xs font-semibold">{currencySymbol}</span>
                <input type="number" placeholder={t("shifts.opening_balance")} value={openingCash}
                  onChange={(e) => setOpeningCash(e.target.value)}
                  className={`${inputClass} ps-11`} />
              </div>
              <button onClick={handleStartShift} disabled={creating || !openingCash}
                className="w-full py-3 bg-emerald-500 hover:bg-emerald-600 text-foreground rounded-xl text-sm font-medium transition-colors disabled:opacity-50">
                {creating ? t("common.processing") : t("shifts.start_shift")}
              </button>
            </div>
          </div>
        )}
      </div>

      {/* ─── KPI summary ──────────────────────────────────────────── */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div className="glass rounded-2xl p-4 flex items-center gap-3">
          <div className="p-2.5 rounded-xl bg-violet-500/10"><Clock className="w-5 h-5 text-violet-400" /></div>
          <div>
            <p className="text-xs text-muted">{t("shifts.total_shifts")}</p>
            <p className="text-lg font-semibold text-foreground">{reports.length}</p>
          </div>
        </div>
        <div className="glass rounded-2xl p-4 flex items-center gap-3">
          <div className="p-2.5 rounded-xl bg-emerald-500/10"><TrendingUp className="w-5 h-5 text-emerald-400" /></div>
          <div>
            <p className="text-xs text-muted">{t("shifts.total_sales")}</p>
            <p className="text-lg font-semibold text-foreground">{formatCurrency(totals.sales, locale)}</p>
          </div>
        </div>
        <div className="glass rounded-2xl p-4 flex items-center gap-3">
          <div className="p-2.5 rounded-xl bg-blue-500/10"><TrendingDown className="w-5 h-5 text-blue-400" /></div>
          <div>
            <p className="text-xs text-muted">{t("shifts.total_transactions")}</p>
            <p className="text-lg font-semibold text-foreground">{totals.transactions}</p>
          </div>
        </div>
        <div className="glass rounded-2xl p-4 flex items-center gap-3">
          <div className="p-2.5 rounded-xl bg-amber-500/10"><FileText className="w-5 h-5 text-amber-400" /></div>
          <div>
            <p className="text-xs text-muted">{t("shifts.total_refunds")}</p>
            <p className="text-lg font-semibold text-foreground">{formatCurrency(totals.refunds, locale)}</p>
          </div>
        </div>
      </div>

      {/* ─── Shift History ────────────────────────────────────────── */}
      <DataTable columns={columns} data={reports as unknown as Record<string, unknown>[]} loading={loading} emptyMessage={t("shifts.empty")} emptyIcon={FileText} onRowClick={openDetail} pagination={{ total, page, perPage, onPageChange: setPage, onPerPageChange: changePageSize }} />

      <ZReportDialog open={!!viewing} report={viewing} onClose={() => setViewing(null)} onPrint={() => window.print()} />
    </motion.div>
  );
}
