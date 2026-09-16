"use client";

import React, { useEffect, useState, useCallback } from "react";
import { motion } from "framer-motion";
import { useAuthStore } from "@/stores/auth-store";
import { PurchaseOrders, Suppliers, payPurchaseOrder, fetchSupplierCatalog } from "@/lib/api";
import type { PurchaseOrder, Supplier, SupplierProduct } from "@/lib/types";
import { formatCurrency } from "@/lib/types";
import { usePagination } from "@/lib/pagination";
import PageHeader from "@/components/ui/PageHeader";
import DataTable from "@/components/ui/DataTable";
import type { Column } from "@/components/ui/DataTable";
import SlideOver from "@/components/ui/SlideOver";
import StatusBadge from "@/components/ui/StatusBadge";
import ConfirmDialog from "@/components/ui/ConfirmDialog";
import { ShoppingCart, Plus, X, Loader2, ChevronDown, Printer, MessageCircle, Info } from "lucide-react";
import { useI18n } from "@/lib/i18n";
import { quantityStep } from "@/lib/product";

interface POFormItem {
  supplier_product_id: string;
  product_id: string;
  name: string;
  quantity: string;
  unit_cost: string;
  is_imported: boolean;
  is_weighable?: boolean;
  unit?: string | null;
}

interface POFormData {
  supplier_id: string;
  notes: string;
  expected_delivery: string;
  items: POFormItem[];
}

const emptyItem: POFormItem = {
  supplier_product_id: "", product_id: "", name: "", quantity: "1", unit_cost: "0", is_imported: false, is_weighable: false, unit: null,
};

