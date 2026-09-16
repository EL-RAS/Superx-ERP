"use client";

import { useEffect, useState, useCallback } from "react";
import { motion } from "framer-motion";
import { useAuthStore } from "@/stores/auth-store";
import { ProductBatches, Products, Suppliers, GoodsReceipts } from "@/lib/api";
import type { ProductBatch, Product, Supplier, GoodsReceipt } from "@/lib/types";
import { formatCurrency } from "@/lib/types";
import { usePagination } from "@/lib/pagination";
import { useI18n } from "@/lib/i18n";
import PageHeader from "@/components/ui/PageHeader";
import DataTable from "@/components/ui/DataTable";
import type { Column } from "@/components/ui/DataTable";
import SlideOver from "@/components/ui/SlideOver";
import ConfirmDialog from "@/components/ui/ConfirmDialog";
import KPICard from "@/components/ui/KPICard";
import { AlertTriangle, Layers, Plus, Search } from "lucide-react";

function getBatchStatus(qty: number, expiry: string | null, t: (key: string) => string): { label: string; color: string } {
  if (qty === 0) return { label: t("batches.status_depleted"), color: "bg-gray-500/20 text-gray-400 border-gray-500/30" };
  if (!expiry) return { label: t("batches.status_active"), color: "bg-green-500/20 text-green-400 border-green-500/30" };
  const d = new Date(expiry);
  const now = new Date();
  if (d < now) return { label: t("batches.status_expired"), color: "bg-red-500/20 text-red-400 border-red-500/30" };
  const diff = (d.getTime() - now.getTime()) / (1000 * 60 * 60 * 24);
  if (diff <= 30) return { label: t("batches.status_near_expiry"), color: "bg-yellow-500/20 text-yellow-400 border-yellow-500/30" };
  return { label: t("batches.status_active"), color: "bg-green-500/20 text-green-400 border-green-500/30" };
}

const SOURCE_LABELS: Record<string, string> = {
  goods_receipt: "batches.source_goods_receipt",
  opening_stock: "batches.source_opening_stock",
  stock_count_finding: "batches.source_stock_count_finding",
  manual_entry: "batches.source_manual_entry",
};

const SOURCE_COLORS: Record<string, string> = {
  goods_receipt: "bg-green-500/15 text-green-400 border-green-500/30",
  opening_stock: "bg-blue-500/15 text-blue-400 border-blue-500/30",
  stock_count_finding: "bg-indigo-500/15 text-indigo-400 border-indigo-500/30",
  manual_entry: "bg-amber-500/15 text-amber-400 border-amber-500/30",
};

const MANUAL_SOURCE_KEYS = ["opening_stock", "stock_count_finding", "manual_entry"];

const emptyForm = {
  product_id: "",
  batch_number: "",
  quantity: "",
  total_cost: "",
  expiry_date: "",
  manufacturing_date: "",
  storage_location: "",
  source_type: "manual_entry",
  supplier_id: "",
};

