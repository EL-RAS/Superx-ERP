"use client";

import { Suspense, useEffect, useState, useCallback } from "react";
import { motion } from "framer-motion";
import { useRouter, useSearchParams } from "next/navigation";
import { useAuthStore } from "@/stores/auth-store";
import { PurchaseOrders, Suppliers, Products } from "@/lib/api";
import type { PurchaseOrder, Supplier, Product } from "@/lib/types";
import { formatCurrency } from "@/lib/types";
import { useI18n } from "@/lib/i18n";
import PageHeader from "@/components/ui/PageHeader";
import { ShoppingCart, Plus, X, Loader2 } from "lucide-react";

interface POItemForm {
  product_id: string;
  name: string;
  quantity: string;
  unit_cost: string;
}

const emptyItem: POItemForm = { product_id: "", name: "", quantity: "1", unit_cost: "0" };

function NewPurchaseOrder() {
  const { t, locale } = useI18n();
  const { token, business } = useAuthStore();
  const router = useRouter();
  const searchParams = useSearchParams();

  const [suppliers, setSuppliers] = useState<Supplier[]>([]);
  const [products, setProducts] = useState<Product[]>([]);
  const [ready, setReady] = useState(false);
  const [saving, setSaving] = useState(false);
  const [form, setForm] = useState({
    supplier_id: "",
    notes: "",
    expected_delivery: "",
    items: [{ ...emptyItem }],
  });
  const [toast, setToast] = useState<{ msg: string; type: "success" | "error" } | null>(null);

  const showToast = (msg: string, type: "success" | "error" = "success") => {
    setToast({ msg, type });
    setTimeout(() => setToast(null), 3000);
  };

  useEffect(() => {
    if (!token || !business) return;
    Promise.all([
      Suppliers.list(token, business.id, { per_page: 200 }),
      Products.list(token, business.id, { per_page: 200 }),
    ])
      .then(([s, p]) => {
        setSuppliers(s.data);
        setProducts(p.data);

        const productId = searchParams.get("product_id");
        const supplierId = searchParams.get("supplier_id");
        const suggestedQty = searchParams.get("suggested_quantity");

        let items = [{ ...emptyItem }];
        if (productId) {
          const product = p.data.find((x) => String(x.id) === productId);
          const qty = Number(suggestedQty) > 0 ? Number(suggestedQty) : 20;
          items = [
            {
              product_id: String(product?.id ?? productId),
              name: product?.name ?? "",
              quantity: String(qty),
              unit_cost: String(product?.cost ?? 0),
            },
          ];
        }

        setForm((prev) => ({
          ...prev,
          supplier_id: supplierId && s.data.some((x) => String(x.id) === supplierId) ? supplierId : "",
          items,
        }));
        setReady(true);
      })
      .catch(() => {});
  }, [token, business, searchParams]);

  const updateFormItem = (idx: number, field: keyof POItemForm, value: string) => {
    setForm((prev) => ({
      ...prev,
      items: prev.items.map((it, i) => (i === idx ? { ...it, [field]: value } : it)),
    }));
  };

  const addItem = () => setForm((prev) => ({ ...prev, items: [...prev.items, { ...emptyItem }] }));
  const removeItem = (idx: number) => setForm((prev) => ({ ...prev, items: prev.items.filter((_, i) => i !== idx) }));

  const calcTotal = () => form.items.reduce((sum, it) => sum + (parseFloat(it.quantity) || 0) * (parseFloat(it.unit_cost) || 0), 0);

  const handleSave = async () => {
    if (!token || !business) return;
    const validItems = form.items.filter((it) => it.name.trim() && parseFloat(it.quantity) > 0);
    if (validItems.length === 0) return;
    setSaving(true);
    try {
      const order: PurchaseOrder = await PurchaseOrders.create(token, business.id, {
        supplier_id: form.supplier_id ? parseInt(form.supplier_id) : null,
        notes: form.notes || null,
        expected_delivery: form.expected_delivery || null,
        items: validItems.map((it) => ({
          product_id: it.product_id ? parseInt(it.product_id) : 1,
          name: it.name,
          quantity: parseFloat(it.quantity),
          unit_cost: parseFloat(it.unit_cost),
        })),
      });
      showToast(t("purchase_orders.created_with", { num: order.order_number }));
      router.push("/purchase-orders");
    } catch (e: unknown) {
      showToast(e instanceof Error ? e.message : t("common.error"), "error");
    } finally {
      setSaving(false);
    }
  };

  if (!ready) {
    return (
      <div className="flex items-center justify-center py-24">
        <Loader2 className="w-6 h-6 animate-spin text-muted" />
      </div>
    );
  }

  return (
    <motion.div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.4 }} className="max-w-3xl">
      {toast && (
        <div className={`fixed top-4 end-4 z-[60] px-4 py-2.5 rounded-xl text-sm font-medium shadow-lg transition-all ${
          toast.type === "success" ? "bg-emerald-500/20 text-emerald-400 border border-emerald-500/30" : "bg-red-500/20 text-red-400 border border-red-500/30"
        }`}>{toast.msg}</div>
      )}

      <PageHeader title={t("purchase_orders.new_title")} subtitle={t("purchase_orders.new_subtitle")} />

      <div className="glass rounded-2xl p-6 space-y-5">
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className="text-sm text-muted mb-1 block">{t("common.supplier")}</label>
            <select value={form.supplier_id} onChange={(e) => setForm((p) => ({ ...p, supplier_id: e.target.value }))}
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
            <h4 className="text-sm font-medium text-muted">{t("invoices.items")}</h4>
            <button onClick={addItem} className="flex items-center gap-1 text-xs text-primary-light hover:text-primary transition-colors">
              <Plus className="w-3.5 h-3.5" /> {t("invoices.add_item")}
            </button>
          </div>
          <div className="space-y-3">
            {form.items.map((item, idx) => (
              <div key={idx} className="glass rounded-xl p-3">
                <div className="grid grid-cols-12 gap-2">
                  <div className="col-span-5">
                    <select
                      value={item.product_id}
                      onChange={(e) => {
                        const pid = e.target.value;
                        const p = products.find((x) => String(x.id) === pid);
                        updateFormItem(idx, "product_id", pid);
                        updateFormItem(idx, "name", p?.name ?? "");
                        if (p) updateFormItem(idx, "unit_cost", String(p.cost ?? 0));
                      }}
                      className="w-full px-3 py-2 bg-card/80 border border-border rounded-lg text-sm text-foreground focus:outline-none focus:border-border-hover"
                    >
                      <option value="">{t("batches.select_product")}</option>
                      {products.map((p) => (
                        <option key={p.id} value={p.id}>{p.name}</option>
                      ))}
                    </select>
                  </div>
                  <div className="col-span-2">
                    <input placeholder={t("invoices.qty")} type="number" min="0.01" step="0.01" value={item.quantity} onChange={(e) => updateFormItem(idx, "quantity", e.target.value)}
                      className="w-full px-3 py-2 bg-card/80 border border-border rounded-lg text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover" />
                  </div>
                  <div className="col-span-3">
                    <input placeholder={t("purchase_orders.unit_cost")} type="number" min="0" step="0.001" value={item.unit_cost} onChange={(e) => updateFormItem(idx, "unit_cost", e.target.value)}
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
          <button onClick={handleSave} disabled={saving}
            className="flex items-center gap-2 px-6 py-3 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors disabled:opacity-40">
            {saving ? <Loader2 className="w-4 h-4 animate-spin" /> : <ShoppingCart className="w-4 h-4" />}
            {saving ? t("invoices.saving") : t("invoices.save")}
          </button>
          <button onClick={() => router.push("/purchase-orders")} disabled={saving} className="px-6 py-3 text-sm text-muted hover:text-foreground transition-colors">
            {t("pos.cancel")}
          </button>
        </div>
      </div>
    </motion.div>
  );
}

export default function NewPurchaseOrderPage() {
  return (
    <Suspense fallback={<div className="flex items-center justify-center py-24"><Loader2 className="w-6 h-6 animate-spin text-muted" /></div>}>
      <NewPurchaseOrder />
    </Suspense>
  );
}
