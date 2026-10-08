"use client";

import { useEffect, useState, useCallback } from "react";
import { motion } from "framer-motion";
import { useAuthStore } from "@/stores/auth-store";
import { ProductBatches, Products, Suppliers, GoodsReceipts, ApiError } from "@/lib/api";
import type { ProductBatch, Product, Supplier, GoodsReceipt } from "@/lib/types";
import { formatCurrency } from "@/lib/types";
import { mapFieldErrors } from "@/lib/validation";
import { usePagination } from "@/lib/pagination";
import { useI18n } from "@/lib/i18n";
import PageHeader from "@/components/ui/PageHeader";
import DataTable from "@/components/ui/DataTable";
import type { Column } from "@/components/ui/DataTable";
import SlideOver from "@/components/ui/SlideOver";
import ConfirmDialog from "@/components/ui/ConfirmDialog";
import SearchableSelect from "@/components/ui/SearchableSelect";
import KPICard from "@/components/ui/KPICard";
import { AlertTriangle, CalendarX2, Layers, Package, Plus, Search } from "lucide-react";

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
  // The product list is fetched server-side on demand (see fetchProductOptions),
  // so only the current selection is held here.
  const [selectedProduct, setSelectedProduct] = useState<Product | null>(null);
  const [suppliers, setSuppliers] = useState<Supplier[]>([]);
  const [summary, setSummary] = useState({ active_value: 0, expiring_soon_value: 0, expired_value: 0 });
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState("");
  const { page, perPage, setPage, resetPage, changePageSize } = usePagination();
  const [slideOpen, setSlideOpen] = useState(false);
  const [editing, setEditing] = useState<ProductBatch | null>(null);
  const [form, setForm] = useState(emptyForm);
  const [saving, setSaving] = useState(false);
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [deleteError, setDeleteError] = useState<string | null>(null);
  const [toast, setToast] = useState<{ msg: string; ok: boolean } | null>(null);
  const [deleteConfirmOpen, setDeleteConfirmOpen] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [previewGrn, setPreviewGrn] = useState<GoodsReceipt | null>(null);
  const [previewOpen, setPreviewOpen] = useState(false);
  const [previewLoading, setPreviewLoading] = useState(false);

  const isPiece = selectedProduct?.unit === "pcs";
  const showBatchExpiry = selectedProduct?.has_batch === true || showExpiry;

  const inputCls = (err?: string) =>
    `w-full px-4 py-2.5 bg-card/80 border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors ${err ? "border-red-500/60" : "border-border"}`;

  const fieldErr = (k: string) =>
    formErrors[k] && <p className="text-xs text-red-400 mt-1">{formErrors[k]}</p>;

  useEffect(() => {
    if (!toast) return;
    const timeout = setTimeout(() => setToast(null), 3500);
    return () => clearTimeout(timeout);
  }, [toast]);

  const validateForm = (): Record<string, string> => {
    const errs: Record<string, string> = {};
    if (!form.product_id) errs.product_id = t("batches.error_product_required");
    if (!form.batch_number.trim()) errs.batch_number = t("batches.error_batch_number_required");
    const qty = Number(form.quantity);
    if (form.quantity === "" || Number.isNaN(qty) || qty < 0 || (!editing && qty === 0)) {
      errs.quantity = t("batches.error_quantity_invalid");
    } else if (isPiece && !Number.isInteger(qty)) {
      errs.quantity = t("batches.error_quantity_invalid");
    }
    const cost = Number(form.total_cost);
    if (form.total_cost === "" || Number.isNaN(cost) || cost < 0) {
      errs.total_cost = t("batches.error_cost_invalid");
    }
    if (
      form.expiry_date &&
      form.manufacturing_date &&
      form.expiry_date < form.manufacturing_date
    ) {
      errs.expiry_date = t("batches.error_expiry_before_manufacturing");
    }
    return errs;
  };

  const generateBatchNumber = (p: Product | null): string => {
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
        if (res.summary) setSummary(res.summary);
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [token, business, search, page, perPage]);

  // Server-side product search. The catalog is paginated (backend default is
  // 10 rows), so the combobox queries it as the user types rather than loading
  // a truncated list up front: 20 per search, 50 for the default open list.
  const fetchProductOptions = useCallback(
    (query: string): Promise<Product[]> => {
      if (!token || !business) return Promise.resolve([]);
      const params: Record<string, string | number> = { per_page: query ? 20 : 50 };
      if (query) params.search = query;
      return Products.list(token, business.id, params).then((res) => res.data);
    },
    [token, business],
  );

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
    fetchSuppliers();
  }, [fetchSuppliers]);

  const openCreate = () => {
    setEditing(null);
    setForm(emptyForm);
    setSelectedProduct(null);
    setFormErrors({});
    setFormError(null);
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
    // index() eager-loads product:id,name,sku,unit,has_batch, so the selector can
    // label the current value immediately without waiting for a search.
    setSelectedProduct(b.product ?? null);
    setFormErrors({});
    setFormError(null);
    setSlideOpen(true);
  };

  const handleSave = async () => {
    if (!token || !business) return;
    const errs = validateForm();
    if (Object.keys(errs).length > 0) {
      setFormErrors(errs);
      setFormError(t("batches.save_failed"));
      setToast({ msg: t("batches.save_failed"), ok: false });
      return;
    }
    setSaving(true);
    setFormErrors({});
    setFormError(null);
    try {
      const payload = {
        product_id: Number(form.product_id),
        batch_number: form.batch_number.trim(),
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
      setToast({ msg: editing ? t("batches.updated") : t("batches.created"), ok: true });
      fetchData();
    } catch (err) {
      const apiError = err as ApiError;
      const msg = apiError?.message || t("batches.save_failed");
      const fieldErrors = mapFieldErrors(apiError, [
        "product_id",
        "batch_number",
        "quantity",
        "total_cost",
        "expiry_date",
        "manufacturing_date",
        "supplier_id",
      ]);
      if (Object.keys(fieldErrors).length > 0) {
        setFormErrors(fieldErrors);
      }
      setFormError(msg);
      setToast({ msg, ok: false });
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

      <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
        <KPICard icon={Package} label={t("batches.active_inventory_value")} value={summary.active_value} format="currency" accent="emerald" />
        <KPICard icon={AlertTriangle} label={t("batches.expiring_soon_value")} value={summary.expiring_soon_value} format="currency" accent="amber" />
        <KPICard icon={CalendarX2} label={t("batches.expired_inventory_value")} value={summary.expired_value} format="currency" accent="red" />
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
          {formError && (
            <p className="px-3 py-2.5 bg-red-500/10 border border-red-500/30 rounded-xl text-xs text-red-400">
              {formError}
            </p>
          )}
          <div>
            <label
              htmlFor="batch-product"
              className="block text-sm font-medium text-muted mb-1.5"
            >
              {t("common.product")}
            </label>
            {/* key forces a fresh query/list when switching between create and edit */}
            <SearchableSelect<Product>
              id="batch-product"
              key={editing ? `edit-${editing.id}` : "create"}
              value={form.product_id}
              selectedOption={selectedProduct}
              fetchOptions={fetchProductOptions}
              initialOptions={editing?.product ? [editing.product] : undefined}
              getOptionId={(p) => String(p.id)}
              renderOption={(p) => {
                const meta = [
                  p.sku ? `${t("common.sku")}: ${p.sku}` : null,
                  p.barcode ? `${t("common.barcode")}: ${p.barcode}` : null,
                ]
                  .filter(Boolean)
                  .join(" · ");
                return (
                  <span className="flex items-baseline gap-2 min-w-0">
                    <span className="truncate">{p.name}</span>
                    {meta && (
                      <span className="shrink-0 text-[11px] font-mono text-muted">{meta}</span>
                    )}
                  </span>
                );
              }}
              onChange={(pid, product) => {
                setSelectedProduct(product);
                setForm((prev) => ({
                  ...prev,
                  product_id: pid,
                  batch_number: !editing
                    ? generateBatchNumber(product) || prev.batch_number
                    : prev.batch_number,
                }));
                setFormErrors((prev) => {
                  if (!prev.product_id) return prev;
                  const next = { ...prev };
                  delete next.product_id;
                  return next;
                });
              }}
              placeholder={t("batches.select_product")}
              searchPlaceholder={t("products.search")}
              emptyLabel={t("products.empty")}
              loadingLabel={t("common.loading")}
            />
            {fieldErr("product_id")}
          </div>
          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">{t("batches.batch_number")}</label>
            <input
              type="text"
              value={form.batch_number}
              onChange={(e) => setForm((p) => ({ ...p, batch_number: e.target.value }))}
              className={inputCls(formErrors.batch_number)}
            />
            {fieldErr("batch_number")}
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
              className={inputCls(formErrors.quantity)}
            />
            {fieldErr("quantity")}
            {!formErrors.quantity && selectedProduct && <p className="text-xs text-muted mt-0.5">{isPiece ? t("batches.whole_numbers_only") : t("batches.decimal_allowed")}</p>}
          </div>
          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">{t("batches.total_cost")}</label>
            <input
              type="number"
              step="0.001"
              min="0"
              value={form.total_cost}
              onChange={(e) => setForm((p) => ({ ...p, total_cost: e.target.value }))}
              className={inputCls(formErrors.total_cost)}
            />
            {fieldErr("total_cost")}
            {Number(form.quantity) > 0 && Number(form.total_cost) > 0 && (
              <p className="text-xs text-muted mt-0.5">
                {t("batches.unit_cost", { cost: formatCurrency(Number(form.total_cost) / Number(form.quantity)) })}
              </p>
            )}
          </div>
          {showBatchExpiry && (
            <div>
              <label className="block text-sm font-medium text-muted mb-1.5">{t("batches.expiry_date")}</label>
              <input
                type="date"
                value={form.expiry_date}
                onChange={(e) => setForm((p) => ({ ...p, expiry_date: e.target.value }))}
                className={inputCls(formErrors.expiry_date)}
              />
              {fieldErr("expiry_date")}
            </div>
          )}
          {showBatchExpiry && (
            <div>
              <label className="block text-sm font-medium text-muted mb-1.5">{t("batches.manufactured_date")}</label>
              <input
                type="date"
                value={form.manufacturing_date}
                onChange={(e) => setForm((p) => ({ ...p, manufacturing_date: e.target.value }))}
                className={inputCls(formErrors.manufacturing_date)}
              />
              {fieldErr("manufacturing_date")}
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
              type="button"
              onClick={handleSave}
              disabled={saving}
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
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
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
              <div className="scroll-x">
                <table className="w-full min-w-[28rem] text-sm">
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
        onClose={() => {
          setDeleteConfirmOpen(false);
          setDeleteError(null);
        }}
        onConfirm={async () => {
          if (!token || !business || !editing) return;
          setDeleting(true);
          setDeleteError(null);
          try {
            await ProductBatches.delete(token, business.id, editing.id);
            setSlideOpen(false);
            setDeleteConfirmOpen(false);
            setEditing(null);
            setToast({ msg: t("batches.deleted"), ok: true });
            fetchData();
          } catch (err) {
            const apiError = err as ApiError;
            setDeleteError(apiError?.message || t("batches.delete_failed"));
          } finally {
            setDeleting(false);
          }
        }}
        title={t("batches.delete_title")}
        message={t("batches.delete_message", { batch: editing?.batch_number ?? "" })}
        error={deleteError ?? undefined}
        confirmLabel={t("common.delete")}
        loading={deleting}
      />

      {toast && (
        <div className={`fixed top-4 end-4 z-[100] px-4 py-3 rounded-xl border text-sm font-medium shadow-lg ${toast.ok ? "bg-emerald-500/10 border-emerald-500/30 text-emerald-400" : "bg-red-500/10 border-red-500/30 text-red-400"}`}>
          {toast.msg}
        </div>
      )}
    </motion.div>
  );
}