export default function BatchesPage() {
  const { token, business, config } = useAuthStore();
  const { t } = useI18n();
  const showExpiry = config?.modules.includes("expiry_tracking") ?? false;
  const [data, setData] = useState<ProductBatch[]>([]);
  const [total, setTotal] = useState(0);
  const [products, setProducts] = useState<Product[]>([]);
  const [suppliers, setSuppliers] = useState<Supplier[]>([]);
  const [valueAtRisk, setValueAtRisk] = useState(0);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState("");
  const { page, perPage, setPage, resetPage, changePageSize } = usePagination();
  const [slideOpen, setSlideOpen] = useState(false);
  const [editing, setEditing] = useState<ProductBatch | null>(null);
  const [form, setForm] = useState(emptyForm);
  const [saving, setSaving] = useState(false);
  const [deleteConfirmOpen, setDeleteConfirmOpen] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [previewGrn, setPreviewGrn] = useState<GoodsReceipt | null>(null);
  const [previewOpen, setPreviewOpen] = useState(false);
  const [previewLoading, setPreviewLoading] = useState(false);

  const selectedProduct = products.find((p) => String(p.id) === form.product_id);
  const isPiece = selectedProduct?.unit === "pcs";

  const generateBatchNumber = (productId: string): string => {
    if (!productId) return "";
    const p = products.find((x) => String(x.id) === productId);
    if (!p?.sku) return "";
    const now = new Date();
    const y = now.getFullYear();
    const m = String(now.getMonth() + 1).padStart(2, "0");
    const d = String(now.getDate()).padStart(2, "0");
    return `${p.sku}-B-${y}${m}${d}`;
  };

  const columns: Column[] = [
    { key: "batch_number", label: t("batches.batch_number") },
    {
      key: "product",
      label: t("common.product"),
      render: (v) => ((v as Record<string, unknown>)?.name as string) || "—",
    },
    { key: "quantity", label: t("batches.quantity"), type: "number" },
    {
      key: "unit_total_cost",
      label: t("batches.unit_total_cost"),
      render: (_v, row) => {
        const b = row as unknown as ProductBatch;
        const unit = Number(b.cost_per_unit ?? 0);
        const total = Number(b.total_cost ?? 0);
        return (
          <div className="leading-tight">
            <p className="text-sm text-foreground">{formatCurrency(unit)} <span className="text-[10px] text-muted">/</span></p>
            <p className="text-xs text-muted">{formatCurrency(total)}</p>
          </div>
        );
      },
    },
    {
      key: "source_type",
      label: t("batches.source"),
      render: (_v, row) => {
        const b = row as unknown as ProductBatch;
        const val = String(b.source_type ?? "");
        const labelKey = SOURCE_LABELS[val];
        const grn = b.goods_receipt;
        if (val === "goods_receipt" && grn?.receipt_number) {
          const supplierName = b.supplier?.name;
          return (
            <button
              type="button"
              title={t("batches.source_goods_receipt")}
              onClick={(e) => {
                e.stopPropagation();
                openGrnPreview(grn.id);
              }}
              className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium border transition-colors ${SOURCE_COLORS[val] ?? "bg-muted/20 text-muted border-border/30"} hover:opacity-80`}
            >
              {grn.receipt_number}
              {supplierName ? <span className="opacity-75">({supplierName})</span> : null}
            </button>
          );
        }
        return (
          <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium border capitalize ${SOURCE_COLORS[val] ?? "bg-muted/20 text-muted border-border/30"}`}>
            {labelKey ? t(labelKey) : val || "—"}
          </span>
        );
      },
    },
    {
      key: "expiry_date",
      label: t("batches.expiry_date"),
      render: (v) => {
        const val = v as string | null;
        return val ? new Date(val).toLocaleDateString("en-JO") : "—";
      },
    },
    {
      key: "batch_status",
      label: t("common.status"),
      render: (_v, row) => {
        const r = row as unknown as ProductBatch;
        const status = getBatchStatus(Number(r.quantity ?? 0), r.expiry_date ?? null, t);
        return (
          <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium border ${status.color}`}>
            {status.label}
          </span>
        );
      },
    },
  ];

  const fetchData = useCallback(() => {
    if (!token || !business) return;
    setLoading(true);
    const params: Record<string, string | number> = { page, per_page: perPage };
    if (search.trim()) params.search = search.trim();
    ProductBatches.list(token, business.id, params)
      .then((res) => {
        setData(res.data);
        setTotal(res.total);
        if (res.value_at_risk != null) setValueAtRisk(res.value_at_risk);
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [token, business, search, page, perPage]);

  const fetchProducts = useCallback(() => {
    if (!token || !business) return;
    Products.list(token, business.id)
      .then((res) => setProducts(res.data))
      .catch(() => {});
  }, [token, business]);

  const fetchSuppliers = useCallback(() => {
    if (!token || !business) return;
    Suppliers.list(token, business.id, { per_page: 200 })
      .then((res) => setSuppliers(res.data))
      .catch(() => {});
  }, [token, business]);

  const openGrnPreview = useCallback((grnId: number) => {
    if (!token || !business) return;
    setPreviewLoading(true);
    setPreviewOpen(true);
    GoodsReceipts.get(token, business.id, grnId)
      .then((res) => setPreviewGrn(res as GoodsReceipt))
      .catch(() => setPreviewOpen(false))
      .finally(() => setPreviewLoading(false));
  }, [token, business]);

  useEffect(() => {
    const t = setTimeout(fetchData, 300);
    return () => clearTimeout(t);
  }, [fetchData]);

  useEffect(() => {
    fetchProducts();
  }, [fetchProducts]);

  useEffect(() => {
    fetchSuppliers();
  }, [fetchSuppliers]);

  const openCreate = () => {
    setEditing(null);
    setForm(emptyForm);
    setSlideOpen(true);
  };

  const openEdit = (row: Record<string, unknown>) => {
    const b = row as unknown as ProductBatch;
    setEditing(b);
      setForm({
        product_id: String(b.product_id),
        batch_number: b.batch_number,
        quantity: String(b.quantity),
        total_cost: String(b.total_cost ?? ((b.cost_per_unit ?? 0) * (b.quantity ?? 0))),
        expiry_date: b.expiry_date?.slice(0, 10) ?? "",
        manufacturing_date: b.manufacturing_date?.slice(0, 10) ?? "",
        storage_location: b.storage_location ?? "",
        source_type: b.source_type ?? "manual_entry",
        supplier_id: b.supplier_id ? String(b.supplier_id) : "",
      });
    setSlideOpen(true);
  };

  const handleSave = async () => {
    if (!token || !business || !form.batch_number) return;
    setSaving(true);
    try {
      const payload = {
        product_id: Number(form.product_id),
        batch_number: form.batch_number,
        quantity: Number(form.quantity),
        total_cost: Number(form.total_cost),
        expiry_date: form.expiry_date || null,
        manufacturing_date: form.manufacturing_date || null,
        storage_location: form.storage_location || null,
        supplier_id: form.supplier_id ? Number(form.supplier_id) : null,
      };
      if (!editing) {
        (payload as Record<string, unknown>).source_type = form.source_type;
      }
      if (editing) {
        await ProductBatches.update(token, business.id, editing.id, payload);
      } else {
        await ProductBatches.create(token, business.id, payload);
      }
      setSlideOpen(false);
      fetchData();
    } catch {
    } finally {
      setSaving(false);
    }
  };

  return (
    <motion.div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.4 }}>
      <PageHeader
        title={t("batches.title")}
        subtitle={t("batches.subtitle", { count: String(data.length) })}
        action={
          <button onClick={openCreate} className="flex items-center gap-2 px-4 py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors">
            <Plus className="w-4 h-4" /> {t("batches.add")}
          </button>
        }
      />

      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
        <KPICard icon={AlertTriangle} label={t("batches.value_at_risk")} value={valueAtRisk} format="currency" accent="red" />
      </div>

      <div className="mb-4">
        <div className="relative max-w-sm">
          <Search className="absolute start-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted" />
          <input
            type="text"
            placeholder={t("batches.search")}
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              resetPage();
            }}
            className="w-full ps-10 pe-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors"
          />
        </div>
      </div>

      <DataTable
        columns={columns}
        data={data as unknown as Record<string, unknown>[]}
        loading={loading}
        emptyMessage={t("batches.empty")}
        emptyIcon={Layers}
        onRowClick={openEdit}
        pagination={{ total, page, perPage, onPageChange: setPage, onPerPageChange: changePageSize }}
      />

      <SlideOver open={slideOpen} onClose={() => setSlideOpen(false)} title={editing ? t("batches.edit") : t("batches.create")}>
        <div className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">{t("common.product")}</label>
            <select
              value={form.product_id}
              onChange={(e) => {
                const pid = e.target.value;
                setForm((p) => ({
                  ...p,
                  product_id: pid,
                  batch_number: !editing ? generateBatchNumber(pid) || p.batch_number : p.batch_number,
                }));
              }}
              className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors"
            >
              <option value="">{t("batches.select_product")}</option>
              {products.map((p) => (
                <option key={p.id} value={p.id}>{p.name}</option>
              ))}
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">{t("batches.batch_number")}</label>
            <input
              type="text"
              value={form.batch_number}
              onChange={(e) => setForm((p) => ({ ...p, batch_number: e.target.value }))}
              className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors"
            />
          </div>
          {!editing && (
            <div>
              <label className="block text-sm font-medium text-muted mb-1.5">{t("batches.source")}</label>
              <select
                value={form.source_type}
                onChange={(e) => setForm((p) => ({ ...p, source_type: e.target.value }))}
                className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors"
              >
                {(MANUAL_SOURCE_KEYS as string[]).map((val) => (
                  <option key={val} value={val}>{t(SOURCE_LABELS[val])}</option>
                ))}
              </select>
            </div>
          )}
          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">{t("common.supplier")}</label>
            <select
              value={form.supplier_id}
              onChange={(e) => setForm((p) => ({ ...p, supplier_id: e.target.value }))}
              className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors"
            >
              <option value="">{t("batches.select_supplier")}</option>
              {suppliers.map((s) => (
                <option key={s.id} value={s.id}>{s.name}</option>
              ))}
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">{t("batches.quantity")}</label>
            <input
              type="number"
              step={isPiece ? "1" : "0.01"}
              min="0"
              value={form.quantity}
              onChange={(e) => setForm((p) => ({ ...p, quantity: e.target.value }))}
              className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors"
            />
            {selectedProduct && <p className="text-xs text-muted mt-0.5">{isPiece ? t("batches.whole_numbers_only") : t("batches.decimal_allowed")}</p>}
          </div>
          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">{t("batches.total_cost")}</label>
            <input
              type="number"
              step="0.001"
              min="0"
              value={form.total_cost}
              onChange={(e) => setForm((p) => ({ ...p, total_cost: e.target.value }))}
              className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors"
            />
            {Number(form.quantity) > 0 && Number(form.total_cost) > 0 && (
              <p className="text-xs text-muted mt-0.5">
                {t("batches.unit_cost", { cost: formatCurrency(Number(form.total_cost) / Number(form.quantity)) })}
              </p>
            )}
          </div>
          {showExpiry && (
            <div>
              <label className="block text-sm font-medium text-muted mb-1.5">{t("batches.expiry_date")}</label>
              <input
                type="date"
                value={form.expiry_date}
                onChange={(e) => setForm((p) => ({ ...p, expiry_date: e.target.value }))}
                className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors"
              />
            </div>
          )}
          {showExpiry && (
            <div>
              <label className="block text-sm font-medium text-muted mb-1.5">{t("batches.manufactured_date")}</label>
              <input
                type="date"
                value={form.manufacturing_date}
                onChange={(e) => setForm((p) => ({ ...p, manufacturing_date: e.target.value }))}
                className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors"
              />
            </div>
          )}
          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">{t("batches.shelf")}</label>
            <input
              type="text"
              value={form.storage_location}
              onChange={(e) => setForm((p) => ({ ...p, storage_location: e.target.value }))}
              placeholder={t("batches.shelf_placeholder")}
              className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors"
            />
          </div>
          <div className="flex gap-3">
            {editing && (
              <button type="button" onClick={() => setDeleteConfirmOpen(true)}
                className="flex-1 py-2.5 bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/30 rounded-xl text-sm font-medium transition-colors">
                {t("common.delete")}
              </button>
            )}
            <button
              onClick={handleSave}
              disabled={saving || !form.batch_number}
              className={`py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors disabled:opacity-50 ${editing ? "flex-1" : "w-full"}`}
            >
              {saving ? t("common.saving") : editing ? t("batches.save") : t("batches.create_btn")}
            </button>
          </div>
        </div>
      </SlideOver>

      <SlideOver open={previewOpen} onClose={() => setPreviewOpen(false)} title={t("batches.source_goods_receipt")}>
        {previewLoading ? (
          <p className="text-sm text-muted">{t("common.loading")}</p>
        ) : previewGrn ? (
          <div className="space-y-4">
            <div className="grid grid-cols-2 gap-3">
              <div className="p-3 bg-card/60 border border-border rounded-xl">
                <p className="text-xs text-muted mb-1">{t("grn.receipt_num")}</p>
                <p className="text-sm font-medium">{previewGrn.receipt_number}</p>
              </div>
              <div className="p-3 bg-card/60 border border-border rounded-xl">
                <p className="text-xs text-muted mb-1">{t("common.supplier")}</p>
                <p className="text-sm font-medium">{previewGrn.supplier?.name ?? "—"}</p>
              </div>
              <div className="p-3 bg-card/60 border border-border rounded-xl">
                <p className="text-xs text-muted mb-1">{t("grn.receipt_date")}</p>
                <p className="text-sm font-medium">{previewGrn.received_at ? new Date(previewGrn.received_at).toLocaleDateString("en-JO") : "—"}</p>
              </div>
              <div className="p-3 bg-card/60 border border-border rounded-xl">
                <p className="text-xs text-muted mb-1">{t("common.total")}</p>
                <p className="text-sm font-medium text-emerald-400">{formatCurrency(Number(previewGrn.total_amount ?? 0))}</p>
              </div>
            </div>
            {previewGrn.purchase_order && (
              <p className="text-sm text-muted">{t("grn.po_num")}: <span className="text-foreground">{previewGrn.purchase_order.order_number}</span></p>
            )}
            <div>
              <p className="text-sm font-medium text-muted mb-2">{t("grn.items")}</p>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="text-muted border-b border-border">
                      <th className="text-start pb-2 font-medium">{t("common.product")}</th>
                      <th className="text-start pb-2 font-medium">{t("common.qty")}</th>
                      <th className="text-start pb-2 font-medium">{t("common.unit_price")}</th>
                      <th className="text-start pb-2 font-medium">{t("common.total")}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {(previewGrn.items ?? []).map((item) => (
                      <tr key={item.id} className="border-b border-border/50">
                        <td className="py-2 pe-2">{item.product?.name ?? item.name ?? "—"}</td>
                        <td className="py-2 pe-2">{item.quantity}</td>
                        <td className="py-2 pe-2">{formatCurrency(item.unit_cost ?? 0)}</td>
                        <td className="py-2">{formatCurrency(item.total ?? 0)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        ) : (
          <p className="text-sm text-muted">{t("batches.empty")}</p>
        )}
      </SlideOver>

      <ConfirmDialog
        open={deleteConfirmOpen}
        onClose={() => setDeleteConfirmOpen(false)}
        onConfirm={async () => {
          if (!token || !business || !editing) return;
          setDeleting(true);
          try {
            await ProductBatches.delete(token, business.id, editing.id);
            setSlideOpen(false);
            setDeleteConfirmOpen(false);
            setEditing(null);
            fetchData();
          } catch {
          } finally {
            setDeleting(false);
          }
        }}
        title={t("batches.delete_title")}
        message={t("batches.delete_message", { batch: editing?.batch_number ?? "" })}
        confirmLabel={t("common.delete")}
        loading={deleting}
      />
    </motion.div>
  );
}
