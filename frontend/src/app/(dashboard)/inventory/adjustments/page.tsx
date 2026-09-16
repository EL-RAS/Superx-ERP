"use client";

import { useEffect, useState, useCallback, useMemo } from "react";
import { motion } from "framer-motion";
import { useAuthStore } from "@/stores/auth-store";
import { InventoryAdjustments, Products, ProductBatches, autoWasteExpiredBatches, Suppliers, PurchaseOrders } from "@/lib/api";
import type { InventoryAdjustment, Product, ProductBatch, Supplier, PurchaseOrder, JournalEntryLine } from "@/lib/types";
import { formatCurrency } from "@/lib/types";
import { useI18n } from "@/lib/i18n";
import { usePagination } from "@/lib/pagination";
import PageHeader from "@/components/ui/PageHeader";
import DataTable, { type Column } from "@/components/ui/DataTable";
import SlideOver from "@/components/ui/SlideOver";
import ConfirmDialog from "@/components/ui/ConfirmDialog";
import { Plus, PackageMinus, AlertTriangle, UserRound, Store, Trash2, BookOpenCheck } from "lucide-react";

type TabKey = "all" | "waste" | "damage" | "count" | "received" | "return" | "purchase_return";

const TYPE_COLORS: Record<string, string> = {
  waste: "bg-red-500/15 text-red-400 border-red-500/30",
  damage: "bg-amber-500/15 text-amber-400 border-amber-500/30",
  count_deficit: "bg-blue-600/15 text-blue-400 border-blue-600/30",
  count_surplus: "bg-blue-400/15 text-blue-300 border-blue-400/30",
  received: "bg-green-500/15 text-green-400 border-green-500/30",
  return: "bg-violet-500/15 text-violet-400 border-violet-500/30",
  purchase_return: "bg-orange-500/15 text-orange-400 border-orange-500/30",
};

const TYPE_LABELS: Record<string, string> = {
  waste: "inventory_adjustments.type_waste",
  damage: "inventory_adjustments.type_damage",
  count_deficit: "inventory_adjustments.type_count_deficit",
  count_surplus: "inventory_adjustments.type_count_surplus",
  received: "inventory_adjustments.type_received",
  return: "inventory_adjustments.type_return",
  purchase_return: "inventory_adjustments.type_purchase_return",
};

const TAB_LABELS: Record<TabKey, string> = {
  all: "common.all",
  waste: "inventory_adjustments.type_waste",
  damage: "inventory_adjustments.type_damage",
  count: "inventory_adjustments.type_count",
  received: "inventory_adjustments.type_received",
  return: "inventory_adjustments.type_return",
  purchase_return: "inventory_adjustments.type_purchase_return",
};

const DEDUCTION_TYPES = new Set(["waste", "damage", "count_deficit", "return", "purchase_return"]);

const emptyForm = { product_id: "", batch_id: "", type: "waste", quantity: "", notes: "", supplier_id: "", purchase_order_id: "", responsibility: "store" };

