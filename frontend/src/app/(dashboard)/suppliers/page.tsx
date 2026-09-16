"use client";

import { useEffect, useState, useCallback } from "react";
import { motion } from "framer-motion";
import { useAuthStore } from "@/stores/auth-store";
import { Suppliers, Products, ApiError, fetchSupplierCatalog, addSupplierCatalogItem, removeSupplierCatalogItem, fetchSupplierLedger } from "@/lib/api";
import type { Supplier, SupplierProduct, Product, SupplierLedger } from "@/lib/types";
import { formatCurrency } from "@/lib/types";
import { normalizePhone, isValidEmail, isValidPhone } from "@/lib/phone";
import { mapFieldErrors } from "@/lib/validation";
import { usePagination } from "@/lib/pagination";
import { useI18n } from "@/lib/i18n";
import PageHeader from "@/components/ui/PageHeader";
import DataTable from "@/components/ui/DataTable";
import type { Column } from "@/components/ui/DataTable";
import SlideOver from "@/components/ui/SlideOver";
import { Truck, Plus, Search, AlertCircle, Trash2, Loader2, ChevronDown, PackageOpen, Pencil, BookOpen } from "lucide-react";
import ConfirmDialog from "@/components/ui/ConfirmDialog";

const emptyForm = { name: "", email: "", phone: "", tax_number: "", address: "", contact_name: "", payment_terms: "" };

const LEDGER_KIND_LABELS: Record<string, string> = {
  purchase_order: "suppliers.ledger_kind_po",
  goods_receipt: "suppliers.ledger_kind_grn",
  payment: "suppliers.ledger_kind_payment",
  purchase_return: "suppliers.ledger_kind_return",
  supplier_claim: "suppliers.ledger_kind_claim",
};

