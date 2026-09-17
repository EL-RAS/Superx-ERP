"use client";

import { useEffect, useState, useCallback } from "react";
import { motion } from "framer-motion";
import { useAuthStore } from "@/stores/auth-store";
import { ReturnExchanges, fetchInvoiceReturnable, Invoices, Products, ApiError } from "@/lib/api";
import type { ReturnExchange, ReturnablePreview, Product, Invoice } from "@/lib/types";
import { formatCurrency } from "@/lib/types";
import { mapFieldErrors } from "@/lib/validation";
import { useI18n } from "@/lib/i18n";
import { quantityStep } from "@/lib/product";
import { usePagination } from "@/lib/pagination";
import { round2 } from "@/lib/math";
import PageHeader from "@/components/ui/PageHeader";
import DataTable from "@/components/ui/DataTable";
import type { Column } from "@/components/ui/DataTable";
import SlideOver from "@/components/ui/SlideOver";
import { Repeat, Plus, Loader2, Search, X, AlertCircle } from "lucide-react";

const typeTabs = ["all", "return", "exchange"] as const;

interface ReturnLine {
  invoice_item_id: string;
  name: string;
  sku: string | null;
  unit_price: number;
  tax_rate: number;
  returnable: number;
  quantity: string;
  reason: string;
}

interface ExchangeLine {
  product_id: string;
  name: string;
  quantity: string;
  unit_price: string;
  tax_rate: string;
  is_weighable?: boolean;
  unit?: string | null;
}

const emptyExchangeLine: ExchangeLine = { product_id: "", name: "", quantity: "1", unit_price: "0", tax_rate: "0", is_weighable: false, unit: null };

