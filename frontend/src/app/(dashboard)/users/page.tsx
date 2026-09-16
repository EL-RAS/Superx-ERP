"use client";

import { useEffect, useState, useCallback } from "react";
import { motion } from "framer-motion";
import { useAuthStore } from "@/stores/auth-store";
import { Users as UsersApi, fetchRoleMatrix, ApiError } from "@/lib/api";
import type { AuthUser, Role } from "@/lib/types";
import { isValidEmail } from "@/lib/phone";
import { mapFieldErrors } from "@/lib/validation";
import { usePagination } from "@/lib/pagination";
import PageHeader from "@/components/ui/PageHeader";
import DataTable from "@/components/ui/DataTable";
import type { Column } from "@/components/ui/DataTable";
import SlideOver from "@/components/ui/SlideOver";
import StatusBadge from "@/components/ui/StatusBadge";
import ConfirmDialog from "@/components/ui/ConfirmDialog";
import PasswordInput from "@/components/ui/PasswordInput";
import { useI18n } from "@/lib/i18n";
import { Users, Plus, Search, AlertCircle, ShieldCheck, Check } from "lucide-react";

const PASSWORD_RULE = {
  minLength: 8,
  uppercase: /[A-Z]/,
  lowercase: /[a-z]/,
  number: /\d/,
  special: /[@$!%*?&]/,
};

function isStrongPassword(password: string): boolean {
  return (
    password.length >= PASSWORD_RULE.minLength &&
    PASSWORD_RULE.uppercase.test(password) &&
    PASSWORD_RULE.lowercase.test(password) &&
    PASSWORD_RULE.number.test(password) &&
    PASSWORD_RULE.special.test(password)
  );
}

const emptyForm = { name: "", email: "", username: "", password: "", role: "staff", is_active: "active" };

