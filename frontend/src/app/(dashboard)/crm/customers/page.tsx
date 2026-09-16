"use client";

import { useEffect, useState, useCallback } from "react";
import { motion } from "framer-motion";
import { useAuthStore } from "@/stores/auth-store";
import { Customers, fetchCustomerSegments } from "@/lib/api";
import type { Customer, CustomerSegments } from "@/lib/types";
import { formatCurrency } from "@/lib/types";
import { useI18n } from "@/lib/i18n";
import { mapFieldErrors } from "@/lib/validation";
import { usePagination } from "@/lib/pagination";
import PageHeader from "@/components/ui/PageHeader";
import DataTable from "@/components/ui/DataTable";
import type { Column } from "@/components/ui/DataTable";
import SlideOver from "@/components/ui/SlideOver";
import ConfirmDialog from "@/components/ui/ConfirmDialog";
import { Users, Search, Plus, Trash2, Pencil, Star, AlertTriangle, ShoppingBag, Phone, X, Loader2 } from "lucide-react";

const TIER_COLORS: Record<string, string> = {
  bronze: "bg-amber-500/10 text-amber-400 border-amber-500/30",
  silver: "bg-slate-400/10 text-slate-300 border-slate-400/30",
  gold: "bg-yellow-500/10 text-yellow-400 border-yellow-500/30",
};

function tierBadge(tier: string, t: (k: string) => string) {
  const key = `customers.tier_${tier}`;
  const label = t(key) !== key ? t(key) : tier.charAt(0).toUpperCase() + tier.slice(1);
  const dot = tier === "gold" ? "bg-yellow-400" : tier === "silver" ? "bg-slate-400" : "bg-amber-400";
  return (
    <span className={`inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium border ${TIER_COLORS[tier] || TIER_COLORS.bronze}`}>
      <span className={`w-1.5 h-1.5 rounded-full ${dot}`} />
      {label}
    </span>
  );
}

const EMPTY_FORM = { name: "", phone: "", email: "", address: "", delivery_notes: "", notes: "", is_vip: false };

