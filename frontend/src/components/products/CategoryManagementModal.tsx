"use client";

import { Fragment, useCallback, useEffect, useMemo, useState } from "react";
import type { ReactNode } from "react";
import { motion, AnimatePresence } from "framer-motion";
import { toast } from "sonner";
import { Check, ChevronDown, ChevronRight, Folder, Loader2, Pencil, Plus, Tag, Trash2, X } from "lucide-react";
import { useI18n } from "@/lib/i18n";
import { createCategory, deleteCategory, fetchCategories, updateCategory } from "@/lib/api";
import type { CategoryPayload } from "@/lib/api";
import type { Category } from "@/lib/types";

interface CategoryManagementModalProps {
  open: boolean;
  onClose: () => void;
  onChanged: (categories: Category[]) => void;
  token: string;
  businessId: string;
}

const DEFAULT_COLOR = "#22C55E";

export default function CategoryManagementModal({ open, onClose, onChanged, token, businessId }: CategoryManagementModalProps) {
  const { t, locale } = useI18n();

  const [cats, setCats] = useState<Category[]>([]);
  const [loading, setLoading] = useState(false);

  const [createName, setCreateName] = useState("");
  const [createNameAr, setCreateNameAr] = useState("");
  const [createColor, setCreateColor] = useState(DEFAULT_COLOR);
  const [createParentId, setCreateParentId] = useState("");
  const [createSortOrder, setCreateSortOrder] = useState("");
  const [creating, setCreating] = useState(false);

  const [editId, setEditId] = useState<number | null>(null);
  const [editName, setEditName] = useState("");
  const [editNameAr, setEditNameAr] = useState("");
  const [editColor, setEditColor] = useState(DEFAULT_COLOR);
  const [editParentId, setEditParentId] = useState("");
  const [editSortOrder, setEditSortOrder] = useState("");
  const [savingEdit, setSavingEdit] = useState(false);

  const [expandedIds, setExpandedIds] = useState<Set<number>>(new Set());

  const [deleteTarget, setDeleteTarget] = useState<Category | null>(null);
  const [reassignId, setReassignId] = useState("");
  const [deleting, setDeleting] = useState(false);

  const displayName = useCallback(
    (cat: Category) => (locale === "ar" && cat.name_ar ? cat.name_ar : cat.name),
    [locale],
  );

  const childrenMap = useMemo(() => {
    const map = new Map<number, Category[]>();
    for (const c of cats) {
      if (c.parent_id == null) continue;
      const arr = map.get(c.parent_id) ?? [];
      arr.push(c);
      map.set(c.parent_id, arr);
    }
    for (const arr of map.values()) {
      arr.sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0) || a.name.localeCompare(b.name));
    }
    return map;
  }, [cats]);

  const roots = useMemo(
    () =>
      cats
        .filter((c) => c.parent_id == null)
        .sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0) || a.name.localeCompare(b.name)),
    [cats],
  );

  const sortedCats = useMemo(
    () => [...cats].sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0) || a.name.localeCompare(b.name)),
    [cats],
  );

  const descendantIdsOf = useCallback(
    (id: number) => {
      const out = new Set<number>();
      const stack = [...(childrenMap.get(id) ?? [])];
      while (stack.length) {
        const cur = stack.pop() as Category;
        out.add(cur.id);
        stack.push(...(childrenMap.get(cur.id) ?? []));
      }
      return out;
    },
    [childrenMap],
  );

  const editParentOptions = useMemo(() => {
    if (editId == null) return [];
    const forbid = descendantIdsOf(editId);
    forbid.add(editId);
    return sortedCats.filter((c) => !forbid.has(c.id));
  }, [sortedCats, descendantIdsOf, editId]);

  const load = () => {
    setLoading(true);
    fetchCategories(token, businessId)
      .then((res) => {
        const list = Array.isArray(res) ? res : [];
        setCats(list);
        onChanged(list);
        setExpandedIds(
          new Set(list.filter((c) => list.some((x) => x.parent_id === c.id)).map((c) => c.id)),
        );
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    let raf = 0;
    if (open) {
      raf = requestAnimationFrame(() => {
        load();
        setCreateName("");
        setCreateNameAr("");
        setCreateColor(DEFAULT_COLOR);
        setCreateParentId("");
        setCreateSortOrder("");
        setEditId(null);
        setDeleteTarget(null);
      });
    }
    return () => cancelAnimationFrame(raf);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const toggleExpand = (id: number) => {
    setExpandedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const handleCreate = async () => {
    const name = createName.trim();
    if (!name || !token || !businessId) return;
    setCreating(true);
    try {
      const payload: CategoryPayload = {
        name,
        name_ar: createNameAr.trim() || null,
        color: createColor,
        parent_id: createParentId ? Number(createParentId) : null,
      };
      if (createSortOrder !== "") payload.sort_order = Number(createSortOrder);
      await createCategory(token, businessId, payload);
      toast.success(t("products.category_created"));
      setCreateName("");
      setCreateNameAr("");
      setCreateColor(DEFAULT_COLOR);
      setCreateParentId("");
      setCreateSortOrder("");
      load();
    } catch (err) {
      toast.error(err instanceof Error && err.message ? err.message : t("common.error"));
    } finally {
      setCreating(false);
    }
  };

  const startEdit = (cat: Category) => {
    if (editId === cat.id) return;
    setEditId(cat.id);
    setEditName(cat.name);
    setEditNameAr(cat.name_ar ?? "");
    setEditColor(cat.color ?? DEFAULT_COLOR);
    setEditParentId(cat.parent_id != null ? String(cat.parent_id) : "");
    setEditSortOrder(cat.sort_order != null && cat.sort_order > 0 ? String(cat.sort_order) : "");
  };

  const cancelEdit = () => {
    setEditId(null);
    setEditName("");
    setEditNameAr("");
    setEditColor(DEFAULT_COLOR);
    setEditParentId("");
    setEditSortOrder("");
  };

  const saveEdit = async (cat: Category) => {
    const name = editName.trim();
    if (!name || !token || !businessId) return;
    const payload: CategoryPayload = {
      name,
      name_ar: editNameAr.trim() || null,
      color: editColor,
      parent_id: editParentId ? Number(editParentId) : null,
    };
    if (editSortOrder !== "") payload.sort_order = Number(editSortOrder);
    setSavingEdit(true);
    try {
      await updateCategory(token, businessId, cat.id, payload);
      toast.success(t("products.category_updated"));
      cancelEdit();
      load();
    } catch (err) {
      toast.error(err instanceof Error && err.message ? err.message : t("common.error"));
    } finally {
      setSavingEdit(false);
    }
  };

  const requestDelete = (cat: Category) => {
    setDeleteTarget(cat);
    setReassignId("");
  };

  const confirmDelete = async () => {
    if (!deleteTarget || !token || !businessId) return;
    setDeleting(true);
    try {
      await deleteCategory(token, businessId, deleteTarget.id, {
        reassign_to: reassignId ? Number(reassignId) : null,
      });
      toast.success(t("products.category_deleted"));
      setDeleteTarget(null);
      load();
    } catch (err) {
      toast.error(err instanceof Error && err.message ? err.message : t("common.error"));
    } finally {
      setDeleting(false);
    }
  };

  const renderRow = (cat: Category, depth: number): ReactNode => {
    const editing = editId === cat.id;
    const kids = childrenMap.get(cat.id) ?? [];
    const hasKids = kids.length > 0;
    const open = expandedIds.has(cat.id);
    const count = cat.linked_products_count ?? 0;
    return (
      <Fragment key={cat.id}>
        <div className="flex items-center gap-3 px-3 py-2.5 border-b border-border last:border-0"
          style={{ paddingInlineStart: 12 + depth * 22 }}>
          {hasKids ? (
            <button onClick={() => toggleExpand(cat.id)}
              className="w-5 h-5 flex items-center justify-center rounded-md text-muted hover:bg-border/40 shrink-0 transition-colors">
              {open ? <ChevronDown className="w-4 h-4" /> : <ChevronRight className="w-4 h-4" />}
            </button>
          ) : (
            <span className="w-5 h-5 flex items-center justify-center shrink-0">
              <Folder className="w-3.5 h-3.5 text-muted" />
            </span>
          )}
          <span className="w-3 h-3 rounded-full shrink-0" style={{ backgroundColor: cat.color ?? "#64748B" }} />
          <span className="flex-1 min-w-0 truncate text-sm text-foreground">{displayName(cat)}</span>
          {cat.name_ar && cat.name_ar !== cat.name && (
            <span className="text-xs text-muted hidden sm:block truncate max-w-28" dir="rtl">{cat.name_ar}</span>
          )}
          <span className="text-xs text-muted whitespace-nowrap">
            {t("products.category_products_count", { count: String(count) })}
          </span>
          <button onClick={() => startEdit(cat)}
            className="p-2 text-muted hover:text-foreground hover:bg-card-hover rounded-lg transition-colors shrink-0">
            <Pencil className="w-4 h-4" />
          </button>
          <button onClick={() => requestDelete(cat)}
            className="p-2 text-red-400 hover:bg-red-500/10 rounded-lg transition-colors shrink-0">
            <Trash2 className="w-4 h-4" />
          </button>
        </div>

        {editing && (
          <div className="px-4 py-3 border-b border-border bg-card/60 space-y-3"
            style={{ paddingInlineStart: 12 + depth * 22 }}>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="block text-xs font-medium text-muted mb-1">{t("products.category_name")}</label>
                <input
                  type="text"
                  value={editName}
                  onChange={(e) => setEditName(e.target.value)}
                  placeholder={t("products.category_name")}
                  className="w-full px-3 py-2 bg-card/80 border border-border rounded-lg text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors"
                />
              </div>
              <div>
                <label className="block text-xs font-medium text-muted mb-1">{t("products.category_name_ar")}</label>
                <input
                  type="text"
                  dir="rtl"
                  value={editNameAr}
                  onChange={(e) => setEditNameAr(e.target.value)}
                  placeholder={t("products.optional")}
                  className="w-full px-3 py-2 bg-card/80 border border-border rounded-lg text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors"
                />
              </div>
            </div>
            <div className="grid grid-cols-2 gap-3 items-end">
              <div>
                <label className="block text-xs font-medium text-muted mb-1">{t("products.category_parent")}</label>
                <select value={editParentId} onChange={(e) => setEditParentId(e.target.value)}
                  className="w-full px-3 py-2 bg-card/80 border border-border rounded-lg text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors">
                  <option value="">{t("products.category_no_parent")}</option>
                  {editParentOptions.map((c) => (
                    <option key={c.id} value={c.id}>{`${c.parent_id != null ? "– " : ""}${displayName(c)}`}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className="block text-xs font-medium text-muted mb-1">{t("products.category_sort_order")}</label>
                <input
                  type="number"
                  min="0"
                  value={editSortOrder}
                  onChange={(e) => setEditSortOrder(e.target.value)}
                  placeholder={t("products.category_sort_auto")}
                  className="w-full px-3 py-2 bg-card/80 border border-border rounded-lg text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors"
                />
              </div>
            </div>
            <div className="flex items-center justify-between gap-3">
              <div className="flex items-center gap-2">
                <label className="text-xs font-medium text-muted">{t("products.category_color")}</label>
                <input
                  type="color"
                  value={/^#[0-9A-Fa-f]{6}$/.test(editColor) ? editColor : DEFAULT_COLOR}
                  onChange={(e) => setEditColor(e.target.value)}
                  className="h-8 w-10 rounded-lg border border-border cursor-pointer"
                  title={t("products.category_color")}
                />
              </div>
              <div className="flex gap-2">
                <button onClick={() => saveEdit(cat)} disabled={savingEdit || !editName.trim()}
                  className="flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium text-emerald-400 hover:bg-emerald-500/10 transition-colors disabled:opacity-40">
                  {savingEdit ? <Loader2 className="w-4 h-4 animate-spin" /> : <Check className="w-4 h-4" />}
                  {t("common.save")}
                </button>
                <button onClick={cancelEdit} disabled={savingEdit}
                  className="flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium text-muted hover:bg-border/40 transition-colors disabled:opacity-40">
                  {t("common.cancel")}
                </button>
              </div>
            </div>
          </div>
        )}

        {hasKids && open && kids.map((k) => renderRow(k, depth + 1))}
      </Fragment>
    );
  };

  return (
    <AnimatePresence>
      {open && (
        <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 p-4" onClick={() => !deleting && onClose()}>
          <motion.div
            initial={{ opacity: 0, scale: 0.97 }}
            animate={{ opacity: 1, scale: 1 }}
            exit={{ opacity: 0, scale: 0.97 }}
            onClick={(e) => e.stopPropagation()}
            className="bg-card border border-border rounded-2xl shadow-2xl w-full max-w-2xl max-h-[85vh] flex flex-col"
          >
            {/* Header */}
            <div className="flex items-center justify-between px-5 py-4 border-b border-border">
              <h3 className="flex items-center gap-2 text-sm font-semibold text-foreground">
                <Folder className="w-4 h-4 text-primary-light" />
                {t("products.manage_categories")}
              </h3>
              <button onClick={onClose} className="p-2 text-muted hover:text-foreground hover:bg-card-hover rounded-lg transition-colors">
                <X className="w-4 h-4" />
              </button>
            </div>

            <div className="p-5 space-y-4 overflow-y-auto">
              {/* Create category form */}
              <div className="rounded-xl border border-border bg-card/40 p-4 space-y-3">
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="block text-xs font-medium text-muted mb-1">{t("products.category_name")}</label>
                    <input
                      type="text"
                      value={createName}
                      onChange={(e) => setCreateName(e.target.value)}
                      onKeyDown={(e) => e.key === "Enter" && handleCreate()}
                      placeholder={t("products.category_name")}
                      className="w-full px-3 py-2 bg-card/80 border border-border rounded-lg text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors"
                    />
                  </div>
                  <div>
                    <label className="block text-xs font-medium text-muted mb-1">{t("products.category_name_ar")}</label>
                    <input
                      type="text"
                      dir="rtl"
                      value={createNameAr}
                      onChange={(e) => setCreateNameAr(e.target.value)}
                      onKeyDown={(e) => e.key === "Enter" && handleCreate()}
                      placeholder={t("products.optional")}
                      className="w-full px-3 py-2 bg-card/80 border border-border rounded-lg text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors"
                    />
                  </div>
                </div>
                <div className="grid grid-cols-2 gap-3 items-end">
                  <div>
                    <label className="block text-xs font-medium text-muted mb-1">{t("products.category_parent")}</label>
                    <select value={createParentId} onChange={(e) => setCreateParentId(e.target.value)}
                      className="w-full px-3 py-2 bg-card/80 border border-border rounded-lg text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors">
                      <option value="">{t("products.category_no_parent")}</option>
                      {sortedCats.map((c) => (
                        <option key={c.id} value={c.id}>{`${c.parent_id != null ? "– " : ""}${displayName(c)}`}</option>
                      ))}
                    </select>
                  </div>
                  <div>
                    <label className="block text-xs font-medium text-muted mb-1">{t("products.category_sort_order")}</label>
                    <input
                      type="number"
                      min="0"
                      value={createSortOrder}
                      onChange={(e) => setCreateSortOrder(e.target.value)}
                      onKeyDown={(e) => e.key === "Enter" && handleCreate()}
                      placeholder={t("products.category_sort_auto")}
                      className="w-full px-3 py-2 bg-card/80 border border-border rounded-lg text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors"
                    />
                  </div>
                </div>
                <div className="flex items-end gap-3">
                  <div className="flex items-center gap-2">
                    <label className="text-xs font-medium text-muted">{t("products.category_color")}</label>
                    <input
                      type="color"
                      value={/^#[0-9A-Fa-f]{6}$/.test(createColor) ? createColor : DEFAULT_COLOR}
                      onChange={(e) => setCreateColor(e.target.value)}
                      className="h-8 w-10 rounded-lg border border-border cursor-pointer"
                      title={t("products.category_color")}
                    />
                  </div>
                  <button
                    onClick={handleCreate}
                    disabled={creating || !createName.trim()}
                    className="ms-auto flex items-center gap-2 px-4 py-2 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors disabled:opacity-50"
                  >
                    {creating ? <Loader2 className="w-4 h-4 animate-spin" /> : <Plus className="w-4 h-4" />}
                    {t("common.create")}
                  </button>
                </div>
              </div>

              {/* Category list */}
              {loading ? (
                <div className="flex items-center justify-center py-10">
                  <Loader2 className="w-6 h-6 animate-spin text-muted" />
                </div>
              ) : cats.length === 0 ? (
                <div className="flex flex-col items-center justify-center gap-2 py-10 text-muted">
                  <Tag className="w-8 h-8" />
                  <p className="text-sm">{t("products.categories_empty")}</p>
                </div>
              ) : (
                <div className="rounded-xl border border-border overflow-hidden bg-card/40">
                  <div className="px-3 py-2.5 border-b border-border flex items-center gap-3">
                    <span className="flex-1 text-xs font-semibold text-muted uppercase tracking-wide">{t("common.category")}</span>
                    <span className="text-xs font-semibold text-muted uppercase tracking-wide">{t("products.products_linked")}</span>
                    <span className="w-16" />
                  </div>
                  {roots.map((root) => renderRow(root, 0))}
                </div>
              )}
            </div>
          </motion.div>

          {/* Reassign/delete confirmation */}
          {deleteTarget && (
            <div className="fixed inset-0 z-[110] flex items-center justify-center bg-black/60 p-4" onClick={() => !deleting && setDeleteTarget(null)}>
              <motion.div
                initial={{ opacity: 0, scale: 0.95 }}
                animate={{ opacity: 1, scale: 1 }}
                exit={{ opacity: 0, scale: 0.95 }}
                onClick={(e) => e.stopPropagation()}
                className="bg-card border border-border rounded-2xl p-6 w-full max-w-md shadow-2xl"
              >
                <div className="flex items-center gap-3 mb-4">
                  <div className="p-2 rounded-xl bg-red-500/10">
                    <Trash2 className="w-5 h-5 text-red-400" />
                  </div>
                  <h3 className="text-base font-semibold text-foreground">{t("products.category_delete_title")}</h3>
                </div>

                {deleteTarget.linked_products_count && deleteTarget.linked_products_count > 0 ? (
                  <>
                    <p className="text-sm text-muted mb-4">
                      {t("products.category_delete_products_message", { count: String(deleteTarget.linked_products_count) })}
                    </p>
                    <select
                      value={reassignId}
                      onChange={(e) => setReassignId(e.target.value)}
                      className="w-full px-3 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors mb-4"
                    >
                      <option value="">{t("products.category_uncategorized")}</option>
                      {sortedCats.filter((c) => c.id !== deleteTarget.id).map((c) => (
                        <option key={c.id} value={c.id}>{`${c.parent_id != null ? "– " : ""}${displayName(c)}`}</option>
                      ))}
                    </select>
                  </>
                ) : (
                  <p className="text-sm text-muted mb-4">{t("products.category_delete_confirm")}</p>
                )}

                <div className="flex justify-end gap-3">
                  <button
                    onClick={() => setDeleteTarget(null)}
                    disabled={deleting}
                    className="px-4 py-2 rounded-xl text-sm font-medium text-muted hover:text-foreground hover:bg-card-hover transition-colors"
                  >
                    {t("common.cancel")}
                  </button>
                  <button
                    onClick={confirmDelete}
                    disabled={deleting}
                    className="flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-medium bg-red-500 text-white hover:bg-red-600 transition-colors disabled:opacity-50"
                  >
                    {deleting ? <Loader2 className="w-4 h-4 animate-spin" /> : <Trash2 className="w-4 h-4" />}
                    {deleting ? t("common.processing") : t("common.delete")}
                  </button>
                </div>
              </motion.div>
            </div>
          )}
        </div>
      )}
    </AnimatePresence>
  );
}