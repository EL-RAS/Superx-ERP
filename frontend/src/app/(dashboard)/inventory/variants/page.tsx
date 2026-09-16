"use client";

import { useEffect, useState, useCallback } from "react";
import { motion } from "framer-motion";
import { useAuthStore } from "@/stores/auth-store";
import { Products, ProductVariants, generateProductVariants } from "@/lib/api";
import Pagination from "@/components/ui/Pagination";
import { usePagination, paginationParams } from "@/lib/pagination";
import type { ProductVariant, Product } from "@/lib/types";
import { useI18n } from "@/lib/i18n";
import PageHeader from "@/components/ui/PageHeader";
import EmptyState from "@/components/ui/EmptyState";
import ConfirmDialog from "@/components/ui/ConfirmDialog";
import { Grid3x3, Plus, Trash2, X } from "lucide-react";
import { toast } from "sonner";

interface GenerateForm {
  product_id: number | null;
  attr1Name: string;
  attr1Values: string[];
  attr2Name: string;
  attr2Values: string[];
}

function toCurrency(n: number | string | null | undefined): string {
  return Number(n || 0).toFixed(2);
}

export default function VariantsPage() {
  const { token, business } = useAuthStore();
  const { t } = useI18n();
  const [data, setData] = useState<ProductVariant[]>([]);
  const [total, setTotal] = useState(0);
  const { page, perPage, setPage, changePageSize } = usePagination();
  const [loading, setLoading] = useState(true);
  const [generating, setGenerating] = useState(false);
  const [products, setProducts] = useState<Product[]>([]);
  const [showGen, setShowGen] = useState(false);

  const [form, setForm] = useState<GenerateForm>({
    product_id: null,
    attr1Name: "",
    attr1Values: [],
    attr2Name: "",
    attr2Values: [],
  });

  const [attr1Input, setAttr1Input] = useState("");
  const [attr2Input, setAttr2Input] = useState("");

  const [deleteTarget, setDeleteTarget] = useState<ProductVariant | null>(null);

  const fetchData = useCallback(() => {
    if (!token || !business) return;
    setLoading(true);
    ProductVariants.list(token, business.id, paginationParams(page, perPage) as Record<string, string | number>)
      .then((res) => {
        setData(res.data);
        setTotal(res.total);
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [token, business, page, perPage]);

  useEffect(() => {
    const timer = setTimeout(fetchData, 0);
    return () => clearTimeout(timer);
  }, [fetchData]);

  useEffect(() => {
    if (token && business) {
      Products.list(token, business.id, { per_page: 200 }).then((r) => setProducts(r.data)).catch(() => {});
    }
  }, [token, business]);

  const handleGenerate = async () => {
    if (!token || !business || !form.product_id) return;
    if (!form.attr1Name || form.attr1Values.length === 0) {
      toast.error(t("variants.error_attr_required"));
      return;
    }
    setGenerating(true);
    try {
      await generateProductVariants(token, business.id, {
        product_id: form.product_id,
        attribute1_name: form.attr1Name,
        attribute1_values: form.attr1Values,
        attribute2_name: form.attr2Name || undefined,
        attribute2_values: form.attr2Values.length > 0 ? form.attr2Values : undefined,
      });
      toast.success(t("variants.success_generated"));
      setShowGen(false);
      setForm({ product_id: null, attr1Name: "", attr1Values: [], attr2Name: "", attr2Values: [] });
      setAttr1Input("");
      setAttr2Input("");
      fetchData();
    } catch {
      toast.error(t("variants.error_generate"));
    } finally {
      setGenerating(false);
    }
  };

  const handleDelete = async () => {
    if (!token || !business || !deleteTarget) return;
    try {
      await ProductVariants.delete(token, business.id, deleteTarget.id);
      toast.success(t("variants.success_deleted"));
      setDeleteTarget(null);
      fetchData();
    } catch {
      toast.error(t("variants.error_delete"));
    }
  };

  const previewCombos: string[] = [];
  if (form.product_id && form.attr1Values.length > 0) {
    const vals = form.attr2Values.length > 0 ? form.attr2Values : [""];
    for (const v1 of form.attr1Values) {
      for (const v2 of vals) {
        previewCombos.push(v2 ? `${v1} / ${v2}` : v1);
      }
    }
  }

  const grouped = data.reduce<Record<number, ProductVariant[]>>((acc, v) => {
    if (!acc[v.product_id]) acc[v.product_id] = [];
    acc[v.product_id].push(v);
    return acc;
  }, {});

  const productEntries = Object.entries(grouped);

  return (
    <motion.div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.4 }}>
      <PageHeader
        title={t("variants.title")}
        subtitle={t("variants.subtitle", { variants: String(total), products: String(productEntries.length) })}
        action={
          <button onClick={() => setShowGen(!showGen)} className="btn-primary text-sm flex items-center gap-2">
            <Plus className="w-4 h-4" />
            {t("variants.generate")}
          </button>
        }
      />

      {showGen && (
        <div className="glass rounded-2xl p-6 mb-6">
          <h3 className="text-sm font-semibold text-foreground mb-4">{t("variants.generate_title")}</h3>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <div>
              <label className="block text-xs font-medium text-muted mb-1">{t("variants.product")}</label>
              <select
                value={form.product_id ?? ""}
                onChange={(e) => setForm({ ...form, product_id: e.target.value ? Number(e.target.value) : null })}
                className="w-full bg-card border border-border rounded-lg px-3 py-2 text-sm text-foreground"
              >
                <option value="">{t("variants.select_product")}</option>
                {products.map((p) => (
                  <option key={p.id} value={p.id}>{p.name} ({p.sku})</option>
                ))}
              </select>
            </div>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <div>
              <label className="block text-xs font-medium text-muted mb-1">{t("variants.attr1_name")}</label>
              <input
                value={form.attr1Name}
                onChange={(e) => setForm({ ...form, attr1Name: e.target.value })}
                placeholder={t("variants.eg_size")}
                className="w-full bg-card border border-border rounded-lg px-3 py-2 text-sm text-foreground"
              />
            </div>
            <div>
              <label className="block text-xs font-medium text-muted mb-1">{t("variants.attr1_values")}</label>
              <div className="flex gap-2">
                <input
                  value={attr1Input}
                  onChange={(e) => setAttr1Input(e.target.value)}
                  onKeyDown={(e) => {
                    if (e.key === "Enter" && attr1Input.trim()) {
                      e.preventDefault();
                      setForm({ ...form, attr1Values: [...form.attr1Values, attr1Input.trim()] });
                      setAttr1Input("");
                    }
                  }}
                  placeholder={t("variants.type_and_enter")}
                  className="flex-1 bg-card border border-border rounded-lg px-3 py-2 text-sm text-foreground"
                />
              </div>
              {form.attr1Values.length > 0 && (
                <div className="flex flex-wrap gap-1.5 mt-2">
                  {form.attr1Values.map((v, i) => (
                    <span key={i} className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-accent-dim text-xs text-foreground">
                      {v}
                      <button onClick={() => setForm({ ...form, attr1Values: form.attr1Values.filter((_, j) => j !== i) })}>
                        <X className="w-3 h-3" />
                      </button>
                    </span>
                  ))}
                </div>
              )}
            </div>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <div>
              <label className="block text-xs font-medium text-muted mb-1">{t("variants.attr2_name_optional")}</label>
              <input
                value={form.attr2Name}
                onChange={(e) => setForm({ ...form, attr2Name: e.target.value })}
                placeholder={t("variants.eg_color")}
                className="w-full bg-card border border-border rounded-lg px-3 py-2 text-sm text-foreground"
              />
            </div>
            <div>
              <label className="block text-xs font-medium text-muted mb-1">{t("variants.attr2_values_optional")}</label>
              <div className="flex gap-2">
                <input
                  value={attr2Input}
                  onChange={(e) => setAttr2Input(e.target.value)}
                  onKeyDown={(e) => {
                    if (e.key === "Enter" && attr2Input.trim()) {
                      e.preventDefault();
                      setForm({ ...form, attr2Values: [...form.attr2Values, attr2Input.trim()] });
                      setAttr2Input("");
                    }
                  }}
                  placeholder={t("variants.type_and_enter")}
                  className="flex-1 bg-card border border-border rounded-lg px-3 py-2 text-sm text-foreground"
                />
              </div>
              {form.attr2Values.length > 0 && (
                <div className="flex flex-wrap gap-1.5 mt-2">
                  {form.attr2Values.map((v, i) => (
                    <span key={i} className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-accent-dim text-xs text-foreground">
                      {v}
                      <button onClick={() => setForm({ ...form, attr2Values: form.attr2Values.filter((_, j) => j !== i) })}>
                        <X className="w-3 h-3" />
                      </button>
                    </span>
                  ))}
                </div>
              )}
            </div>
          </div>

          {previewCombos.length > 0 && (
            <div className="mb-4">
              <p className="text-xs font-medium text-muted mb-2">{t("variants.will_generate", { count: String(previewCombos.length) })}</p>
              <div className="flex flex-wrap gap-1.5">
                {previewCombos.map((c, i) => (
                  <span key={i} className="px-2 py-0.5 rounded-md bg-accent-dim text-xs text-foreground">{c}</span>
                ))}
              </div>
            </div>
          )}

          <div className="flex gap-3">
            <button
              onClick={handleGenerate}
              disabled={generating || !form.product_id || form.attr1Values.length === 0}
              className="btn-primary text-sm"
            >
              {generating ? t("variants.generating") : t("variants.generate_btn")}
            </button>
            <button onClick={() => { setShowGen(false); setForm({ product_id: null, attr1Name: "", attr1Values: [], attr2Name: "", attr2Values: [] }); setAttr1Input(""); setAttr2Input(""); }} className="btn-ghost text-sm">
              {t("common.cancel")}
            </button>
          </div>
        </div>
      )}

      {loading ? (
        <div className="glass rounded-2xl p-8 text-center">
          <div className="h-4 skeleton rounded w-48 mx-auto" />
          <div className="h-4 skeleton rounded w-32 mx-auto mt-3" />
        </div>
      ) : productEntries.length === 0 ? (
        <div className="glass rounded-2xl overflow-hidden">
          <EmptyState icon={Grid3x3} title={t("variants.empty")} description={t("variants.empty_desc")} />
        </div>
      ) : (
        <>
        <div className="space-y-6">
          {productEntries.map(([productId, variants]) => {
            const productName = variants[0]?.product?.name ?? t("variants.product") + ` #${productId}`;
            const basePrice = variants[0]?.product?.price ?? 0;
            const baseCost = variants[0]?.product?.cost ?? 0;

            return (
              <motion.div
                key={productId}
                initial={{ opacity: 0, y: 8 }}
                animate={{ opacity: 1, y: 0 }}
                className="glass rounded-2xl overflow-hidden"
              >
                <div className="px-6 py-4 border-b border-border flex items-center justify-between">
                  <div>
                    <h3 className="text-sm font-semibold text-foreground">{productName}</h3>
                    <p className="text-xs text-muted mt-0.5">{t("variants.count", { count: String(variants.length) })}</p>
                  </div>
                </div>
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="border-b border-border/30">
                        <th className="px-4 py-3 text-start text-xs font-medium text-muted uppercase tracking-wider">{t("variants.sku")}</th>
                        <th className="px-4 py-3 text-start text-xs font-medium text-muted uppercase tracking-wider">{t("variants.barcode")}</th>
                        <th className="px-4 py-3 text-start text-xs font-medium text-muted uppercase tracking-wider">{variants[0]?.attribute1_name || t("variants.attr_1")}</th>
                        <th className="px-4 py-3 text-start text-xs font-medium text-muted uppercase tracking-wider">{variants[0]?.attribute2_name || t("variants.attr_2")}</th>
                        <th className="px-4 py-3 text-end text-xs font-medium text-muted uppercase tracking-wider">{t("variants.stock")}</th>
                        <th className="px-4 py-3 text-end text-xs font-medium text-muted uppercase tracking-wider">{t("variants.price")}</th>
                        <th className="px-4 py-3 text-end text-xs font-medium text-muted uppercase tracking-wider">{t("variants.cost")}</th>
                        <th className="px-4 py-3 text-center text-xs font-medium text-muted uppercase tracking-wider">{t("variants.active")}</th>
                        <th className="px-4 py-3" />
                      </tr>
                    </thead>
                    <tbody>
                      {variants.map((v) => {
                        const price = basePrice + (v.price_adjustment ?? 0);
                        const cost = baseCost + (v.cost_adjustment ?? 0);
                        return (
                          <tr key={v.id} className="border-b border-border/20 hover:bg-accent-dim/30 transition-colors">
                            <td className="px-4 py-3 font-mono text-xs text-foreground">{v.sku}</td>
                            <td className="px-4 py-3 font-mono text-xs text-muted">{v.barcode || "—"}</td>
                            <td className="px-4 py-3 text-foreground">{v.attribute1_value || "—"}</td>
                            <td className="px-4 py-3 text-foreground">{v.attribute2_value || "—"}</td>
                            <td className="px-4 py-3 text-end">
                              <span className={`inline-flex items-center justify-center min-w-[2rem] px-2 py-0.5 rounded-md text-xs font-medium ${
                                v.stock_quantity === 0 ? "text-red-400 bg-red-500/10" :
                                v.stock_quantity <= 3 ? "text-yellow-400 bg-yellow-500/10" :
                                "text-emerald-400 bg-emerald-500/10"
                              }`}>
                                {v.stock_quantity}
                              </span>
                            </td>
                            <td className="px-4 py-3 text-end font-mono text-xs text-foreground">{toCurrency(price)}</td>
                            <td className="px-4 py-3 text-end font-mono text-xs text-muted">{toCurrency(cost)}</td>
                            <td className="px-4 py-3 text-center">
                              <span className={`inline-block w-2 h-2 rounded-full ${v.is_active ? "bg-emerald-400" : "bg-red-400"}`} />
                            </td>
                            <td className="px-4 py-3 text-end">
                              <button
                                onClick={() => setDeleteTarget(v)}
                                className="p-1.5 rounded-md text-muted hover:text-red-400 hover:bg-red-500/10 transition-colors"
                              >
                                <Trash2 className="w-4 h-4" />
                              </button>
                            </td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              </motion.div>
            );
          })}
        </div>
        <div className="mt-4 glass rounded-2xl overflow-hidden">
          <Pagination total={total} page={page} perPage={perPage} onPageChange={setPage} onPerPageChange={changePageSize} />
        </div>
        </>
      )}

      <ConfirmDialog
        open={deleteTarget !== null}
        onClose={() => setDeleteTarget(null)}
        onConfirm={handleDelete}
        title={t("variants.delete_title")}
        message={t("variants.delete_message", { sku: deleteTarget?.sku ?? "" })}
        confirmLabel={t("common.delete")}
      />
    </motion.div>
  );
}
