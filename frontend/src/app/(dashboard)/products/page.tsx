"use client";

import { Fragment, useEffect, useState, useCallback, useMemo, useRef } from "react";
import { motion } from "framer-motion";
import { useAuthStore } from "@/stores/auth-store";
import { Products, fetchCategories, Warehouses, importProducts } from "@/lib/api";
import type { Product, Category, Warehouse } from "@/lib/types";
import PageHeader from "@/components/ui/PageHeader";
import DataTable from "@/components/ui/DataTable";
import type { Column } from "@/components/ui/DataTable";
import SlideOver from "@/components/ui/SlideOver";
import ConfirmDialog from "@/components/ui/ConfirmDialog";
import CategoryManagementModal from "@/components/products/CategoryManagementModal";
import { Package, Plus, Search, Upload, X, ImageIcon, Loader2, FileSpreadsheet, Download, Folder } from "lucide-react";
import { useI18n } from "@/lib/i18n";
import { isSupermarketVertical } from "@/lib/morphing-engine";
import { usePagination } from "@/lib/pagination";
import { downloadCSV } from "@/lib/export";

const emptyForm = {
  name: "",
  sku: "",
  barcode: "",
  cost: "",
  price: "",
  sale_price: "",
  is_on_sale: false,
  stock_quantity: "",
  unit: "pcs",
  category: "",
  category_id: null as number | null,
  tax_rate: "0",
  min_stock: "10",
  storage_location: "",
  image_url: "",
  status: "active",
};

