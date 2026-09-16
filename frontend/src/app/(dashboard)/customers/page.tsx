"use client";

import { useEffect, useState, useCallback } from "react";
import { motion } from "framer-motion";
import { useAuthStore } from "@/stores/auth-store";
import { Customers, fetchCustomerStatement, ApiError } from "@/lib/api";
import type { Customer, CustomerStatement } from "@/lib/types";
import { formatCurrency } from "@/lib/types";
import { normalizePhone, isValidEmail, isValidPhone } from "@/lib/phone";
import { mapFieldErrors } from "@/lib/validation";
import { usePagination } from "@/lib/pagination";
import PageHeader from "@/components/ui/PageHeader";
import DataTable from "@/components/ui/DataTable";
import type { Column } from "@/components/ui/DataTable";
import SlideOver from "@/components/ui/SlideOver";
import { Users, Plus, Search, FileText, Loader2, AlertCircle, CreditCard, Award } from "lucide-react";
import { useI18n } from "@/lib/i18n";
import ConfirmDialog from "@/components/ui/ConfirmDialog";

const emptyForm = { name: "", email: "", phone: "", address: "", size_top: "", size_bottom: "", shoe_size: "", fit_preference: "", preferred_brands: "" };

const TIER_STYLES: Record<string, string> = {
  bronze: "bg-amber-500/10 text-amber-500 border border-amber-500/30",
  silver: "bg-slate-400/10 text-slate-300 border border-slate-400/30",
  gold: "bg-yellow-500/10 text-yellow-400 border border-yellow-500/30",
  platinum: "bg-purple-500/10 text-purple-400 border border-purple-500/30",
};