export default function PurchaseOrdersPage() {
  const { t, locale } = useI18n();
  const { token, business } = useAuthStore();
  const [data, setData] = useState<PurchaseOrder[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const { page, perPage, setPage, changePageSize } = usePagination();
  const [selected, setSelected] = useState<PurchaseOrder | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [formOpen, setFormOpen] = useState(false);
  const [formLoading, setFormLoading] = useState(false);
  const [suppliers, setSuppliers] = useState<Supplier[]>([]);
  const [catalog, setCatalog] = useState<SupplierProduct[]>([]);
  const [catalogLoading, setCatalogLoading] = useState(false);
  const [itemDropdown, setItemDropdown] = useState<number | null>(null);
  const [itemSearch, setItemSearch] = useState("");
  const [importConfirmOpen, setImportConfirmOpen] = useState(false);
  const [importNames, setImportNames] = useState<string[]>([]);
  const [form, setForm] = useState<POFormData>({
    supplier_id: "", notes: "", expected_delivery: "", items: [{ ...emptyItem }],
  });
  const [toast, setToast] = useState<{ msg: string; type: "success" | "error" } | null>(null);
  const [payAmount, setPayAmount] = useState("");
  const [payMethod, setPayMethod] = useState("cash");
  const [paying, setPaying] = useState(false);
  const [statusUpdating, setStatusUpdating] = useState(false);

  const showToast = (msg: string, type: "success" | "error" = "success") => {
    setToast({ msg, type });
    setTimeout(() => setToast(null), 3000);
  };

  const columns: Column[] = [
    { key: "order_number", label: t("purchase_orders.order_num") },
    { key: "supplier", label: t("common.supplier"), render: (v) => ((v as Record<string, unknown>)?.name ?? "\u2014") as React.ReactNode },
    { key: "status", label: t("common.status"), render: (v) => <StatusBadge status={String(v)} /> },
    { key: "total_amount", label: t("common.total"), type: "currency" },
    { key: "created_at", label: t("common.date"), type: "date" },
  ];

  const fetchData = useCallback(() => {
    if (!token || !business) return;
    setLoading(true);
    PurchaseOrders.list(token, business.id, { page, per_page: perPage })
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

  const openDetail = async (row: Record<string, unknown>) => {
    if (!token || !business) return;
    const po = row as unknown as PurchaseOrder;
    setDetailLoading(true);
    setSelected(po);
    setPayAmount("");
    setPayMethod("cash");
    try { const full = await PurchaseOrders.get(token, business.id, po.id); setSelected(full); } catch {} finally { setDetailLoading(false); }
  };

  const paidAmount = selected
    ? Number(selected.paid_amount ?? (selected.payments ?? []).reduce((sum, p) => sum + Number(p.amount), 0))
    : 0;
  const remaining = selected
    ? Number(selected.remaining_amount ?? Math.max(0, Number(selected.total_amount) - paidAmount))
    : 0;

  const handlePay = async () => {
    if (!token || !business || !selected) return;
    const amount = parseFloat(payAmount);
    if (!amount || amount <= 0) return;
    setPaying(true);
    try {
      await payPurchaseOrder(token, business.id, selected.id, {
        amount,
        method: payMethod,
        notes: "Payment to supplier",
      });
      showToast(t("purchase_orders.payment_success"));
      const full = await PurchaseOrders.get(token, business.id, selected.id);
      setSelected(full);
      setPayAmount("");
    } catch (e: unknown) {
      showToast(e instanceof Error ? e.message : "Error", "error");
    } finally {
      setPaying(false);
    }
  };

  const handleStatusChange = async (newStatus: string) => {
    if (!token || !business || !selected) return;
    setStatusUpdating(true);
    try {
      await PurchaseOrders.update(token, business.id, selected.id, { status: newStatus });
      showToast(t("purchase_orders.status_updated"));
      const full = await PurchaseOrders.get(token, business.id, selected.id);
      setSelected(full);
      fetchData();
    } catch (e: unknown) {
      showToast(e instanceof Error ? e.message : "Error", "error");
    } finally {
      setStatusUpdating(false);
    }
  };

  const openCreateForm = () => {
    if (!token || !business) return;
    setForm({ supplier_id: "", notes: "", expected_delivery: "", items: [{ ...emptyItem }] });
    setCatalog([]);
    setItemDropdown(null);
    setItemSearch("");
    setImportConfirmOpen(false);
    setFormOpen(true);
    Suppliers.list(token, business.id, { per_page: 200 })
      .then((s) => setSuppliers(s.data))
      .catch(() => {});
  };

  const loadCatalog = (supplierId: string) => {
    if (!token || !business || !supplierId) return;
    setCatalogLoading(true);
    fetchSupplierCatalog(token, business.id, parseInt(supplierId, 10))
      .then(setCatalog)
      .catch(() => setCatalog([]))
      .finally(() => setCatalogLoading(false));
  };

  const handleSupplierChange = (supplierId: string) => {
    setForm((prev) => ({ ...prev, supplier_id: supplierId, items: [{ ...emptyItem }] }));
    setCatalog([]);
    setItemDropdown(null);
    setItemSearch("");
    loadCatalog(supplierId);
  };

  const selectCatalogItem = (idx: number, row: SupplierProduct) => {
    setForm((prev) => ({
      ...prev,
      items: prev.items.map((it, i) => (i === idx ? {
        ...it,
        supplier_product_id: String(row.id),
        product_id: row.product_id ? String(row.product_id) : "",
        name: row.name,
        unit_cost: row.catalog_cost != null && (it.unit_cost === "0" || it.unit_cost === "")
          ? String(Number(row.catalog_cost))
          : it.unit_cost,
        is_imported: !!row.product_id,
        is_weighable: row.product?.is_weighable ?? false,
        unit: row.product?.unit ?? null,
      } : it)),
    }));
    setItemDropdown(null);
    setItemSearch("");
  };

  const updateFormItem = (idx: number, field: string, value: string) => {
    setForm((prev) => ({
      ...prev,
      items: prev.items.map((it, i) => (i === idx ? { ...it, [field]: value } : it)),
    }));
  };

  const addItem = () => setForm((prev) => ({ ...prev, items: [...prev.items, { ...emptyItem }] }));
  const removeItem = (idx: number) => setForm((prev) => ({ ...prev, items: prev.items.filter((_, i) => i !== idx) }));

  const calcTotal = () => form.items.reduce((sum, it) => sum + (parseFloat(it.quantity) || 0) * (parseFloat(it.unit_cost) || 0), 0);

  const doCreate = async (items: POFormItem[]) => {
    if (!token || !business) return;
    setFormLoading(true);
    try {
      await PurchaseOrders.create(token, business.id, {
        supplier_id: form.supplier_id ? parseInt(form.supplier_id, 10) : null,
        notes: form.notes || null,
        expected_delivery: form.expected_delivery || null,
        items: items.map((it) => ({
          product_id: it.product_id ? parseInt(it.product_id, 10) : null,
          name: it.name,
          quantity: parseFloat(it.quantity),
          unit_cost: parseFloat(it.unit_cost),
          import_product: it.product_id ? undefined : true,
        })),
      });
      showToast(t("purchase_orders.created"));
      setFormOpen(false);
      setImportConfirmOpen(false);
      fetchData();
    } catch (e: unknown) {
      showToast(e instanceof Error ? e.message : "Error", "error");
    } finally {
      setFormLoading(false);
    }
  };

  const handleSave = () => {
    const validItems = form.items.filter((it) => it.name.trim() && parseFloat(it.quantity) > 0);
    if (validItems.length === 0) return;
    const newItems = validItems.filter((it) => !it.product_id);
    if (newItems.length > 0) {
      setImportNames(newItems.map((it) => it.name));
      setImportConfirmOpen(true);
      return;
    }
    doCreate(validItems);
  };

  const filteredCatalog = catalog.filter((c) => c.name.toLowerCase().includes(itemSearch.toLowerCase()));

  const poLines = (po: PurchaseOrder) =>
    (po.items ?? []).map((it) => ({
      name: it.name,
      qty: it.quantity,
      unit: Number(it.unit_cost),
      total: Number(it.total) || Number(it.quantity) * Number(it.unit_cost),
    }));

  const sharePO = (po: PurchaseOrder) => {
    const lines = poLines(po);
    const parts = [
      `${t("purchase_orders.order_num")}: ${po.order_number}`,
      `${t("common.supplier")}: ${po.supplier?.name ?? "—"}`,
      `${t("common.date")}: ${new Date(po.created_at).toLocaleDateString()}`,
      "",
      ...lines.map((l) => `- ${l.name} × ${l.qty} @ ${formatCurrency(l.unit, locale)} = ${formatCurrency(l.total, locale)}`),
      "",
      `${t("common.total")}: ${formatCurrency(Number(po.total_amount), locale)}`,
    ];
    window.open(`https://wa.me/?text=${encodeURIComponent(parts.join("\n"))}`, "_blank");
  };

  const printPO = (po: PurchaseOrder) => {
    const lines = poLines(po);
    const rows = lines.map((l) =>
      `<tr><td style="padding:8px;border:1px solid #ddd">${l.name}</td><td style="padding:8px;border:1px solid #ddd;text-align:center">${l.qty}</td><td style="padding:8px;border:1px solid #ddd;text-align:right">${formatCurrency(l.unit, "en")}</td><td style="padding:8px;border:1px solid #ddd;text-align:right">${formatCurrency(l.total, "en")}</td></tr>`
    ).join("");
    const html = `<!DOCTYPE html><html dir="${locale === "ar" ? "rtl" : "ltr"}"><head><meta charset="utf-8"><title>${po.order_number}</title>
    <style>body{font-family:Arial,sans-serif;padding:24px;color:#111}h1{font-size:20px;margin:0 0 4px}h2{font-size:16px;margin:0 0 12px;color:#444}.meta{display:flex;justify-content:space-between;gap:24px;margin:16px 0;padding:12px;background:#f5f5f5;border-radius:8px;font-size:13px}table{width:100%;border-collapse:collapse;margin-top:12px}th{background:#eee;text-align:right;padding:8px;border:1px solid #ddd;font-size:12px}.total{display:flex;justify-content:flex-end;margin-top:12px;font-weight:700}</style></head><body>
    <h1>${t("purchase_orders.title")}</h1>
    <h2>${po.order_number}</h2>
    <div class="meta"><div><strong>${t("common.supplier")}:</strong> ${po.supplier?.name ?? "—"}<br><strong>${t("common.date")}:</strong> ${new Date(po.created_at).toLocaleDateString()}<br><strong>${t("common.status")}:</strong> ${po.status}</div></div>
    <table><thead><tr><th>${t("common.product")}</th><th>${t("common.qty")}</th><th>${t("common.unit_price")}</th><th>${t("common.total")}</th></tr></thead><tbody>${rows}</tbody></table>
    <p class="total">${t("common.total")}: ${formatCurrency(Number(po.total_amount), "en")}</p>
    </body></html>`;
    const win = window.open("", "_blank");
    if (!win) { showToast(t("common.popup_blocked"), "error"); return; }
    win.document.write(html);
    win.document.close();
    win.focus();
    win.print();
  };

  return (
    <motion.div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.4 }}>
      {toast && (
        <div className={`fixed top-4 end-4 z-[60] px-4 py-2.5 rounded-xl text-sm font-medium shadow-lg transition-all ${
          toast.type === "success" ? "bg-emerald-500/20 text-emerald-400 border border-emerald-500/30" : "bg-red-500/20 text-red-400 border border-red-500/30"
        }`}>{toast.msg}</div>
      )}

      <PageHeader
        title={t("purchase_orders.title")}
        subtitle={t("purchase_orders.subtitle", { count: String(data.length) })}
        action={
          <button onClick={openCreateForm} className="flex items-center gap-2 px-4 py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors">
            <Plus className="w-4 h-4" /> {t("purchase_orders.create")}
          </button>
        }
      />

      <DataTable
        columns={columns}
        data={data as unknown as Record<string, unknown>[]}
        loading={loading}
        emptyMessage={t("purchase_orders.empty")}
        emptyIcon={ShoppingCart}
        onRowClick={openDetail}
        pagination={{ total, page, perPage, onPageChange: setPage, onPerPageChange: changePageSize }}
      />

      {/* Detail SlideOver */}
      <SlideOver open={!!selected} onClose={() => setSelected(null)} title={t("purchase_orders.detail")} width="max-w-xl">
        {selected && (
          <div className="space-y-6">
            <div className="glass rounded-xl p-4 space-y-3">
              <div className="flex justify-between"><span className="text-sm text-muted">{t("purchase_orders.order_num")}</span><span className="text-sm text-foreground font-medium">{selected.order_number}</span></div>
              <div className="flex justify-between"><span className="text-sm text-muted">{t("common.supplier")}</span><span className="text-sm text-foreground">{selected.supplier?.name ?? "\u2014"}</span></div>
              <div className="flex justify-between"><span className="text-sm text-muted">{t("common.date")}</span><span className="text-sm text-foreground">{new Date(selected.created_at).toLocaleDateString(locale === "ar" ? "ar-JO" : "en-JO")}</span></div>
              <div className="flex justify-between"><span className="text-sm text-muted">{t("common.status")}</span><StatusBadge status={selected.status} /></div>
              {selected.expected_delivery && <div className="flex justify-between"><span className="text-sm text-muted">{t("purchase_orders.expected")}</span><span className="text-sm text-foreground">{new Date(selected.expected_delivery).toLocaleDateString(locale === "ar" ? "ar-JO" : "en-JO")}</span></div>}
            </div>
            <div>
              <h4 className="text-sm font-medium text-muted mb-3">{t("purchase_orders.items")}</h4>
              {selected.items && selected.items.length > 0 ? (
                <div className="space-y-2">
                  {selected.items.map((item) => (
                    <div key={item.id} className="flex justify-between items-center py-2 border-b border-border/50 last:border-0">
                      <div>
                        <p className="text-sm text-foreground">{item.name}</p>
                        <p className="text-xs text-muted">{item.quantity} x {formatCurrency(item.unit_cost, locale)}</p>
                      </div>
                      <p className="text-sm font-medium text-foreground">{formatCurrency(item.total, locale)}</p>
                    </div>
                  ))}
                </div>
              ) : (
                <p className="text-sm text-muted">{detailLoading ? t("purchase_orders.loading") : t("purchase_orders.no_items")}</p>
              )}
            </div>
            <div className="glass rounded-xl p-4 space-y-2">
              <div className="flex justify-between text-base font-semibold pt-2 border-t border-border/50"><span className="text-foreground">{t("common.total")}</span><span className="text-foreground">{formatCurrency(selected.total_amount, locale)}</span></div>
              <div className="flex justify-between text-sm"><span className="text-muted">{t("purchase_orders.paid")}</span><span className="text-foreground">{formatCurrency(paidAmount, locale)}</span></div>
              <div className="flex justify-between text-sm"><span className="text-muted">{t("purchase_orders.remaining")}</span><span className="text-foreground font-medium">{formatCurrency(remaining, locale)}</span></div>
            </div>

            <div className="flex gap-3">
              <button onClick={() => printPO(selected)} className="flex-1 flex items-center justify-center gap-2 py-2.5 bg-card/80 hover:bg-card-hover text-foreground border border-border rounded-xl text-sm font-medium transition-colors">
                <Printer className="w-4 h-4" /> {t("purchase_orders.print_pdf")}
              </button>
              <button onClick={() => sharePO(selected)} className="flex-1 flex items-center justify-center gap-2 py-2.5 bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 rounded-xl text-sm font-medium transition-colors">
                <MessageCircle className="w-4 h-4" /> {t("purchase_orders.whatsapp")}
              </button>
            </div>

            {(selected.status === "draft" || selected.status === "ordered" || selected.status === "partially_received") && (
              <div className="flex gap-3">
                {selected.status === "draft" && (
                  <button
                    onClick={() => handleStatusChange("ordered")}
                    disabled={statusUpdating}
                    className="flex-1 py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors disabled:opacity-50"
                  >
                    {statusUpdating ? <Loader2 className="w-4 h-4 animate-spin mx-auto" /> : t("purchase_orders.approve")}
                  </button>
                )}
                <button
                  onClick={() => handleStatusChange("cancelled")}
                  disabled={statusUpdating}
                  className="flex-1 py-2.5 bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/30 rounded-xl text-sm font-medium transition-colors disabled:opacity-50"
                >
                  {t("purchase_orders.cancel_order")}
                </button>
              </div>
            )}

            {selected.status === "draft" && (
              <div className="glass rounded-xl p-4 flex items-start gap-3 border border-amber-500/30 bg-amber-500/5">
                <Info className="w-4 h-4 text-amber-400 mt-0.5 shrink-0" />
                <div>
                  <h4 className="text-sm font-medium text-foreground">{t("purchase_orders.record_payment")}</h4>
                  <p className="text-xs text-muted mt-1">{t("purchase_orders.payment_draft_note")}</p>
                </div>
              </div>
            )}

            {remaining > 0.005 && selected.status !== "cancelled" && selected.status !== "draft" && (
              <div className="glass rounded-xl p-4 space-y-3">
                <h4 className="text-sm font-medium text-muted">{t("purchase_orders.record_payment")}</h4>
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="text-xs text-muted mb-1 block">{t("common.amount")}</label>
                    <input
                      type="number"
                      min="0.01"
                      step="0.01"
                      max={remaining}
                      value={payAmount}
                      onChange={(e) => setPayAmount(e.target.value)}
                      placeholder={String(remaining)}
                      className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors"
                    />
                  </div>
                  <div>
                    <label className="text-xs text-muted mb-1 block">{t("common.method")}</label>
                    <select
                      value={payMethod}
                      onChange={(e) => setPayMethod(e.target.value)}
                      className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors"
                    >
                      <option value="cash">{t("pos.cash")}</option>
                      <option value="card">{t("pos.card")}</option>
                      <option value="bank_transfer">{t("pos.bank_transfer")}</option>
                      <option value="check">{t("pos.check")}</option>
                      <option value="mobile">{t("pos.mobile")}</option>
                    </select>
                  </div>
                </div>
                <button
                  onClick={handlePay}
                  disabled={paying || !payAmount || parseFloat(payAmount) <= 0}
                  className="w-full py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors disabled:opacity-50"
                >
                  {paying ? <Loader2 className="w-4 h-4 animate-spin mx-auto" /> : t("purchase_orders.pay")}
                </button>
              </div>
            )}
          </div>
        )}
      </SlideOver>

      {/* Create SlideOver */}
      <SlideOver open={formOpen} onClose={() => setFormOpen(false)} title={t("purchase_orders.create")} width="max-w-2xl">
        <div className="space-y-5">
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="text-sm text-muted mb-1 block">{t("common.supplier")}</label>
              <select value={form.supplier_id} onChange={(e) => handleSupplierChange(e.target.value)}
                className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors">
                <option value="">{"\u2014"}</option>
                {suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
              </select>
            </div>
            <div>
              <label className="text-sm text-muted mb-1 block">{t("purchase_orders.expected")}</label>
              <input type="date" value={form.expected_delivery} onChange={(e) => setForm((p) => ({ ...p, expected_delivery: e.target.value }))}
                className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors" />
            </div>
          </div>
          <div>
            <label className="text-sm text-muted mb-1 block">{t("invoices.notes")}</label>
            <textarea value={form.notes} onChange={(e) => setForm((p) => ({ ...p, notes: e.target.value }))} rows={2}
              className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors resize-none" />
          </div>
          <div>
            <div className="flex items-center justify-between mb-3">
              <h4 className="text-sm font-medium text-muted">{t("common.items")}</h4>
              <button onClick={addItem} className="flex items-center gap-1 text-xs text-primary-light hover:text-primary transition-colors">
                <Plus className="w-3.5 h-3.5" /> {t("invoices.add_item")}
              </button>
            </div>
            <div className="space-y-3">
              {form.items.map((item, idx) => (
                <div key={idx} className="glass rounded-xl p-3">
                  <div className="grid grid-cols-12 gap-2">
                    <div className="col-span-5 relative">
                      <button
                        type="button"
                        disabled={!form.supplier_id || catalogLoading}
                        onClick={() => {
                          if (itemDropdown === idx) { setItemDropdown(null); setItemSearch(""); }
                          else { setItemDropdown(idx); setItemSearch(""); }
                        }}
                        className="w-full px-3 py-2 bg-card/80 border border-border rounded-lg text-sm text-start text-foreground focus:outline-none focus:border-border-hover disabled:opacity-50 flex items-center justify-between gap-1 transition-colors"
                      >
                        <span className={`truncate ${item.name ? "text-foreground" : "text-muted"}`}>
                          {item.name || (form.supplier_id
                            ? (catalogLoading ? t("purchase_orders.loading") : t("purchase_orders.select_item"))
                            : t("purchase_orders.select_supplier_first"))}
                        </span>
                        <ChevronDown className="w-4 h-4 text-muted shrink-0" />
                      </button>
                      {itemDropdown === idx && (
                        <div className="absolute z-30 mt-1 w-full glass rounded-xl border border-border shadow-xl overflow-hidden">
                          <input
                            autoFocus
                            value={itemSearch}
                            onChange={(e) => setItemSearch(e.target.value)}
                            placeholder={t("purchase_orders.select_item")}
                            className="w-full px-3 py-2 bg-card/80 border-b border-border text-sm text-foreground placeholder:text-muted focus:outline-none"
                          />
                          <div className="max-h-48 overflow-y-auto">
                            {filteredCatalog.length === 0 ? (
                              <p className="px-3 py-3 text-xs text-muted">
                                {form.supplier_id ? t("purchase_orders.no_catalog") : t("purchase_orders.select_supplier_first")}
                              </p>
                            ) : (
                              filteredCatalog.map((c) => (
                                <button
                                  key={c.id}
                                  type="button"
                                  onClick={() => selectCatalogItem(idx, c)}
                                  className="w-full px-3 py-2 text-start text-sm text-foreground hover:bg-card-hover transition-colors flex items-center justify-between gap-2"
                                >
                                  <span className="truncate">{c.name}</span>
                                  {c.product_id ? (
                                    <span className="text-[10px] px-1.5 py-0.5 rounded-full bg-emerald-500/10 text-emerald-400 shrink-0">{t("purchase_orders.exists_badge")}</span>
                                  ) : (
                                    <span className="text-[10px] px-1.5 py-0.5 rounded-full bg-amber-500/10 text-amber-400 shrink-0">{t("purchase_orders.new_item_badge")}</span>
                                  )}
                                </button>
                              ))
                            )}
                          </div>
                        </div>
                      )}
                    </div>
                    <div className="col-span-2">
                      <input placeholder={t("invoices.qty")} type="number" min="0.01" step={quantityStep({ is_weighable: item.is_weighable, unit: item.unit })} value={item.quantity} onChange={(e) => updateFormItem(idx, "quantity", e.target.value)}
                        className="w-full px-3 py-2 bg-card/80 border border-border rounded-lg text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover" />
                    </div>
                    <div className="col-span-3">
                      <input placeholder={t("invoices.unit_price")} type="number" min="0" step="0.001" value={item.unit_cost} onChange={(e) => updateFormItem(idx, "unit_cost", e.target.value)}
                        className="w-full px-3 py-2 bg-card/80 border border-border rounded-lg text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover" />
                    </div>
                    <div className="col-span-1 flex items-center justify-center">
                      <span className="text-sm text-foreground font-medium">{formatCurrency((parseFloat(item.quantity) || 0) * (parseFloat(item.unit_cost) || 0), locale)}</span>
                    </div>
                    <div className="col-span-1 flex items-center justify-center">
                      {form.items.length > 1 && (
                        <button onClick={() => removeItem(idx)} className="p-1 text-muted hover:text-red-400 transition-colors"><X className="w-4 h-4" /></button>
                      )}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </div>
          <div className="glass rounded-xl p-4">
            <div className="flex justify-between text-base font-semibold"><span className="text-foreground">{t("common.total")}</span><span className="text-foreground">{formatCurrency(calcTotal(), locale)}</span></div>
          </div>
          <div className="flex gap-3">
            <button onClick={handleSave} disabled={formLoading}
              className="flex items-center gap-2 px-6 py-3 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors disabled:opacity-40">
              {formLoading ? <Loader2 className="w-4 h-4 animate-spin" /> : null}
              {formLoading ? t("common.saving") : t("common.save")}
            </button>
            <button onClick={() => setFormOpen(false)} disabled={formLoading} className="px-6 py-3 text-sm text-muted hover:text-foreground transition-colors">
              {t("pos.cancel")}
            </button>
          </div>
        </div>
      </SlideOver>

      {/* Import-confirmation modal */}
      <ConfirmDialog
        open={importConfirmOpen}
        onClose={() => { setImportConfirmOpen(false); setImportNames([]); }}
        onConfirm={() => {
          const validItems = form.items.filter((it) => it.name.trim() && parseFloat(it.quantity) > 0);
          doCreate(validItems);
        }}
        title={t("purchase_orders.import_title")}
        message={t("purchase_orders.import_message", { names: importNames.map((n) => `\u201C${n}\u201D`).join(", ") })}
        confirmLabel={t("purchase_orders.import_confirm")}
        confirmVariant="primary"
        loading={formLoading}
      />
    </motion.div>
  );
}