export default function SuppliersPage() {
  const { token, business } = useAuthStore();
  const { t, locale } = useI18n();
  const [data, setData] = useState<Supplier[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState("");
  const { page, perPage, setPage, resetPage, changePageSize } = usePagination();
  const [slideOpen, setSlideOpen] = useState(false);
  const [editing, setEditing] = useState<Supplier | null>(null);
  const [form, setForm] = useState(emptyForm);
  const [saving, setSaving] = useState(false);
  const [deleteConfirmOpen, setDeleteConfirmOpen] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [toast, setToast] = useState<{ msg: string; type: "success" | "error" } | null>(null);

  const [profileOpen, setProfileOpen] = useState(false);
  const [profileSupplier, setProfileSupplier] = useState<Supplier | null>(null);
  const [catalog, setCatalog] = useState<SupplierProduct[]>([]);
  const [catalogLoading, setCatalogLoading] = useState(false);
  const [activeTab, setActiveTab] = useState<"supply" | "imported" | "ledger">("supply");
  const [ledger, setLedger] = useState<SupplierLedger | null>(null);
  const [ledgerLoading, setLedgerLoading] = useState(false);
  const [products, setProducts] = useState<Product[]>([]);
  const [productsLoading, setProductsLoading] = useState(false);
  const [addOpen, setAddOpen] = useState(false);
  const [addSearch, setAddSearch] = useState("");
  const [addName, setAddName] = useState("");
  const [addProductId, setAddProductId] = useState<number | null>(null);
  const [addCost, setAddCost] = useState("");
  const [adding, setAdding] = useState(false);
  const [removeTarget, setRemoveTarget] = useState<SupplierProduct | null>(null);
  const [removing, setRemoving] = useState(false);

  const columns: Column[] = [
    { key: "name", label: t("common.name") },
    { key: "email", label: t("common.email") },
    { key: "phone", label: t("common.phone") },
    { key: "contact_name", label: t("common.contact_person") },
    {
      key: "balance",
      label: t("suppliers.balance"),
      type: "currency",
      render: (v) => {
        const val = Number(v ?? 0);
        return (
          <span className={val > 0.005 ? "text-red-400" : "text-emerald-400"}>
            {formatCurrency(val, locale)}
          </span>
        );
      },
    },
    { key: "created_at", label: t("common.created_at"), type: "date" },
  ];

  const validate = useCallback((): Record<string, string> => {
    const e: Record<string, string> = {};
    if (!form.name.trim()) e.name = t("suppliers.required");
    if (form.email.trim() && !isValidEmail(form.email)) e.email = t("suppliers.invalid_email");
    if (form.phone.trim() && !isValidPhone(form.phone)) e.phone = t("suppliers.invalid_phone");
    return e;
  }, [form, t]);

  const showToast = useCallback((msg: string, type: "success" | "error") => {
    setToast({ msg, type });
    window.setTimeout(() => setToast(null), 3000);
  }, []);

  const fetchData = useCallback(() => {
    if (!token || !business) return;
    setLoading(true);
    const params: Record<string, string | number> = { page, per_page: perPage };
    if (search.trim()) params.search = search.trim();
    Suppliers.list(token, business.id, params)
      .then((res) => {
        setData(res.data);
        setTotal(res.total);
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [token, business, search, page, perPage]);

  useEffect(() => {
    const timer = setTimeout(fetchData, 300);
    return () => clearTimeout(timer);
  }, [fetchData]);

  const openCreate = () => {
    setEditing(null);
    setForm(emptyForm);
    setErrors({});
    setSlideOpen(true);
  };

  const openEdit = (s: Supplier | null) => {
    setEditing(s);
    setForm(s ? {
      name: s.name,
      email: s.email ?? "",
      phone: s.phone ?? "",
      tax_number: s.tax_number ?? "",
      address: s.address ?? "",
      contact_name: s.contact_name ?? "",
      payment_terms: s.payment_terms ?? "",
    } : emptyForm);
    setErrors({});
    setSlideOpen(true);
  };

  const loadCatalog = useCallback((supplierId: number) => {
    if (!token || !business) return;
    setCatalogLoading(true);
    fetchSupplierCatalog(token, business.id, supplierId)
      .then(setCatalog)
      .catch(() => setCatalog([]))
      .finally(() => setCatalogLoading(false));
  }, [token, business]);

  const loadProducts = useCallback(() => {
    if (!token || !business) return;
    setProductsLoading(true);
    Products.list(token, business.id, { per_page: 200 })
      .then((res) => setProducts(res.data))
      .catch(() => setProducts([]))
      .finally(() => setProductsLoading(false));
  }, [token, business]);

  const loadLedger = useCallback((supplierId: number) => {
    if (!token || !business) return;
    setLedgerLoading(true);
    fetchSupplierLedger(token, business.id, supplierId)
      .then(setLedger)
      .catch(() => setLedger(null))
      .finally(() => setLedgerLoading(false));
  }, [token, business]);

  const switchTab = (tab: "supply" | "imported" | "ledger") => {
    setActiveTab(tab);
    if (tab === "ledger" && profileSupplier) loadLedger(profileSupplier.id);
  };

  const openProfile = (row: Record<string, unknown>) => {
    const s = row as unknown as Supplier;
    setProfileSupplier(s);
    setActiveTab("supply");
    setLedger(null);
    setAddOpen(false);
    setAddSearch("");
    setAddName("");
    setAddProductId(null);
    setAddCost("");
    setProfileOpen(true);
    loadCatalog(s.id);
    loadProducts();
  };

  const addItemToCatalog = async () => {
    if (!token || !business || !profileSupplier) return;
    const name = addName.trim();
    if (!name) return;
    setAdding(true);
    try {
      await addSupplierCatalogItem(token, business.id, profileSupplier.id, {
        product_id: addProductId,
        name,
        catalog_cost: addCost.trim() ? Number(addCost) : null,
      });
      showToast(t("suppliers.item_added"), "success");
      setAddName("");
      setAddProductId(null);
      setAddSearch("");
      setAddCost("");
      setAddOpen(false);
      loadCatalog(profileSupplier.id);
    } catch {
      showToast(t("common.error"), "error");
    } finally {
      setAdding(false);
    }
  };

  const removeItemFromCatalog = async () => {
    if (!token || !business || !profileSupplier || !removeTarget) return;
    setRemoving(true);
    try {
      await removeSupplierCatalogItem(token, business.id, profileSupplier.id, removeTarget.id);
      showToast(t("suppliers.item_removed"), "success");
      setRemoveTarget(null);
      loadCatalog(profileSupplier.id);
    } catch {
      showToast(t("common.error"), "error");
    } finally {
      setRemoving(false);
    }
  };

  const handleSave = async () => {
    if (!token || !business) return;
    const fieldErrors = validate();
    setErrors(fieldErrors);
    if (Object.keys(fieldErrors).length > 0) {
      showToast(t("suppliers.invalid_form"), "error");
      return;
    }
    const phone = form.phone.trim();
    const payload: Record<string, unknown> = {
      name: form.name.trim(),
      email: form.email.trim() || null,
      phone: phone ? normalizePhone(phone) ?? phone : null,
      tax_number: form.tax_number.trim() || null,
      address: form.address.trim() || null,
      contact_name: form.contact_name.trim() || null,
      payment_terms: form.payment_terms.trim() || null,
    };
    setSaving(true);
    try {
      if (editing) {
        await Suppliers.update(token, business.id, editing.id, payload);
        showToast(t("suppliers.updated"), "success");
      } else {
        await Suppliers.create(token, business.id, payload);
        showToast(t("suppliers.created"), "success");
      }
      setSlideOpen(false);
      setErrors({});
      fetchData();
    } catch (err) {
      const apiErr = err instanceof ApiError ? err : null;
      setErrors(apiErr ? mapFieldErrors(apiErr, ["name", "email", "phone", "tax_number", "address", "contact_name", "payment_terms"]) : {});
      showToast(apiErr?.message || t("common.error"), "error");
    } finally {
      setSaving(false);
    }
  };

  const fields: { label: string; key: keyof typeof emptyForm }[] = [
    { label: t("common.name"), key: "name" },
    { label: t("common.email"), key: "email" },
    { label: t("common.phone"), key: "phone" },
    { label: t("common.tax_number"), key: "tax_number" },
    { label: t("common.contact_person"), key: "contact_name" },
    { label: t("common.address"), key: "address" },
    { label: t("common.payment_terms"), key: "payment_terms" },
  ];

  const importedItems = catalog.filter((c) => c.product_id != null);
  const tabItems = activeTab === "supply" ? catalog : importedItems;
  const filteredProducts = products.filter((p) =>
    !addSearch.trim() || p.name.toLowerCase().includes(addSearch.toLowerCase()) || (p.sku ?? "").toLowerCase().includes(addSearch.toLowerCase())
  );

  const tabClass = (active: boolean) =>
    `flex-1 py-2 rounded-lg text-xs font-medium transition-colors ${active ? "bg-primary/15 text-primary-light" : "text-muted hover:text-foreground"}`;

  const itemBadge = (item: SupplierProduct) =>
    item.product_id ? (
      <span className="text-[10px] px-1.5 py-0.5 rounded-full bg-emerald-500/10 text-emerald-400 shrink-0">{t("purchase_orders.exists_badge")}</span>
    ) : (
      <span className="text-[10px] px-1.5 py-0.5 rounded-full bg-amber-500/10 text-amber-400 shrink-0">{t("purchase_orders.new_item_badge")}</span>
    );

  return (
    <motion.div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.4 }}>
      <PageHeader
        title={t("suppliers.title")}
        subtitle={t("suppliers.subtitle", { count: String(data.length) })}
        action={
          <button onClick={openCreate} className="flex items-center gap-2 px-4 py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors">
            <Plus className="w-4 h-4" /> {t("suppliers.add")}
          </button>
        }
      />

      <div className="mb-4">
        <div className="relative max-w-sm">
          <Search className="absolute start-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted" />
          <input
            type="text"
            placeholder={t("suppliers.search")}
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
        emptyMessage={t("suppliers.empty")}
        emptyIcon={Truck}
        onRowClick={openProfile}
        pagination={{ total, page, perPage, onPageChange: setPage, onPerPageChange: changePageSize }}
      />

      {/* Edit / Create drawer */}
      <SlideOver open={slideOpen} onClose={() => setSlideOpen(false)} title={editing ? t("suppliers.edit") : t("suppliers.create")}>
        <div className="space-y-4">
          {fields.map((field) => {
            const error = errors[field.key];
            const normalizedPhone = field.key === "phone" && form.phone.trim() ? normalizePhone(form.phone) : null;
            return (
              <div key={field.key}>
                <label className="block text-sm font-medium text-muted mb-1.5">{field.label}</label>
                <input
                  type="text"
                  inputMode={field.key === "phone" ? "tel" : undefined}
                  value={form[field.key]}
                  onChange={(e) => {
                    setForm((p) => ({ ...p, [field.key]: e.target.value }));
                    if (errors[field.key]) setErrors((p) => ({ ...p, [field.key]: "" }));
                  }}
                  onBlur={(e) => {
                    if (field.key === "phone" && e.target.value.trim()) {
                      const normalized = normalizePhone(e.target.value);
                      if (normalized) setForm((p) => ({ ...p, phone: normalized }));
                    }
                  }}
                  className={`w-full px-4 py-2.5 bg-card/80 border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-primary/50 transition-colors ${error ? "border-red-500/50" : "border-border"}`}
                />
                {field.key === "phone" && normalizedPhone && normalizedPhone !== form.phone.trim() && (
                  <p className="text-xs text-muted mt-1">
                    {t("suppliers.phone_preview", { phone: normalizedPhone })}
                  </p>
                )}
                {error && (
                  <p className="flex items-center gap-1 text-xs text-red-400 mt-1">
                    <AlertCircle className="w-3 h-3" /> {error}
                  </p>
                )}
              </div>
            );
          })}
          <div className="flex gap-3">
            {editing && (
              <button type="button" onClick={() => setDeleteConfirmOpen(true)}
                className="flex-1 py-2.5 bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/30 rounded-xl text-sm font-medium transition-colors">
                {t("common.delete")}
              </button>
            )}
            <button
              onClick={handleSave}
              disabled={saving || !form.name}
              className={`py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors disabled:opacity-50 ${editing ? "flex-1" : "w-full"}`}
            >
              {saving ? t("common.saving") : editing ? t("suppliers.save") : t("suppliers.create")}
            </button>
          </div>
        </div>
      </SlideOver>

      {/* Profile / catalog drawer */}
      <SlideOver open={profileOpen} onClose={() => setProfileOpen(false)} title={t("suppliers.profile")} width="max-w-xl">
        {profileSupplier && (
          <div className="space-y-5">
            <div className="glass rounded-xl p-4 space-y-2">
              <div className="flex items-center justify-between gap-2">
                <h3 className="text-base font-semibold text-foreground">{profileSupplier.name}</h3>
                <button
                  onClick={() => {
                    setProfileOpen(false);
                    window.setTimeout(() => openEdit(profileSupplier), 300);
                  }}
                  className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-primary-light hover:text-foreground border border-border rounded-lg transition-colors"
                >
                  <Pencil className="w-3.5 h-3.5" /> {t("suppliers.edit")}
                </button>
              </div>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1.5 text-sm">
                <p className="text-muted"><span className="text-foreground/60">{t("suppliers.balance")}:</span> <span className={Number(profileSupplier.balance ?? 0) > 0.005 ? "text-red-400 font-medium" : "text-emerald-400 font-medium"}>{formatCurrency(Number(profileSupplier.balance ?? 0), locale)}</span></p>
                {profileSupplier.email && <p className="text-muted"><span className="text-foreground/60">{t("common.email")}:</span> {profileSupplier.email}</p>}
                {profileSupplier.phone && <p className="text-muted"><span className="text-foreground/60">{t("common.phone")}:</span> {profileSupplier.phone}</p>}
                {profileSupplier.tax_number && <p className="text-muted"><span className="text-foreground/60">{t("common.tax_number")}:</span> {profileSupplier.tax_number}</p>}
                {profileSupplier.contact_name && <p className="text-muted"><span className="text-foreground/60">{t("common.contact_person")}:</span> {profileSupplier.contact_name}</p>}
                {profileSupplier.address && <p className="text-muted"><span className="text-foreground/60">{t("common.address")}:</span> {profileSupplier.address}</p>}
                {profileSupplier.payment_terms && <p className="text-muted"><span className="text-foreground/60">{t("common.payment_terms")}:</span> {profileSupplier.payment_terms}</p>}
              </div>
            </div>

            <div className="flex gap-1 rounded-xl p-1 bg-card/60 border border-border">
              <button type="button" onClick={() => switchTab("supply")} className={tabClass(activeTab === "supply")}>
                {t("suppliers.available_to_supply")} ({catalog.length})
              </button>
              <button type="button" onClick={() => switchTab("imported")} className={tabClass(activeTab === "imported")}>
                {t("suppliers.currently_imported")} ({importedItems.length})
              </button>
              <button type="button" onClick={() => switchTab("ledger")} className={tabClass(activeTab === "ledger")}>
                <span className="inline-flex items-center gap-1"><BookOpen className="w-3 h-3" /> {t("suppliers.ledger")}</span>
              </button>
            </div>

            {activeTab === "ledger" ? (
              <div className="space-y-2">
                {ledgerLoading ? (
                  <div className="flex items-center justify-center gap-2 py-6 text-muted text-sm">
                    <Loader2 className="w-4 h-4 animate-spin" /> {t("purchase_orders.loading")}
                  </div>
                ) : !ledger || ledger.rows.length === 0 ? (
                  <div className="flex flex-col items-center gap-2 py-8 text-muted">
                    <BookOpen className="w-8 h-8" />
                    <p className="text-sm">{t("suppliers.ledger_empty")}</p>
                  </div>
                ) : (
                  <>
                    <div className="flex justify-end text-sm">
                      <span className="text-muted">{t("suppliers.balance")}: </span>
                      <span className={`font-medium ms-1 ${Number(ledger.balance) > 0.005 ? "text-red-400" : "text-emerald-400"}`}>{formatCurrency(Number(ledger.balance), locale)}</span>
                    </div>
                    <div className="glass rounded-xl overflow-x-auto">
                      <table className="w-full min-w-[640px] text-sm">
                        <thead>
                          <tr className="text-muted text-xs border-b border-border/50">
                            <th className="text-start px-3 py-2 font-medium">{t("common.date")}</th>
                            <th className="text-start px-3 py-2 font-medium">{t("common.reference")}</th>
                            <th className="text-start px-3 py-2 font-medium">{t("common.detail")}</th>
                            <th className="text-end px-3 py-2 font-medium">{t("common.debit")}</th>
                            <th className="text-end px-3 py-2 font-medium">{t("common.credit")}</th>
                            <th className="text-end px-3 py-2 font-medium">{t("common.balance")}</th>
                          </tr>
                        </thead>
                        <tbody>
                          {ledger.rows.map((row, i) => (
                            <tr key={i} className="border-b border-border/30 last:border-0 hover:bg-card-hover/50">
                              <td className="px-3 py-2 text-muted whitespace-nowrap">{row.date || "—"}</td>
                              <td className="px-3 py-2 text-foreground whitespace-nowrap">
                                <span className="text-[10px] px-1.5 py-0.5 rounded-full bg-primary/10 text-primary-light me-1">{t(LEDGER_KIND_LABELS[row.kind] ?? row.kind)}</span>
                                {row.reference}
                              </td>
                              <td className="px-3 py-2 text-muted">{row.detail}</td>
                              <td className="px-3 py-2 text-end text-red-400">{row.debit > 0 ? formatCurrency(row.debit, locale) : "—"}</td>
                              <td className="px-3 py-2 text-end text-emerald-400">{row.credit > 0 ? formatCurrency(row.credit, locale) : "—"}</td>
                              <td className="px-3 py-2 text-end font-medium text-foreground">{formatCurrency(row.balance, locale)}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  </>
                )}
              </div>
            ) : (
            <>
            <div className="space-y-2">
              {catalogLoading ? (
                <div className="flex items-center justify-center gap-2 py-6 text-muted text-sm">
                  <Loader2 className="w-4 h-4 animate-spin" /> {t("purchase_orders.loading")}
                </div>
              ) : tabItems.length === 0 ? (
                <div className="flex flex-col items-center gap-2 py-8 text-muted">
                  <PackageOpen className="w-8 h-8" />
                  <p className="text-sm">{activeTab === "supply" ? t("suppliers.catalog_empty") : t("suppliers.imported_empty")}</p>
                </div>
              ) : (
                tabItems.map((item) => (
                  <div key={item.id} className="glass rounded-xl p-3 flex items-center justify-between gap-2">
                    <div className="flex items-center gap-2 min-w-0">
                      {itemBadge(item)}
                      <div className="min-w-0">
                        <p className="text-sm text-foreground truncate">{item.name}</p>
                        <p className="text-xs text-muted">
                          {item.product ? `${item.product.sku ?? ""}`.trim() : ""}
                          {item.catalog_cost != null ? ` ${formatCurrency(Number(item.catalog_cost), locale)}` : ""}
                        </p>
                      </div>
                    </div>
                    <button
                      onClick={() => setRemoveTarget(item)}
                      className="p-1.5 text-muted hover:text-red-400 transition-colors shrink-0"
                    >
                      <Trash2 className="w-4 h-4" />
                    </button>
                  </div>
                ))
              )}
            </div>

            <div className="glass rounded-xl p-4 space-y-3">
              <h4 className="text-sm font-medium text-muted">{t("suppliers.add_item")}</h4>
              <div className="relative">
                <button
                  type="button"
                  onClick={() => { setAddOpen((o) => !o); setAddSearch(""); }}
                  className="w-full px-3 py-2 bg-card/80 border border-border rounded-lg text-sm text-start text-foreground focus:outline-none focus:border-border-hover flex items-center justify-between gap-1 transition-colors"
                >
                  <span className={`truncate ${addProductId ? "text-foreground" : "text-muted"}`}>
                    {addProductId ? products.find((p) => p.id === addProductId)?.name ?? addName : t("suppliers.link_product")}
                  </span>
                  <ChevronDown className="w-4 h-4 text-muted shrink-0" />
                </button>
                {addOpen && (
                  <div className="absolute z-30 mt-1 w-full glass rounded-xl border border-border shadow-xl overflow-hidden">
                    <input
                      autoFocus
                      value={addSearch}
                      onChange={(e) => setAddSearch(e.target.value)}
                      placeholder={t("suppliers.link_product")}
                      className="w-full px-3 py-2 bg-card/80 border-b border-border text-sm text-foreground placeholder:text-muted focus:outline-none"
                    />
                    <div className="max-h-44 overflow-y-auto">
                      {productsLoading ? (
                        <p className="px-3 py-3 text-xs text-muted">{t("purchase_orders.loading")}</p>
                      ) : filteredProducts.length === 0 ? (
                        <p className="px-3 py-3 text-xs text-muted">{t("suppliers.no_products")}</p>
                      ) : (
                        filteredProducts.map((p) => (
                          <button
                            key={p.id}
                            type="button"
                            onClick={() => {
                              setAddProductId(p.id);
                              setAddName(p.name);
                              setAddOpen(false);
                            }}
                            className="w-full px-3 py-2 text-start text-sm text-foreground hover:bg-card-hover transition-colors flex items-center justify-between gap-2"
                          >
                            <span className="truncate">{p.name}</span>
                            {p.sku ? <span className="text-xs text-muted shrink-0">{p.sku}</span> : null}
                          </button>
                        ))
                      )}
                    </div>
                  </div>
                )}
              </div>
              <input
                type="text"
                value={addName}
                onChange={(e) => {
                  setAddName(e.target.value);
                  if (addProductId) setAddProductId(null);
                }}
                placeholder={t("suppliers.item_name")}
                className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors"
              />
              <input
                type="number"
                min="0"
                step="0.01"
                value={addCost}
                onChange={(e) => setAddCost(e.target.value)}
                placeholder={t("suppliers.catalog_cost")}
                className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors"
              />
              <button
                onClick={addItemToCatalog}
                disabled={adding || !addName.trim()}
                className="w-full py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors disabled:opacity-50 flex items-center justify-center gap-2"
              >
                {adding ? <Loader2 className="w-4 h-4 animate-spin" /> : <Plus className="w-4 h-4" />}
                {adding ? t("common.saving") : t("suppliers.add_item")}
              </button>
            </div>
            </>
            )}
          </div>
        )}
      </SlideOver>

      <ConfirmDialog
        open={deleteConfirmOpen}
        onClose={() => setDeleteConfirmOpen(false)}
        onConfirm={async () => {
          if (!token || !business || !editing) return;
          setDeleting(true);
          try {
            await Suppliers.delete(token, business.id, editing.id);
            setSlideOpen(false);
            setDeleteConfirmOpen(false);
            setEditing(null);
            showToast(t("suppliers.deleted"), "success");
            fetchData();
          } catch {
            showToast(t("common.error"), "error");
          } finally {
            setDeleting(false);
          }
        }}
        title={t("suppliers.delete_title")}
        message={t("suppliers.delete_message", { name: editing?.name ?? "" })}
        confirmLabel={t("common.delete")}
        loading={deleting}
      />

      <ConfirmDialog
        open={removeTarget !== null}
        onClose={() => setRemoveTarget(null)}
        onConfirm={removeItemFromCatalog}
        title={t("suppliers.remove_confirm_title")}
        message={t("suppliers.remove_confirm_message", { name: removeTarget?.name ?? "" })}
        confirmLabel={t("common.delete")}
        loading={removing}
      />

      {toast && (
        <div className={`fixed top-4 end-4 z-[100] px-4 py-3 rounded-xl text-sm font-medium shadow-lg border ${toast.type === "success" ? "bg-emerald-500/20 text-emerald-400 border-emerald-500/30" : "bg-red-500/20 text-red-400 border-red-500/30"}`}>
          {toast.msg}
        </div>
      )}
    </motion.div>
  );
}
