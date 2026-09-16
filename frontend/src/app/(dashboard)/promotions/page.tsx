"use client";

import { useEffect, useState, useCallback, useMemo } from "react";
import { motion } from "framer-motion";
import { useAuthStore } from "@/stores/auth-store";
import { Promotions, Products, fetchCategories, ApiError } from "@/lib/api";
import type { Promotion, Product, Category, PromotionType } from "@/lib/types";
import { formatCurrency } from "@/lib/types";
import { mapFieldErrors } from "@/lib/validation";
import { usePagination } from "@/lib/pagination";
import PageHeader from "@/components/ui/PageHeader";
import DataTable from "@/components/ui/DataTable";
import type { Column } from "@/components/ui/DataTable";
import SlideOver from "@/components/ui/SlideOver";
import ConfirmDialog from "@/components/ui/ConfirmDialog";
import { Plus, Loader2, AlertCircle, Search, X, ChevronDown, ChevronUp, DollarSign, BadgePercent, Hash } from "lucide-react";
import { useI18n } from "@/lib/i18n";

const TYPE_COLORS: Record<string, string> = {
  percentage: "bg-blue-500/10 text-blue-400 border-blue-500/30",
  fixed: "bg-purple-500/10 text-purple-400 border-purple-500/30",
  bogo: "bg-emerald-500/10 text-emerald-400 border-emerald-500/30",
  bundle: "bg-amber-500/10 text-amber-400 border-amber-500/30",
  multi_buy: "bg-orange-500/10 text-orange-400 border-orange-500/30",
  category: "bg-cyan-500/10 text-cyan-400 border-cyan-500/30",
  happy_hour: "bg-pink-500/10 text-pink-400 border-pink-500/30",
};

const EFFECTIVENESS_STYLES: Record<string, string> = {
  high_impact: "bg-emerald-500/10 text-emerald-400 border-emerald-500/30",
  low_impact: "bg-amber-500/10 text-amber-400 border-amber-500/30",
  negative_margin: "bg-red-500/10 text-red-400 border-red-500/30",
};

const EFFECTIVENESS_DOTS: Record<string, string> = {
  high_impact: "bg-emerald-400",
  low_impact: "bg-amber-400",
  negative_margin: "bg-red-400",
};

const emptyForm = {
  name: "",
  type: "percentage" as PromotionType,
  value: "",
  start_date: "",
  end_date: "",
  applies_to: "all" as "all" | "specific",
  min_quantity: "",
  min_amount: "",
  buy_quantity: "",
  get_quantity: "",
  discount_value: "",
  max_uses: "",
  combo_products: [] as number[],
  happy_hour_start: "",
  happy_hour_end: "",
  applicable_products: [] as number[],
  category_id: "",
  is_active: true,
};