export default function CustomersPage() {
  const { t } = useI18n();
  const { token, business } = useAuthStore();
  const [data, setData] = useState<Customer[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState("");
  const { page, perPage, setPage, resetPage, changePageSize } = usePagination();
  const [slideOpen, setSlideOpen] = useState(false);
  const [editing, setEditing] = useState<Customer | null>(null);
  const [form, setForm] = useState(emptyForm);
  const [saving, setSaving] = useState(false);
  const [deleteConfirmOpen, setDeleteConfirmOpen] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [statementOpen, setStatementOpen] = useState(false);
  const [statementData, setStatementData] = useState<CustomerStatement | null>(null);
  const [statementLoading, setStatementLoading] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [toast, setToast] = useState<{ msg: string; type: "success" | "error" } | null>(null);

  const validate = useCallback((): Record<string, string> => {
    const e: Record<string, string> = {};
    if (!form.name.trim()) e.name = t("customers.required");
    if (form.email.trim() && !isValidEmail(form.email)) e.email = t("customers.invalid_email");
    if (form.phone.trim() && !isValidPhone(form.phone)) e.phone = t("customers.invalid_phone");
    return e;
  }, [form, t]);

  const showToast = useCallback((msg: string, type: "success" | "error") => {
    setToast({ msg, type });
    window.setTimeout(() => setToast(null), 3000);
  }, []);

  const columns: Column[] = [
    { key: "name", label: t("common.name") },
    { key: "email", label: t("common.email") },
    { key: "phone", label: t("common.phone") },
    { key: "created_at", label: t("common.created_at"), type: "date" },
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
    const c = row as unknown as Customer;
    setEditing(c);
    setForm({
      name: c.name,
      email: c.email ?? "",
      phone: c.phone ?? "",
      address: c.address ?? "",
      size_top: c.size_top ?? "",
      size_bottom: c.size_bottom ?? "",
      shoe_size: c.shoe_size ?? "",
      fit_preference: c.fit_preference ?? "",
      preferred_brands: Array.isArray(c.preferred_brands) ? (c.preferred_brands as string[]).join(", ") : "",
    });
    setErrors({});
    setSlideOpen(true);
  };

  const openStatement = async () => {
    if (!token || !business || !editing) return;
    setStatementLoading(true);
    setStatementOpen(true);
    try {
      const res = await fetchCustomerStatement(token, business.id, editing.id);
      setStatementData(res);
    } catch {
      setStatementData(null);
    } finally {
      setStatementLoading(false);
    }
  };

  const handleSave = async () => {
    if (!token || !business) return;
    const fieldErrors = validate();
    setErrors(fieldErrors);
    if (Object.keys(fieldErrors).length > 0) {
      showToast(t("customers.invalid_form"), "error");
      return;
    }
    const phone = form.phone.trim();
    const isClothing = business?.business_type.slug === "clothing_apparel";
    const payload: Record<string, unknown> = {
      name: form.name.trim(),
      email: form.email.trim() || null,
      phone: phone ? normalizePhone(phone) ?? phone : null,
      address: form.address.trim() || null,
    };
    if (isClothing) {
      const brands = form.preferred_brands.split(",").map((b) => b.trim()).filter(Boolean);
      payload.size_top = form.size_top.trim() || null;
      payload.size_bottom = form.size_bottom.trim() || null;
      payload.shoe_size = form.shoe_size.trim() || null;
      payload.fit_preference = form.fit_preference.trim() || null;
      payload.preferred_brands = brands.length ? brands : null;
    }
    setSaving(true);
    try {
      if (editing) {
        await Customers.update(token, business.id, editing.id, payload);
        showToast(t("customers.updated"), "success");
      } else {
        await Customers.create(token, business.id, payload);
        showToast(t("customers.created"), "success");
      }
      setSlideOpen(false);
      setErrors({});
      fetchData();
    } catch (err) {
      const apiErr = err instanceof ApiError ? err : null;
      setErrors(apiErr ? mapFieldErrors(apiErr, ["name", "email", "phone", "address"]) : {});
      showToast(apiErr?.message || t("common.error"), "error");
    } finally {
      setSaving(false);
    }
  };

  return (
    <motion.div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.4 }}>
      <PageHeader
        title={t("customers.title")}
        subtitle={t("customers.subtitle", { count: String(data.length) })}
        action={
          <button onClick={openCreate} className="flex items-center gap-2 px-4 py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors">
            <Plus className="w-4 h-4" /> {t("customers.add")}
          </button>
        }
      />

      <div className="mb-4">
        <div className="relative max-w-sm">
          <Search className="absolute start-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted" />
          <input
            type="text"
            placeholder={t("customers.search")}
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
        emptyMessage={t("customers.empty")}
        emptyIcon={Users}
        onRowClick={openEdit}
        pagination={{ total, page, perPage, onPageChange: setPage, onPerPageChange: changePageSize }}
      />

      <SlideOver open={slideOpen} onClose={() => setSlideOpen(false)} title={editing ? t("customers.edit") : t("customers.create")}>
        <div className="space-y-4">
          {editing && editing.loyalty_card_number && (
            <div className="bg-emerald-500/10 border border-emerald-500/20 rounded-xl px-4 py-3 space-y-1.5">
              <div className="flex items-center gap-2 text-xs text-emerald-400 font-medium">
                <CreditCard className="w-3.5 h-3.5" /> {t("customers.loyalty_card")}
              </div>
              <div className="flex items-center justify-between">
                <span className="text-sm font-semibold text-foreground tracking-wide">{editing.loyalty_card_number}</span>
                <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold uppercase ${TIER_STYLES[editing.tier_level] ?? TIER_STYLES.bronze}`}>
                  <Award className="w-3 h-3" /> {editing.tier_level}
                </span>
              </div>
              <div className="text-xs text-muted">
                {t("customers.points_balance")}: <span className="font-medium text-foreground">{editing.loyalty_points_balance}</span>
              </div>
            </div>
          )}
          {([
            { label: t("common.name"), key: "name" },
            { label: t("common.email"), key: "email" },
            { label: t("common.phone"), key: "phone" },
            { label: t("common.address"), key: "address" },
          ] as const).map((field) => {
            const error = errors[field.key];
            const normalizedPhone = field.key === "phone" && form.phone.trim() ? normalizePhone(form.phone) : null;
            return (
              <div key={field.key}>
                <label className="block text-sm font-medium text-muted mb-1.5">{field.label}</label>
                <input
                  type="text"
                  inputMode={field.key === "phone" ? "tel" : undefined}
                  value={(form as Record<string, string>)[field.key]}
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
                    {t("customers.phone_preview", { phone: normalizedPhone })}
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
          {business?.business_type.slug === "clothing_apparel" && (
            <div className="pt-2 border-t border-border space-y-4">
              <div className="text-xs font-semibold uppercase tracking-wide text-muted">{t("customers.size_preferences")}</div>
              <div className="grid grid-cols-2 gap-3">
                {([
                  { label: t("customers.size_top"), key: "size_top" },
                  { label: t("customers.size_bottom"), key: "size_bottom" },
                  { label: t("customers.shoe_size"), key: "shoe_size" },
                  { label: t("customers.fit_preference"), key: "fit_preference" },
                ] as const).map((field) => (
                  <div key={field.key}>
                    <label className="block text-sm font-medium text-muted mb-1.5">{field.label}</label>
                    <input
                      type="text"
                      value={(form as Record<string, string>)[field.key]}
                      onChange={(e) => setForm((p) => ({ ...p, [field.key]: e.target.value }))}
                      className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-primary/50 transition-colors"
                    />
                  </div>
                ))}
              </div>
              <div>
                <label className="block text-sm font-medium text-muted mb-1.5">{t("customers.preferred_brands")}</label>
                <input
                  type="text"
                  value={form.preferred_brands}
                  onChange={(e) => setForm((p) => ({ ...p, preferred_brands: e.target.value }))}
                  placeholder={t("customers.preferred_brands_placeholder")}
                  className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-primary/50 transition-colors"
                />
              </div>
            </div>
          )}
          <div className="flex gap-3">
            {editing && (
              <>
                <button type="button" onClick={openStatement}
                  className="py-2.5 px-3 bg-blue-500/10 hover:bg-blue-500/20 text-blue-400 border border-blue-500/30 rounded-xl text-sm font-medium transition-colors">
                  <FileText className="w-4 h-4" />
                </button>
                <button type="button" onClick={() => setDeleteConfirmOpen(true)}
                  className="flex-1 py-2.5 bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/30 rounded-xl text-sm font-medium transition-colors">
                  {t("common.delete")}
                </button>
              </>
            )}
            <button
              onClick={handleSave}
              disabled={saving}
              className={`py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors disabled:opacity-50 ${editing ? "flex-1" : "w-full"}`}
            >
              {saving ? t("common.saving") : editing ? t("customers.save") : t("customers.create")}
            </button>
          </div>
        </div>
      </SlideOver>

      <SlideOver open={statementOpen} onClose={() => setStatementOpen(false)} title={t("customers.statement.title")}>
        {statementLoading ? (
          <div className="flex items-center justify-center py-16">
            <Loader2 className="w-6 h-6 animate-spin text-muted" />
          </div>
        ) : statementData ? (
          <div className="space-y-4">
            <div className="flex items-center justify-between bg-card/60 border border-border rounded-xl p-4">
              <div>
                <div className="text-sm font-semibold text-foreground">{statementData.customer.name}</div>
                <div className="text-xs text-muted mt-0.5">
                  {statementData.account_name} ({statementData.account_code})
                </div>
              </div>
              <div className="text-end">
                <div className="text-xs text-muted">{t("customers.statement.balance")}</div>
                <div className="text-lg font-bold text-primary">{formatCurrency(statementData.balance)}</div>
              </div>
            </div>

            <div className="overflow-x-auto rounded-xl border border-border">
              <table className="w-full text-sm">
                <thead>
                  <tr className="bg-card/60 text-muted text-xs uppercase tracking-wide">
                    <th className="text-start px-4 py-2.5 font-medium">{t("customers.statement.date")}</th>
                    <th className="text-start px-4 py-2.5 font-medium">{t("customers.statement.reference")}</th>
                    <th className="text-start px-4 py-2.5 font-medium">{t("customers.statement.type")}</th>
                    <th className="text-end px-4 py-2.5 font-medium">{t("customers.statement.debit")}</th>
                    <th className="text-end px-4 py-2.5 font-medium">{t("customers.statement.credit")}</th>
                    <th className="text-end px-4 py-2.5 font-medium">{t("customers.statement.balance")}</th>
                  </tr>
                </thead>
                <tbody>
                  {statementData.lines.length === 0 ? (
                    <tr>
                      <td colSpan={6} className="px-4 py-8 text-center text-muted">{t("customers.statement.empty")}</td>
                    </tr>
                  ) : (
                    statementData.lines.map((line, i) => (
                      <tr key={i} className="border-t border-border">
                        <td className="px-4 py-2.5 whitespace-nowrap">{line.date}</td>
                        <td className="px-4 py-2.5">{line.reference}</td>
                        <td className="px-4 py-2.5">
                          <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${line.type === "invoice" ? "bg-blue-500/10 text-blue-400" : "bg-green-500/10 text-green-400"}`}>
                            {line.type === "invoice" ? t("customers.statement.invoice") : t("customers.statement.payment")}
                          </span>
                        </td>
                        <td className="px-4 py-2.5 text-end tabular-nums">{line.debit ? formatCurrency(line.debit) : "—"}</td>
                        <td className="px-4 py-2.5 text-end tabular-nums">{line.credit ? formatCurrency(line.credit) : "—"}</td>
                        <td className="px-4 py-2.5 text-end tabular-nums font-medium">{formatCurrency(line.balance)}</td>
                      </tr>
                    ))
                  )}
                </tbody>
                <tfoot>
                  <tr className="border-t border-border bg-card/60 font-semibold">
                    <td colSpan={3} className="px-4 py-3">{t("customers.statement.totals")}</td>
                    <td className="px-4 py-3 text-end tabular-nums">{formatCurrency(statementData.total_debit)}</td>
                    <td className="px-4 py-3 text-end tabular-nums">{formatCurrency(statementData.total_credit)}</td>
                    <td className="px-4 py-3 text-end tabular-nums">{formatCurrency(statementData.balance)}</td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </div>
        ) : (
          <div className="py-16 text-center text-muted text-sm">{t("customers.statement.error")}</div>
        )}
      </SlideOver>

      <ConfirmDialog
        open={deleteConfirmOpen}
        onClose={() => setDeleteConfirmOpen(false)}
        onConfirm={async () => {
          if (!token || !business || !editing) return;
          setDeleting(true);
          try {
            await Customers.delete(token, business.id, editing.id);
            setSlideOpen(false);
            setDeleteConfirmOpen(false);
            setEditing(null);
            showToast(t("customers.deleted"), "success");
            fetchData();
          } catch {
            showToast(t("common.error"), "error");
          } finally {
            setDeleting(false);
          }
        }}
        title={t("customers.delete_title")}
        message={t("customers.delete_message", { name: editing?.name ?? "" })}
        confirmLabel={t("common.delete")}
        loading={deleting}
      />

      {toast && (
        <div className={`fixed top-4 end-4 z-[100] px-4 py-3 rounded-xl text-sm font-medium shadow-lg border ${toast.type === "success" ? "bg-emerald-500/20 text-emerald-400 border-emerald-500/30" : "bg-red-500/20 text-red-400 border-red-500/30"}`}>
          {toast.msg}
        </div>
      )}
    </motion.div>
  );
}