export default function AdjustmentsPage() {
  const { token, business } = useAuthStore();
  const { t } = useI18n();
  const [data, setData] = useState<InventoryAdjustment[]>([]);
  const [products, setProducts] = useState<Product[]>([]);
  const [batches, setBatches] = useState<ProductBatch[]>([]);
  const [suppliers, setSuppliers] = useState<Supplier[]>([]);
  const [purchaseOrders, setPurchaseOrders] = useState<PurchaseOrder[]>([]);
  const [loading, setLoading] = useState(true);
  const [tab, setTab] = useState<TabKey>("all");
  const [total, setTotal] = useState(0);
  const { page, perPage, setPage, resetPage, changePageSize } = usePagination();
  const [slideOpen, setSlideOpen] = useState(false);
  const [form, setForm] = useState(emptyForm);
  const [saving, setSaving] = useState(false);
  const [toast, setToast] = useState<{ msg: string; ok: boolean } | null>(null);
  const [detailOpen, setDetailOpen] = useState(false);
  const [detail, setDetail] = useState<InventoryAdjustment | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [deleteConfirmOpen, setDeleteConfirmOpen] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [autoWasteLoading, setAutoWasteLoading] = useState(false);

  const isDeduction = DEDUCTION_TYPES.has(form.type);
  const needsBatch = isDeduction && form.type !== "purchase_return";
  const isPurchaseReturn = form.type === "purchase_return";
  const isClaimable = form.type === "waste" || form.type === "damage";
  const isSupplierLiable = isPurchaseReturn || (isClaimable && form.responsibility === "supplier");

  const selectedProduct = products.find((p) => String(p.id) === form.product_id);
  const selectedBatch = batches.find((b) => String(b.id) === form.batch_id);

  const derivedUnitCost = useMemo(() => {
    if (selectedBatch?.cost_per_unit != null && Number(selectedBatch.cost_per_unit) > 0) {
      return Number(selectedBatch.cost_per_unit);
    }
    if (selectedProduct?.cost != null && Number(selectedProduct.cost) > 0) {
      return Number(selectedProduct.cost);
    }
    return null;
  }, [selectedBatch, selectedProduct]);

  const productSupplierIds = useMemo(
    () => Array.from(new Set(batches.map((b) => b.supplier_id).filter((x): x is number => x != null))),
    [batches],
  );
  const productSuppliers = useMemo(
    () => (productSupplierIds.length > 0 ? suppliers.filter((s) => productSupplierIds.includes(s.id)) : suppliers),
    [suppliers, productSupplierIds],
  );

  const fetchData = useCallback(() => {
    if (!token || !business) return;
    setLoading(true);
    const params: Record<string, string | number> = { page, per_page: perPage };
    if (tab !== "all") params.type = tab;
    InventoryAdjustments.list(token, business.id, params)
      .then((r) => {
        setData(r.data);
        setTotal(r.total);
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [token, business, tab, page, perPage]);

  const fetchProducts = useCallback(() => {
    if (!token || !business) return;
    Products.list(token, business.id).then((r) => setProducts(r.data)).catch(() => {});
  }, [token, business]);

  const fetchSuppliers = useCallback(() => {
    if (!token || !business) return;
    Suppliers.list(token, business.id, { per_page: 200 }).then((r) => setSuppliers(r.data)).catch(() => {});
    PurchaseOrders.list(token, business.id, { per_page: 200 }).then((r) => setPurchaseOrders(r.data)).catch(() => {});
  }, [token, business]);

  useEffect(() => {
    const timer = setTimeout(fetchData, 300);
    return () => clearTimeout(timer);
  }, [fetchData]);
  useEffect(() => { fetchProducts(); }, [fetchProducts]);
  useEffect(() => { fetchSuppliers(); }, [fetchSuppliers]);

  useEffect(() => {
    if (!toast) return;
    const id = setTimeout(() => setToast(null), 3500);
    return () => clearTimeout(id);
  }, [toast]);

  const fetchBatchesForProduct = useCallback((productId: string) => {
    if (!token || !business || !productId) { setBatches([]); return; }
    ProductBatches.list(token, business.id, { product_id: productId, is_active: "true" })
      .then((r) => setBatches(r.data))
      .catch(() => setBatches([]));
  }, [token, business]);

  useEffect(() => {
    const timer = setTimeout(() => fetchBatchesForProduct(form.product_id), 300);
    return () => clearTimeout(timer);
  }, [form.product_id, fetchBatchesForProduct]);

  const impactOf = (row: InventoryAdjustment): "ap" | "expense" | "inventory" => {
    if (row.type === "purchase_return" || row.metadata?.responsibility === "supplier" || row.liability_type === "supplier_claim") return "ap";
    if (row.type === "return" || row.type === "received" || row.type === "count_surplus") return "inventory";
    return "expense";
  };

  const partyOf = (row: InventoryAdjustment): string | null => {
    const yes = row.type === "purchase_return" || row.metadata?.responsibility === "supplier" || row.liability_type === "supplier_claim";
    if (!yes) return null;
    const supplierName =
      row.supplier?.name ??
      row.batch?.supplier?.name ??
      suppliers.find((s) => s.id === Number(row.metadata?.supplier_id ?? row.supplier_id))?.name;
    return supplierName ?? t("inventory_adjustments.resp_supplier");
  };

  const columns: Column[] = [
    {
      key: "adjustment_number",
      label: t("inventory_adjustments.transaction"),
      render: (_v, row) => {
        const r = row as unknown as InventoryAdjustment;
        return (
          <div className="leading-tight">
            <p className="font-medium">{r.adjustment_number}</p>
            <p className="text-xs text-muted">{new Date(String(r.created_at)).toLocaleDateString("en-JO", { year: "numeric", month: "short", day: "numeric" })}</p>
          </div>
        );
      },
    },
    {
      key: "product",
      label: t("common.product"),
      render: (v) => (v as Product | undefined)?.name ?? "—",
    },
    {
      key: "batch",
      label: t("common.batch"),
      render: (v) => (v as ProductBatch | undefined)?.batch_number ?? "—",
    },
    {
      key: "type",
      label: t("common.type"),
      render: (v) => {
        const val = String(v);
        const label = TYPE_LABELS[val] ? t(TYPE_LABELS[val]) : val;
        return (
          <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border capitalize ${TYPE_COLORS[val] ?? "bg-muted/20 text-muted border-border/30"}`}>
            {label}
          </span>
        );
      },
    },
    {
      key: "party",
      label: t("inventory_adjustments.responsible_party"),
      render: (_v, row) => {
        const r = row as unknown as InventoryAdjustment;
        if (r.type === "purchase_return" || r.metadata?.responsibility === "supplier" || r.liability_type === "supplier_claim") {
          const isAuto = Boolean(r.metadata?.source_claim_id);
          return (
            <span className="inline-flex items-center gap-1">
              <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium border bg-orange-500/10 text-orange-400 border-orange-500/25 max-w-[170px]">
                <UserRound className="w-3 h-3 shrink-0" />
                <span className="truncate">{partyOf(r)}</span>
              </span>
              {isAuto && (
                <span className="px-1.5 py-0.5 rounded text-[10px] font-medium bg-orange-500/10 text-orange-400 border border-orange-500/20 shrink-0">
                  {t("inventory_adjustments.auto_debit_note")}
                </span>
              )}
            </span>
          );
        }
        return (
          <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium border bg-muted/10 text-muted border-border/40">
            <Store className="w-3 h-3" />
            {t("inventory_adjustments.resp_store")}
          </span>
        );
      },
    },
    {
      key: "impact",
      label: t("inventory_adjustments.financial_impact"),
      render: (_v, row) => {
        const r = row as unknown as InventoryAdjustment;
        const impact = impactOf(r);
        const cls = impact === "ap"
          ? "bg-orange-500/15 text-orange-400 border-orange-500/30"
          : impact === "expense"
            ? "bg-red-500/15 text-red-400 border-red-500/30"
            : "bg-emerald-500/15 text-emerald-400 border-emerald-500/30";
        const label = impact === "ap"
          ? t("inventory_adjustments.impact_ap")
          : impact === "expense"
            ? t("inventory_adjustments.impact_expense")
            : t("inventory_adjustments.impact_inventory");
        return (
          <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium border ${cls}`}>
            {label}
          </span>
        );
      },
    },
    {
      key: "quantity_adjusted",
      label: t("inventory_adjustments.qty"),
      render: (v) => {
        const val = Number(v);
        const isNeg = val < 0;
        return (
          <span className={isNeg ? "text-red-400" : "text-green-400"}>
            {isNeg ? val : `+${val}`}
          </span>
        );
      },
    },
  ];

  const handleTypeChange = (type: string) => {
    setForm((p) => ({
      ...p,
      type,
      supplier_id: "",
      purchase_order_id: "",
      responsibility: type === "purchase_return" ? "supplier" : "store",
    }));
  };

  const handleBatchChange = (bid: string) => {
    const b = batches.find((x) => String(x.id) === bid);
    setForm((p) => ({
      ...p,
      batch_id: bid,
      supplier_id: b?.supplier_id ? String(b.supplier_id) : p.supplier_id,
    }));
  };

  const handleCreate = async () => {
    if (!token || !business || !form.product_id) return;
    setSaving(true);
    try {
      const payload: Record<string, unknown> = {
        product_id: Number(form.product_id),
        type: form.type,
        quantity: Number(form.quantity),
        notes: form.notes || null,
      };
      if (form.batch_id) payload.batch_id = Number(form.batch_id);
      if (isClaimable) payload.responsibility = form.responsibility;
      if (isSupplierLiable) {
        payload.supplier_id = Number(form.supplier_id);
        if (derivedUnitCost != null) payload.unit_cost = derivedUnitCost;
        if (isPurchaseReturn && form.purchase_order_id) payload.purchase_order_id = Number(form.purchase_order_id);
        if (!isPurchaseReturn) payload.generate_purchase_return = true;
      }
      await InventoryAdjustments.create(token, business.id, payload);
      setSlideOpen(false);
      setForm(emptyForm);
      setToast({ msg: t("inventory_adjustments.created"), ok: true });
      fetchData();
    } catch (e: unknown) {
      const msg = e instanceof Error ? e.message : t("inventory_adjustments.create_failed");
      setToast({ msg, ok: false });
    } finally {
      setSaving(false);
    }
  };

  const handleAutoWaste = async () => {
    if (!token || !business) return;
    setAutoWasteLoading(true);
    try {
      const res = await autoWasteExpiredBatches(token, business.id, false);
      setToast({ msg: res.message, ok: true });
      fetchData();
    } catch (e: unknown) {
      setToast({ msg: e instanceof Error ? e.message : t("inventory_adjustments.auto_waste_failed"), ok: false });
    } finally {
      setAutoWasteLoading(false);
    }
  };

  const openDetail = (row: InventoryAdjustment) => {
    setDetail(row);
    setDetailOpen(true);
    if (!token || !business) return;
    setDetailLoading(true);
    InventoryAdjustments.get(token, business.id, row.id)
      .then((r) => setDetail(r as InventoryAdjustment))
      .catch(() => {})
      .finally(() => setDetailLoading(false));
  };

  const handleDelete = async () => {
    if (!token || !business || !detail) return;
    setDeleting(true);
    try {
      await InventoryAdjustments.delete(token, business.id, detail.id);
      setDeleteConfirmOpen(false);
      setDetailOpen(false);
      setDetail(null);
      setToast({ msg: t("inventory_adjustments.deleted"), ok: true });
      fetchData();
    } catch {
      setToast({ msg: t("inventory_adjustments.delete_failed"), ok: false });
    } finally {
      setDeleting(false);
    }
  };

  const renderJournalLine = (line: JournalEntryLine) => {
    const code = line.account?.code ?? "";
    const name = line.account?.name ?? "";
    const isDr = Number(line.debit) > 0;
    const amount = isDr ? Number(line.debit) : Number(line.credit);
    return (
      <tr key={line.id} className="border-b border-border/40">
        <td className="py-1.5 pe-2 text-sm font-medium">
          {isDr ? `${t("inventory_adjustments.debit")} ${code}` : `${t("inventory_adjustments.credit")} ${code}`}
        </td>
        <td className="py-1.5 pe-2 text-sm text-muted">{name || "—"}</td>
        <td className={`py-1.5 text-sm ${isDr ? "text-red-400" : "text-emerald-400"}`}>{formatCurrency(amount)}</td>
      </tr>
    );
  };

  const tabs: [TabKey, string][] = (Object.keys(TAB_LABELS) as TabKey[]).map((key) => [key, t(TAB_LABELS[key])]);

  return (
    <motion.div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.4 }} className="space-y-6">
      {toast && (
        <div className={`fixed top-4 end-4 z-[100] px-4 py-3 rounded-xl text-sm font-medium shadow-lg ${toast.ok ? "bg-green-500/20 text-green-400 border border-green-500/30" : "bg-red-500/20 text-red-400 border border-red-500/30"}`}>
          {toast.msg}
        </div>
      )}

      <PageHeader
        title={t("inventory_adjustments.title")}
        subtitle={t("inventory_adjustments.subtitle_desc")}
        action={
          <div className="flex gap-2">
            <button onClick={handleAutoWaste} disabled={autoWasteLoading}
              className="flex items-center gap-2 px-4 py-2.5 bg-amber-500/10 hover:bg-amber-500/20 text-amber-400 border border-amber-500/30 rounded-xl text-sm font-medium transition-colors disabled:opacity-50">
              <AlertTriangle className="w-4 h-4" />
              {autoWasteLoading ? t("inventory_adjustments.scanning") : t("inventory_adjustments.auto_waste")}
            </button>
            <button onClick={() => { setForm(emptyForm); setSlideOpen(true); }}
              className="flex items-center gap-2 px-4 py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors">
              <Plus className="w-4 h-4" /> {t("inventory_adjustments.create")}
            </button>
          </div>
        }
      />

      <div className="flex flex-wrap gap-2">
        {tabs.map(([key, label]) => (
          <button
            key={key}
            onClick={() => { setTab(key); resetPage(); }}
            className={`px-4 py-2 rounded-xl text-sm font-medium transition-colors ${tab === key ? "bg-primary text-foreground" : "bg-card/80 border border-border text-muted hover:text-foreground hover:bg-card-hover"}`}
          >
            {label}
          </button>
        ))}
      </div>

      <DataTable
        columns={columns}
        data={data as unknown as Record<string, unknown>[]}
        loading={loading}
        emptyMessage={t("inventory_adjustments.empty")}
        emptyIcon={PackageMinus}
        onRowClick={(row) => openDetail(row as unknown as InventoryAdjustment)}
        pagination={{ total, page, perPage, onPageChange: setPage, onPerPageChange: changePageSize }}
      />

      <SlideOver open={slideOpen} onClose={() => setSlideOpen(false)} title={t("inventory_adjustments.create")}>
        <div className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">{t("common.product")}</label>
            <select
              value={form.product_id}
              onChange={(e) => setForm((p) => ({ ...p, product_id: e.target.value, batch_id: "", supplier_id: "", purchase_order_id: "" }))}
              className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors"
            >
              <option value="">{t("inventory_adjustments.select_product")}</option>
              {products.map((p) => (
                <option key={p.id} value={p.id}>{p.name}</option>
              ))}
            </select>
          </div>

          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">{t("common.type")}</label>
            <select
              value={form.type}
              onChange={(e) => handleTypeChange(e.target.value)}
              className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors"
            >
              {(Object.keys(TYPE_LABELS) as string[]).map((val) => (
                <option key={val} value={val}>{t(TYPE_LABELS[val])}</option>
              ))}
            </select>
            <p className="text-xs text-muted mt-1">
              {isDeduction
                ? t("inventory_adjustments.stock_deducted")
                : t("inventory_adjustments.stock_added")}
            </p>
          </div>

          {isClaimable && (
            <div>
              <label className="block text-sm font-medium text-muted mb-1.5">{t("inventory_adjustments.responsibility")}</label>
              <select
                value={form.responsibility}
                onChange={(e) => setForm((p) => ({ ...p, responsibility: e.target.value, supplier_id: "", purchase_order_id: "" }))}
                className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors"
              >
                <option value="store">{t("inventory_adjustments.resp_store")}</option>
                <option value="supplier">{t("inventory_adjustments.resp_supplier_fault")}</option>
              </select>
            </div>
          )}

          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">{t("common.batch")}</label>
            <select
              value={form.batch_id}
              onChange={(e) => handleBatchChange(e.target.value)}
              className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors"
            >
              <option value="">{needsBatch ? t("inventory_adjustments.select_batch") : t("inventory_adjustments.select_batch_optional")}</option>
              {batches.map((b) => (
                <option key={b.id} value={b.id}>
                  {b.batch_number} ({b.quantity - b.quantity_sold} {t("common.available")})
                </option>
              ))}
              {batches.length === 0 && form.product_id && (
                <option disabled>{t("inventory_adjustments.no_batches")}</option>
              )}
            </select>
          </div>

          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">{t("common.quantity")}</label>
            <input
              type="number"
              step={selectedProduct?.is_weighable ? "0.01" : "1"}
              min="0"
              value={form.quantity}
              onChange={(e) => setForm((p) => ({ ...p, quantity: e.target.value }))}
              className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors"
              placeholder={isDeduction ? t("inventory_adjustments.qty_deduct") : t("inventory_adjustments.qty_add")}
            />
            {isDeduction && (
              <p className="text-xs text-muted mt-1">{t("inventory_adjustments.positive_number")}</p>
            )}
          </div>

          {isSupplierLiable && (
            <>
              <div>
                <label className="block text-sm font-medium text-muted mb-1.5">{t("common.supplier")}</label>
                <select
                  value={form.supplier_id}
                  onChange={(e) => setForm((p) => ({ ...p, supplier_id: e.target.value, purchase_order_id: "" }))}
                  className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors"
                >
                  <option value="">{t("inventory_adjustments.select_supplier")}</option>
                  {productSuppliers.map((s) => (
                    <option key={s.id} value={s.id}>{s.name}</option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block text-sm font-medium text-muted mb-1.5">{t("inventory_adjustments.unit_cost")}</label>
                <input
                  type="text"
                  readOnly
                  value={derivedUnitCost != null ? formatCurrency(derivedUnitCost) : "—"}
                  className="w-full px-4 py-2.5 bg-card/40 border border-border rounded-xl text-sm text-muted focus:outline-none cursor-not-allowed"
                />
                <p className="text-xs text-muted mt-1">{t("inventory_adjustments.unit_auto_hint")}</p>
              </div>

              {isPurchaseReturn && (
                <div>
                  <label className="block text-sm font-medium text-muted mb-1.5">{t("inventory_adjustments.purchase_order")}</label>
                  <select
                    value={form.purchase_order_id}
                    onChange={(e) => setForm((p) => ({ ...p, purchase_order_id: e.target.value }))}
                    disabled={!form.supplier_id}
                    className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors disabled:opacity-50"
                  >
                    <option value="">{t("inventory_adjustments.select_po_optional")}</option>
                    {purchaseOrders
                      .filter((po) => form.supplier_id && String(po.supplier_id) === form.supplier_id)
                      .filter((po) => po.status !== "draft" && po.status !== "cancelled")
                      .map((po) => (
                        <option key={po.id} value={po.id}>{po.order_number}</option>
                      ))}
                  </select>
                </div>
              )}

              <div className="p-3 rounded-xl border border-border bg-card/40">
                <p className="text-xs text-muted">
                  {isPurchaseReturn ? t("inventory_adjustments.debit_note_hint") : t("inventory_adjustments.supplier_claim_hint")}
                </p>
              </div>
            </>
          )}

          {!isDeduction && (
            <div className="p-3 bg-blue-500/10 border border-blue-500/20 rounded-xl">
              <p className="text-xs text-blue-400">
                {t("inventory_adjustments.addition_note")}
              </p>
            </div>
          )}

          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">{t("common.notes")}</label>
            <textarea
              rows={3}
              value={form.notes}
              onChange={(e) => setForm((p) => ({ ...p, notes: e.target.value }))}
              className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors resize-none"
              placeholder={t("inventory_adjustments.reason_placeholder")}
            />
          </div>

          <button
            onClick={handleCreate}
            disabled={saving || !form.product_id || !form.quantity || (needsBatch && !form.batch_id) || (isSupplierLiable && !form.supplier_id)}
            className="w-full py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors disabled:opacity-50"
          >
            {saving ? t("common.saving") : t("inventory_adjustments.create_btn")}
          </button>
        </div>
      </SlideOver>

      <SlideOver open={detailOpen} onClose={() => setDetailOpen(false)} title={t("inventory_adjustments.details")}>
        {detail ? (
          <div className="space-y-5">
            <div className="grid grid-cols-2 gap-3">
              <div className="p-3 bg-card/60 border border-border rounded-xl">
                <p className="text-xs text-muted mb-1">{t("inventory_adjustments.transaction")}</p>
                <p className="text-sm font-medium">{detail.adjustment_number}</p>
              </div>
              <div className="p-3 bg-card/60 border border-border rounded-xl">
                <p className="text-xs text-muted mb-1">{t("common.date")}</p>
                <p className="text-sm font-medium">{new Date(detail.created_at).toLocaleDateString("en-JO", { year: "numeric", month: "short", day: "numeric" })}</p>
              </div>
              <div className="p-3 bg-card/60 border border-border rounded-xl">
                <p className="text-xs text-muted mb-1">{t("common.product")}</p>
                <p className="text-sm font-medium">{detail.product?.name ?? "—"}</p>
              </div>
              <div className="p-3 bg-card/60 border border-border rounded-xl">
                <p className="text-xs text-muted mb-1">{t("common.batch")}</p>
                <p className="text-sm font-medium">{detail.batch?.batch_number ?? "—"}</p>
              </div>
              <div className="p-3 bg-card/60 border border-border rounded-xl">
                <p className="text-xs text-muted mb-1">{t("common.type")}</p>
                <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border capitalize ${TYPE_COLORS[detail.type] ?? "bg-muted/20 text-muted border-border/30"}`}>
                  {TYPE_LABELS[detail.type] ? t(TYPE_LABELS[detail.type]) : detail.type}
                </span>
              </div>
              <div className="p-3 bg-card/60 border border-border rounded-xl">
                <p className="text-xs text-muted mb-1">{t("inventory_adjustments.qty")}</p>
                <p className={`text-sm font-medium ${Number(detail.quantity_adjusted) < 0 ? "text-red-400" : "text-green-400"}`}>
                  {String(detail.quantity_adjusted).startsWith("-") ? detail.quantity_adjusted : `+${detail.quantity_adjusted}`}
                </p>
              </div>
              <div className="p-3 bg-card/60 border border-border rounded-xl">
                <p className="text-xs text-muted mb-1">{t("inventory_adjustments.unit_cost")}</p>
                <p className="text-sm font-medium">{detail.unit_cost != null ? formatCurrency(Number(detail.unit_cost)) : "—"}</p>
              </div>
              <div className="p-3 bg-card/60 border border-border rounded-xl">
                <p className="text-xs text-muted mb-1">{t("common.status")}</p>
                <p className="text-sm font-medium capitalize">{detail.status ?? "—"}</p>
              </div>
            </div>

            <div className="p-3 bg-card/60 border border-border rounded-xl">
              <p className="text-xs text-muted mb-1">{t("inventory_adjustments.responsible_party")}</p>
              {detail.type === "purchase_return" || detail.metadata?.responsibility === "supplier" || detail.liability_type === "supplier_claim" ? (
                <div className="flex flex-wrap items-center gap-2">
                  <span className="inline-flex items-center gap-1.5 text-orange-400">
                    <UserRound className="w-4 h-4" />
                    <span className="text-sm font-medium">{partyOf(detail)}</span>
                  </span>
                  {Boolean(detail.metadata?.source_claim_id) && (
                    <span className="px-1.5 py-0.5 rounded text-[10px] font-medium bg-orange-500/10 text-orange-400 border border-orange-500/20">
                      {t("inventory_adjustments.auto_debit_note")}
                    </span>
                  )}
                </div>
              ) : (
                <span className="inline-flex items-center gap-1.5 text-muted">
                  <Store className="w-4 h-4" />
                  <span className="text-sm font-medium">{t("inventory_adjustments.resp_store")}</span>
                </span>
              )}
              <p className="text-xs text-muted mt-2">
                {t("inventory_adjustments.linked_supplier")}: <span className="text-foreground">{partyOf(detail) ?? "—"}</span>
              </p>
            </div>

            <div className="p-3 bg-card/60 border border-border rounded-xl">
              <p className="text-xs text-muted mb-1">{t("inventory_adjustments.created_by")}</p>
              <p className="text-sm font-medium">{detail.user?.name ?? "—"}</p>
            </div>

            <div className="p-3 bg-card/60 border border-border rounded-xl">
              <p className="text-xs text-muted mb-1">{t("common.notes")}</p>
              <p className="text-sm text-foreground whitespace-pre-wrap">{detail.notes ?? detail.reason ?? "—"}</p>
            </div>

            <div className="p-3 bg-card/60 border border-border rounded-xl">
              <div className="flex items-center gap-2 mb-2">
                <BookOpenCheck className="w-4 h-4 text-muted" />
                <p className="text-sm font-medium">{t("inventory_adjustments.journal_entries")}</p>
              </div>
              {detailLoading ? (
                <p className="text-sm text-muted">{t("common.loading")}</p>
              ) : (detail.journal_entries ?? []).length === 0 ? (
                <p className="text-sm text-muted">{t("inventory_adjustments.journal_none")}</p>
              ) : (
                (detail.journal_entries ?? []).map((entry) => (
                  <div key={entry.id} className="mt-2 first:mt-0">
                    <p className="text-xs font-medium text-muted mb-1">{entry.entry_number} · {entry.description}</p>
                    <table className="w-full text-sm">
                      <thead>
                        <tr className="text-muted border-b border-border">
                          <th className="text-start pb-1.5 font-medium">{t("inventory_adjustments.debit")} / {t("inventory_adjustments.credit")}</th>
                          <th className="text-start pb-1.5 font-medium">{t("common.account")}</th>
                          <th className="text-start pb-1.5 font-medium">{t("common.amount")}</th>
                        </tr>
                      </thead>
                      <tbody>
                        {(entry.lines ?? []).map(renderJournalLine)}
                      </tbody>
                    </table>
                  </div>
                ))
              )}
            </div>
          </div>
        ) : (
          <p className="text-sm text-muted">{t("common.loading")}</p>
        )}

        <div className="mt-6">
          <button
            onClick={() => setDeleteConfirmOpen(true)}
            className="w-full py-2.5 bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/30 rounded-xl text-sm font-medium transition-colors"
          >
            <span className="inline-flex items-center justify-center gap-2">
              <Trash2 className="w-4 h-4" />
              {t("inventory_adjustments.delete_reverse")}
            </span>
          </button>
        </div>
      </SlideOver>

      <ConfirmDialog
        open={deleteConfirmOpen}
        onClose={() => setDeleteConfirmOpen(false)}
        onConfirm={handleDelete}
        title={t("inventory_adjustments.delete_title")}
        message={t("inventory_adjustments.delete_message")}
        confirmLabel={t("common.delete")}
        loading={deleting}
      />
    </motion.div>
  );
}