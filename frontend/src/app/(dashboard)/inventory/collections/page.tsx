"use client";

import { useEffect, useState, useCallback } from "react";
import { motion } from "framer-motion";
import { useAuthStore } from "@/stores/auth-store";
import { Collections, ApiError } from "@/lib/api";
import type { Collection } from "@/lib/types";
import { mapFieldErrors } from "@/lib/validation";
import { usePagination } from "@/lib/pagination";
import PageHeader from "@/components/ui/PageHeader";
import DataTable from "@/components/ui/DataTable";
import type { Column } from "@/components/ui/DataTable";
import SlideOver from "@/components/ui/SlideOver";
import ConfirmDialog from "@/components/ui/ConfirmDialog";
import { Layers, Plus, Loader2, AlertCircle, Trash2 } from "lucide-react";
import { useI18n } from "@/lib/i18n";

const SEASONS = ["spring", "summer", "autumn", "winter"] as const;

const emptyForm = {
  name: "",
  season: "",
  year: "",
  description: "",
  start_date: "",
  end_date: "",
  is_active: true,
};

export default function CollectionsPage() {
  const { t } = useI18n();
  const { token, business } = useAuthStore();
  const [data, setData] = useState<Collection[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const { page, perPage, setPage, changePageSize } = usePagination();
  const [slideOpen, setSlideOpen] = useState(false);
  const [editing, setEditing] = useState<Collection | null>(null);
  const [form, setForm] = useState(emptyForm);
  const [saving, setSaving] = useState(false);
  const [deleteConfirmOpen, setDeleteConfirmOpen] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [toast, setToast] = useState<{ msg: string; type: "success" | "error" } | null>(null);

  const showToast = useCallback((msg: string, type: "success" | "error") => {
    setToast({ msg, type });
    window.setTimeout(() => setToast(null), 3000);
  }, []);

  const columns: Column[] = [
    { key: "name", label: t("collections.name") },
    { key: "season", label: t("collections.season"), type: "badge" },
    { key: "year", label: t("collections.year") },
    {
      key: "products_count",
      label: t("common.products"),
      type: "number",
      render: (v) => t("collections.products_count", { count: String(v ?? 0) }),
    },
    { key: "is_active", label: t("collections.is_active"), type: "boolean" },
  ];

  const fetchData = useCallback(() => {
    if (!token || !business) return;
    setLoading(true);
    Collections.list(token, business.id, { page, per_page: perPage })
      .then((res) => {
        const rows = Array.isArray(res) ? res : (res as { data: Collection[] }).data;
        setData(rows);
        if (!Array.isArray(res)) setTotal((res as { total?: number }).total ?? rows.length);
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [token, business, page, perPage]);

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

  const openEdit = (row: Record<string, unknown>) => {
    const c = row as unknown as Collection;
    setEditing(c);
    setForm({
      name: c.name,
      season: c.season ?? "",
      year: c.year ?? "",
      description: c.description ?? "",
      start_date: c.start_date?.slice(0, 10) ?? "",
      end_date: c.end_date?.slice(0, 10) ?? "",
      is_active: c.is_active,
    });
    setErrors({});
    setSlideOpen(true);
  };

  const handleSave = async () => {
    if (!token || !business) return;
    const fieldErrors: Record<string, string> = {};
    if (!form.name.trim()) fieldErrors.name = t("collections.required");
    if (!form.season) fieldErrors.season = t("collections.season_required");
    if (!form.year.trim()) fieldErrors.year = t("collections.year_required");
    if (Object.keys(fieldErrors).length > 0) {
      setErrors(fieldErrors);
      return;
    }
    setSaving(true);
    const payload: Record<string, unknown> = {
      name: form.name.trim(),
      season: form.season,
      year: form.year.trim(),
      description: form.description.trim() || null,
      start_date: form.start_date || null,
      end_date: form.end_date || null,
      is_active: form.is_active,
    };
    try {
      if (editing) {
        await Collections.update(token, business.id, editing.id, payload);
        showToast(t("collections.updated"), "success");
      } else {
        await Collections.create(token, business.id, payload);
        showToast(t("collections.created"), "success");
      }
      setSlideOpen(false);
      setErrors({});
      fetchData();
    } catch (err) {
      const apiErr = err instanceof ApiError ? err : null;
      setErrors(apiErr ? mapFieldErrors(apiErr, ["name", "season", "year", "start_date", "end_date"]) : {});
      showToast(apiErr?.message || t("common.error"), "error");
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async () => {
    if (!token || !business || !editing) return;
    setDeleting(true);
    try {
      await Collections.delete(token, business.id, editing.id);
      setSlideOpen(false);
      setDeleteConfirmOpen(false);
      setEditing(null);
      showToast(t("collections.deleted"), "success");
      fetchData();
    } catch {
      showToast(t("common.error"), "error");
    } finally {
      setDeleting(false);
    }
  };

  return (
    <motion.div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.4 }}>
      <PageHeader
        title={t("collections.title")}
        subtitle={t("collections.subtitle")}
        action={
          <button onClick={openCreate} className="flex items-center gap-2 px-4 py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors">
            <Plus className="w-4 h-4" /> {t("collections.add")}
          </button>
        }
      />

      <DataTable
        columns={columns}
        data={data as unknown as Record<string, unknown>[]}
        loading={loading}
        emptyMessage={t("collections.empty")}
        emptyIcon={Layers}
        onRowClick={openEdit}
        pagination={{ total, page, perPage, onPageChange: setPage, onPerPageChange: changePageSize }}
      />

      <SlideOver open={slideOpen} onClose={() => setSlideOpen(false)} title={editing ? t("collections.edit") : t("collections.create")}>
        <div className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">{t("collections.name")}</label>
            <input
              type="text"
              value={form.name}
              onChange={(e) => {
                setForm((p) => ({ ...p, name: e.target.value }));
                if (errors.name) setErrors((p) => ({ ...p, name: "" }));
              }}
              className={`w-full px-4 py-2.5 bg-card/80 border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-primary/50 transition-colors ${errors.name ? "border-red-500/50" : "border-border"}`}
            />
            {errors.name && (
              <p className="flex items-center gap-1 text-xs text-red-400 mt-1">
                <AlertCircle className="w-3 h-3" /> {errors.name}
              </p>
            )}
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-sm font-medium text-muted mb-1.5">{t("collections.season")}</label>
              <select
                value={form.season}
                onChange={(e) => {
                  setForm((p) => ({ ...p, season: e.target.value }));
                  if (errors.season) setErrors((p) => ({ ...p, season: "" }));
                }}
                className={`w-full px-4 py-2.5 bg-card/80 border rounded-xl text-sm text-foreground focus:outline-none focus:border-primary/50 transition-colors ${errors.season ? "border-red-500/50" : "border-border"}`}
              >
                <option value="">{t("collections.season_placeholder")}</option>
                {SEASONS.map((s) => (
                  <option key={s} value={s}>
                    {t(`collections.season_${s}`)}
                  </option>
                ))}
              </select>
              {errors.season && (
                <p className="flex items-center gap-1 text-xs text-red-400 mt-1">
                  <AlertCircle className="w-3 h-3" /> {errors.season}
                </p>
              )}
            </div>
            <div>
              <label className="block text-sm font-medium text-muted mb-1.5">{t("collections.year")}</label>
              <input
                type="text"
                inputMode="numeric"
                value={form.year}
                onChange={(e) => {
                  setForm((p) => ({ ...p, year: e.target.value }));
                  if (errors.year) setErrors((p) => ({ ...p, year: "" }));
                }}
                placeholder="2026"
                className={`w-full px-4 py-2.5 bg-card/80 border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-primary/50 transition-colors ${errors.year ? "border-red-500/50" : "border-border"}`}
              />
              {errors.year && (
                <p className="flex items-center gap-1 text-xs text-red-400 mt-1">
                  <AlertCircle className="w-3 h-3" /> {errors.year}
                </p>
              )}
            </div>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-sm font-medium text-muted mb-1.5">{t("collections.start_date")}</label>
              <input
                type="date"
                value={form.start_date}
                onChange={(e) => setForm((p) => ({ ...p, start_date: e.target.value }))}
                className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-primary/50 transition-colors"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-muted mb-1.5">{t("collections.end_date")}</label>
              <input
                type="date"
                value={form.end_date}
                onChange={(e) => setForm((p) => ({ ...p, end_date: e.target.value }))}
                className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-primary/50 transition-colors"
              />
            </div>
          </div>

          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">{t("collections.description")}</label>
            <textarea
              value={form.description}
              onChange={(e) => setForm((p) => ({ ...p, description: e.target.value }))}
              rows={3}
              className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-primary/50 transition-colors resize-none"
            />
          </div>

          <label className="flex items-center gap-2 cursor-pointer">
            <input
              type="checkbox"
              checked={form.is_active}
              onChange={(e) => setForm((p) => ({ ...p, is_active: e.target.checked }))}
              className="w-4 h-4 accent-emerald-500"
            />
            <span className="text-sm font-medium text-muted">{t("collections.is_active")}</span>
          </label>

          <div className="flex gap-3 pt-2">
            {editing && (
              <button
                type="button"
                onClick={() => setDeleteConfirmOpen(true)}
                className="flex items-center gap-1.5 px-3 py-2.5 bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/30 rounded-xl text-sm font-medium transition-colors"
              >
                <Trash2 className="w-4 h-4" />
              </button>
            )}
            <button
              onClick={handleSave}
              disabled={saving}
              className={`py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors disabled:opacity-50 ${editing ? "flex-1" : "w-full"}`}
            >
              {saving ? (
                <span className="flex items-center justify-center gap-2">
                  <Loader2 className="w-4 h-4 animate-spin" /> {t("common.saving")}
                </span>
              ) : editing ? (
                t("common.save")
              ) : (
                t("collections.create")
              )}
            </button>
          </div>
        </div>
      </SlideOver>

      <ConfirmDialog
        open={deleteConfirmOpen}
        onClose={() => setDeleteConfirmOpen(false)}
        onConfirm={handleDelete}
        title={t("collections.delete_title")}
        message={t("collections.delete_message", { name: editing?.name ?? "" })}
        loading={deleting}
      />

      {toast && (
        <div className={`fixed top-4 end-4 z-[60] px-4 py-2.5 rounded-xl text-sm font-medium shadow-lg ${toast.type === "success" ? "bg-emerald-500/10 text-emerald-400 border border-emerald-500/30" : "bg-red-500/10 text-red-400 border border-red-500/30"}`}>
          {toast.msg}
        </div>
      )}
    </motion.div>
  );
}
