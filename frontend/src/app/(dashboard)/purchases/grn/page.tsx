"use client";

import { useEffect, useState, useCallback } from "react";
import { motion } from "framer-motion";
import { useAuthStore } from "@/stores/auth-store";
import { GoodsReceipts, PurchaseOrders, Suppliers, Products, fetchSupplierCatalog } from "@/lib/api";
import type { GoodsReceipt, PurchaseOrder, PurchaseOrderItem, Supplier, SupplierProduct, Product } from "@/lib/types";
import { formatCurrency } from "@/lib/types";
import PageHeader from "@/components/ui/PageHeader";
import DataTable from "@/components/ui/DataTable";
import type { Column } from "@/components/ui/DataTable";
import SlideOver from "@/components/ui/SlideOver";
import { PackageCheck, Plus, Loader2, Search, X } from "lucide-react";
import { useI18n } from "@/lib/i18n";
import { quantityStep } from "@/lib/product";
import { round2 } from "@/lib/math";
import { usePagination } from "@/lib/pagination";

type GRNItem = {
  purchase_order_item_id: string;
  product_id: string;
  name: string;
  quantity: string;
  unit_cost: string;
  expiry_date: string;
  storage_location: string;
  purchase_unit?: string | null;
  purchase_unit_qty?: number | null;
};