export default function CustomersDirectoryPage() {
  const { token, business } = useAuthStore();
  const { t, locale } = useI18n();
  const [data, setData] = useState<Customer[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState("");
  const { page, perPage, setPage, resetPage, changePageSize } = usePagination();
  const [segments, setSegments] = useState<CustomerSegments | null>(null);

  const [detailCustomer, setDetailCustomer] = useState<Customer | null>(null);
  const [detailOpen, setDetailOpen] = useState(false);

  const [formOpen, setFormOpen] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [form, setForm] = useState(EMPTY_FORM);
  const [saving, setSaving] = useState(false);
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});
  const [toast, setToast] = useState<{ type: "success" | "error"; msg: string } | null>(null);

  const [confirmDelete, setConfirmDelete] = useState(false);
  const [deletingId, setDeletingId] = useState<number | null>(null);

  const showToast = (type: "success" | "error", msg: string) => {
    setToast({ type, msg });
    setTimeout(() => setToast(null), 3000);
  };

  const columns: Column[] = [
    { key: "name", label: t("common.name") },
    { key: "phone", label: t("crm.phone") },
    {
      key: "is_vip",
      label: "VIP",
      render: (_v, row) => {
        const c = row as unknown as Customer;
        return c.is_vip ? (
          <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-amber-500/10 text-amber-400 border border-amber-500/30">
            <Star className="w-3 h-3" /> VIP
          </span>
        ) : null;
      },
    },
    {
      key: "loyalty_points_balance",
      label: t("crm.loyalty_points"),
      render: (_v, row) => {
        const c = row as unknown as Customer;
        const pts = c.loyalty_card?.points_balance ?? c.loyalty_points_balance ?? 0;
        return <span className="font-mono">{pts}</span>;
      },
    },
    {
      key: "total_spend",
      label: t("crm.total_spend"),
      render: (_v, row) => formatCurrency(Number((row as unknown as Customer).total_spend) || 0, locale),
    },
    { key: "total_visits", label: t("crm.total_visits"), type: "number" },
    {
      key: "last_visit_date",
      label: t("crm.last_visit"),
      render: (_v, row) => {
        const c = row as unknown as Customer;
        return c.last_visit_date ? new Date(c.last_visit_date).toLocaleDateString() : t("crm.never");
      },
    },
    {
      key: "tier_level",
      label: t("crm.tier"),
      render: (_v, row) => tierBadge((row as unknown as Customer).tier_level || "bronze", t),
    },
  ];

  const fetchData = useCallback(() => {
    if (!token || !business) return;
    setLoading(true);
    const params: Record<string, string | number> = { page, per_page: perPage };
    if (search.trim()) params.search = search.trim();
    Customers.list(token, business.id, params)
      .then((res) => {
        setData(res.data);
        setTotal(res.total);
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [token, business, search, page, perPage]);

  const fetchSegments = useCallback(() => {
    if (!token || !business) return;
    fetchCustomerSegments(token, business.id).then(setSegments).catch(() => {});
  }, [token, business]);

  useEffect(() => {
    const timer = setTimeout(fetchData, 300);
    return () => clearTimeout(timer);
  }, [fetchData]);
  useEffect(() => { fetchSegments(); }, [fetchSegments]);

  const openDetail = (row: Record<string, unknown>) => {
    const c = row as unknown as Customer;
    setDetailCustomer(c);
    setDetailOpen(true);
  };

  const openCreate = () => {
    setEditingId(null);
    setForm({ ...EMPTY_FORM });
    setFormErrors({});
    setFormOpen(true);
  };

  const openEdit = (c: Customer) => {
    setEditingId(c.id);
    setForm({
      name: c.name ?? "",
      phone: c.phone ?? "",
      email: c.email ?? "",
      address: c.address ?? "",
      delivery_notes: c.delivery_notes ?? "",
      notes: c.notes ?? "",
      is_vip: c.is_vip ?? false,
    });
    setFormErrors({});
    setFormOpen(true);
  };

  const handleSave = async () => {
    if (!token || !business) return;
    setSaving(true);
    setFormErrors({});
    try {
      if (editingId) {
        const updated = await Customers.update(token, business.id, String(editingId), form);
        setData((prev) => prev.map((c) => (c.id === editingId ? { ...c, ...updated } : c)));
        showToast("success", t("customers.updated") || "Customer updated");
      } else {
        const created = await Customers.create(token, business.id, form);
        setData((prev) => [created, ...prev]);
        showToast("success", t("customers.created") || "Customer created");
      }
      setFormOpen(false);
    } catch (err) {
      setFormErrors(mapFieldErrors(err, ["name", "phone", "email", "address", "delivery_notes", "notes", "is_vip"]));
      const msg = (err as { message?: string }).message;
      if (msg) showToast("error", msg);
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async () => {
    if (!token || !business || !deletingId) return;
    try {
      await Customers.delete(token, business.id, String(deletingId));
      setData((prev) => prev.filter((c) => c.id !== deletingId));
      showToast("success", t("customers.deleted") || "Customer deleted");
    } catch (err) {
      const msg = (err as { message?: string }).message;
      showToast("error", msg || "Failed to delete");
    } finally {
      setConfirmDelete(false);
      setDeletingId(null);
    }
  };

  const inputCls = (err?: string) =>
    `w-full px-3 py-2 bg-card/80 border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-primary/50 transition-colors ${err ? "border-red-500/50" : "border-border"}`;

  const fieldErr = (k: string) => formErrors[k] && (
    <p className="text-xs text-red-400 mt-1">{formErrors[k]}</p>
  );

  return (
    <motion.div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.4 }}>
      <PageHeader title={t("crm.customers")} subtitle={t("crm.customers_subtitle")} action={<button onClick={openCreate} className="flex items-center gap-2 px-4 py-2 bg-primary/20 hover:bg-primary/30 text-primary-light rounded-xl text-sm font-medium transition-colors"><Plus className="w-4 h-4" />{t("crm.new_customer") || "New Customer"}</button>} />

      {/* KPI */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div className="glass rounded-2xl p-4 flex items-center gap-3">
          <div className="p-2.5 rounded-xl bg-blue-500/10"><Users className="w-5 h-5 text-blue-400" /></div>
          <div><p className="text-xs text-muted">{t("crm.total_customers")}</p><p className="text-lg font-semibold text-foreground">{segments?.total ?? data.length}</p></div>
        </div>
        <div className="glass rounded-2xl p-4 flex items-center gap-3">
          <div className="p-2.5 rounded-xl bg-emerald-500/10"><ShoppingBag className="w-5 h-5 text-emerald-400" /></div>
          <div><p className="text-xs text-muted">{t("crm.active_customers")}</p><p className="text-lg font-semibold text-foreground">{segments?.active ?? 0}</p></div>
        </div>
        <div className="glass rounded-2xl p-4 flex items-center gap-3">
          <div className="p-2.5 rounded-xl bg-red-500/10"><AlertTriangle className="w-5 h-5 text-red-400" /></div>
          <div><p className="text-xs text-muted">{t("crm.lost_customers")}</p><p className="text-lg font-semibold text-foreground">{segments?.lost ?? 0}</p></div>
        </div>
        <div className="glass rounded-2xl p-4 flex items-center gap-3">
          <div className="p-2.5 rounded-xl bg-amber-500/10"><Star className="w-5 h-5 text-amber-400" /></div>
          <div><p className="text-xs text-muted">{t("crm.vip_customers")}</p><p className="text-lg font-semibold text-foreground">{segments?.vip ?? 0}</p></div>
        </div>
      </div>

      {/* Search */}
      <div className="mb-4">
        <div className="relative max-w-sm">
          <Search className="absolute start-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted" />
          <input type="text" placeholder={t("crm.search_placeholder") ?? "Search customers..."} value={search} onChange={(e) => {
            setSearch(e.target.value);
            resetPage();
          }}
            className="w-full ps-10 pe-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors" />
          {search && <button onClick={() => { setSearch(""); resetPage(); }} className="absolute end-3 top-1/2 -translate-y-1/2 text-muted hover:text-foreground"><X className="w-4 h-4" /></button>}
        </div>
      </div>

      <DataTable
        columns={columns}
        data={data as unknown as Record<string, unknown>[]}
        loading={loading}
        emptyMessage={t("crm.no_customers")}
        emptyIcon={Users}
        onRowClick={openDetail}
        pagination={{ total, page, perPage, onPageChange: setPage, onPerPageChange: changePageSize }}
      />

      {/* ─── Detail SlideOver ─────────────────────────── */}
      <SlideOver open={detailOpen} onClose={() => setDetailOpen(false)} title={t("crm.customer_details")} width="max-w-xl">
        {detailCustomer && (
          <div className="space-y-5">
            <div className="glass rounded-xl p-4 space-y-3">
              <div className="flex items-center justify-between gap-2">
                <h3 className="text-base font-semibold text-foreground">{detailCustomer.name}</h3>
                <div className="flex items-center gap-2">
                  {detailCustomer.is_vip && <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-amber-500/10 text-amber-400 border border-amber-500/30"><Star className="w-3 h-3" /> VIP</span>}
                  {tierBadge(detailCustomer.tier_level || "bronze", t)}
                </div>
              </div>
              {detailCustomer.loyalty_card_number && <div className="flex items-center gap-2 text-sm"><span className="text-muted">{t("crm.card_number")}:</span><span className="font-mono text-foreground">{detailCustomer.loyalty_card_number}</span></div>}
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div className="glass rounded-xl p-3"><p className="text-xs text-muted">{t("crm.loyalty_points")}</p><p className="text-lg font-semibold text-foreground">{detailCustomer.loyalty_card?.points_balance ?? detailCustomer.loyalty_points_balance ?? 0}</p></div>
              <div className="glass rounded-xl p-3"><p className="text-xs text-muted">{t("crm.total_spend")}</p><p className="text-lg font-semibold text-foreground">{formatCurrency(Number(detailCustomer.total_spend) || 0, locale)}</p></div>
              <div className="glass rounded-xl p-3"><p className="text-xs text-muted">{t("crm.total_visits")}</p><p className="text-lg font-semibold text-foreground">{detailCustomer.total_visits}</p></div>
              <div className="glass rounded-xl p-3"><p className="text-xs text-muted">{t("crm.last_visit")}</p><p className="text-lg font-semibold text-foreground">{detailCustomer.last_visit_date ? new Date(detailCustomer.last_visit_date).toLocaleDateString() : t("crm.never")}</p></div>
            </div>
            <div className="glass rounded-xl p-4 space-y-3">
              <h4 className="text-sm font-medium text-foreground">{t("crm.customer_details")}</h4>
              <div className="space-y-2 text-sm">
                {detailCustomer.phone && <div className="flex items-center gap-2 text-muted"><Phone className="w-4 h-4 shrink-0" /><span>{detailCustomer.phone}</span></div>}
                {detailCustomer.email && <div className="flex items-center gap-2 text-muted"><span className="w-4 h-4 shrink-0 text-center text-xs">@</span><span>{detailCustomer.email}</span></div>}
                {detailCustomer.address && <div className="text-muted">{detailCustomer.address}</div>}
                {detailCustomer.delivery_notes && <div className="text-muted text-xs italic">{detailCustomer.delivery_notes}</div>}
                {detailCustomer.notes && <div className="text-muted text-xs italic">{detailCustomer.notes}</div>}
              </div>
            </div>
            <div className="flex gap-2">
              <button onClick={() => { setDetailOpen(false); setTimeout(() => openEdit(detailCustomer), 200); }} className="flex items-center gap-2 px-4 py-2 bg-primary/20 hover:bg-primary/30 text-primary-light rounded-xl text-sm font-medium transition-colors"><Pencil className="w-4 h-4" />{t("common.edit") || "Edit"}</button>
              <button onClick={() => { setDetailOpen(false); setTimeout(() => { setDeletingId(detailCustomer.id); setConfirmDelete(true); }, 200); }} className="flex items-center gap-2 px-4 py-2 bg-red-500/10 hover:bg-red-500/20 text-red-400 rounded-xl text-sm font-medium transition-colors"><Trash2 className="w-4 h-4" />{t("common.delete") || "Delete"}</button>
            </div>
          </div>
        )}
      </SlideOver>

      {/* ─── Create / Edit SlideOver ──────────────────── */}
      <SlideOver open={formOpen} onClose={() => setFormOpen(false)} title={editingId ? (t("customers.edit") || "Edit Customer") : (t("customers.create") || "Create Customer")} width="max-w-xl">
        <div className="space-y-4">
          <div>
            <label className="text-xs text-muted uppercase tracking-wider block mb-1.5">{t("common.name")} *</label>
            <input type="text" value={form.name} onChange={(e) => setForm((p) => ({ ...p, name: e.target.value }))} placeholder={t("customers.name_placeholder") || "Customer name"} className={inputCls(formErrors.name)} />
            {fieldErr("name")}
          </div>
          <div>
            <label className="text-xs text-muted uppercase tracking-wider block mb-1.5">{t("crm.phone")} *</label>
            <input type="tel" value={form.phone} onChange={(e) => setForm((p) => ({ ...p, phone: e.target.value }))} placeholder="+962 7XXXXXXXX" className={inputCls(formErrors.phone)} />
            {fieldErr("phone")}
          </div>
          <div>
            <label className="text-xs text-muted uppercase tracking-wider block mb-1.5">{t("common.email")}</label>
            <input type="email" value={form.email} onChange={(e) => setForm((p) => ({ ...p, email: e.target.value }))} placeholder="email@example.com" className={inputCls(formErrors.email)} />
            {fieldErr("email")}
          </div>
          <div>
            <label className="text-xs text-muted uppercase tracking-wider block mb-1.5">{t("customers.address") || "Address"}</label>
            <input type="text" value={form.address} onChange={(e) => setForm((p) => ({ ...p, address: e.target.value }))} className={inputCls(formErrors.address)} />
          </div>
          <div>
            <label className="text-xs text-muted uppercase tracking-wider block mb-1.5">{t("customers.delivery_notes") || "Delivery Notes"}</label>
            <textarea value={form.delivery_notes} onChange={(e) => setForm((p) => ({ ...p, delivery_notes: e.target.value }))} rows={2} className={inputCls(formErrors.delivery_notes)} />
          </div>
          <div>
            <label className="text-xs text-muted uppercase tracking-wider block mb-1.5">{t("customers.notes") || "Notes"}</label>
            <textarea value={form.notes} onChange={(e) => setForm((p) => ({ ...p, notes: e.target.value }))} rows={2} className={inputCls(formErrors.notes)} />
          </div>
          <label className="flex items-center gap-3 p-3 bg-card/60 border border-border rounded-xl cursor-pointer hover:border-border-hover transition-colors">
            <input type="checkbox" checked={form.is_vip} onChange={(e) => setForm((p) => ({ ...p, is_vip: e.target.checked }))} className="w-4 h-4 rounded border-border text-primary focus:ring-primary/50 bg-card/80" />
            <span className="text-sm text-foreground">{t("customers.mark_vip") || "Mark as VIP Customer"}</span>
          </label>
          <div className="flex justify-end gap-3 pt-4 border-t border-border">
            <button onClick={() => setFormOpen(false)} className="px-4 py-2 text-sm text-muted hover:text-foreground transition-colors">{t("common.cancel") || "Cancel"}</button>
            <button onClick={handleSave} disabled={saving} className="flex items-center gap-2 px-5 py-2 bg-primary/20 hover:bg-primary/30 text-primary-light rounded-xl text-sm font-medium transition-colors disabled:opacity-50">
              {saving && <Loader2 className="w-4 h-4 animate-spin" />}
              {saving ? (t("common.saving") || "Saving...") : (t("common.save") || "Save")}
            </button>
          </div>
        </div>
      </SlideOver>

      {/* ─── Delete Confirm ───────────────────────────── */}
      <ConfirmDialog open={confirmDelete} onClose={() => { setConfirmDelete(false); setDeletingId(null); }} onConfirm={handleDelete} title={t("customers.delete_title") || "Delete Customer"} message={t("customers.delete_confirm") || "Are you sure? This cannot be undone."} />

      {/* ─── Toast ────────────────────────────────────── */}
      {toast && (
        <div className={`fixed top-4 end-4 z-50 px-4 py-3 rounded-xl text-sm font-medium shadow-lg ${toast.type === "success" ? "bg-emerald-500/20 text-emerald-400 border border-emerald-500/30" : "bg-red-500/20 text-red-400 border border-red-500/30"}`}>
          {toast.msg}
        </div>
      )}
    </motion.div>
  );
}