export default function ReturnsExchangesPage() {
  const { t, locale } = useI18n();
  const { token, business } = useAuthStore();

  const [data, setData] = useState<ReturnExchange[]>([]);
  const [loading, setLoading] = useState(true);
  const [typeFilter, setTypeFilter] = useState<string>("all");
  const [search, setSearch] = useState("");
  const [debouncedSearch, setDebouncedSearch] = useState("");

  const [selected, setSelected] = useState<ReturnExchange | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);

  const [formOpen, setFormOpen] = useState(false);
  const [formType, setFormType] = useState<"return" | "exchange">("return");
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [toast, setToast] = useState<{ msg: string; type: "success" | "error" } | null>(null);
  const [total, setTotal] = useState(0);
  const { page, perPage, setPage, resetPage, changePageSize } = usePagination();

  const [invoiceSearch, setInvoiceSearch] = useState("");
  const [invoiceFocused, setInvoiceFocused] = useState(false);
  const [invoiceResults, setInvoiceResults] = useState<Invoice[]>([]);
  const [invoiceLoading, setInvoiceLoading] = useState(false);
  const [preview, setPreview] = useState<ReturnablePreview | null>(null);
  const [previewLoading, setPreviewLoading] = useState(false);

  const [returnLines, setReturnLines] = useState<ReturnLine[]>([]);
  const [exchangeLines, setExchangeLines] = useState<ExchangeLine[]>([{ ...emptyExchangeLine }]);
  const [refundMethod, setRefundMethod] = useState<string>("credit");
  const [differenceMethod, setDifferenceMethod] = useState<string>("cash");
  const [notes, setNotes] = useState("");

  const [products, setProducts] = useState<Product[]>([]);
  const [productSearch, setProductSearch] = useState("");
  const [productFocused, setProductFocused] = useState<number | null>(null);

  const showToast = useCallback((msg: string, type: "success" | "error") => {
    setToast({ msg, type });
    window.setTimeout(() => setToast(null), 3000);
  }, []);

  const columns: Column[] = [
    { key: "return_number", label: t("returns_exchanges.return_number") },
    {
      key: "type",
      label: t("returns_exchanges.type"),
      render: (v) => {
        const val = String(v);
        const isReturn = val === "return";
        return (
          <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border ${isReturn ? "bg-amber-500/15 text-amber-400 border-amber-500/30" : "bg-blue-500/15 text-blue-400 border-blue-500/30"}`}>
            {t(`returns_exchanges.${val}`)}
          </span>
        );
      },
    },
    {
      key: "invoice",
      label: t("returns_exchanges.invoice"),
      render: (_v, row) => ((row as unknown as ReturnExchange).invoice?.invoice_number ?? "—") as React.ReactNode,
    },
    {
      key: "customer",
      label: t("returns_exchanges.customer"),
      render: (_v, row) => ((row as unknown as ReturnExchange).invoice?.customer?.name ?? "—") as React.ReactNode,
    },
    { key: "returned_amount", label: t("returns_exchanges.returned"), type: "currency" },
    {
      key: "refund_amount",
      label: t("returns_exchanges.refund"),
      type: "currency",
      render: (v, row) => {
        const r = row as unknown as ReturnExchange;
        if (r.type === "return") return formatCurrency(r.refund_amount, locale);
        const diff = r.difference_amount;
        return diff < 0 ? formatCurrency(Math.abs(diff), locale) : (t("returns_exchanges.no_refund") as React.ReactNode);
      },
    },
    { key: "created_at", label: t("returns_exchanges.date"), type: "date" },
  ];

  const fetchData = useCallback(() => {
    if (!token || !business) return;
    setLoading(true);
    const params: Record<string, string | number> = { page, per_page: perPage };
    if (typeFilter !== "all") params.type = typeFilter;
    if (debouncedSearch.trim()) params.search = debouncedSearch.trim();
    ReturnExchanges.list(token, business.id, params)
      .then((res) => {
        setData(res.data);
        setTotal(res.total);
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [token, business, typeFilter, debouncedSearch, page, perPage]);

  useEffect(() => {
    const timer = setTimeout(fetchData, 300);
    return () => clearTimeout(timer);
  }, [fetchData]);

  useEffect(() => {
    const timer = setTimeout(() => setDebouncedSearch(search), 300);
    return () => clearTimeout(timer);
  }, [search]);

  const openDetail = async (row: Record<string, unknown>) => {
    if (!token || !business) return;
    const re = row as unknown as ReturnExchange;
    setDetailLoading(true);
    setSelected(re);
    try {
      const full = await ReturnExchanges.get(token, business.id, re.id);
      setSelected(full);
    } catch {} finally {
      setDetailLoading(false);
    }
  };

  const openCreate = () => {
    setFormType("return");
    setRefundMethod("credit");
    setDifferenceMethod("cash");
    setNotes("");
    setInvoiceSearch("");
    setInvoiceResults([]);
    setPreview(null);
    setReturnLines([]);
    setExchangeLines([{ ...emptyExchangeLine }]);
    setProductSearch("");
    setErrors({});
    setFormOpen(true);
    if (token && business) {
      Products.list(token, business.id, { per_page: 200 })
        .then((res) => setProducts(res.data))
        .catch(() => {});
    }
  };

  const fetchInvoiceResults = useCallback((q: string) => {
    if (!token || !business) return;
    if (!q.trim()) {
      setInvoiceResults([]);
      return;
    }
    setInvoiceLoading(true);
    Invoices.list(token, business.id, { search: q.trim(), per_page: 6 })
      .then((res) => setInvoiceResults(res.data))
      .catch(() => setInvoiceResults([]))
      .finally(() => setInvoiceLoading(false));
  }, [token, business]);

  useEffect(() => {
    const timer = setTimeout(() => fetchInvoiceResults(invoiceSearch), 300);
    return () => clearTimeout(timer);
  }, [invoiceSearch, fetchInvoiceResults]);

  const selectInvoice = async (inv: Invoice) => {
    if (!token || !business) return;
    setInvoiceSearch(inv.invoice_number);
    setInvoiceResults([]);
    setInvoiceFocused(false);
    setPreviewLoading(true);
    setErrors((p) => ({ ...p, invoice: "" }));
    try {
      const p = await fetchInvoiceReturnable(token, business.id, inv.id);
      setPreview(p);
      setReturnLines(
        p.items.map((it) => ({
          invoice_item_id: String(it.invoice_item_id),
          name: it.name,
          sku: it.sku,
          unit_price: it.unit_price,
          tax_rate: it.tax_rate,
          returnable: it.returnable,
          quantity: it.returnable > 0 ? String(it.returnable) : "0",
          reason: "",
        })),
      );
    } catch (e: unknown) {
      setPreview(null);
      setReturnLines([]);
      showToast(e instanceof Error ? e.message : t("common.error"), "error");
    } finally {
      setPreviewLoading(false);
    }
  };

  const updateReturnLine = (idx: number, field: string, value: string) => {
    setReturnLines((prev) => prev.map((l, i) => (i === idx ? { ...l, [field]: value } : l)));
  };

  const updateExchangeLine = (idx: number, field: string, value: string) => {
    setExchangeLines((prev) => prev.map((l, i) => (i === idx ? { ...l, [field]: value } : l)));
  };

  const selectProduct = (idx: number, p: Product) => {
    updateExchangeLine(idx, "product_id", String(p.id));
    updateExchangeLine(idx, "name", p.name);
    updateExchangeLine(idx, "unit_price", String(p.price ?? 0));
    updateExchangeLine(idx, "tax_rate", String(p.tax_rate ?? 0));
    setExchangeLines((prev) => prev.map((l, i) => (i === idx ? { ...l, is_weighable: !!p.is_weighable, unit: p.unit ?? null } : l)));
    setProductFocused(null);
    setProductSearch("");
  };

  const addExchangeLine = () => {
    setExchangeLines((prev) => [...prev, { ...emptyExchangeLine }]);
    setProductFocused(null);
  };

  const removeExchangeLine = (idx: number) => setExchangeLines((prev) => prev.filter((_, i) => i !== idx));

  const returnedValue = round2(
    returnLines.reduce((s, l) => {
      const qty = parseFloat(l.quantity) || 0;
      return s + qty * l.unit_price * (1 + l.tax_rate / 100);
    }, 0),
  );

  const exchangedValue = round2(
    exchangeLines.reduce((s, l) => {
      const qty = parseFloat(l.quantity) || 0;
      const price = parseFloat(l.unit_price) || 0;
      const tax = parseFloat(l.tax_rate) || 0;
      return s + qty * price * (1 + tax / 100);
    }, 0),
  );

  const difference = round2(exchangedValue - returnedValue);

  const handleSave = async () => {
    if (!token || !business) return;
    const fieldErrors: Record<string, string> = {};
    if (!preview) fieldErrors.invoice = t("returns_exchanges.invoice_required");
    const validLines = returnLines.filter((l) => parseFloat(l.quantity) > 0);
    if (validLines.length === 0) fieldErrors.items = t("returns_exchanges.empty_return_items");
    for (const l of validLines) {
      if ((parseFloat(l.quantity) || 0) > l.returnable + 0.001) {
        fieldErrors.items = t("returns_exchanges.qty_exceeds");
        break;
      }
    }
    if (formType === "exchange") {
      const validEx = exchangeLines.filter((l) => l.product_id && parseFloat(l.quantity) > 0);
      if (validEx.length === 0) fieldErrors.exchange_items = t("returns_exchanges.empty_exchange_items");
    }
    if (Object.keys(fieldErrors).length > 0) {
      setErrors(fieldErrors);
      return;
    }
    setSaving(true);
    const payload: Record<string, unknown> = {
      type: formType,
      invoice_id: preview!.invoice.id,
      notes: notes.trim() || null,
      items: validLines.map((l) => ({
        invoice_item_id: parseInt(l.invoice_item_id),
        quantity: parseFloat(l.quantity),
        reason: l.reason.trim() || null,
      })),
    };
    if (formType === "return") {
      payload.refund_method = refundMethod;
    } else {
      payload.exchange_items = exchangeLines
        .filter((l) => l.product_id && parseFloat(l.quantity) > 0)
        .map((l) => ({
          product_id: parseInt(l.product_id),
          quantity: parseFloat(l.quantity),
          unit_price: parseFloat(l.unit_price) || 0,
          tax_rate: parseFloat(l.tax_rate) || 0,
        }));
      if (difference > 0.009) payload.exchange_difference_method = differenceMethod;
    }
    try {
      await ReturnExchanges.create(token, business.id, payload);
      showToast(t("returns_exchanges.created"), "success");
      setFormOpen(false);
      setSelected(null);
      setErrors({});
      fetchData();
    } catch (err: unknown) {
      const apiErr = err instanceof ApiError ? err : null;
      setErrors(apiErr ? mapFieldErrors(apiErr, ["type", "invoice_id", "refund_method", "items", "exchange_items", "notes"]) : {});
      showToast(apiErr?.message || t("common.error"), "error");
    } finally {
      setSaving(false);
    }
  };

  const refundAmount = formType === "return" ? returnedValue : difference < 0 ? Math.abs(difference) : 0;

  return (
    <motion.div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.4 }}>
      {toast && (
        <div className={`fixed top-4 end-4 z-[60] px-4 py-2.5 rounded-xl text-sm font-medium shadow-lg ${toast.type === "success" ? "bg-emerald-500/10 text-emerald-400 border border-emerald-500/30" : "bg-red-500/10 text-red-400 border border-red-500/30"}`}>
          {toast.msg}
        </div>
      )}

      <PageHeader
        title={t("returns_exchanges.title")}
        subtitle={t("returns_exchanges.subtitle", { count: String(data.length) })}
        action={
          <button onClick={openCreate} className="flex items-center gap-2 px-4 py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors">
            <Plus className="w-4 h-4" /> {t("returns_exchanges.new")}
          </button>
        }
      />

      <div className="flex flex-wrap gap-2 mb-4 items-center">
        <div className="flex gap-2">
          {typeTabs.map((tab) => (
            <button key={tab} onClick={() => { setTypeFilter(tab); resetPage(); }}
              className={`px-4 py-2 rounded-xl text-sm font-medium transition-colors ${
                typeFilter === tab ? "bg-primary/20 text-primary-light border border-primary/30" : "text-muted hover:text-foreground hover:bg-card-hover"
              }`}>
              {t(`returns_exchanges.${tab}`)}
            </button>
          ))}
        </div>
        <div className="relative ms-auto min-w-[220px]">
          <Search className="w-4 h-4 absolute start-3 top-1/2 -translate-y-1/2 text-muted" />
          <input
            value={search}
            onChange={(e) => { setSearch(e.target.value); resetPage(); }}
            placeholder={t("returns_exchanges.search_placeholder")}
            className="w-full ps-9 pe-4 py-2 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-primary/50 transition-colors"
          />
        </div>
      </div>

      <DataTable columns={columns} data={data as unknown as Record<string, unknown>[]} loading={loading} emptyMessage={t("returns_exchanges.empty")} emptyIcon={Repeat} onRowClick={openDetail} pagination={{ total, page, perPage, onPageChange: setPage, onPerPageChange: changePageSize }} />

      {/* Detail SlideOver */}
      <SlideOver open={!!selected && !formOpen} onClose={() => setSelected(null)} title={t("returns_exchanges.detail_title")} width="max-w-xl">
        {selected && (
          <div className="space-y-6">
            <div className="glass rounded-xl p-4 space-y-3">
              <div className="flex justify-between"><span className="text-sm text-muted">{t("returns_exchanges.return_number")}</span><span className="text-sm text-foreground font-medium">{selected.return_number}</span></div>
              <div className="flex justify-between items-center">
                <span className="text-sm text-muted">{t("returns_exchanges.type")}</span>
                <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border ${selected.type === "return" ? "bg-amber-500/15 text-amber-400 border-amber-500/30" : "bg-blue-500/15 text-blue-400 border-blue-500/30"}`}>
                  {t(`returns_exchanges.${selected.type}`)}
                </span>
              </div>
              <div className="flex justify-between"><span className="text-sm text-muted">{t("returns_exchanges.invoice")}</span><span className="text-sm text-foreground">{selected.invoice?.invoice_number ?? "—"}</span></div>
              <div className="flex justify-between"><span className="text-sm text-muted">{t("returns_exchanges.customer")}</span><span className="text-sm text-foreground">{selected.invoice?.customer?.name ?? "—"}</span></div>
              <div className="flex justify-between"><span className="text-sm text-muted">{t("returns_exchanges.date")}</span><span className="text-sm text-foreground">{new Date(selected.created_at).toLocaleDateString("en-JO")}</span></div>
              {selected.user?.name && <div className="flex justify-between"><span className="text-sm text-muted">{t("returns_exchanges.processed_by")}</span><span className="text-sm text-foreground">{selected.user.name}</span></div>}
              {selected.exchange_invoice && (
                <div className="flex justify-between"><span className="text-sm text-muted">{t("returns_exchanges.exchange_invoice")}</span><span className="text-sm text-foreground font-medium">{selected.exchange_invoice.invoice_number}</span></div>
              )}
              {selected.notes && <div className="flex justify-between"><span className="text-sm text-muted">{t("returns_exchanges.notes")}</span><span className="text-sm text-foreground whitespace-pre-wrap text-end">{selected.notes}</span></div>}
            </div>

            <div>
              <h4 className="text-sm font-medium text-muted mb-3">{t("returns_exchanges.items_returned")}</h4>
              {selected.items && selected.items.length > 0 ? (
                <div className="space-y-2">
                  {selected.items.map((item) => (
                    <div key={item.id} className="flex justify-between items-center py-2 border-b border-border/50 last:border-0">
                      <div>
                        <p className="text-sm text-foreground">{item.product?.name ?? item.invoice_item?.name ?? `#${item.product_id}`}</p>
                        <p className="text-xs text-muted">{item.quantity} x {formatCurrency(item.unit_price, locale)}</p>
                        {item.reason && <p className="text-xs text-muted mt-0.5">{item.reason}</p>}
                      </div>
                      <p className="text-sm font-medium text-foreground">{formatCurrency(round2(item.quantity * item.unit_price * (1 + item.tax_rate / 100)), locale)}</p>
                    </div>
                  ))}
                </div>
              ) : (
                <p className="text-sm text-muted">{detailLoading ? t("common.loading") : t("returns_exchanges.no_items")}</p>
              )}
            </div>

            <div className="glass rounded-xl p-4 space-y-2">
              <div className="flex justify-between text-sm"><span className="text-muted">{t("returns_exchanges.total_returned")}</span><span className="text-foreground">{formatCurrency(selected.returned_amount, locale)}</span></div>
              {selected.type === "exchange" && (
                <>
                  <div className="flex justify-between text-sm"><span className="text-muted">{t("returns_exchanges.total_exchanged")}</span><span className="text-foreground">{formatCurrency(selected.exchanged_amount, locale)}</span></div>
                  <div className="flex justify-between text-sm">
                    <span className="text-muted">{t("returns_exchanges.difference")}</span>
                    <span className={selected.difference_amount < 0 ? "text-emerald-400" : "text-red-400"}>
                      {selected.difference_amount < 0
                        ? `${t("returns_exchanges.difference_refund")} ${formatCurrency(Math.abs(selected.difference_amount), locale)}`
                        : selected.difference_amount > 0
                          ? `${t("returns_exchanges.difference_due")} ${formatCurrency(selected.difference_amount, locale)}`
                          : formatCurrency(0, locale)}
                    </span>
                  </div>
                </>
              )}
              <div className="flex justify-between text-base font-semibold pt-2 border-t border-border/50">
                <span className="text-foreground">{t("returns_exchanges.refund")}</span>
                <span className="text-foreground">{formatCurrency(selected.refund_amount, locale)}</span>
              </div>
            </div>
          </div>
        )}
      </SlideOver>

      {/* Create SlideOver */}
      <SlideOver open={formOpen} onClose={() => setFormOpen(false)} title={t("returns_exchanges.new")} width="max-w-2xl">
        <div className="space-y-5">
          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">{t("returns_exchanges.type")}</label>
            <div className="flex gap-2">
              {(["return", "exchange"] as const).map((tp) => (
                <button key={tp} onClick={() => { setFormType(tp); setErrors({}); }}
                  className={`flex-1 px-4 py-2.5 rounded-xl text-sm font-medium border transition-colors ${
                    formType === tp ? "bg-primary/20 text-primary-light border-primary/30" : "bg-card/80 text-muted border-border hover:text-foreground"
                  }`}>
                  {t(`returns_exchanges.${tp}`)}
                </button>
              ))}
            </div>
          </div>

          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">{t("returns_exchanges.select_invoice")}</label>
            <div className="relative">
              <input
                value={invoiceSearch}
                onChange={(e) => { setInvoiceSearch(e.target.value); if (errors.invoice) setErrors((p) => ({ ...p, invoice: "" })); }}
                onFocus={() => setInvoiceFocused(true)}
                onBlur={() => setTimeout(() => setInvoiceFocused(false), 200)}
                placeholder={t("returns_exchanges.select_invoice_hint")}
                className={`w-full px-4 py-2.5 bg-card/80 border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-primary/50 transition-colors ${errors.invoice ? "border-red-500/50" : "border-border"}`}
              />
              {invoiceFocused && invoiceSearch && (
                <div className="absolute z-50 top-full start-0 end-0 mt-1 bg-card border border-border rounded-lg shadow-xl max-h-44 overflow-y-auto">
                  {invoiceLoading ? (
                    <div className="px-3 py-2 text-xs text-muted">{t("common.loading")}</div>
                  ) : invoiceResults.length > 0 ? (
                    invoiceResults.map((inv) => (
                      <button key={inv.id} type="button" onMouseDown={() => selectInvoice(inv)}
                        className="w-full text-start px-3 py-2 text-xs text-foreground hover:bg-primary/10 transition-colors flex items-center gap-2">
                        <Search className="w-3 h-3 text-muted shrink-0" />
                        <span className="truncate">{inv.invoice_number}</span>
                        <span className="ms-auto text-xs text-muted shrink-0">{inv.customer?.name ?? t("common.walk_in")}</span>
                      </button>
                    ))
                  ) : (
                    <div className="px-3 py-2 text-xs text-muted">{t("returns_exchanges.invoice_not_found")}</div>
                  )}
                </div>
              )}
            </div>
            {errors.invoice && (
              <p className="flex items-center gap-1 text-xs text-red-400 mt-1"><AlertCircle className="w-3 h-3" /> {errors.invoice}</p>
            )}
            {previewLoading && <p className="text-xs text-muted mt-2">{t("common.loading")}</p>}
            {preview && !previewLoading && (
              <p className="text-xs text-muted mt-2">
                {preview.invoice.invoice_number} — {preview.invoice.customer_name ?? t("common.walk_in")} — {formatCurrency(preview.invoice.net_amount, locale)}
              </p>
            )}
          </div>

          {preview && !previewLoading && (
            <div>
              <div className="flex items-center justify-between mb-3">
                <h4 className="text-sm font-medium text-muted">{t("returns_exchanges.items_returned")}</h4>
              </div>
              <div className="grid text-xs text-muted font-medium mb-1.5" style={{ gridTemplateColumns: "2.2fr 0.9fr 1fr 1fr 1.6fr", gap: "6px" }}>
                <span>{t("returns_exchanges.invoice")}</span>
                <span className="text-center">{t("returns_exchanges.returnable")}</span>
                <span className="text-center">{t("returns_exchanges.return_qty")}</span>
                <span className="text-end">{t("returns_exchanges.returned")}</span>
                <span>{t("returns_exchanges.reason")}</span>
              </div>
              <div className="space-y-1.5">
                {returnLines.map((line, idx) => (
                  <div key={line.invoice_item_id} className="glass rounded-lg px-2.5 py-2">
                    <div className="grid" style={{ gridTemplateColumns: "2.2fr 0.9fr 1fr 1fr 1.6fr", gap: "6px", alignItems: "center" }}>
                      <div className="min-w-0">
                        <p className="text-xs text-foreground truncate">{line.name}</p>
                        {line.sku && <p className="text-[11px] text-muted truncate">{line.sku}</p>}
                      </div>
                      <span className="text-xs text-muted text-center">{line.returnable}</span>
                      <input type="number" min="0" step="0.01" max={line.returnable} value={line.quantity}
                        onChange={(e) => updateReturnLine(idx, "quantity", e.target.value)}
                        className="w-full px-1.5 py-1.5 bg-card/80 border border-border rounded-lg text-xs text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover text-center" />
                      <span className="text-xs text-foreground font-medium text-end">{formatCurrency(round2((parseFloat(line.quantity) || 0) * line.unit_price * (1 + line.tax_rate / 100)), locale)}</span>
                      <input type="text" value={line.reason}
                        onChange={(e) => updateReturnLine(idx, "reason", e.target.value)}
                        placeholder={t("returns_exchanges.reason_placeholder")}
                        className="w-full px-2 py-1.5 bg-card/80 border border-border rounded-lg text-xs text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover" />
                    </div>
                  </div>
                ))}
              </div>
              {errors.items && (
                <p className="flex items-center gap-1 text-xs text-red-400 mt-2"><AlertCircle className="w-3 h-3" /> {errors.items}</p>
              )}
            </div>
          )}

          {formType === "return" && (
            <div>
              <label className="block text-sm font-medium text-muted mb-1.5">{t("returns_exchanges.refund_method")}</label>
              <select value={refundMethod} onChange={(e) => setRefundMethod(e.target.value)}
                className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-primary/50 transition-colors">
                <option value="credit">{t("returns_exchanges.refund_method_credit")}</option>
                <option value="cash">{t("returns_exchanges.refund_method_cash")}</option>
                <option value="bank">{t("returns_exchanges.refund_method_bank")}</option>
              </select>
            </div>
          )}

          {formType === "exchange" && (
            <div>
              <div className="flex items-center justify-between mb-3">
                <h4 className="text-sm font-medium text-muted">{t("returns_exchanges.exchange_items")}</h4>
                <button onClick={addExchangeLine} className="flex items-center gap-1 text-xs text-primary-light hover:text-primary transition-colors">
                  <Plus className="w-3.5 h-3.5" /> {t("returns_exchanges.add_exchange_item")}
                </button>
              </div>
              <div className="grid text-xs text-muted font-medium mb-1.5" style={{ gridTemplateColumns: "2.2fr 0.8fr 1fr 0.9fr 1fr 24px", gap: "6px" }}>
                <span>{t("common.products")}</span>
                <span className="text-center">{t("returns_exchanges.return_qty")}</span>
                <span className="text-end">{t("invoices.unit_price")}</span>
                <span className="text-end">{t("invoices.tax_rate")}</span>
                <span className="text-end">{t("invoices.total")}</span>
                <span></span>
              </div>
              <div className="space-y-1.5">
                {exchangeLines.map((line, idx) => (
                  <div key={idx} className="glass rounded-lg px-2.5 py-2">
                    <div className="grid" style={{ gridTemplateColumns: "2.2fr 0.8fr 1fr 0.9fr 1fr 24px", gap: "6px", alignItems: "start" }}>
                      <div className="relative min-w-0">
                        <input
                          placeholder={t("returns_exchanges.select_invoice_hint")}
                          value={productSearch || line.name}
                          onChange={(e) => {
                            setProductSearch(e.target.value);
                            if (!e.target.value) {
                              updateExchangeLine(idx, "product_id", "");
                              updateExchangeLine(idx, "name", "");
                              updateExchangeLine(idx, "unit_price", "0");
                              updateExchangeLine(idx, "tax_rate", "0");
                            }
                          }}
                          onFocus={() => setProductFocused(idx)}
                          onBlur={() => setTimeout(() => setProductFocused(null), 200)}
                          className="w-full px-2 py-1.5 bg-card/80 border border-border rounded-lg text-xs text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover"
                        />
                        {productFocused === idx && (productSearch || line.name) && (
                          <div className="absolute z-50 top-full start-0 end-0 mt-1 bg-card border border-border rounded-lg shadow-xl max-h-40 overflow-y-auto">
                            {products
                              .filter((p) => p.name.toLowerCase().includes((productSearch || line.name).toLowerCase()))
                              .slice(0, 6)
                              .map((p) => (
                                <button key={p.id} type="button"
                                  onMouseDown={() => selectProduct(idx, p)}
                                  className="w-full text-start px-2 py-1.5 text-xs text-foreground hover:bg-primary/10 transition-colors flex items-center gap-2">
                                  <Search className="w-3 h-3 text-muted shrink-0" />
                                  <span className="truncate">{p.name}</span>
                                  <span className="ms-auto text-xs text-muted shrink-0">{formatCurrency(p.price ?? 0, locale)}</span>
                                </button>
                              ))}
                          </div>
                        )}
                      </div>
                      <div className="min-w-0">
                        <input type="number" min="0.01" step={quantityStep(line.is_weighable !== undefined ? { is_weighable: line.is_weighable, unit: line.unit } : products.find((p) => String(p.id) === line.product_id))} value={line.quantity}
                          onChange={(e) => updateExchangeLine(idx, "quantity", e.target.value)}
                          className="w-full px-1.5 py-1.5 bg-card/80 border border-border rounded-lg text-xs text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover text-center" />
                      </div>
                      <div className="min-w-0">
                        <input type="number" min="0" step="0.001" value={line.unit_price}
                          onChange={(e) => updateExchangeLine(idx, "unit_price", e.target.value)}
                          className="w-full px-1.5 py-1.5 bg-card/80 border border-border rounded-lg text-xs text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover text-end" />
                      </div>
                      <div className="min-w-0">
                        <input type="number" min="0" step="0.01" value={line.tax_rate}
                          onChange={(e) => updateExchangeLine(idx, "tax_rate", e.target.value)}
                          className="w-full px-1.5 py-1.5 bg-card/80 border border-border rounded-lg text-xs text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover text-end" />
                      </div>
                      <div className="flex items-center justify-end min-w-0">
                        <span className="text-xs text-foreground font-medium">{formatCurrency(
                          round2((parseFloat(line.quantity) || 0) * (parseFloat(line.unit_price) || 0) * (1 + (parseFloat(line.tax_rate) || 0) / 100)),
                          "en"
                        )}</span>
                      </div>
                      <div className="flex items-center justify-center">
                        {exchangeLines.length > 1 && (
                          <button onClick={() => removeExchangeLine(idx)} className="p-0.5 text-muted hover:text-red-400 transition-colors"><X className="w-3.5 h-3.5" /></button>
                        )}
                      </div>
                    </div>
                  </div>
                ))}
              </div>
              {errors.exchange_items && (
                <p className="flex items-center gap-1 text-xs text-red-400 mt-2"><AlertCircle className="w-3 h-3" /> {errors.exchange_items}</p>
              )}

              {difference > 0.009 && (
                <div className="mt-4">
                  <label className="block text-sm font-medium text-muted mb-1.5">{t("returns_exchanges.difference_method")}</label>
                  <select value={differenceMethod} onChange={(e) => setDifferenceMethod(e.target.value)}
                    className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-primary/50 transition-colors">
                    <option value="cash">{t("invoices.cash")}</option>
                    <option value="card">{t("invoices.card")}</option>
                    <option value="bank_transfer">{t("invoices.bank_transfer")}</option>
                    <option value="check">{t("invoices.check")}</option>
                    <option value="mobile">{t("invoices.mobile")}</option>
                  </select>
                </div>
              )}
            </div>
          )}

          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">{t("returns_exchanges.notes")}</label>
            <textarea value={notes} onChange={(e) => setNotes(e.target.value)} rows={2}
              className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-primary/50 transition-colors resize-none" />
          </div>

          <div className="glass rounded-xl p-4 space-y-2">
            <div className="flex justify-between text-sm"><span className="text-muted">{t("returns_exchanges.total_returned")}</span><span className="text-foreground">{formatCurrency(returnedValue, locale)}</span></div>
            {formType === "exchange" && (
              <>
                <div className="flex justify-between text-sm"><span className="text-muted">{t("returns_exchanges.total_exchanged")}</span><span className="text-foreground">{formatCurrency(exchangedValue, locale)}</span></div>
                <div className="flex justify-between text-sm">
                  <span className="text-muted">{t("returns_exchanges.difference")}</span>
                  <span className={difference < 0 ? "text-emerald-400" : difference > 0 ? "text-red-400" : "text-foreground"}>
                    {difference < 0
                      ? `${t("returns_exchanges.difference_refund")} ${formatCurrency(Math.abs(difference), locale)}`
                      : difference > 0
                        ? `${t("returns_exchanges.difference_due")} ${formatCurrency(difference, locale)}`
                        : formatCurrency(0, locale)}
                  </span>
                </div>
              </>
            )}
            <div className="flex justify-between text-base font-semibold pt-2 border-t border-border/50">
              <span className="text-foreground">{t("returns_exchanges.refund")}</span>
              <span className="text-foreground">{formatCurrency(refundAmount, locale)}</span>
            </div>
          </div>

          <div className="flex gap-3">
            <button onClick={handleSave} disabled={saving}
              className="flex items-center gap-2 px-6 py-3 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors disabled:opacity-40">
              {saving ? <Loader2 className="w-4 h-4 animate-spin" /> : null}
              {saving ? t("common.saving") : formType === "return" ? t("returns_exchanges.submit_return") : t("returns_exchanges.submit_exchange")}
            </button>
            <button onClick={() => setFormOpen(false)} disabled={saving} className="px-6 py-3 text-sm text-muted hover:text-foreground transition-colors">
              {t("common.cancel")}
            </button>
          </div>
        </div>
      </SlideOver>
    </motion.div>
  );
}