export default function ProductsPage() {
  const { t, locale } = useI18n();
  const { token, business, config } = useAuthStore();
  const [data, setData] = useState<Product[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState("");
  const [categoryFilter, setCategoryFilter] = useState<number | null>(null);
  const [slideOpen, setSlideOpen] = useState(false);
  const [editing, setEditing] = useState<Product | null>(null);
  const [form, setForm] = useState(emptyForm);
  const [saving, setSaving] = useState(false);
  const [dragOver, setDragOver] = useState(false);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [categories, setCategories] = useState<Category[]>([]);
  const [warehouses, setWarehouses] = useState<Warehouse[]>([]);
  const [manageCatsOpen, setManageCatsOpen] = useState(false);
  const [deleteConfirmOpen, setDeleteConfirmOpen] = useState(false);
  const [deleting, setDeleting] = useState(false);

  const [importOpen, setImportOpen] = useState(false);
  const [importFile, setImportFile] = useState<File | null>(null);
  const [importing, setImporting] = useState(false);
  const [importResult, setImportResult] = useState<{ created: number; updated: number; rows: number } | null>(null);
  const [importError, setImportError] = useState<string | null>(null);
  const [importDragOver, setImportDragOver] = useState(false);
  const importFileRef = useRef<HTMLInputElement>(null);
  const [total, setTotal] = useState(0);
  const { page, perPage, setPage, resetPage, changePageSize } = usePagination();

  const columns: Column[] = [
    { key: "name", label: t("common.name") },
    { key: "sku", label: t("common.sku") },
    { key: "barcode", label: t("common.barcode") },
    { key: "cost", label: t("common.cost"), type: "currency" },
    {
      key: "price",
      label: t("common.price"),
      type: "currency",
      render: (v, row) => {
        const p = row as unknown as Product;
        if (p.is_on_sale && p.sale_price != null && p.sale_price > 0) {
          return (
            <span className="inline-flex items-center gap-1.5">
              <span className="line-through text-muted">{String(p.price)}</span>
              <span className="text-emerald-400 font-medium">{String(p.sale_price)}</span>
            </span>
          );
        }
        return v as React.ReactNode;
      },
    },
    { key: "stock_quantity", label: t("common.stock"), type: "number" },
    {
      key: "is_active",
      label: t("common.status"),
      render: (v) => {
        const active = v === true || v === "true" || v === 1 || v === "1";
        return (
          <span className={`inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium ${
            active ? "bg-green-500/10 text-green-400" : "bg-gray-500/10 text-gray-400"
          }`}>
            <span className={`w-1.5 h-1.5 rounded-full ${active ? "bg-green-400" : "bg-gray-400"}`} />
            {active ? t("common.active") : t("common.inactive")}
          </span>
        );
      },
    },
  ];

  const fetchData = useCallback(() => {
    if (!token || !business) return;
    setLoading(true);
    const params: Record<string, string | number> = { page, per_page: perPage };
    if (search) params.search = search;
    if (categoryFilter !== null) params.category_id = categoryFilter;
    Products.list(token, business.id, params)
      .then((res) => {
        setData(res.data);
        setTotal(res.total);
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [token, business, search, categoryFilter, page, perPage]);

  useEffect(() => {
    const timer = setTimeout(fetchData, 300);
    return () => clearTimeout(timer);
  }, [fetchData]);

  useEffect(() => {
    if (!token || !business) return;
    fetchCategories(token, business.id).then(setCategories).catch(() => {});
    Warehouses.list(token, business.id).then((res) => setWarehouses(res.data)).catch(() => {});
  }, [token, business]);

  const getDefaultMinStock = (): string => {
    const slug = business?.business_type?.slug ?? "";
    if (isSupermarketVertical(slug) || ["restaurant", "fast_food_kds", "fine_dining_reservations"].includes(slug)) return "10";
    if (["clothing_apparel", "fashion"].includes(slug)) return "3";
    if (["electronics_warranty"].includes(slug)) return "2";
    if (["pharmacy"].includes(slug)) return "5";
    return "5";
  };

  const openCreate = () => {
    setEditing(null);
    const defaultTax = String((config?.settings as Record<string, unknown>)?.default_tax_rate ?? "16");
    setForm({ ...emptyForm, tax_rate: defaultTax, min_stock: getDefaultMinStock() });
    setSlideOpen(true);
  };

  const openEdit = (row: Record<string, unknown>) => {
    const p = row as unknown as Product;
    setEditing(p);
    setForm({
      name: p.name,
      sku: p.sku ?? "",
      barcode: p.barcode ?? "",
      cost: String(p.cost),
      price: String(p.price),
      sale_price: p.sale_price != null && p.sale_price > 0 ? String(p.sale_price) : "",
      is_on_sale: p.is_on_sale === true,
      stock_quantity: String(p.stock_quantity),
      unit: p.unit ?? "pcs",
      category: p.category ?? "",
      category_id: p.category_id ?? null,
      tax_rate: String(p.tax_rate ?? "0"),
      min_stock: String(p.min_stock ?? "10"),
      storage_location: p.storage_location ?? "",
      image_url: p.image_url ?? "",
      status: p.is_active ? "active" : "inactive",
    });
    setSlideOpen(true);
  };

  const handleImageFile = (file: File) => {
    if (!file.type.startsWith("image/")) return;
    const reader = new FileReader();
    reader.onload = () => {
      setForm((p) => ({ ...p, image_url: reader.result as string }));
    };
    reader.readAsDataURL(file);
  };

  const handleDrop = (e: React.DragEvent) => {
    e.preventDefault();
    setDragOver(false);
    const file = e.dataTransfer.files?.[0];
    if (file) handleImageFile(file);
  };

  const handleFilePick = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (file) handleImageFile(file);
  };

  const handleCategoriesChanged = (cats: Category[]) => {
    setCategories(cats);
  };

  const isPiece = form.unit === "pcs";
  const isWeight = form.unit === "kg";

  const downloadTemplate = () => {
    downloadCSV("product-import-template.csv", [
      ["barcode", "name", "cost_price", "selling_price", "stock_quantity", "tax_rate", "category"],
      ["6291041500213", "Apple", "0.50", "1.20", "50", "16", "Produce"],
    ]);
  };

  const handleImportDrop = (e: React.DragEvent) => {
    e.preventDefault();
    setImportDragOver(false);
    const file = e.dataTransfer.files?.[0];
    if (!file) return;
    const name = file.name.toLowerCase();
    if (name.endsWith(".xlsx") || name.endsWith(".csv")) {
      setImportFile(file);
      setImportResult(null);
      setImportError(null);
    }
  };

  const handleImportPick = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setImportFile(file);
    setImportResult(null);
    setImportError(null);
  };

  const handleImport = async () => {
    if (!token || !business || !importFile) return;
    setImporting(true);
    setImportError(null);
    try {
      const res = await importProducts(token, business.id, importFile);
      setImportResult({ created: res.created, updated: res.updated, rows: res.rows });
      fetchData();
    } catch (err) {
      setImportError(err instanceof Error ? err.message : t("products.import_failed"));
    } finally {
      setImporting(false);
    }
  };

  const topCategories = useMemo(() => categories.filter((c) => !c.parent_id), [categories]);

  const renderPill = (c: Category, depth: number) => {
    const kids = categories.filter((x) => x.parent_id === c.id);
    const active = categoryFilter === c.id;
    return (
      <Fragment key={c.id}>
        <button onClick={() => { setCategoryFilter(active ? null : c.id); resetPage(); }}
          style={{ paddingInlineStart: 8 + depth * 12 }}
          className={`px-3 py-1.5 rounded-full text-xs font-medium border transition-colors flex items-center gap-1.5 ${active ? "bg-primary/20 border-primary/50 text-primary-light" : "bg-card/60 border-border text-muted hover:border-border-hover"}`}>
          {c.color && <span className="w-1.5 h-1.5 rounded-full" style={{ backgroundColor: c.color }} />}
          {locale === "ar" && c.name_ar ? c.name_ar : c.name}
        </button>
        {kids.map((k) => renderPill(k, depth + 1))}
      </Fragment>
    );
  };

  const handleSave = async () => {
    if (!token || !business || !form.name) return;
    if (form.barcode && !/^\d{13}$/.test(form.barcode)) return;
    if (isPiece && form.stock_quantity && !Number.isInteger(Number(form.stock_quantity))) return;
    setSaving(true);
    try {
      const { min_stock, status, ...rest } = form;
      const stockRaw = rest.stock_quantity;
      const stockClean = Math.round(Number(stockRaw));
      console.log('[PRODUCT SAVE] stock_raw:', JSON.stringify(stockRaw), '| Number():', Number(stockRaw), '| Math.round():', stockClean);
      const payload: Record<string, unknown> = {
        ...rest,
        is_active: status === "active",
        is_weighable: isWeight,
        cost: Number(rest.cost),
        price: Number(rest.price),
        sale_price: rest.sale_price ? Number(rest.sale_price) : null,
        is_on_sale: rest.is_on_sale === true && !!rest.sale_price,
        stock_quantity: stockClean,
        tax_rate: Number(rest.tax_rate),
        min_stock: min_stock ? Number(min_stock) : 10,
      };
      console.log('[PRODUCT SAVE] payload:', JSON.stringify(payload));
      if (editing) {
        await Products.update(token, business.id, editing.id, payload);
      } else {
        await Products.create(token, business.id, payload);
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
        title={t("products.title")}
        subtitle={t("products.subtitle", { count: String(data.length) })}
        action={
          <div className="flex items-center gap-2">
            <button
              onClick={() => setManageCatsOpen(true)}
              className="flex items-center gap-2 px-4 py-2.5 bg-card/80 border border-border hover:border-border-hover text-foreground rounded-xl text-sm font-medium transition-colors"
            >
              <Folder className="w-4 h-4" /> {t("products.manage_categories")}
            </button>
            <button
              onClick={() => { setImportOpen(true); setImportFile(null); setImportResult(null); setImportError(null); }}
              className="flex items-center gap-2 px-4 py-2.5 bg-card/80 border border-border hover:border-border-hover text-foreground rounded-xl text-sm font-medium transition-colors"
            >
              <FileSpreadsheet className="w-4 h-4" /> {t("products.import")}
            </button>
            <button onClick={openCreate} className="flex items-center gap-2 px-4 py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors">
              <Plus className="w-4 h-4" /> {t("products.add")}
            </button>
          </div>
        }
      />

      <div className="mb-4">
        <div className="relative max-w-sm">
          <Search className="absolute start-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted" />
          <input
            type="text"
            placeholder={t("products.search")}
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              resetPage();
            }}
            className="w-full ps-10 pe-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors"
          />
        </div>
        {topCategories.length > 0 && (
          <div className="mt-3 flex flex-wrap gap-2">
<button onClick={() => { setCategoryFilter(null); resetPage(); }}
            className={`px-3 py-1.5 rounded-full text-xs font-medium border transition-colors ${categoryFilter === null ? "bg-primary/20 border-primary/50 text-primary-light" : "bg-card/60 border-border text-muted hover:border-border-hover"}`}>
              {t("common.all")}
            </button>
            {topCategories.map((c) => renderPill(c, 0))}
          </div>
        )}
      </div>

      <DataTable columns={columns} data={data as unknown as Record<string, unknown>[]} loading={loading} emptyMessage={t("products.empty")} emptyIcon={Package} onRowClick={openEdit} pagination={{ total, page, perPage, onPageChange: setPage, onPerPageChange: changePageSize }} />

      <SlideOver open={slideOpen} onClose={() => setSlideOpen(false)} title={editing ? t("products.edit") : t("products.create")}>
        <div className="space-y-4">
          {/* Image Dropzone */}
          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">{t("products.image")}</label>
            <input ref={fileInputRef} type="file" accept="image/*" className="hidden" onChange={handleFilePick} />
            {form.image_url ? (
              <div className="relative rounded-xl border border-border overflow-hidden bg-card/60 group">
                <img src={form.image_url} alt="" className="w-full h-44 object-contain" />
                <button type="button" onClick={() => fileInputRef.current?.click()} className="absolute top-2 end-8 px-2.5 py-1 text-xs bg-black/50 text-white rounded-lg opacity-0 group-hover:opacity-100 transition-opacity hover:bg-black/70">{t("products.image_change")}</button>
                <button type="button" onClick={() => setForm((p) => ({ ...p, image_url: "" }))} className="absolute top-2 end-2 p-1.5 bg-red-500/80 text-white rounded-lg opacity-0 group-hover:opacity-100 transition-opacity hover:bg-red-600"><X className="w-3.5 h-3.5" /></button>
              </div>
            ) : (
              <div
                onDragOver={(e) => { e.preventDefault(); setDragOver(true); }}
                onDragLeave={() => setDragOver(false)}
                onDrop={handleDrop}
                onClick={() => fileInputRef.current?.click()}
                className={`flex flex-col items-center justify-center gap-2 h-44 rounded-xl border-2 border-dashed cursor-pointer transition-colors ${
                  dragOver ? "border-primary-light bg-primary/5" : "border-border bg-card/60 hover:border-border-hover hover:bg-card/80"
                }`}
              >
                {dragOver ? (
                  <><Upload className="w-8 h-8 text-primary-light" /><span className="text-sm text-primary-light font-medium">{t("products.drop_image")}</span></>
                ) : (
                  <><ImageIcon className="w-8 h-8 text-muted" /><span className="text-sm text-muted">{t("products.drag_hint")}</span></>
                )}
              </div>
            )}
          </div>

          {/* Name */}
          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">{t("common.name")}</label>
            <input type="text" value={form.name} onChange={(e) => setForm((p) => ({ ...p, name: e.target.value }))} className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors" />
          </div>

          {/* SKU - readOnly auto-generated */}
          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">{t("common.sku")}</label>
            <input type="text" value={form.sku} readOnly placeholder={t("products.sku_auto")} className="w-full px-4 py-2.5 bg-card/40 border border-border rounded-xl text-sm text-muted cursor-not-allowed" />
          </div>

          {/* Barcode - EAN-13 */}
          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">{t("products.barcode_ean13")}</label>
            <input type="text" inputMode="numeric" maxLength={13} value={form.barcode}
              onChange={(e) => {
                const val = e.target.value.replace(/\D/g, "").slice(0, 13);
                setForm((p) => ({ ...p, barcode: val }));
              }}
              className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors"
              placeholder={t("products.barcode_eg")} />
            {form.barcode && form.barcode.length > 0 && form.barcode.length < 13 && (
              <p className="text-xs text-amber-400 mt-1">{t("products.barcode_hint", { count: String(form.barcode.length) })}</p>
            )}
          </div>

          {/* Cost & Price */}
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-sm font-medium text-muted mb-1.5">{t("products.cost_jod")}</label>
              <input type="number" step="0.01" min="0" value={form.cost} onChange={(e) => setForm((p) => ({ ...p, cost: e.target.value }))} className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors" />
            </div>
            <div>
              <label className="block text-sm font-medium text-muted mb-1.5">{t("products.price_jod")}</label>
              <input type="number" step="0.01" min="0" value={form.price} onChange={(e) => setForm((p) => ({ ...p, price: e.target.value }))} className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors" />
            </div>
          </div>

          {/* Sale Price & Toggle */}
          <div className="grid grid-cols-2 gap-3 items-end">
            <div>
              <label className="block text-sm font-medium text-muted mb-1.5">{t("products.sale_price")}</label>
              <input
                type="number" step="0.01" min="0"
                value={form.sale_price}
                disabled={!form.is_on_sale}
                onChange={(e) => setForm((p) => ({ ...p, sale_price: e.target.value }))}
                className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors disabled:opacity-50"
              />
            </div>
            <label className={`flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl border cursor-pointer transition-colors text-sm font-medium ${
              form.is_on_sale ? "bg-emerald-500/10 border-emerald-500/30 text-emerald-400" : "bg-card/60 border-border text-muted hover:border-border-hover"
            }`}>
              <input type="checkbox" checked={form.is_on_sale} onChange={(e) => setForm((p) => ({ ...p, is_on_sale: e.target.checked }))} className="accent-emerald-500 w-4 h-4" />
              {t("products.sale_active")}
            </label>
          </div>

          {/* Unit Toggle */}
          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">{t("common.unit")}</label>
            <div className="flex gap-3">
              <label className={`flex-1 flex items-center justify-center gap-2 px-4 py-3 rounded-xl border cursor-pointer transition-colors text-sm font-medium ${
                isPiece ? "bg-primary/10 border-primary-light text-primary-light" : "bg-card/60 border-border text-muted hover:border-border-hover"
              }`}>
                <input type="radio" name="unit" value="pcs" checked={isPiece} onChange={() => setForm((p) => ({ ...p, unit: "pcs" }))} className="sr-only" />
                {t("products.unit_piece")}
              </label>
              <label className={`flex-1 flex items-center justify-center gap-2 px-4 py-3 rounded-xl border cursor-pointer transition-colors text-sm font-medium ${
                isWeight ? "bg-primary/10 border-primary-light text-primary-light" : "bg-card/60 border-border text-muted hover:border-border-hover"
              }`}>
                <input type="radio" name="unit" value="kg" checked={isWeight} onChange={() => setForm((p) => ({ ...p, unit: "kg" }))} className="sr-only" />
                {t("products.unit_weight")}
              </label>
            </div>
          </div>

          {/* Stock Quantity (read-only, auto-calculated from batches) & Min Stock */}
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-sm font-medium text-muted mb-1.5">{t("common.stock")}</label>
              <input type="number" value={form.stock_quantity || "0"} readOnly
                className="w-full px-4 py-2.5 bg-card/40 border border-border rounded-xl text-sm text-muted cursor-not-allowed" />
              <p className="text-xs text-muted mt-0.5">{t("products.stock_auto")}</p>
            </div>
            <div>
              <label className="block text-sm font-medium text-muted mb-1.5">{t("products.min_stock")}</label>
              <input type="number" min="0" value={form.min_stock}
                onChange={(e) => setForm((p) => ({ ...p, min_stock: e.target.value }))}
                className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors" />
            </div>
          </div>

          {/* Tax Rate */}
          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">{t("products.tax_rate")}</label>
            <input type="number" step="0.01" min="0" max="100" value={form.tax_rate}
              onChange={(e) => setForm((p) => ({ ...p, tax_rate: e.target.value }))}
              className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors" />
          </div>

          {/* Category - Dynamic Dropdown + Quick Add */}
          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">{t("common.category")}</label>
            <div className="flex gap-2">
              <select value={form.category_id ?? ""} onChange={(e) => {
                if (e.target.value === "__add__") { setManageCatsOpen(true); return; }
                const id = e.target.value === "" ? null : Number(e.target.value);
                const cat = categories.find((c) => c.id === id);
                setForm((p) => ({ ...p, category_id: id, category: cat ? cat.name : (id === null ? "" : p.category) }));
              }}
                className="flex-1 px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors">
                <option value="">{t("products.select_category")}</option>
                {categories.map((cat) => (
                  <option key={cat.id} value={cat.id}>{locale === "ar" && cat.name_ar ? cat.name_ar : cat.name}</option>
                ))}
                <option value="__add__" className="text-primary-light font-medium">{t("products.add_category")}</option>
              </select>
              <button type="button" onClick={() => setManageCatsOpen(true)}
                className="px-3 py-2.5 bg-primary/10 border border-primary/30 text-primary-light rounded-xl text-sm hover:bg-primary/20 transition-colors shrink-0">
                <Plus className="w-4 h-4" />
              </button>
            </div>
          </div>

          {/* Storage Location - Warehouse Dropdown */}
          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">{t("products.storage_location")}</label>
            <select value={form.storage_location} onChange={(e) => setForm((p) => ({ ...p, storage_location: e.target.value }))}
              className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors">
              <option value="">{t("products.select_warehouse")}</option>
              {warehouses.map((w) => (
                <option key={w.id} value={w.name}>{w.name}{w.code ? ` (${w.code})` : ""}</option>
              ))}
            </select>
          </div>

          {/* Status */}
          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">{t("common.status")}</label>
            <select value={form.status} onChange={(e) => setForm((p) => ({ ...p, status: e.target.value }))}
              className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors">
              <option value="active">{t("common.active")}</option>
              <option value="inactive">{t("common.inactive")}</option>
            </select>
          </div>

          <div className="flex gap-3">
            {editing && (
              <button type="button" onClick={() => setDeleteConfirmOpen(true)}
                className="flex-1 py-2.5 bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/30 rounded-xl text-sm font-medium transition-colors">
                {t("common.delete")}
              </button>
            )}
            <button onClick={handleSave} disabled={saving || !form.name}
              className={`py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors disabled:opacity-50 ${editing ? "flex-1" : "w-full"}`}>
              {saving ? t("common.saving") : editing ? t("products.save") : t("products.create_btn")}
            </button>
          </div>
        </div>
      </SlideOver>

      <ConfirmDialog
        open={deleteConfirmOpen}
        onClose={() => setDeleteConfirmOpen(false)}
        onConfirm={async () => {
          if (!token || !business || !editing) return;
          setDeleting(true);
          try {
            await Products.delete(token, business.id, editing.id);
            setSlideOpen(false);
            setDeleteConfirmOpen(false);
            setEditing(null);
            fetchData();
          } catch {
          } finally {
            setDeleting(false);
          }
        }}
        title={t("products.delete_title")}
        message={t("products.delete_message", { name: editing?.name ?? "" })}
        confirmLabel={t("common.delete")}
        loading={deleting}
      />

      {/* Manage Categories Modal */}
      {token && business && (
        <CategoryManagementModal
          open={manageCatsOpen}
          onClose={() => setManageCatsOpen(false)}
          onChanged={handleCategoriesChanged}
          token={token}
          businessId={business.id}
        />
      )}

      {/* Import Products SlideOver */}
      <SlideOver open={importOpen} onClose={() => setImportOpen(false)} title={t("products.import")}>
        <div className="space-y-4">
          <p className="text-sm text-muted leading-relaxed">{t("products.import_desc")}</p>

          <button
            type="button"
            onClick={downloadTemplate}
            className="flex items-center justify-center gap-2 w-full px-4 py-2.5 bg-primary/10 border border-primary/30 text-primary-light rounded-xl text-sm font-medium hover:bg-primary/20 transition-colors"
          >
            <Download className="w-4 h-4" /> {t("products.download_template")}
          </button>

          <input ref={importFileRef} type="file" accept=".xlsx,.csv" className="hidden" onChange={handleImportPick} />
          <div
            onDragOver={(e) => { e.preventDefault(); setImportDragOver(true); }}
            onDragLeave={() => setImportDragOver(false)}
            onDrop={handleImportDrop}
            onClick={() => importFileRef.current?.click()}
            className={`flex flex-col items-center justify-center gap-2 h-40 rounded-xl border-2 border-dashed cursor-pointer transition-colors ${
              importDragOver ? "border-primary-light bg-primary/5" : "border-border bg-card/60 hover:border-border-hover hover:bg-card/80"
            }`}
          >
            {importFile ? (
              <><FileSpreadsheet className="w-8 h-8 text-emerald-400" /><span className="text-sm text-foreground font-medium">{importFile.name}</span></>
            ) : importDragOver ? (
              <><Upload className="w-8 h-8 text-primary-light" /><span className="text-sm text-primary-light font-medium">{t("products.import_drop")}</span></>
            ) : (
              <><Upload className="w-8 h-8 text-muted" /><span className="text-sm text-muted">{t("products.import_hint")}</span></>
            )}
          </div>

          {importError && (
            <p className="text-sm text-red-400 bg-red-500/10 border border-red-500/30 rounded-xl px-3 py-2.5">{importError}</p>
          )}

          {importResult && (
            <div className="text-sm bg-emerald-500/10 border border-emerald-500/30 rounded-xl px-3 py-2.5 text-emerald-400">
              {t("products.import_done", {
                created: String(importResult.created),
                updated: String(importResult.updated),
                rows: String(importResult.rows),
              })}
            </div>
          )}

          <button
            type="button"
            onClick={handleImport}
            disabled={importing || !importFile}
            className="w-full py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors disabled:opacity-50 flex items-center justify-center gap-2"
          >
            {importing ? <Loader2 className="w-4 h-4 animate-spin" /> : <Upload className="w-4 h-4" />}
            {importing ? t("common.saving") : t("products.import_btn")}
          </button>
        </div>
      </SlideOver>
    </motion.div>
  );
}
