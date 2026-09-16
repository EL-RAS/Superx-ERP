"use client";

import { useCallback, useEffect, useState } from "react";
import { motion } from "framer-motion";
import { useAuthStore } from "@/stores/auth-store";
import { fetchRoleMatrix, Roles, updateRolePermissions } from "@/lib/api";
import Pagination from "@/components/ui/Pagination";
import { usePagination, paginationParams } from "@/lib/pagination";
import type { Role, RbacModule } from "@/lib/types";
import { RBAC_ACTIONS } from "@/lib/types";
import { useI18n } from "@/lib/i18n";
import PageHeader from "@/components/ui/PageHeader";
import SlideOver from "@/components/ui/SlideOver";
import ConfirmDialog from "@/components/ui/ConfirmDialog";
import { toast } from "sonner";
import { Shield, Plus, Trash2, Loader2, Check } from "lucide-react";

export default function RolesPage() {
  const { token, business } = useAuthStore();
  const { t } = useI18n();
  const [modules, setModules] = useState<RbacModule[]>([]);
  const [roles, setRoles] = useState<Role[]>([]);
  const [total, setTotal] = useState(0);
  const { page, perPage, setPage, changePageSize } = usePagination();
  const [loading, setLoading] = useState(true);
  const [savingKey, setSavingKey] = useState<string | null>(null);
  const [slideOpen, setSlideOpen] = useState(false);
  const [form, setForm] = useState({ name: "", description: "" });
  const [saving, setSaving] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState<Role | null>(null);
  const [deleting, setDeleting] = useState(false);

  const fetchData = useCallback(() => {
    if (!token || !business) return;
    setLoading(true);
    Roles.list(token, business.id, paginationParams(page, perPage) as Record<string, string | number>)
      .then((res) => {
        setRoles(res.data);
        setTotal(res.total);
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [token, business, page, perPage]);

  useEffect(() => {
    const timer = setTimeout(fetchData, 0);
    return () => clearTimeout(timer);
  }, [fetchData]);

  useEffect(() => {
    if (!token || !business || modules.length > 0) return;
    fetchRoleMatrix(token, business.id)
      .then((res) => setModules(res.modules))
      .catch(() => {});
  }, [token, business, modules.length]);

  const permissionOf = (role: Role, module: string, action: string): boolean =>
    role.permissions.includes(`${module}.${action}`);

  const handleToggle = async (role: Role, module: string, action: string) => {
    if (!token || !business || role.slug === "admin") return;

    const key = `${module}.${action}`;
    const next = role.permissions.includes(key)
      ? role.permissions.filter((p) => p !== key)
      : [...role.permissions, key];

    setRoles((prev) => prev.map((r) => (r.id === role.id ? { ...r, permissions: next } : r)));
    setSavingKey(role.id);

    try {
      await updateRolePermissions(token, business.id, role.id, next);
      toast.success(t("roles.saved"));
    } catch (err) {
      const msg = err instanceof Error ? err.message : "";
      toast.error(msg || t("roles.permission_save_failed"));
      fetchData();
    } finally {
      setSavingKey(null);
    }
  };

  const openCreate = () => {
    setForm({ name: "", description: "" });
    setSlideOpen(true);
  };

  const handleSave = async () => {
    if (!token || !business || !form.name.trim()) return;
    setSaving(true);
    try {
      await Roles.create(token, business.id, { name: form.name.trim(), description: form.description.trim() || null, permissions: [] });
      toast.success(t("roles.created"));
      setSlideOpen(false);
      fetchData();
    } catch (err) {
      const msg = err instanceof Error ? err.message : "";
      toast.error(msg || t("common.error"));
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async () => {
    if (!token || !business || !deleteTarget) return;
    setDeleting(true);
    try {
      await Roles.delete(token, business.id, deleteTarget.id);
      toast.success(t("roles.deleted"));
      setDeleteTarget(null);
      fetchData();
    } catch (err) {
      const msg = err instanceof Error ? err.message : "";
      toast.error(msg || t("roles.delete_failed"));
      setDeleteTarget(null);
    } finally {
      setDeleting(false);
    }
  };

  return (
    <motion.div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.4 }}>
      <PageHeader
        title={t("roles.title")}
        subtitle={t("roles.subtitle")}
        action={
          <button onClick={openCreate} className="flex items-center gap-2 px-4 py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors">
            <Plus className="w-4 h-4" /> {t("roles.create")}
          </button>
        }
      />

      <div className="glass rounded-2xl overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead>
              <tr className="border-b border-border">
                <th className="px-4 py-3 text-start text-xs font-medium text-muted uppercase tracking-wider min-w-[160px]">{t("roles.role")}</th>
                {modules.map((mod) => (
                  <th key={mod.key} className="px-2 py-3 text-center text-xs font-medium text-muted uppercase tracking-wider min-w-[96px]">
                    {mod.label}
                  </th>
                ))}
                <th className="px-4 py-3 text-center text-xs font-medium text-muted uppercase tracking-wider min-w-[90px]"> </th>
              </tr>
            </thead>
            <tbody>
              {loading && (
                <tr>
                  <td colSpan={modules.length + 2} className="px-4 py-10 text-center text-sm text-muted">
                    <Loader2 className="w-5 h-5 animate-spin mx-auto" />
                  </td>
                </tr>
              )}
              {!loading && roles.length === 0 && (
                <tr>
                  <td colSpan={modules.length + 2} className="px-4 py-10 text-center text-sm text-muted">
                    {t("roles.empty")}
                  </td>
                </tr>
              )}
              {roles.map((role) => {
                const isAdmin = role.slug === "admin";
                return (
                  <tr key={role.id} className="border-b border-border/50 hover:bg-card-hover/50 transition-colors">
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-2">
                        <Shield className={`w-4 h-4 ${isAdmin ? "text-primary" : "text-muted"}`} />
                        <div>
                          <div className="text-sm font-medium text-foreground">{role.name}</div>
                          <div className="text-xs text-muted">
                            {isAdmin ? t("roles.admin_full") : role.is_system ? t("roles.system") : t("roles.custom")}
                          </div>
                        </div>
                      </div>
                    </td>
                    {modules.map((mod) => (
                      <td key={mod.key} className="px-2 py-3 text-center">
                        <div className="flex items-center justify-center gap-1">
                          {RBAC_ACTIONS.map((action) => {
                            const granted = isAdmin || permissionOf(role, mod.key, action);
                            const disabled = isAdmin;
                            const busy = savingKey === role.id;
                            return (
                              <button
                                key={action}
                                type="button"
                                disabled={disabled || busy}
                                onClick={() => handleToggle(role, mod.key, action)}
                                title={disabled ? `${mod.label} · ${action}` : `${mod.label} · ${action}`}
                                className={`inline-flex items-center justify-center w-6 h-6 rounded-md text-xs transition-colors ${
                                  granted ? "bg-primary/20 text-primary" : "bg-muted/10 text-muted/40"
                                } ${disabled ? "cursor-default" : "hover:bg-primary/40 cursor-pointer"}`}
                              >
                                {granted ? <Check className="w-3 h-3" /> : "—"}
                              </button>
                            );
                          })}
                        </div>
                      </td>
                    ))}
                    <td className="px-4 py-3">
                      <div className="flex items-center justify-center gap-1">
                        {!role.is_system && (
                          <button
                            type="button"
                            onClick={() => setDeleteTarget(role)}
                            className="p-1.5 rounded-lg text-muted hover:text-red-400 hover:bg-red-500/10 transition-colors"
                            title={t("common.delete")}
                          >
                            <Trash2 className="w-4 h-4" />
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
        <Pagination total={total} page={page} perPage={perPage} onPageChange={setPage} onPerPageChange={changePageSize} />
        <div className="p-4 border-t border-border flex flex-wrap items-center gap-4 text-xs text-muted">
          <span className="flex items-center gap-1">
            <span className="inline-flex items-center justify-center w-5 h-5 rounded bg-primary/20 text-primary"><Check className="w-3 h-3" /></span> {t("roles.granted")}
          </span>
          <span className="flex items-center gap-1">
            <span className="inline-flex items-center justify-center w-5 h-5 rounded bg-muted/10 text-muted/40">—</span> {t("roles.denied")}
          </span>
          <span>{t("roles.actions_order")}</span>
        </div>
      </div>

      <SlideOver open={slideOpen} onClose={() => setSlideOpen(false)} title={t("roles.create")}>
        <div className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">{t("roles.name")}</label>
            <input
              type="text"
              placeholder={t("roles.name_placeholder")}
              value={form.name}
              onChange={(e) => setForm((p) => ({ ...p, name: e.target.value }))}
              className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">{t("roles.description")}</label>
            <textarea
              rows={3}
              placeholder={t("roles.description_placeholder")}
              value={form.description}
              onChange={(e) => setForm((p) => ({ ...p, description: e.target.value }))}
              className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors resize-none"
            />
          </div>
          <p className="text-xs text-muted">{t("roles.system_locked")}</p>
          <button
            onClick={handleSave}
            disabled={saving || !form.name.trim()}
            className="w-full py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors disabled:opacity-50"
          >
            {saving ? t("common.saving") : t("roles.create")}
          </button>
        </div>
      </SlideOver>

      <ConfirmDialog
        open={!!deleteTarget}
        onClose={() => setDeleteTarget(null)}
        onConfirm={handleDelete}
        loading={deleting}
        title={t("roles.delete_confirm_title")}
        message={deleteTarget ? t("roles.delete_confirm_message", { name: deleteTarget.name }) : ""}
      />
    </motion.div>
  );
}