export default function GRNPage() {
  const { t, locale } = useI18n();
  const { token, business, config } = useAuthStore();
  const showExpiry = config?.modules.includes("expiry_tracking") ?? false;
  const [data, setData] = useState<GoodsReceipt[]>([]);
  const [loading, setLoading] = useState(true);
  const [total, setTotal] = useState(0);
  const { page, perPage, setPage, changePageSize } = usePagination();
  const [formOpen, setFormOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [mode, setMode] = useState<"po" | "direct">("po");
  const [pos, setPos] = useState<PurchaseOrder[]>([]);
  const [suppliers, setSuppliers] = useState<Supplier[]>([]);
  const [selectedPO, setSelectedPO] = useState<PurchaseOrder | null>(null);
  const [supplierId, setSupplierId] = useState<string>("");
  const [items, setItems] = useState<GRNItem[]>([]);
  const [paymentMethod, setPaymentMethod] = useState<string>("");
  const [payNow, setPayNow] = useState<string>("");
  const [receiptDate, setReceiptDate] = useState<string>("");
  const [referenceInvoice, setReferenceInvoice] = useState<string>("");
  const [productSearch, setProductSearch] = useState("");
  const [productList, setProductList] = useState<Product[]>([]);
  const [supplierCatalog, setSupplierCatalog] = useState<SupplierProduct[]>([]);
  const [productOpen, setProductOpen] = useState(false);
  const [toast, setToast] = useState<{ msg: string; type: "success" | "error" } | null>(null);

  const showToast = (msg: string, type: "success" | "error" = "success") => {
    setToast({ msg, type });
    setTimeout(() => setToast(null), 3000);
  };

  const columns: Column[] = [
    { key: "receipt_number", label: t("grn.receipt_num") },
    { key: "purchase_order_id", label: t("grn.po_num"), render: (_v, row) => (row.purchase_order as PurchaseOrder | undefined)?.order_number ?? "—" },
    { key: "reference_invoice_number", label: t("grn.reference_invoice_number"), render: (v) => (v ? String(v) : "—") },
    { key: "supplier_id", label: t("suppliers.name"), render: (_v, row) => (row.supplier as Supplier | undefined)?.name ?? "—" },
    { key: "total_amount", label: t("common.total"), type: "currency" },
    { key: "received_at", label: t("common.date"), type: "date" },
  ];

  const fetchData = useCallback(() => {
    if (!token || !business) return;
    setLoading(true);
    GoodsReceipts.list(token, business.id, { page, per_page: perPage })
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

  const openForm = async () => {
    if (!token || !business) return;
    try {
      const [orderedRes, partialRes, suppliersRes, productsRes] = await Promise.all([
        PurchaseOrders.list(token, business.id, { status: "ordered", per_page: 200 }),
        PurchaseOrders.list(token, business.id, { status: "partially_received", per_page: 200 }),
        Suppliers.list(token, business.id, { per_page: 200 }),
        Products.list(token, business.id, { per_page: 200 }),
      ]);
      setPos([...orderedRes.data, ...partialRes.data]);
      setSuppliers(suppliersRes.data ?? []);
      setProductList(productsRes.data ?? []);
    } catch {}
    setMode("po");
    setSelectedPO(null);
    setSupplierId("");
    setItems([]);
    setSupplierCatalog([]);
    setPaymentMethod("");
    setPayNow("");
    setReceiptDate("");
    setReferenceInvoice("");
    setProductSearch("");
    setFormOpen(true);
  };

  const loadPO = async (poId: string) => {
    if (!token || !business || !poId) { setSelectedPO(null); setItems([]); setPaymentMethod(""); setPayNow(""); return; }
    try {
      const po = await PurchaseOrders.get(token, business.id, parseInt(poId));
      setSelectedPO(po);
      setItems((po.items ?? []).map((it: PurchaseOrderItem) => ({
        purchase_order_item_id: String(it.id ?? ""),
        product_id: String(it.product_id ?? ""),
        name: it.name,
        quantity: String(it.quantity - (it.received_quantity ?? 0)),
        unit_cost: String(it.unit_cost),
        expiry_date: "",
        storage_location: "",
        purchase_unit: null,
        purchase_unit_qty: null,
      })));
      setPaymentMethod("");
    } catch { setSelectedPO(null); setItems([]); }
  };

  const addDirectItem = (p: Product) => {
    if (items.some((i) => i.product_id === String(p.id))) {
      showToast(t("grn.duplicate_item"), "error");
      return;
    }
    const catalogRow = supplierCatalog.find((c) => c.product_id != null && String(c.product_id) === String(p.id));
    const catalogCost = catalogRow && catalogRow.catalog_cost != null && catalogRow.catalog_cost > 0
      ? catalogRow.catalog_cost
      : p.cost;
    const costString = catalogCost != null ? String(catalogCost) : "";
    setItems((prev) => [...prev, {
      purchase_order_item_id: "",
      product_id: String(p.id),
      name: p.name,
      quantity: "",
      unit_cost: costString,
      expiry_date: "",
      storage_location: "",
      purchase_unit: p.purchase_unit ?? null,
      purchase_unit_qty: p.purchase_unit_qty ?? 1,
    }]);
    setProductSearch("");
    setProductOpen(false);
  };

  const removeItem = (idx: number) => setItems((prev) => prev.filter((_, i) => i !== idx));

  const handleSupplierChange = async (id: string) => {
    setSupplierId(id);
    setProductSearch("");
    setProductOpen(false);
    setSupplierCatalog([]);
    if (!token || !business || !id) return;
    try {
      const catalog = await fetchSupplierCatalog(token, business.id, parseInt(id));
      setSupplierCatalog(Array.isArray(catalog) ? catalog : []);
    } catch {
      setSupplierCatalog([]);
    }
  };

  const catalogProductIds = new Set(
    supplierCatalog.filter((c) => c.product_id != null).map((c) => String(c.product_id)),
  );
  const supplierProducts = supplierId ? productList.filter((p) => catalogProductIds.has(String(p.id))) : [];
  const filteredProducts = supplierProducts.filter((p) => {
    const q = productSearch.trim().toLowerCase();
    return !q || p.name.toLowerCase().includes(q) || (p.sku ?? "").toLowerCase().includes(q) || (p.barcode ?? "").toLowerCase().includes(q);
  });

  const receiptTotal = round2(items.reduce((sum, i) => {
    const qty = parseFloat(i.quantity) * (mode === "direct" ? (i.purchase_unit_qty ?? 1) : 1);
    const cost = parseFloat(i.unit_cost);
    return sum + (isFinite(qty) && isFinite(cost) && qty > 0 && cost > 0 ? qty * cost : 0);
  }, 0));

  const poTotal = selectedPO?.total_amount ?? 0;
  const advancePaid = selectedPO?.paid_amount ?? 0;
  const netDue = round2(Math.max(0, poTotal - advancePaid));
  const maxPayNow = mode === "po" ? round2(Math.max(0, Math.min(receiptTotal, netDue))) : receiptTotal;

  const handleMethodChange = (method: string) => {
    setPaymentMethod(method);
    setPayNow(method && maxPayNow > 0.005 ? String(maxPayNow) : "");
  };

  const payNowValue = payNow.trim() !== "" ? parseFloat(payNow) || 0 : maxPayNow;

  const handleSave = async () => {
    if (!token || !business) return;
    if (mode === "po" && !selectedPO) return;
    if (mode === "direct" && !supplierId) { showToast(t("grn.supplier_required"), "error"); return; }
    const validItems = items.filter((i) => parseFloat(i.quantity) > 0 && i.name.trim());
    if (validItems.length === 0) { showToast(t("grn.error_no_items"), "error"); return; }
    if (paymentMethod && payNowValue > maxPayNow + 0.005) {
      showToast(t("grn.overpay_remaining"), "error");
      return;
    }
    const paidNow = paymentMethod && payNowValue > 0.005
      ? { payment_method: paymentMethod, pay_now_amount: round2(Math.min(payNowValue, maxPayNow)) }
      : {};
    setSaving(true);
    try {
      await GoodsReceipts.create(token, business.id, {
        ...(mode === "po" ? { purchase_order_id: selectedPO!.id } : { supplier_id: parseInt(supplierId) }),
        ...(receiptDate ? { received_at: receiptDate } : {}),
        ...(referenceInvoice.trim() ? { reference_invoice_number: referenceInvoice.trim() } : {}),
        items: validItems.map((i) => ({
          ...(mode === "po" ? { purchase_order_item_id: parseInt(i.purchase_order_item_id) || null } : {}),
          product_id: parseInt(i.product_id) || null,
          received_quantity: parseFloat(i.quantity),
          ...(i.unit_cost !== "" ? { unit_cost: parseFloat(i.unit_cost) } : {}),
          ...(i.expiry_date ? { expiry_date: i.expiry_date } : {}),
          ...(i.storage_location ? { storage_location: i.storage_location } : {}),
        })),
        ...paidNow,
      });
      showToast(t("grn.success_received"));
      setFormOpen(false);
      fetchData();
    } catch (e) {
      showToast(e instanceof Error && e.message ? e.message : t("grn.error_failed"), "error");
    } finally { setSaving(false); }
  };

  const inputCls = "w-full px-3 py-1.5 bg-card/80 border border-border rounded-lg text-sm text-foreground focus:outline-none focus:border-border-hover";

  const itemStep = (item: GRNItem) => quantityStep(productList.find((p) => String(p.id) === item.product_id));

  return (
    <motion.div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.4 }}>
      {toast && (
        <div className={`fixed top-4 end-4 z-[60] px-4 py-2.5 rounded-xl text-sm font-medium shadow-lg border ${
          toast.type === "success" ? "bg-emerald-500/20 text-emerald-400 border-emerald-500/30" : "bg-red-500/20 text-red-400 border-red-500/30"
        }`}>{toast.msg}</div>
      )}
      <PageHeader title={t("grn.title")} subtitle={t("grn.subtitle_count", { count: String(data.length) })} action={
        <button onClick={openForm} className="flex items-center gap-2 px-4 py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors">
          <Plus className="w-4 h-4" /> {t("grn.new_receiving")}
        </button>
      } />
      <DataTable columns={columns} data={data as unknown as Record<string, unknown>[]} loading={loading} emptyMessage={t("grn.empty")} emptyIcon={PackageCheck} pagination={{ total, page, perPage, onPageChange: setPage, onPerPageChange: changePageSize }} />
      <SlideOver open={formOpen} onClose={() => setFormOpen(false)} title={t("grn.receive_goods")} width="max-w-2xl">
        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-2">
            <button onClick={() => { setMode("po"); setSelectedPO(null); setItems([]); setSupplierId(""); setSupplierCatalog([]); }}
              className={`px-4 py-2 rounded-xl text-sm font-medium border transition-colors ${mode === "po" ? "bg-primary text-foreground border-primary" : "border-border text-muted"}`}>
              {t("grn.mode_po")}
            </button>
            <button onClick={() => { setMode("direct"); setSelectedPO(null); setItems([]); setProductSearch(""); setSupplierCatalog([]); }}
              className={`px-4 py-2 rounded-xl text-sm font-medium border transition-colors ${mode === "direct" ? "bg-primary text-foreground border-primary" : "border-border text-muted"}`}>
              {t("grn.mode_direct")}
            </button>
          </div>

          {mode === "po" && (
            <div>
              <label className="text-sm text-muted mb-1 block">{t("grn.purchase_order")}</label>
              <select onChange={(e) => loadPO(e.target.value)} value={selectedPO?.id ?? ""}
                className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors">
                <option value="">{t("grn.select_po")}</option>
                {pos.map((po) => <option key={po.id} value={po.id}>{po.order_number} - {formatCurrency(po.total_amount ?? 0, locale)}</option>)}
              </select>
            </div>
          )}

          {mode === "po" && selectedPO && (
            <div className="glass rounded-xl p-3 space-y-1.5">
              <div className="flex justify-between text-sm">
                <span className="text-muted">{t("grn.order_total")}</span>
                <span className="text-foreground font-medium">{formatCurrency(poTotal, locale)}</span>
              </div>
              {advancePaid > 0.005 && (
                <div className="flex justify-between text-sm">
                  <span className="text-muted">{t("grn.advance_paid")}</span>
                  <span className="text-amber-500 font-medium">- {formatCurrency(advancePaid, locale)}</span>
                </div>
              )}
              <div className="flex justify-between text-sm pt-1.5 border-t border-border">
                <span className="text-muted">{t("grn.net_due")}</span>
                <span className={netDue > 0.005 ? "text-emerald-500 font-semibold" : "text-muted font-semibold"}>
                  {formatCurrency(netDue, locale)}
                </span>
              </div>
            </div>
          )}

          {mode === "direct" && (
            <>
              <div>
                <label className="text-sm text-muted mb-1 block">{t("suppliers.supplier")}</label>
                <select onChange={(e) => handleSupplierChange(e.target.value)} value={supplierId}
                  className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors">
                  <option value="">{t("grn.select_supplier")}</option>
                  {suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                </select>
              </div>
              <div>
                <label className="text-sm text-muted mb-1 block">{t("grn.select_product")}</label>
                <div className="relative z-40">
                  <Search className="w-4 h-4 absolute start-3 top-3 text-muted" />
                  <input
                    value={productSearch}
                    disabled={!supplierId}
                    onFocus={() => { if (supplierId) setProductOpen(true); }}
                    onChange={(e) => { setProductSearch(e.target.value); setProductOpen(true); }}
                    onBlur={(e) => { if (!(e.relatedTarget as HTMLElement | null)?.closest?.(".product-options")) setProductOpen(false); }}
                    placeholder={supplierId ? t("grn.search_product") : t("grn.select_supplier_first")}
                    className="w-full ps-10 px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                  />
                  {productOpen && supplierId && (
                    <div className="product-options absolute z-50 mt-1 w-full max-h-52 overflow-y-auto bg-card rounded-xl border border-border shadow-xl">
                      {filteredProducts.length === 0
                        ? <p className="px-4 py-3 text-sm text-muted">{supplierCatalog.length === 0 ? t("grn.no_catalog") : t("grn.no_products")}</p>
                        : filteredProducts.map((p) => (
                            <button key={p.id} onMouseDown={(e) => e.preventDefault()} onClick={() => addDirectItem(p)}
                              className="w-full text-start px-4 py-2.5 hover:bg-border/40 text-sm text-foreground transition-colors">
                              <span className="font-medium">{p.name}</span>
                              <span className="ms-2 text-xs text-muted">{p.sku}</span>
                              {p.purchase_unit && <span className="ms-2 text-xs text-muted">({p.purchase_unit}{p.purchase_unit_qty && p.purchase_unit_qty > 1 ? ` = ${p.purchase_unit_qty} ${p.unit}` : ""})</span>}
                            </button>
                          ))}
                    </div>
                  )}
                </div>
              </div>
            </>
          )}

          <div className="grid grid-cols-2 gap-2">
            <div>
              <label className="text-sm text-muted mb-1 block">{t("grn.receipt_date")}</label>
              <input type="date" value={receiptDate} onChange={(e) => setReceiptDate(e.target.value)}
                className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors" />
            </div>
            <div>
              <label className="text-sm text-muted mb-1 block">{t("grn.reference_invoice_number")}</label>
              <input type="text" value={referenceInvoice} onChange={(e) => setReferenceInvoice(e.target.value)}
                placeholder={t("grn.reference_invoice_placeholder")}
                className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors" />
            </div>
          </div>

          {items.length > 0 && (
            <div className="space-y-2">
              <p className="text-sm font-medium text-muted">{t("grn.items")}</p>
              {items.map((item, idx) => (
                <div key={idx} className="glass rounded-xl p-3 space-y-2">
                  <div className="flex items-center justify-between gap-2">
                    <span className="text-sm text-foreground">{item.name}</span>
                    <button onClick={() => removeItem(idx)} className="text-muted hover:text-red-400 transition-colors" aria-label={t("common.remove")}>
                      <X className="w-4 h-4" />
                    </button>
                  </div>
                  <div className="grid grid-cols-2 gap-2">
                    <div>
                      <label className="text-xs text-muted block mb-1">{t("grn.qty")}{item.purchase_unit ? ` (${item.purchase_unit})` : ""}</label>
                      <input type="number" min="0" step={itemStep(item)} value={item.quantity ?? ""} onChange={(e) => setItems((prev) => prev.map((it, i) => i === idx ? { ...it, quantity: e.target.value } : it))}
                        className={inputCls} />
                      {(item.purchase_unit_qty && item.purchase_unit_qty > 1) && (
                        <p className="text-[11px] text-muted mt-1">{t("grn.unit_equals", { n: String(item.purchase_unit_qty), unit: item.purchase_unit ?? "" })}</p>
                      )}
                    </div>
                    <div>
                      <label className="text-xs text-muted block mb-1">{t("grn.unit_cost")}</label>
                      <input type="number" min="0" step="0.01" value={item.unit_cost ?? ""} onChange={(e) => setItems((prev) => prev.map((it, i) => i === idx ? { ...it, unit_cost: e.target.value } : it))}
                        className={inputCls} />
                    </div>
                    {showExpiry && (
                      <div>
                        <label className="text-xs text-muted block mb-1">{t("common.expiry_date")}</label>
                        <input type="date" value={item.expiry_date ?? ""} onChange={(e) => setItems((prev) => prev.map((it, i) => i === idx ? { ...it, expiry_date: e.target.value } : it))}
                          className={inputCls} />
                      </div>
                    )}
                    {mode === "direct" && (
                      <div>
                        <label className="text-xs text-muted block mb-1">{t("grn.storage_location")}</label>
                        <input type="text" value={item.storage_location ?? ""} onChange={(e) => setItems((prev) => prev.map((it, i) => i === idx ? { ...it, storage_location: e.target.value } : it))}
                          className={inputCls} />
                      </div>
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}

          <div>
            <label className="text-sm text-muted mb-1 block">{t("grn.payment")}</label>
            <select value={paymentMethod} onChange={(e) => handleMethodChange(e.target.value)}
              className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors">
              <option value="">{t("grn.pay_later")}</option>
              <option value="cash">{t("grn.paid_now", { method: t("invoices.cash") })}</option>
              <option value="card">{t("grn.paid_now", { method: t("invoices.card") })}</option>
              <option value="bank_transfer">{t("grn.paid_now", { method: t("invoices.bank_transfer") })}</option>
              <option value="check">{t("grn.paid_now", { method: t("invoices.check") })}</option>
              <option value="mobile">{t("grn.paid_now", { method: t("invoices.mobile") })}</option>
            </select>
          </div>

          {paymentMethod && maxPayNow > 0.005 && (
            <div>
              <label className="text-sm text-muted mb-1 block">{t("grn.pay_now")}</label>
              <input type="number" min="0" step="0.01" value={payNow} onChange={(e) => setPayNow(e.target.value)}
                className={inputCls} />
              {advancePaid > 0.005 && (
                <p className="text-[11px] text-muted mt-1">{t("grn.pay_now_hint")}</p>
              )}
            </div>
          )}
          {paymentMethod && maxPayNow <= 0.005 && (
            <p className="text-xs text-amber-500">{t("grn.fully_prepaid")}</p>
          )}

          <button onClick={handleSave} disabled={saving || (mode === "po" ? !selectedPO : !supplierId) || items.length === 0}
            className="w-full py-3 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors disabled:opacity-50">
            {saving ? <Loader2 className="w-4 h-4 animate-spin mx-auto" /> : t("grn.receive_goods")}
          </button>
        </div>
      </SlideOver>
    </motion.div>
  );
}