export default function PromotionsPage() {
  const { t, locale } = useI18n();
  const { token, business } = useAuthStore();
  const [data, setData] = useState<Promotion[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState("");
  const { page, perPage, setPage, resetPage, changePageSize } = usePagination();
  const [slideOpen, setSlideOpen] = useState(false);
  const [editing, setEditing] = useState<Promotion | null>(null);
  const [form, setForm] = useState(emptyForm);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [deleteConfirm, setDeleteConfirm] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [toast, setToast] = useState<{ msg: string; type: "success" | "error" } | null>(null);
  const [categories, setCategories] = useState<Category[]>([]);
  const [productOptions, setProductOptions] = useState<Product[]>([]);
  const [productSearch, setProductSearch] = useState("");
  const [productListOpen, setProductListOpen] = useState(false);
  const [advancedOpen, setAdvancedOpen] = useState(false);

  const showToast = useCallback((msg: string, type: "success" | "error") => {
    setToast({ msg, type });
    window.setTimeout(() => setToast(null), 3000);
  }, []);

  const fetchData = useCallback(() => {
    if (!token || !business) return;
    setLoading(true);
    const params: Record<string, string | number> = { page, per_page: perPage };
    if (search.trim()) params.search = search.trim();
    Promotions.list(token, business.id, params)
      .then((res) => {
        const rows = Array.isArray(res) ? res : (res as { data: Promotion[] }).data;
        setData(rows);
        if (!Array.isArray(res)) setTotal((res as { total?: number }).total ?? rows.length);
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [token, business, search, page, perPage]);

  useEffect(() => {
    const timer = setTimeout(fetchData, 300);
    return () => clearTimeout(timer);
  }, [fetchData]);

  useEffect(() => {
    if (!token || !business) return;
    fetchCategories(token, business.id)
      .then(setCategories)
      .catch(() => setCategories([]));
    Products.list(token, business.id, { per_page: 200 })
      .then((res) => setProductOptions(Array.isArray(res) ? res : (res as { data: Product[] }).data))
      .catch(() => setProductOptions([]));
  }, [token, business]);

  const productById = useMemo(() => {
    const map = new Map<number, Product>();
    productOptions.forEach((p) => map.set(p.id, p));
    return map;
  }, [productOptions]);

  const typeLabel = (type: string) => t(`promotions.type_${type}`);

  const isBundle = form.type === "bundle";
  const isCategory = form.type === "category";
  const isMultiBuy = form.type === "multi_buy";
  const isHappyHour = form.type === "happy_hour";
  const isPercentLike = form.type === "percentage" || form.type === "category" || isHappyHour;
  const showsAppliesToSelect = !isBundle && !isCategory;
  const showsProductPicker = isBundle || (!isCategory && form.applies_to === "specific");

  const valueHint = isPercentLike
    ? t("promotions.value_percent")
    : isMultiBuy
      ? t("promotions.value_bundle")
      : isBundle
        ? t("promotions.value_combo")
        : t("promotions.value_fixed");

  const totals = useMemo(() => {
    let revenue = 0;
    let discount = 0;
    let uses = 0;
    data.forEach((p) => {
      revenue += p.stats?.total_revenue ?? 0;
      discount += p.stats?.total_discount ?? 0;
      uses += p.stats?.times_used ?? 0;
    });
    return { revenue, discount, uses };
  }, [data]);

  const columns: Column[] = [
    { key: "name", label: t("common.name") },
    {
      key: "type",
      label: t("promotions.type"),
      render: (v) => (
        <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border ${TYPE_COLORS[String(v)] ?? TYPE_COLORS.percentage}`}>
          {typeLabel(String(v))}
        </span>
      ),
    },
    {
      key: "value",
      label: t("promotions.value"),
      render: (v, row) => {
        const p = row as unknown as Promotion;
        if (p.type === "percentage" || p.type === "category" || p.type === "happy_hour") return `${v}%`;
        return formatCurrency(Number(v), locale);
      },
    },
    { key: "start_date", label: t("promotions.start_date"), render: (v) => String(v ?? "").slice(0, 10) },
    { key: "end_date", label: t("promotions.end_date"), render: (v) => String(v ?? "").slice(0, 10) },
    {
      key: "is_active",
      label: t("common.status"),
      render: (v, row) => (
        <button
          onClick={(e) => { e.stopPropagation(); toggleActive(row as unknown as Promotion); }}
          className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium border transition-colors cursor-pointer ${
            v === true ? "bg-emerald-500/10 text-emerald-400 border-emerald-500/30 hover:bg-emerald-500/20" : "bg-gray-500/10 text-gray-400 border-gray-500/30 hover:bg-gray-500/20"
          }`}
        >
          <span className={`w-1.5 h-1.5 rounded-full ${v === true ? "bg-emerald-400" : "bg-gray-400"}`} />
          {v === true ? t("common.active") : t("common.inactive")}
        </button>
      ),
    },
    {
      key: "total_revenue",
      label: t("promotions.revenue_generated"),
      render: (_v, row) => formatCurrency(Number((row as unknown as Promotion).stats?.total_revenue ?? 0), locale),
    },
    {
      key: "total_discount",
      label: t("promotions.total_discount_given"),
      render: (_v, row) => formatCurrency(Number((row as unknown as Promotion).stats?.total_discount ?? 0), locale),
    },
    {
      key: "times_used",
      label: t("promotions.usage_count"),
      render: (_v, row) => `${Number((row as unknown as Promotion).stats?.times_used ?? 0)} ${t("promotions.redemptions")}`,
    },
    {
      key: "effectiveness",
      label: t("promotions.effectiveness"),
      render: (_v, row) => {
        const eff = (row as unknown as Promotion).stats?.effectiveness ?? "low_impact";
        return (
          <span className={`inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium border ${EFFECTIVENESS_STYLES[eff] ?? EFFECTIVENESS_STYLES.low_impact}`}>
            <span className={`w-1.5 h-1.5 rounded-full ${EFFECTIVENESS_DOTS[eff] ?? EFFECTIVENESS_DOTS.low_impact}`} />
            {t(`promotions.effectiveness_${eff}`)}
          </span>
        );
      },
    },
  ];

  const openCreate = () => {
    setEditing(null);
    setForm(emptyForm);
    setErrors({});
    setProductSearch("");
    setProductListOpen(false);
    setAdvancedOpen(false);
    setSlideOpen(true);
  };

  const openEdit = (row: Record<string, unknown>) => {
    const p = row as unknown as Promotion;
    const isBnd = p.type === "bundle";
    const hasApplicable = (p.applicable_products?.length ?? 0) > 0 || (p.combo_products?.length ?? 0) > 0;
    setEditing(p);
    setErrors({});
    setProductSearch("");
    setProductListOpen(false);
    setAdvancedOpen(false);
    setForm({
      name: p.name,
      type: p.type as PromotionType,
      value: isBnd
        ? String(p.discount_value ?? p.value ?? 0)
        : p.value != null ? String(p.value) : "",
      start_date: p.start_date ? String(p.start_date).slice(0, 10) : "",
      end_date: p.end_date ? String(p.end_date).slice(0, 10) : "",
      applies_to: hasApplicable ? "specific" : "all",
      min_quantity: p.min_quantity != null ? String(p.min_quantity) : "",
      min_amount: p.min_amount != null ? String(p.min_amount) : "",
      buy_quantity: p.buy_quantity != null ? String(p.buy_quantity) : "",
      get_quantity: p.get_quantity != null ? String(p.get_quantity) : "",
      discount_value: p.discount_value != null ? String(p.discount_value) : "",
      max_uses: p.max_uses != null ? String(p.max_uses) : "",
      combo_products: p.combo_products ?? [],
      happy_hour_start: p.happy_hour_start ?? "",
      happy_hour_end: p.happy_hour_end ?? "",
      applicable_products: isBnd ? (p.combo_products ?? []) : (p.applicable_products ?? []),
      category_id: p.category_id != null ? String(p.category_id) : "",
      is_active: p.is_active,
    });
    setSlideOpen(true);
  };

  const set = (patch: Partial<typeof emptyForm>) => setForm((prev) => ({ ...prev, ...patch }));

  const clearErr = (key: string) => setErrors((p) => (p[key] ? { ...p, [key]: "" } : p));

  const toggleProduct = (id: number) => {
    setForm((prev) => ({
      ...prev,
      applicable_products: prev.applicable_products.includes(id) ? prev.applicable_products.filter((x) => x !== id) : [...prev.applicable_products, id],
    }));
    clearErr("applicable_products");
  };

  const filteredProducts = useMemo(() => {
    if (!productSearch.trim()) return productOptions;
    const q = productSearch.trim().toLowerCase();
    return productOptions.filter((p) => p.name.toLowerCase().includes(q) || String(p.sku ?? "").toLowerCase().includes(q));
  }, [productOptions, productSearch]);

  const validateForm = (): Record<string, string> => {
    const fe: Record<string, string> = {};
    if (!form.name.trim()) fe.name = t("promotions.name_required");
    if (!form.type) fe.type = t("promotions.type_required");

    const valueNum = parseFloat(form.value);
    if (form.value.trim() === "" || isNaN(valueNum)) fe.value = t("promotions.value_required");
    else if (valueNum <= 0) fe.value = t("promotions.value_positive");

    if (!form.start_date) fe.start_date = t("promotions.start_date_required");
    if (!form.end_date) fe.end_date = t("promotions.end_date_required");
    else if (form.start_date && form.end_date <= form.start_date) fe.end_date = t("promotions.end_date_after_start");

    if (isMultiBuy && !form.min_quantity) fe.min_quantity = t("promotions.bundle_size_required");
    if (form.type === "bogo") {
      if (!form.buy_quantity) fe.buy_quantity = t("promotions.buy_quantity_required");
      if (!form.get_quantity) fe.get_quantity = t("promotions.get_quantity_required");
    }
    if (isCategory && !form.category_id) fe.category_id = t("promotions.category_required");
    if (isBundle && form.applicable_products.length === 0) fe.applicable_products = t("promotions.combo_required");
    if (showsAppliesToSelect && form.applies_to === "specific" && form.applicable_products.length === 0) fe.applicable_products = t("promotions.products_required");

    if (isHappyHour) {
      if (!form.happy_hour_start) fe.happy_hour_start = t("promotions.happy_hour_start_required");
      if (!form.happy_hour_end) fe.happy_hour_end = t("promotions.happy_hour_end_required");
    }

    return fe;
  };

  const handleSave = async () => {
    if (!token || !business) return;
    const fieldErrors = validateForm();
    if (Object.keys(fieldErrors).length > 0) {
      setErrors(fieldErrors);
      showToast(Object.values(fieldErrors)[0], "error");
      return;
    }
    setSaving(true);
    try {
      const payload: Record<string, unknown> = {
        name: form.name.trim(),
        type: form.type,
        value: Number(form.value),
        start_date: form.start_date,
        end_date: form.end_date ? `${form.end_date}T23:59:59` : "",
        is_active: form.is_active,
      };
      if (form.min_quantity) payload.min_quantity = Number(form.min_quantity);
      if (form.min_amount) payload.min_amount = Number(form.min_amount);
      if (form.buy_quantity) payload.buy_quantity = Number(form.buy_quantity);
      if (form.get_quantity) payload.get_quantity = Number(form.get_quantity);
      if (form.max_uses) payload.max_uses = Number(form.max_uses);
      if (form.category_id) payload.category_id = Number(form.category_id);
      if (form.happy_hour_start) payload.happy_hour_start = form.happy_hour_start;
      if (form.happy_hour_end) payload.happy_hour_end = form.happy_hour_end;

      if (isBundle) {
        if (form.applicable_products.length > 0) payload.combo_products = form.applicable_products;
        payload.discount_value = Number(form.value);
      } else if (form.applicable_products.length > 0) {
        payload.applicable_products = form.applicable_products;
      }

      if (editing) {
        await Promotions.update(token, business.id, editing.id, payload);
        showToast(t("promotions.updated"), "success");
      } else {
        await Promotions.create(token, business.id, payload);
        showToast(t("promotions.created"), "success");
      }
      setSlideOpen(false);
      setErrors({});
      fetchData();
    } catch (err) {
      const apiErr = err instanceof ApiError ? err : null;
      setErrors(apiErr ? mapFieldErrors(apiErr, [
        "name", "type", "value", "start_date", "end_date",
        "min_quantity", "min_amount", "buy_quantity", "get_quantity",
        "discount_value", "max_uses", "happy_hour_start", "happy_hour_end",
        "category_id", "combo_products", "applicable_products",
      ]) : {});
      showToast(apiErr?.message || t("common.error"), "error");
    } finally {
      setSaving(false);
    }
  };

  const toggleActive = async (p: Promotion) => {
    if (!token || !business) return;
    try {
      await Promotions.update(token, business.id, p.id, { is_active: !p.is_active });
      fetchData();
    } catch {
      showToast(t("common.error"), "error");
    }
  };

  const handleDelete = async () => {
    if (!token || !business || !editing) return;
    setDeleting(true);
    try {
      await Promotions.delete(token, business.id, editing.id);
      setDeleteConfirm(false);
      setSlideOpen(false);
      showToast(t("promotions.deleted"), "success");
      fetchData();
    } catch {
      showToast(t("common.error"), "error");
    } finally {
      setDeleting(false);
    }
  };

  const err = (key: string) =>
    errors[key] ? (
      <p className="text-xs text-red-400 mt-1 flex items-center gap-1">
        <AlertCircle className="w-3 h-3" /> {errors[key]}
      </p>
    ) : null;

  const inputCls = "w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors";
  const fieldCls = (key: string) => `${inputCls} ${errors[key] ? "!border-red-500/70" : ""}`;

  return (
    <motion.div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.4 }}>
      <PageHeader
        title={t("promotions.title")}
        subtitle={t("promotions.subtitle", { count: String(data.length) })}
        action={
          <button onClick={openCreate} className="flex items-center gap-2 px-4 py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors">
            <Plus className="w-4 h-4" /> {t("promotions.create_btn")}
          </button>
        }
      />

      {toast && (
        <div className={`fixed top-4 end-4 z-[60] px-4 py-3 rounded-xl shadow-lg text-sm font-medium text-white ${toast.type === "success" ? "bg-emerald-600" : "bg-red-600"}`}>
          {toast.msg}
        </div>
      )}

      <div className="flex items-center gap-3 mb-4">
        <div className="relative w-72">
          <Search className="absolute start-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted" />
          <input
            type="text"
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              resetPage();
            }}            placeholder={t("promotions.search")}
            className="w-full ps-10 pe-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors"
          />
        </div>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
        <div className="glass rounded-2xl p-5">
          <div className="flex items-center gap-2 text-xs font-medium text-muted mb-1.5">
            <DollarSign className="w-4 h-4 text-emerald-400" />
            {t("promotions.stats_revenue")}
          </div>
          <p className="text-xl font-semibold">{formatCurrency(totals.revenue, locale)}</p>
        </div>
        <div className="glass rounded-2xl p-5">
          <div className="flex items-center gap-2 text-xs font-medium text-muted mb-1.5">
            <BadgePercent className="w-4 h-4 text-amber-400" />
            {t("promotions.stats_discount")}
          </div>
          <p className="text-xl font-semibold">{formatCurrency(totals.discount, locale)}</p>
        </div>
        <div className="glass rounded-2xl p-5">
          <div className="flex items-center gap-2 text-xs font-medium text-muted mb-1.5">
            <Hash className="w-4 h-4 text-indigo-400" />
            {t("promotions.stats_uses")}
          </div>
          <p className="text-xl font-semibold">{totals.uses} {t("promotions.redemptions")}</p>
        </div>
      </div>

      <div className="glass rounded-2xl overflow-hidden">
        <DataTable
          columns={columns}
          data={data as unknown as Record<string, unknown>[]}
          loading={loading}
          emptyMessage={t("promotions.empty")}
          onRowClick={openEdit}
          pagination={{ total, page, perPage, onPageChange: setPage, onPerPageChange: changePageSize }}
        />
      </div>

      <SlideOver open={slideOpen} onClose={() => setSlideOpen(false)} title={editing ? t("promotions.edit") : t("promotions.create")}>
        <div className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">{t("common.name")}</label>
            <input
              type="text"
              value={form.name}
              onChange={(e) => { set({ name: e.target.value }); clearErr("name"); }}
              className={fieldCls("name")}
            />
            {err("name")}
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-sm font-medium text-muted mb-1.5">{t("promotions.type")}</label>
              <select
                value={form.type}
                onChange={(e) => { set({ type: e.target.value as PromotionType }); clearErr("type"); }}
                className={fieldCls("type")}
              >
                {(["percentage", "fixed", "multi_buy", "bogo", "bundle", "category", "happy_hour"] as PromotionType[]).map((ty) => (
                  <option key={ty} value={ty}>{typeLabel(ty)}</option>
                ))}
              </select>
              {err("type")}
            </div>
            <div>
              <label className="block text-sm font-medium text-muted mb-1.5">{t("promotions.value")}</label>
              <input
                type="number" step="0.01" min="0"
                value={form.value}
                onChange={(e) => { set({ value: e.target.value }); clearErr("value"); }}
                className={fieldCls("value")}
              />
              {err("value")}
              <p className="text-xs text-muted mt-1">{valueHint}</p>
            </div>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-sm font-medium text-muted mb-1.5">{t("promotions.start_date")}</label>
              <input
                type="date"
                value={form.start_date}
                onChange={(e) => { set({ start_date: e.target.value }); clearErr("start_date"); }}
                className={fieldCls("start_date")}
              />
              {err("start_date")}
            </div>
            <div>
              <label className="block text-sm font-medium text-muted mb-1.5">{t("promotions.end_date")}</label>
              <input
                type="date"
                value={form.end_date}
                onChange={(e) => { set({ end_date: e.target.value }); clearErr("end_date"); }}
                className={fieldCls("end_date")}
              />
              {err("end_date")}
            </div>
          </div>

          {isMultiBuy && (
            <div>
              <label className="block text-sm font-medium text-muted mb-1.5">{t("promotions.bundle_size")}</label>
              <input
                type="number" min="1"
                value={form.min_quantity}
                onChange={(e) => { set({ min_quantity: e.target.value }); clearErr("min_quantity"); }}
                placeholder="3"
                className={fieldCls("min_quantity")}
              />
              {err("min_quantity")}
            </div>
          )}

          {form.type === "bogo" && (
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="block text-sm font-medium text-muted mb-1.5">{t("promotions.buy_quantity")}</label>
                <input
                  type="number" min="1"
                  value={form.buy_quantity}
                  onChange={(e) => { set({ buy_quantity: e.target.value }); clearErr("buy_quantity"); }}
                  className={fieldCls("buy_quantity")}
                />
                {err("buy_quantity")}
              </div>
              <div>
                <label className="block text-sm font-medium text-muted mb-1.5">{t("promotions.get_quantity")}</label>
                <input
                  type="number" min="1"
                  value={form.get_quantity}
                  onChange={(e) => { set({ get_quantity: e.target.value }); clearErr("get_quantity"); }}
                  className={fieldCls("get_quantity")}
                />
                {err("get_quantity")}
              </div>
            </div>
          )}

          {isCategory && (
            <div>
              <label className="block text-sm font-medium text-muted mb-1.5">{t("promotions.category")}</label>
              <select
                value={form.category_id}
                onChange={(e) => { set({ category_id: e.target.value }); clearErr("category_id"); }}
                className={fieldCls("category_id")}
              >
                <option value="">{t("common.select")}</option>
                {categories.map((c) => (
                  <option key={c.id} value={String(c.id)}>{c.name}</option>
                ))}
              </select>
              {err("category_id")}
            </div>
          )}

          {showsAppliesToSelect && (
            <div>
              <label className="block text-sm font-medium text-muted mb-1.5">{t("promotions.applies_to")}</label>
              <select
                value={form.applies_to}
                onChange={(e) => { set({ applies_to: e.target.value as "all" | "specific" }); clearErr("applicable_products"); }}
                className={fieldCls("applicable_products")}
              >
                <option value="all">{t("promotions.all_products")}</option>
                <option value="specific">{t("promotions.specific_products")}</option>
              </select>
            </div>
          )}

          {showsProductPicker && (
            <div>
              <label className="block text-sm font-medium text-muted mb-1.5">
                {isBundle ? t("promotions.combo_products") : t("promotions.product_picker")}
              </label>

              <div
                className="relative"
                onBlur={(e) => {
                  if (!e.currentTarget.contains(e.relatedTarget as Node)) setProductListOpen(false);
                }}
              >
                <input
                  type="text"
                  value={productSearch}
                  onFocus={() => setProductListOpen(true)}
                  onChange={(e) => { setProductSearch(e.target.value); setProductListOpen(true); clearErr("applicable_products"); }}
                  placeholder={t("promotions.product_search")}
                  className={fieldCls("applicable_products")}
                />
                {!productListOpen && (
                  <p className="text-xs text-muted mt-1">{t("promotions.select_products_hint")}</p>
                )}
                {productListOpen && (
                  <div className="absolute z-10 mt-1 w-full max-h-44 overflow-y-auto border border-border rounded-xl divide-y divide-border/50 bg-card shadow-lg">
                    {filteredProducts.length === 0 ? (
                      <p className="text-xs text-muted p-3">{t("promotions.no_products")}</p>
                    ) : (
                      filteredProducts.map((p) => {
                        const selected = form.applicable_products.includes(p.id);
                        return (
                          <button
                            key={p.id}
                            type="button"
                            onClick={() => toggleProduct(p.id)}
                            className={`w-full flex items-center justify-between px-3 py-2 text-start text-xs transition-colors ${selected ? "bg-primary/10 text-primary-light" : "hover:bg-card-hover"}`}
                          >
                            <span className="truncate">{p.name}</span>
                            <span className="text-muted shrink-0 ms-2">{formatCurrency(p.price, locale)}</span>
                          </button>
                        );
                      })
                    )}
                  </div>
                )}
              </div>

              {form.applicable_products.length > 0 && (
                <div className="flex flex-wrap gap-1.5 mt-2">
                  {form.applicable_products.map((id) => {
                    const p = productById.get(id);
                    return (
                      <span key={id} className="inline-flex items-center gap-1 px-2 py-1 rounded-md bg-card/60 border border-border text-[11px] text-foreground">
                        {p?.name ?? `#${id}`}
                        <button type="button" onClick={() => toggleProduct(id)} className="text-muted hover:text-red-400">
                          <X className="w-3 h-3" />
                        </button>
                      </span>
                    );
                  })}
                </div>
              )}
              {err("applicable_products")}
            </div>
          )}

          <div className="border border-border rounded-xl overflow-hidden">
            <button
              type="button"
              onClick={() => setAdvancedOpen((o) => !o)}
              className="w-full flex items-center justify-between px-4 py-2.5 text-sm font-medium text-muted hover:text-foreground transition-colors"
            >
              <span>{t("promotions.advanced_rules")}</span>
              {advancedOpen ? <ChevronUp className="w-4 h-4" /> : <ChevronDown className="w-4 h-4" />}
            </button>
            {advancedOpen && (
              <div className="px-4 pb-4 pt-1 border-t border-border/50 space-y-3">
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="block text-sm font-medium text-muted mb-1.5">{t("promotions.min_amount")}</label>
                    <input
                      type="number" step="0.01" min="0"
                      value={form.min_amount}
                      onChange={(e) => { set({ min_amount: e.target.value }); clearErr("min_amount"); }}
                      className={fieldCls("min_amount")}
                    />
                    {err("min_amount")}
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-muted mb-1.5">{t("promotions.max_uses")}</label>
                    <input
                      type="number" min="1"
                      value={form.max_uses}
                      onChange={(e) => { set({ max_uses: e.target.value }); clearErr("max_uses"); }}
                      placeholder={t("promotions.unlimited")}
                      className={fieldCls("max_uses")}
                    />
                    {err("max_uses")}
                  </div>
                </div>

                {isPercentLike && (
                  <div>
                    <label className="block text-sm font-medium text-muted mb-1.5">{t("promotions.min_quantity")}</label>
                    <input
                      type="number" min="1"
                      value={form.min_quantity}
                      onChange={(e) => { set({ min_quantity: e.target.value }); clearErr("min_quantity"); }}
                      className={fieldCls("min_quantity")}
                    />
                    {err("min_quantity")}
                  </div>
                )}

                {isHappyHour && (
                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <label className="block text-sm font-medium text-muted mb-1.5">{t("promotions.happy_hour_start")}</label>
                      <input
                        type="time"
                        value={form.happy_hour_start}
                        onChange={(e) => { set({ happy_hour_start: e.target.value }); clearErr("happy_hour_start"); }}
                        className={fieldCls("happy_hour_start")}
                      />
                      {err("happy_hour_start")}
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-muted mb-1.5">{t("promotions.happy_hour_end")}</label>
                      <input
                        type="time"
                        value={form.happy_hour_end}
                        onChange={(e) => { set({ happy_hour_end: e.target.value }); clearErr("happy_hour_end"); }}
                        className={fieldCls("happy_hour_end")}
                      />
                      {err("happy_hour_end")}
                    </div>
                  </div>
                )}

                <div>
                  <label className="block text-sm font-medium text-muted mb-1.5">{t("common.status")}</label>
                  <select
                    value={form.is_active ? "active" : "inactive"}
                    onChange={(e) => set({ is_active: e.target.value === "active" })}
                    className={inputCls}
                  >
                    <option value="active">{t("common.active")}</option>
                    <option value="inactive">{t("common.inactive")}</option>
                  </select>
                </div>
              </div>
            )}
          </div>

          <div className="flex gap-3 pt-2">
            {editing && (
              <button
                type="button"
                onClick={() => setDeleteConfirm(true)}
                className="px-4 py-2.5 bg-red-500/10 border border-red-500/30 text-red-400 rounded-xl text-sm font-medium hover:bg-red-500/20 transition-colors"
              >
                {t("common.delete")}
              </button>
            )}
            <button
              onClick={handleSave}
              disabled={saving}
              className="flex-1 py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors disabled:opacity-50 flex items-center justify-center gap-2"
            >
              {saving && <Loader2 className="w-4 h-4 animate-spin" />}
              {editing ? t("promotions.save") : t("promotions.create_btn")}
            </button>
          </div>
        </div>
      </SlideOver>

      <ConfirmDialog
        open={deleteConfirm}
        onClose={() => setDeleteConfirm(false)}
        onConfirm={handleDelete}
        title={t("common.delete")}
        message={editing ? t("promotions.delete_confirm", { name: editing.name }) : ""}
        confirmLabel={t("common.delete")}
        loading={deleting}
      />
    </motion.div>
  );
}
