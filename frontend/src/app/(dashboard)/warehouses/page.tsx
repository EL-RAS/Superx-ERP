"use client";

import { useEffect, useState, useCallback } from "react";
import { motion } from "framer-motion";
import { useAuthStore } from "@/stores/auth-store";
import { Warehouses } from "@/lib/api";
import type { Warehouse } from "@/lib/types";
import { usePagination } from "@/lib/pagination";
import PageHeader from "@/components/ui/PageHeader";
import DataTable from "@/components/ui/DataTable";
import type { Column } from "@/components/ui/DataTable";
import SlideOver from "@/components/ui/SlideOver";
import StatusBadge from "@/components/ui/StatusBadge";
import { Building2, Plus, Search, Loader2 } from "lucide-react";
import { useI18n } from "@/lib/i18n";
import ConfirmDialog from "@/components/ui/ConfirmDialog";

const emptyForm = { name: "", code: "", location: "", is_active: "true" };

export default function WarehousesPage() {
  const { t } = useI18n();
  const { token, business } = useAuthStore();
  const [data, setData] = useState<Warehouse[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState("");
  const { page, perPage, setPage, resetPage, changePageSize } = usePagination();
  const [slideOpen, setSlideOpen] = useState(false);
  const [editing, setEditing] = useState<Warehouse | null>(null);
  const [form, setForm] = useState(emptyForm);
  const [saving, setSaving] = useState(false);
  const [deleteConfirmOpen, setDeleteConfirmOpen] = useState(false);
  const [deleting, setDeleting] = useState(false);

  const columns: Column[] = [
    { key: "name", label: t("common.name") },
    { key: "code", label: t("warehouses.code") },
    { key: "location", label: t("warehouses.location") },
    { key: "is_active", label: t("common.status"), render: (v) => <StatusBadge status={v ? "active" : "inactive"} /> },
  ];

  const fetchData = useCallback(() => {
    if (!token || !business) return;
    setLoading(true);
    const params: Record<string, string | number> = { page, per_page: perPage };
    if (search.trim()) params.search = search.trim();
    Warehouses.list(token, business.id, params)
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
    setSlideOpen(true);
  };

  const openEdit = (row: Record<string, unknown>) => {
    const w = row as unknown as Warehouse;
    setEditing(w);
    setForm({ name: w.name, code: w.code, location: w.location ?? "", is_active: String(w.is_active) });
    setSlideOpen(true);
  };

  const handleSave = async () => {
    if (!token || !business || !form.name || !form.code) return;
    setSaving(true);
    try {
      const payload = { name: form.name, code: form.code, location: form.location || null, is_active: form.is_active === "true" };
      if (editing) {
        await Warehouses.update(token, business.id, editing.id, payload);
      } else {
        await Warehouses.create(token, business.id, payload);
      }
      setSlideOpen(false);
      fetchData();
    } catch {} finally {
      setSaving(false);
    }
  };

  return (
    <motion.div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.4 }}>
      <PageHeader title={t("warehouses.title")} subtitle={t("warehouses.subtitle", { count: String(data.length) })} action={
        <button onClick={openCreate} className="flex items-center gap-2 px-4 py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors">
          <Plus className="w-4 h-4" /> {t("warehouses.add")}
        </button>
      } />
      <div className="mb-4">
        <div className="relative max-w-sm">
          <Search className="absolute start-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted" />
          <input type="text" placeholder={t("warehouses.search")} value={search} onChange={(e) => {
            setSearch(e.target.value);
            resetPage();
          }}
            className="w-full ps-10 pe-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors" />
        </div>
      </div>
      <DataTable
        columns={columns}
        data={data as unknown as Record<string, unknown>[]}
        loading={loading}
        emptyMessage={t("warehouses.empty")}
        emptyIcon={Building2}
        onRowClick={openEdit}
        pagination={{ total, page, perPage, onPageChange: setPage, onPerPageChange: changePageSize }}
      />
      <SlideOver open={slideOpen} onClose={() => setSlideOpen(false)} title={editing ? t("warehouses.edit") : t("warehouses.add")}>
        <div className="space-y-4">
          {([{ label: t("common.name"), key: "name" }, { label: t("warehouses.code"), key: "code" }, { label: t("warehouses.location"), key: "location" }] as const).map((field) => (
            <div key={field.key}>
              <label className="block text-sm font-medium text-muted mb-1.5">{field.label}</label>
              <input type="text" value={form[field.key]} onChange={(e) => setForm((p) => ({ ...p, [field.key]: e.target.value }))}
                className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors" />
            </div>
          ))}
          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">{t("common.status")}</label>
            <select value={form.is_active} onChange={(e) => setForm((p) => ({ ...p, is_active: e.target.value }))}
              className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors">
              <option value="true">{t("warehouses.active")}</option>
              <option value="false">{t("warehouses.inactive")}</option>
            </select>
          </div>
          <div className="flex gap-3">
            {editing && (
              <button type="button" onClick={() => setDeleteConfirmOpen(true)}
                className="flex-1 py-2.5 bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/30 rounded-xl text-sm font-medium transition-colors">
                {t("common.delete")}
              </button>
            )}
            <button onClick={handleSave} disabled={saving || !form.name || !form.code}
              className={`py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors disabled:opacity-50 ${editing ? "flex-1" : "w-full"}`}>
              {saving ? <Loader2 className="w-4 h-4 animate-spin mx-auto" /> : editing ? t("common.update") : t("common.create")}
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
            await Warehouses.delete(token, business.id, editing.id);
            setSlideOpen(false);
            setDeleteConfirmOpen(false);
            setEditing(null);
            fetchData();
          } catch {
          } finally {
            setDeleting(false);
          }
        }}
        title={t("warehouses.delete_title")}
        message={t("warehouses.delete_message", { name: editing?.name ?? "" })}
        confirmLabel={t("common.delete")}
        loading={deleting}
      />
    </motion.div>
  );
}