export default function UsersPage() {
  const { t } = useI18n();
  const { token, business, user: currentUser } = useAuthStore();
  const [data, setData] = useState<AuthUser[]>([]);
  const [total, setTotal] = useState(0);
  const [roles, setRoles] = useState<Role[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState("");
  const { page, perPage, setPage, resetPage, changePageSize } = usePagination();
  const [slideOpen, setSlideOpen] = useState(false);
  const [editing, setEditing] = useState<AuthUser | null>(null);
  const [form, setForm] = useState(emptyForm);
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [deleteConfirmOpen, setDeleteConfirmOpen] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [toast, setToast] = useState<{ msg: string; type: "success" | "error" } | null>(null);

  const passwordChecks = [
    { label: t("users.min_chars"), ok: form.password.length >= PASSWORD_RULE.minLength },
    { label: t("users.uppercase"), ok: PASSWORD_RULE.uppercase.test(form.password) },
    { label: t("users.lowercase"), ok: PASSWORD_RULE.lowercase.test(form.password) },
    { label: t("users.number"), ok: PASSWORD_RULE.number.test(form.password) },
    { label: t("users.special"), ok: PASSWORD_RULE.special.test(form.password) },
  ];
  const satisfiedChecks = passwordChecks.filter((c) => c.ok).length;

  const showToast = useCallback((msg: string, type: "success" | "error") => {
    setToast({ msg, type });
    window.setTimeout(() => setToast(null), 3000);
  }, []);

  const canDelete = Boolean(editing) && !editing?.is_primary_admin && editing?.id !== currentUser?.id;

  const validate = useCallback((): Record<string, string> => {
    const e: Record<string, string> = {};
    if (!form.name.trim()) e.name = t("users.name_required");
    if (!form.email.trim()) e.email = t("users.email_required");
    else if (!isValidEmail(form.email)) e.email = t("users.email_invalid");
    if (!form.username.trim()) e.username = t("users.username_required");
    else if (form.username.length < 3) e.username = t("users.username_min");
    else if (!/^[a-zA-Z0-9_]+$/.test(form.username)) e.username = t("users.username_chars");
    if (!editing && !form.password) e.password = t("users.password_required");
    else if (form.password && !isStrongPassword(form.password)) e.password = t("users.password_weak");
    if (!form.role) e.role = t("users.role_required");
    return e;
  }, [form, editing, t]);

  const columns: Column[] = [
    { key: "name", label: t("users.name") },
    { key: "email", label: t("users.email") },
    { key: "username", label: t("users.username") },
    { key: "role", label: t("users.role"), render: (v, row) => (
      <span className="inline-flex items-center gap-1.5">
        {Boolean((row as Record<string, unknown>).is_primary_admin) && (
          <ShieldCheck className="w-4 h-4 text-primary" aria-label={t("users.primary_admin")} />
        )}
        <span className="capitalize">{String(v)}</span>
      </span>
    ) },
    { key: "is_active", label: t("users.status"), render: (v) => <StatusBadge status={v ? "active" : "inactive"} /> },
  ];

  const fetchData = useCallback(() => {
    if (!token || !business) return;
    setLoading(true);
    const params: Record<string, string | number> = { page, per_page: perPage };
    if (search.trim()) params.search = search.trim();
    UsersApi.list(token, business.id, params)
      .then((res) => {
        setData(res.data as unknown as AuthUser[]);
        setTotal(res.total);
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
    fetchRoleMatrix(token, business.id)
      .then((res) => setRoles(res.roles))
      .catch(() => {});
  }, [token, business]);

  const openCreate = () => {
    setEditing(null);
    setForm(emptyForm);
    setErrors({});
    setSlideOpen(true);
  };

  const openEdit = (row: Record<string, unknown> | AuthUser) => {
    const u = row as AuthUser;
    setEditing(u);
    setForm({
      name: u.name,
      email: u.email ?? "",
      username: u.username,
      password: "",
      role: u.role,
      is_active: u.is_active === false ? "inactive" : "active",
    });
    setErrors({});
    setSlideOpen(true);
  };

  const handleSave = async () => {
    if (!token || !business) return;
    const fieldErrors = validate();
    setErrors(fieldErrors);
    if (Object.keys(fieldErrors).length > 0) {
      showToast(t("users.fix_fields"), "error");
      return;
    }
    setSaving(true);
    const isActive = form.is_active === "active";
    const payload: Record<string, unknown> = {
      name: form.name.trim(),
      email: form.email.trim(),
      username: form.username.trim(),
      role: form.role,
      is_active: isActive,
    };
    if (form.password) payload.password = form.password;
    try {
      if (editing) {
        await UsersApi.update(token, business.id, editing.id, payload);
        showToast(t("users.updated"), "success");
      } else {
        await UsersApi.create(token, business.id, payload);
        showToast(t("users.created"), "success");
      }
      setSlideOpen(false);
      setErrors({});
      fetchData();
    } catch (err) {
      const apiErr = err instanceof ApiError ? err : null;
      setErrors(apiErr ? mapFieldErrors(apiErr, ["name", "email", "username", "password", "role", "is_active"]) : {});
      showToast(apiErr?.message || t("users.failed"), "error");
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async () => {
    if (!token || !business || !editing) return;
    setDeleting(true);
    try {
      await UsersApi.delete(token, business.id, editing.id);
      setSlideOpen(false);
      setDeleteConfirmOpen(false);
      setEditing(null);
      showToast(t("users.deleted"), "success");
      fetchData();
    } catch (err) {
      const msg = err instanceof Error ? err.message : "";
      showToast(msg || t("users.delete_failed"), "error");
      setDeleteConfirmOpen(false);
    } finally {
      setDeleting(false);
    }
  };

  const inputClass = (error?: string) =>
    `w-full px-4 py-2.5 bg-card/80 border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-primary/50 transition-colors ${error ? "border-red-500/50" : "border-border"}`;

  return (
    <motion.div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.4 }}>
      <PageHeader
        title={t("users.title")}
        subtitle={t("users.subtitle", { count: String(data.length) })}
        action={
          <button onClick={openCreate} className="flex items-center gap-2 px-4 py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors">
            <Plus className="w-4 h-4" /> {t("users.create")}
          </button>
        }
      />
      <div className="mb-4">
        <div className="relative max-w-sm">
          <Search className="absolute start-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted" />
          <input type="text" placeholder={t("users.search")} value={search}
            onChange={(e) => {
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
        emptyMessage={t("users.empty")}
        emptyIcon={Users}
        onRowClick={openEdit}
        pagination={{ total, page, perPage, onPageChange: setPage, onPerPageChange: changePageSize }}
      />
      <SlideOver open={slideOpen} onClose={() => setSlideOpen(false)} title={editing ? t("users.edit") : t("users.create")}>
        <div className="space-y-4">
          {([
            { label: t("users.name"), key: "name", type: "text" },
            { label: t("users.email"), key: "email", type: "email" },
            { label: t("users.username"), key: "username", type: "text" },
            { label: t("users.password"), key: "password", type: "password" },
          ] as const).map((field) => {
            const error = errors[field.key];
            const value = (form as Record<string, string>)[field.key];
            return (
              <div key={field.key}>
                <label className="block text-sm font-medium text-muted mb-1.5">{field.label}</label>
                {field.type === "password" ? (
                  <PasswordInput
                    value={value}
                    placeholder={editing ? t("users.leave_blank") : undefined}
                    autoComplete="new-password"
                    error={error}
                    onChange={(v) => {
                      setForm((p) => ({ ...p, [field.key]: v }));
                      if (errors[field.key]) setErrors((p) => ({ ...p, [field.key]: "" }));
                    }}
                  />
                ) : (
                  <input
                    type={field.type}
                    value={value}
                    autoComplete="off"
                    onChange={(e) => {
                      setForm((p) => ({ ...p, [field.key]: e.target.value }));
                      if (errors[field.key]) setErrors((p) => ({ ...p, [field.key]: "" }));
                    }}
                    className={inputClass(error)}
                  />
                )}
                {error && (
                  <p className="flex items-center gap-1 text-xs text-red-400 mt-1">
                    <AlertCircle className="w-3 h-3" /> {error}
                  </p>
                )}
                {field.key === "password" && form.password.length > 0 && (
                  <div className="mt-2 space-y-1.5">
                    <div className="flex gap-1">
                      {passwordChecks.map((c, i) => (
                        <div key={i} className={`h-1.5 flex-1 rounded-full ${c.ok ? "bg-emerald-400" : "bg-muted/20"}`} />
                      ))}
                    </div>
                    <ul className="grid grid-cols-1 gap-1">
                      {passwordChecks.map((c, i) => (
                        <li key={i} className={`flex items-center gap-1.5 text-xs ${c.ok ? "text-emerald-400" : "text-muted"}`}>
                          <Check className={`w-3 h-3 ${c.ok ? "text-emerald-400" : "text-muted/50"}`} /> {c.label}
                        </li>
                      ))}
                    </ul>
                    <p className="text-xs text-muted">
                      {satisfiedChecks < 5 ? t("users.requirements", { count: String(satisfiedChecks) }) : t("users.strong")}
                    </p>
                  </div>
                )}
              </div>
            );
          })}
          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">{t("users.role")}</label>
            <select value={form.role}
              onChange={(e) => {
                setForm((p) => ({ ...p, role: e.target.value }));
                if (errors.role) setErrors((p) => ({ ...p, role: "" }));
              }}
              className={inputClass(errors.role)}>
              {roles.length === 0 && <option value="admin">{t("profile.role_admin")}</option>}
              {roles.map((role) => (
                <option key={role.id} value={role.slug}>{role.name}</option>
              ))}
            </select>
            {errors.role && (
              <p className="flex items-center gap-1 text-xs text-red-400 mt-1">
                <AlertCircle className="w-3 h-3" /> {errors.role}
              </p>
            )}
          </div>
          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">{t("users.status")}</label>
            <select value={form.is_active}
              disabled={Boolean(editing?.is_primary_admin)}
              onChange={(e) => {
                setForm((p) => ({ ...p, is_active: e.target.value }));
                if (errors.is_active) setErrors((p) => ({ ...p, is_active: "" }));
              }}
              className={`${inputClass(errors.is_active)} ${editing?.is_primary_admin ? "opacity-60 cursor-not-allowed" : ""}`}>
              <option value="active">{t("users.active")}</option>
              <option value="inactive">{t("users.inactive")}</option>
            </select>
            {editing?.is_primary_admin && (
              <p className="text-xs text-muted mt-1">{t("users.primary_must_active")}</p>
            )}
          </div>
          <div className="flex gap-3">
            {canDelete && (
              <button
                type="button"
                onClick={() => setDeleteConfirmOpen(true)}
                className="flex-1 py-2.5 bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/30 rounded-xl text-sm font-medium transition-colors">
                {t("users.delete")}
              </button>
            )}
            <button onClick={handleSave} disabled={saving}
              className={`py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors disabled:opacity-50 ${canDelete ? "flex-1" : "w-full"}`}>
              {saving ? t("common.saving") : editing ? t("users.update") : t("users.create")}
            </button>
          </div>
        </div>
      </SlideOver>

      <ConfirmDialog
        open={deleteConfirmOpen}
        onClose={() => setDeleteConfirmOpen(false)}
        onConfirm={handleDelete}
        loading={deleting}
        title={t("users.delete_title")}
        message={editing ? t("users.delete_message", { name: editing.name }) : ""}
        confirmLabel={t("users.delete")}
      />

      {toast && (
        <div className={`fixed top-4 end-4 z-[100] px-4 py-3 rounded-xl text-sm font-medium shadow-lg border ${toast.type === "success" ? "bg-emerald-500/20 text-emerald-400 border-emerald-500/30" : "bg-red-500/20 text-red-400 border-red-500/30"}`}>
          {toast.msg}
        </div>
      )}
    </motion.div>
  );
}
