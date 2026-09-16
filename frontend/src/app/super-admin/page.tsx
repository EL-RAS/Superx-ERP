"use client";

import { useCallback, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import Image from "next/image";
import { motion } from "framer-motion";
import {
  fetchBusinessTypes,
  fetchPlatformTenants,
  createPlatformTenant,
  updatePlatformTenant,
  fetchPlatformLeads,
  approvePlatformLead,
  rejectPlatformLead,
  ApiError,
  BusinessTypeItem,
} from "@/lib/api";
import type {
  PlatformTenant,
  PlatformTenantsResponse,
  PlatformLead,
  PlatformLeadsResponse,
  ApproveLeadResponse,
} from "@/lib/types";
import { isValidPhone, normalizePhone } from "@/lib/phone";
import { useAuthStore } from "@/stores/auth-store";
import { useI18n } from "@/lib/i18n";
import PageHeader from "@/components/ui/PageHeader";
import SlideOver from "@/components/ui/SlideOver";
import ConfirmDialog from "@/components/ui/ConfirmDialog";
import PasswordInput from "@/components/ui/PasswordInput";
import Pagination from "@/components/ui/Pagination";
import { usePagination } from "@/lib/pagination";
import {
  Building2,
  Plus,
  ShieldCheck,
  Users,
  CalendarClock,
  MonitorSmartphone,
  RefreshCw,
  Loader2,
  AlertCircle,
  CheckCircle2,
  XCircle,
  Inbox,
  Phone,
  Mail,
  MapPin,
  Check,
  Copy,
  ExternalLink,
  Send,
  Trash2,
} from "lucide-react";

const inputCls =
  "w-full rounded-xl border border-border bg-card px-3 py-2.5 text-sm text-foreground outline-none focus:border-gold/60 transition-colors placeholder:text-muted/60";

const labelCls = "block text-[11px] font-medium text-muted tracking-wide mb-1";

function StateBadge({ state }: { state: string }) {
  const { t } = useI18n();
  const map: Record<string, string> = {
    active: "bg-emerald-500/10 text-emerald-400 border-emerald-500/30",
    expiring_soon: "bg-amber-500/10 text-amber-400 border-amber-500/30",
    expired: "bg-red-500/10 text-red-400 border-red-500/30",
    suspended: "bg-slate-500/10 text-slate-300 border-slate-500/30",
  };
  const keyMap: Record<string, string> = {
    active: "superadmin.state_active",
    expiring_soon: "superadmin.state_expiring_soon",
    expired: "superadmin.state_expired",
    suspended: "superadmin.state_suspended",
  };
  const key = keyMap[state] ?? keyMap.suspended;
  return (
    <span className={`inline-flex items-center px-2 py-0.5 rounded-full border text-[10px] font-medium ${map[state] ?? map.suspended}`}>
      <span className="w-1 h-1 rounded-full bg-current me-1" />
      {t(key)}
    </span>
  );
}

function DrawerField({
  label,
  value,
  onChange,
  type = "text",
  error,
  hint,
  placeholder,
}: {
  label: string;
  value: string;
  onChange: (v: string) => void;
  type?: string;
  error?: string;
  hint?: string;
  placeholder?: string;
}) {
  return (
    <div>
      <label className={labelCls}>{label}</label>
      {type === "password" ? (
        <PasswordInput
          value={value}
          onChange={onChange}
          placeholder={placeholder}
          autoComplete="new-password"
          error={error}
          className="px-3"
        />
      ) : (
        <input
          className={`${inputCls} ${error ? "border-danger" : ""}`}
          type={type}
          value={value}
          onChange={(e) => onChange(e.target.value)}
          placeholder={placeholder}
          autoComplete="off"
        />
      )}
      {hint && !error && <p className="text-[10px] text-muted mt-1 ps-1">{hint}</p>}
      {error && (
        <p className="text-[11px] text-danger mt-1 flex items-center gap-1">
          <AlertCircle className="w-3 h-3" /> {error}
        </p>
      )}
    </div>
  );
}

export default function SuperAdminPage() {
  const router = useRouter();
  const { user } = useAuthStore();
  const { t, locale } = useI18n();

  const [checking, setChecking] = useState(true);
  const [tab, setTab] = useState<"tenants" | "leads">("tenants");
  const [data, setData] = useState<PlatformTenantsResponse | null>(null);
  const [leads, setLeads] = useState<PlatformLeadsResponse | null>(null);
  const [types, setTypes] = useState<BusinessTypeItem[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [toast, setToast] = useState<{ ok: boolean; msg: string } | null>(null);
  const [refreshing, setRefreshing] = useState(false);
  const { page: tenantPage, perPage: tenantPerPage, setPage: setTenantPage, changePageSize: changeTenantPageSize } = usePagination();
  const { page: leadPage, perPage: leadPerPage, setPage: setLeadPage, changePageSize: changeLeadPageSize } = usePagination();

  const visibleTenants = (data?.tenants ?? []).slice((tenantPage - 1) * tenantPerPage, tenantPage * tenantPerPage);
  const visibleLeads = (leads?.leads ?? []).slice((leadPage - 1) * leadPerPage, leadPage * leadPerPage);

  const [createOpen, setCreateOpen] = useState(false);
  const [manageTenant, setManageTenant] = useState<PlatformTenant | null>(null);
  const [approveLead, setApproveLead] = useState<PlatformLead | null>(null);
  const [rejectLead, setRejectLead] = useState<PlatformLead | null>(null);
  const [rejecting, setRejecting] = useState(false);

  const showToast = useCallback((ok: boolean, msg: string) => {
    setToast({ ok, msg });
    setTimeout(() => setToast(null), 3500);
  }, []);

  const load = useCallback(async () => {
    const tk = useAuthStore.getState().token;
    if (!tk) return;
    try {
      const [tenants, leadData] = await Promise.all([
        fetchPlatformTenants(tk),
        fetchPlatformLeads(tk),
      ]);
      setData(tenants);
      setLeads(leadData);
      setError(null);
    } catch (err) {
      if (err instanceof ApiError && err.status === 404) {
        router.replace("/login");
        return;
      }
      setError(err instanceof ApiError ? err.message : t("superadmin.load_failed"));
    }
  }, [router, t]);

  const handleReject = useCallback(async () => {
    if (!rejectLead || rejecting) return;
    const tk = useAuthStore.getState().token;
    if (!tk) return;
    setRejecting(true);
    try {
      await rejectPlatformLead(tk, rejectLead.id);
      setRejectLead(null);
      showToast(true, t("superadmin.rejected_toast", { name: rejectLead.business_name }));
      load();
    } catch (err) {
      showToast(false, err instanceof ApiError ? err.message : t("superadmin.reject_failed"));
    } finally {
      setRejecting(false);
    }
  }, [rejectLead, rejecting, load, showToast, t]);

  useEffect(() => {
    const s = useAuthStore.getState();
    const hydrated = s.hydrate();
    if (!hydrated || !s.token || !s.user?.is_platform_owner) {
      router.replace("/login");
      return;
    }
    const id = requestAnimationFrame(() => setChecking(false));
    return () => cancelAnimationFrame(id);
  }, [router]);

  useEffect(() => {
    fetchBusinessTypes()
      .then(setTypes)
      .catch(() => {});
    if (checking) return;
    const id = setTimeout(load, 0);
    return () => clearTimeout(id);
  }, [checking, load]);

  if (checking) {
    return (
      <div className="min-h-screen bg-background flex items-center justify-center">
        <Loader2 className="w-8 h-8 animate-spin text-gold" />
      </div>
    );
  }

  const summary = data?.summary;

  return (
    <div className="min-h-screen bg-background text-foreground">
      {toast && (
        <div
          className={`fixed top-4 end-4 z-[100] flex items-center gap-2 rounded-xl border px-4 py-3 text-sm shadow-lg ${
            toast.ok
              ? "bg-emerald-500/10 border-emerald-500/30 text-emerald-400"
              : "bg-red-500/10 border-red-500/30 text-red-400"
          }`}
        >
          {toast.ok ? <CheckCircle2 className="w-4 h-4" /> : <AlertCircle className="w-4 h-4" />}
          {toast.msg}
        </div>
      )}

      {/* Portal header */}
      <header className="border-b border-border bg-card/60 backdrop-blur-md sticky top-0 z-40 print:hidden">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between">
          <div className="flex items-center gap-3">
            <Image src="/images/logobg.png" alt="SuperX" width={40} height={40} className="w-9 h-9 object-contain rounded-lg" />
            <div>
              <p className="text-sm font-semibold leading-tight">{t("superadmin.title")}</p>
              <p className="text-[10px] text-muted tracking-wide">{user?.email}</p>
            </div>
          </div>
          <div className="flex items-center gap-2">
            <button
              onClick={() => {
                setRefreshing(true);
                load().finally(() => setRefreshing(false));
              }}
              className="p-2.5 rounded-xl border border-border hover:bg-card-hover transition-colors"
              title={t("common.refresh")}
            >
              <RefreshCw className={`w-4 h-4 ${refreshing ? "animate-spin" : ""}`} />
            </button>
            <button
              onClick={() => {
                useAuthStore.getState().logout();
                router.replace("/login");
              }}
              className="text-xs text-muted hover:text-foreground transition-colors px-3"
            >
              {t("auth.logout")}
            </button>
            <button
              onClick={() => setCreateOpen(true)}
              className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-gradient-to-r from-[#D49A37] to-[#B8860B] text-[#0A111E] text-xs font-semibold hover:opacity-90 transition-opacity"
            >
              <Plus className="w-4 h-4" />
              {t("superadmin.new_tenant")}
            </button>
          </div>
        </div>
      </header>

      <main className="max-w-7xl mx-auto px-4 sm:px-6 py-8">
        <div className="flex items-center gap-2 mb-6">
          {(
            [
              { key: "tenants", label: t("superadmin.tab_tenants") },
              { key: "leads", label: t("superadmin.tab_leads") },
            ] as const
          ).map((tb) => (
            <button
              key={tb.key}
              onClick={() => setTab(tb.key)}
              className={`px-4 py-2 rounded-xl text-xs font-semibold transition-colors border ${
                tab === tb.key
                  ? "bg-[#D49A37]/15 text-[#D49A37] border-[#D49A37]/30"
                  : "text-muted border-border hover:bg-card-hover"
              }`}
            >
              {tb.label}
              {tb.key === "leads" && (leads?.summary.new ?? 0) > 0 && (
                <span className="ms-2 inline-flex items-center justify-center min-w-5 h-4 px-1.5 rounded-full bg-[#D49A37] text-[#0A111E] text-[10px] font-bold">
                  {leads!.summary.new}
                </span>
              )}
            </button>
          ))}
        </div>

        {tab === "tenants" && (
          <>
        <PageHeader title={t("superadmin.tenants")} subtitle={t("superadmin.subtitle")} />

        {/* Summary cards */}
        <div className="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-8">
          {[
            { label: t("superadmin.summary_total"), value: summary?.total ?? 0, icon: Building2, cls: "text-sky-400 bg-sky-500/10" },
            { label: t("superadmin.state_active"), value: summary?.active ?? 0, icon: ShieldCheck, cls: "text-emerald-400 bg-emerald-500/10" },
            { label: t("superadmin.state_expiring_soon"), value: summary?.expiring_soon ?? 0, icon: CalendarClock, cls: "text-amber-400 bg-amber-500/10" },
            { label: t("superadmin.state_expired"), value: summary?.expired ?? 0, icon: XCircle, cls: "text-red-400 bg-red-500/10" },
            { label: t("superadmin.state_suspended"), value: summary?.suspended ?? 0, icon: Users, cls: "text-slate-300 bg-slate-500/10" },
          ].map((c) => (
            <div key={c.label} className="glass rounded-2xl border border-border p-4 flex items-center gap-3">
              <div className={`w-9 h-9 rounded-xl flex items-center justify-center ${c.cls}`}>
                <c.icon className="w-4 h-4" />
              </div>
              <div>
                <p className="text-xl font-bold leading-none">{c.value}</p>
                <p className="text-[10px] text-muted mt-1">{c.label}</p>
              </div>
            </div>
          ))}
        </div>

        {error && (
          <div className="mb-6 p-4 rounded-2xl bg-red-500/10 border border-red-500/30 text-red-400 text-sm flex items-center gap-2">
            <AlertCircle className="w-4 h-4" /> {error}
          </div>
        )}

        {!data ? (
          <div className="flex justify-center py-20">
            <Loader2 className="w-7 h-7 animate-spin text-gold" />
          </div>
        ) : (
          <div className="glass rounded-2xl border border-border overflow-hidden overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-start text-[11px] uppercase tracking-wider text-muted border-b border-border">
                  <th className="text-start font-medium px-5 py-3.5">{t("superadmin.col_tenant")}</th>
                  <th className="text-start font-medium px-5 py-3.5">{t("superadmin.col_subdomain")}</th>
                  <th className="text-start font-medium px-5 py-3.5">{t("superadmin.col_type")}</th>
                  <th className="text-start font-medium px-5 py-3.5">{t("superadmin.col_owner")}</th>
                  <th className="text-start font-medium px-5 py-3.5">{t("superadmin.col_plan")}</th>
                  <th className="text-start font-medium px-5 py-3.5">{t("superadmin.col_subscription")}</th>
                  <th className="text-start font-medium px-5 py-3.5">{t("superadmin.col_database")}</th>
                  <th className="text-start font-medium px-5 py-3.5">{t("superadmin.col_seats")}</th>
                  <th className="text-start font-medium px-5 py-3.5">{t("superadmin.col_users")}</th>
                </tr>
              </thead>
              <tbody>
                {visibleTenants.map((tn) => (
                  <tr
                    key={tn.id}
                    onClick={() => setManageTenant(tn)}
                    className="border-b border-border/50 last:border-0 hover:bg-card-hover/60 cursor-pointer transition-colors"
                  >
                    <td className="px-5 py-3.5">
                      <p className="font-medium">{tn.name}</p>
                      <p className="text-[11px] text-muted">{tn.owner_contact.email ?? tn.slug}</p>
                    </td>
                    <td className="px-5 py-3.5">
                      {tn.subdomain ? (
                        <>
                          <p className="text-foreground" dir="ltr">{tn.store_url ?? tn.domain ?? tn.subdomain}</p>
                          <p className="text-[11px] text-muted" dir="ltr">{tn.subdomain}.*</p>
                        </>
                      ) : (
                        <span className="text-muted">—</span>
                      )}
                    </td>
                    <td className="px-5 py-3.5 text-muted">
                      {locale === "ar" ? tn.business_type?.name_ar : tn.business_type?.name_en}
                    </td>
                    <td className="px-5 py-3.5">
                      <p className="text-foreground">{tn.owner_contact.name ?? "—"}</p>
                      <p className="text-[11px] text-muted" dir="ltr">
                        {tn.owner_contact.phone ?? "—"}
                      </p>
                    </td>
                    <td className="px-5 py-3.5 capitalize">{tn.plan}</td>
                    <td className="px-5 py-3.5">
                      <StateBadge state={tn.subscription.state} />
                      {tn.subscription.expires_at && (
                        <p className="text-[10px] text-muted mt-1">{tn.subscription.expires_at}</p>
                      )}
                    </td>
                    <td className="px-5 py-3.5">
                      {tn.database ? (
                        <span className="font-mono text-[11px] text-muted" dir="ltr">{tn.database}</span>
                      ) : (
                        <span className="text-muted">—</span>
                      )}
                    </td>
                    <td className="px-5 py-3.5">
                      <span className="inline-flex items-center gap-1.5 text-muted">
                        <MonitorSmartphone className="w-3.5 h-3.5" />
                        {tn.pos_terminals.used}
                        {" / "}
                        {tn.pos_terminals.max ?? "∞"}
                      </span>
                    </td>
                    <td className="px-5 py-3.5 text-muted">{tn.users_count}</td>
                  </tr>
                ))}
                {data.tenants.length === 0 && (
                  <tr>
                    <td colSpan={9} className="px-5 py-14 text-center text-muted text-sm">
                      {t("superadmin.empty")}
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
            {data.tenants.length > 0 && (
              <Pagination total={data.tenants.length} page={tenantPage} perPage={tenantPerPage} onPageChange={setTenantPage} onPerPageChange={changeTenantPageSize} />
            )}
          </div>
        )}
          </>
        )}

        {tab === "leads" && (
          <>
            <PageHeader title={t("superadmin.tab_leads")} subtitle={t("superadmin.leads_subtitle")} />

            <div className="grid grid-cols-3 gap-3 mb-8">
              {[
                { label: t("superadmin.summary_total"), value: leads?.summary.total ?? 0, icon: Inbox, cls: "text-sky-400 bg-sky-500/10" },
                { label: t("superadmin.lead_status_new"), value: leads?.summary.new ?? 0, icon: ShieldCheck, cls: "text-amber-400 bg-amber-500/10" },
                { label: t("superadmin.lead_status_provisioned"), value: leads?.summary.provisioned ?? 0, icon: CheckCircle2, cls: "text-emerald-400 bg-emerald-500/10" },
              ].map((c) => (
                <div key={c.label} className="glass rounded-2xl border border-border p-4 flex items-center gap-3">
                  <div className={`w-9 h-9 rounded-xl flex items-center justify-center ${c.cls}`}>
                    <c.icon className="w-4 h-4" />
                  </div>
                  <div>
                    <p className="text-xl font-bold leading-none">{c.value}</p>
                    <p className="text-[10px] text-muted mt-1">{c.label}</p>
                  </div>
                </div>
              ))}
            </div>

            {error && (
              <div className="mb-6 p-4 rounded-2xl bg-red-500/10 border border-red-500/30 text-red-400 text-sm flex items-center gap-2">
                <AlertCircle className="w-4 h-4" /> {error}
              </div>
            )}

            {!leads ? (
              <div className="flex justify-center py-20">
                <Loader2 className="w-7 h-7 animate-spin text-gold" />
              </div>
            ) : (
              <div className="glass rounded-2xl border border-border overflow-hidden overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="text-start text-[11px] uppercase tracking-wider text-muted border-b border-border">
                      <th className="text-start font-medium px-5 py-3.5">{t("superadmin.col_contact")}</th>
                      <th className="text-start font-medium px-5 py-3.5">{t("superadmin.col_business")}</th>
                      <th className="text-start font-medium px-5 py-3.5">{t("superadmin.col_type")}</th>
                      <th className="text-start font-medium px-5 py-3.5">{t("superadmin.col_date")}</th>
                      <th className="text-start font-medium px-5 py-3.5">{t("superadmin.status_label")}</th>
                      <th className="text-start font-medium px-5 py-3.5" />
                    </tr>
                  </thead>
                  <tbody>
                    {visibleLeads.map((ld) => (
                      <tr key={ld.id} className="border-b border-border/50 last:border-0 hover:bg-card-hover/60 transition-colors">
                        <td className="px-5 py-3.5">
                          <p className="font-medium">{ld.name}</p>
                          <p className="text-[11px] text-muted flex items-center gap-1 mt-0.5">
                            <Mail className="w-2.5 h-2.5" />
                            <span dir="ltr">{ld.email ?? "—"}</span>
                            <span className="text-border-hover">·</span>
                            <Phone className="w-2.5 h-2.5" />
                            <span dir="ltr">{ld.phone}</span>
                          </p>
                        </td>
                        <td className="px-5 py-3.5">
                          <p className="text-foreground">{ld.business_name}</p>
                          {ld.city && (
                            <p className="text-[11px] text-muted flex items-center gap-1 mt-0.5">
                              <MapPin className="w-2.5 h-2.5" />
                              {ld.city}
                            </p>
                          )}
                        </td>
                        <td className="px-5 py-3.5 text-muted">
                          {locale === "ar" ? ld.business_type?.name_ar : ld.business_type?.name_en ?? "—"}
                        </td>
                        <td className="px-5 py-3.5 text-muted">
                          {ld.created_at ? new Date(ld.created_at).toLocaleDateString() : "—"}
                        </td>
                        <td className="px-5 py-3.5">
                          <span
                            className={`inline-flex items-center px-2 py-0.5 rounded-full border text-[10px] font-medium ${
                              ld.status === "provisioned"
                                ? "bg-emerald-500/10 text-emerald-400 border-emerald-500/30"
                                : ld.status === "rejected"
                                  ? "bg-red-500/10 text-red-400 border-red-500/30"
                                  : "bg-amber-500/10 text-amber-400 border-amber-500/30"
                            }`}
                          >
                            <span className="w-1 h-1 rounded-full bg-current me-1" />
                            {ld.status === "provisioned"
                              ? t("superadmin.lead_status_provisioned")
                              : ld.status === "rejected"
                                ? t("superadmin.lead_status_rejected")
                                : t("superadmin.lead_status_new")}
                          </span>
                          {ld.status === "provisioned" && ld.subdomain && (
                            <p className="text-[10px] text-muted mt-1" dir="ltr">{ld.subdomain}.*</p>
                          )}
                        </td>
                        <td className="px-5 py-3.5 text-end">
                          {ld.status === "new" && (
                            <div className="flex items-center justify-end gap-2">
                              <button
                                onClick={() => setRejectLead(ld)}
                                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-red-500/30 text-red-400 text-xs font-semibold hover:bg-red-500/10 transition-colors"
                              >
                                <Trash2 className="w-3.5 h-3.5" />
                                {t("superadmin.reject_lead")}
                              </button>
                              <button
                                onClick={() => setApproveLead(ld)}
                                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-gradient-to-r from-[#D49A37] to-[#B8860B] text-[#0A111E] text-xs font-semibold hover:opacity-90 transition-opacity"
                              >
                                <Check className="w-3.5 h-3.5" />
                                {t("superadmin.approve_lead")}
                              </button>
                            </div>
                          )}
                        </td>
                      </tr>
                    ))}
                    {leads.leads.length === 0 && (
                      <tr>
                        <td colSpan={6} className="px-5 py-14 text-center text-muted text-sm">
                          {t("superadmin.leads_empty")}
                        </td>
                      </tr>
                    )}
                  </tbody>
                </table>
                {leads.leads.length > 0 && (
                  <Pagination total={leads.leads.length} page={leadPage} perPage={leadPerPage} onPageChange={setLeadPage} onPerPageChange={changeLeadPageSize} />
                )}
              </div>
            )}
          </>
        )}
      </main>

      <CreateTenantDrawer
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        types={types}
        onCreated={(tn) => {
          setCreateOpen(false);
          showToast(true, t("superadmin.created_toast", { name: tn.name }));
          load();
        }}
        onError={(msg) => showToast(false, msg)}
      />

      <ManageTenantDrawer
        tenant={manageTenant}
        onClose={() => setManageTenant(null)}
        onSaved={(tn) => {
          setManageTenant(null);
          showToast(true, t("superadmin.updated_toast", { name: tn.name }));
          load();
        }}
        onError={(msg) => showToast(false, msg)}
      />

      <ApproveLeadModal
        lead={approveLead}
        onClose={() => {
          setApproveLead(null);
          load();
        }}
        onError={(msg) => showToast(false, msg)}
      />

      <ConfirmDialog
        open={!!rejectLead}
        onClose={() => setRejectLead(null)}
        onConfirm={handleReject}
        title={t("superadmin.reject_title", { name: rejectLead?.business_name ?? "" })}
        message={t("superadmin.reject_message")}
        confirmLabel={t("superadmin.reject_confirm")}
        loading={rejecting}
      />
    </div>
  );
}

/* ─── Create drawer ─────────────────────────────────────────────── */

function CreateTenantDrawer({
  open,
  onClose,
  types,
  onCreated,
  onError,
}: {
  open: boolean;
  onClose: () => void;
  types: BusinessTypeItem[];
  onCreated: (tn: PlatformTenant) => void;
  onError: (msg: string) => void;
}) {
  const { t, locale } = useI18n();
  const today = new Date().toISOString().slice(0, 10);
  const [form, setForm] = useState<Record<string, string>>({
    name: "",
    plan: "standard",
    contact_phone: "",
    city: "",
    subscription_starts_at: today,
    expires_at: "",
    max_pos_registers: "",
    admin_name: "",
    admin_username: "",
    admin_email: "",
    admin_password: "",
    admin_password_confirmation: "",
  });
  const [businessTypeId, setBusinessTypeId] = useState<number | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  const set = (k: string, v: string) => {
    setForm((f) => ({ ...f, [k]: v }));
    setErrors((e) => ({ ...e, [k]: "" }));
  };

  const submit = async () => {
    if (saving) return;
    setSaving(true);
    setErrors({});
    try {
      const tn = await createPlatformTenant(useAuthStore.getState().token!, {
        name: form.name.trim(),
        business_type_id: businessTypeId!,
        plan: form.plan,
        contact_phone: form.contact_phone.trim()
          ? normalizePhone(form.contact_phone) ?? form.contact_phone.trim()
          : undefined,
        city: form.city.trim() || undefined,
        subscription_starts_at: form.subscription_starts_at || undefined,
        expires_at: form.expires_at || undefined,
        max_pos_registers: form.max_pos_registers === "" ? undefined : Number(form.max_pos_registers),
        admin_name: form.admin_name.trim(),
        admin_username: form.admin_username.trim(),
        admin_email: form.admin_email.trim(),
        admin_password: form.admin_password,
        admin_password_confirmation: form.admin_password_confirmation,
      });
      onCreated(tn);
      setForm((f) => ({
        ...f,
        name: "",
        contact_phone: "",
        city: "",
        expires_at: "",
        max_pos_registers: "",
        admin_name: "",
        admin_username: "",
        admin_email: "",
        admin_password: "",
        admin_password_confirmation: "",
      }));
      setBusinessTypeId(null);
    } catch (err) {
      if (err instanceof ApiError && err.errors) {
        const m: Record<string, string> = {};
        for (const [k, v] of Object.entries(err.errors)) if (v?.length) m[k] = v[0];
        setErrors(m);
      }
      onError(err instanceof ApiError ? err.message : t("superadmin.create_failed"));
    } finally {
      setSaving(false);
    }
  };

  return (
    <SlideOver open={open} onClose={onClose} title={t("superadmin.new_tenant")} width="max-w-xl">
      <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="space-y-4">
        <div>
          <label className={labelCls}>{t("superadmin.business_name")}</label>
          <input
            className={`${inputCls} ${errors.name ? "border-danger" : ""}`}
            value={form.name}
            onChange={(e) => set("name", e.target.value)}
            autoComplete="off"
          />
          {errors.name && <p className="text-[11px] text-danger mt-1">{errors.name}</p>}
        </div>

        <div className="grid grid-cols-2 gap-3">
          <div>
            <label className={labelCls}>{t("superadmin.business_type")}</label>
            <select
              className={inputCls}
              value={businessTypeId ?? ""}
              onChange={(e) => setBusinessTypeId(e.target.value ? Number(e.target.value) : null)}
            >
              <option value="">{t("leads.select_business_type")}</option>
              {types.map((bt) => (
                <option key={bt.id} value={bt.id}>
                  {locale === "ar" ? bt.name_ar || bt.name_en : bt.name_en}
                </option>
              ))}
            </select>
            {errors.business_type_id && <p className="text-[11px] text-danger mt-1">{errors.business_type_id}</p>}
          </div>
          <div>
            <label className={labelCls}>{t("superadmin.plan")}</label>
            <select className={inputCls} value={form.plan} onChange={(e) => set("plan", e.target.value)}>
              <option value="standard">{t("superadmin.plan_standard")}</option>
              <option value="premium">{t("superadmin.plan_premium")}</option>
              <option value="enterprise">{t("superadmin.plan_enterprise")}</option>
            </select>
          </div>
        </div>

        <div className="grid grid-cols-2 gap-3">
          <DrawerField label={t("leads.phone")} value={form.contact_phone} onChange={(v) => set("contact_phone", v)} error={errors.contact_phone} />
          <DrawerField label={t("leads.city")} value={form.city} onChange={(v) => set("city", v)} error={errors.city} />
        </div>

        <div className="grid grid-cols-3 gap-3">
          <DrawerField label={t("superadmin.starts_at")} type="date" value={form.subscription_starts_at} onChange={(v) => set("subscription_starts_at", v)} error={errors.subscription_starts_at} />
          <DrawerField label={t("superadmin.expires_at")} type="date" value={form.expires_at} onChange={(v) => set("expires_at", v)} error={errors.expires_at} />
          <DrawerField label={t("superadmin.max_seats")} type="number" value={form.max_pos_registers} onChange={(v) => set("max_pos_registers", v)} error={errors.max_pos_registers} />
        </div>

        <div className="pt-2 border-t border-border/60">
          <p className="text-[11px] font-semibold text-gold tracking-wide mb-3">{t("superadmin.admin_section")}</p>
          <div className="grid grid-cols-2 gap-3">
            <DrawerField label={t("superadmin.admin_name")} value={form.admin_name} onChange={(v) => set("admin_name", v)} error={errors.admin_name} />
            <DrawerField label={t("superadmin.admin_username")} value={form.admin_username} onChange={(v) => set("admin_username", v)} error={errors.admin_username} placeholder="letters_digits_only" />
          </div>
          <div className="grid grid-cols-2 gap-3 mt-3">
            <DrawerField label={t("superadmin.admin_email")} type="email" value={form.admin_email} onChange={(v) => set("admin_email", v)} error={errors.admin_email} />
          </div>
          <div className="grid grid-cols-2 gap-3 mt-3">
            <DrawerField label={t("superadmin.admin_password")} type="password" value={form.admin_password} onChange={(v) => set("admin_password", v)} error={errors.admin_password} />
            <DrawerField label={t("superadmin.confirm_password")} type="password" value={form.admin_password_confirmation} onChange={(v) => set("admin_password_confirmation", v)} error={errors.admin_password_confirmation} />
          </div>
        </div>

        <div className="flex gap-3 pt-2">
          <button
            onClick={submit}
            disabled={saving}
            className="flex-1 py-3 rounded-2xl bg-gradient-to-r from-[#D49A37] to-[#B8860B] text-[#0A111E] text-sm font-semibold hover:opacity-90 transition-opacity flex items-center justify-center gap-2 disabled:opacity-60"
          >
            {saving && <Loader2 className="w-4 h-4 animate-spin" />}
            {saving ? t("common.saving") : t("common.save")}
          </button>
          <button onClick={onClose} className="px-6 py-3 rounded-2xl border border-border text-sm hover:bg-card-hover transition-colors">
            {t("common.cancel")}
          </button>
        </div>
      </motion.div>
    </SlideOver>
  );
}

/* ─── Manage drawer ─────────────────────────────────────────────── */

function ManageTenantDrawer({
  tenant,
  onClose,
  onSaved,
  onError,
}: {
  tenant: PlatformTenant | null;
  onClose: () => void;
  onSaved: (tn: PlatformTenant) => void;
  onError: (msg: string) => void;
}) {
  const { t, locale } = useI18n();
  const [form, setForm] = useState<Record<string, string>>({});
  const [status, setStatus] = useState("active");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [detail, setDetail] = useState<PlatformTenant | null>(null);

  useEffect(() => {
    if (!tenant) return;
    const id = requestAnimationFrame(() => {
      setDetail(tenant);
      setStatus(tenant.status);
      setForm({
        name: tenant.name,
        plan: tenant.plan,
        contact_phone: tenant.owner_contact.phone ?? "",
        city: tenant.city ?? "",
        subscription_starts_at: tenant.subscription.starts_at ?? "",
        expires_at: tenant.subscription.expires_at ?? "",
        max_pos_registers: tenant.pos_terminals.max === null ? "" : String(tenant.pos_terminals.max),
        admin_email: "",
        admin_password: "",
        admin_password_confirmation: "",
      });
      setErrors({});
    });
    return () => cancelAnimationFrame(id);
  }, [tenant]);

  if (!tenant || !detail) return null;

  const set = (k: string, v: string) => {
    setForm((f) => ({ ...f, [k]: v }));
    setErrors((e) => ({ ...e, [k]: "" }));
  };

  const save = async () => {
    if (saving) return;
    setSaving(true);
    setErrors({});
    try {
      const payload: Record<string, unknown> = {
        name: form.name,
        plan: form.plan,
        status,
        contact_phone: form.contact_phone.trim() ? normalizePhone(form.contact_phone) ?? form.contact_phone.trim() : null,
        city: form.city.trim() || null,
        subscription_starts_at: form.subscription_starts_at || null,
        expires_at: form.expires_at || null,
        max_pos_registers: form.max_pos_registers === "" ? null : Number(form.max_pos_registers),
      };
      if (form.admin_password) {
        payload.admin_password = form.admin_password;
        payload.admin_password_confirmation = form.admin_password_confirmation;
      }
      const tn = await updatePlatformTenant(useAuthStore.getState().token!, tenant.id, payload);
      onSaved({ ...tn, business_type: detail.business_type });
    } catch (err) {
      if (err instanceof ApiError && err.errors) {
        const m: Record<string, string> = {};
        for (const [k, v] of Object.entries(err.errors)) if (v?.length) m[k] = v[0];
        setErrors(m);
      }
      onError(err instanceof ApiError ? err.message : t("superadmin.update_failed"));
    } finally {
      setSaving(false);
    }
  };

  const typeName = locale === "ar" ? detail.business_type?.name_ar : detail.business_type?.name_en;

  return (
    <SlideOver open={!!tenant} onClose={onClose} title={detail.name} width="max-w-xl">
      <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="space-y-4">
        {/* Snapshot */}
        <div className="rounded-2xl border border-border bg-card/60 p-4 grid grid-cols-2 gap-y-2 text-xs">
          <span className="text-muted">{t("superadmin.col_type")}</span>
          <span>{typeName}</span>
          <span className="text-muted">{t("superadmin.tenant_subdomain")}</span>
          <span dir="ltr">{detail.subdomain ?? "—"}</span>
          <span className="text-muted">{t("superadmin.tenant_domain")}</span>
          <span dir="ltr">{detail.store_url ?? detail.domain ?? "—"}</span>
          <span className="text-muted">{t("superadmin.tenant_database")}</span>
          <span dir="ltr" className="font-mono">{detail.database ?? "—"}</span>
          <span className="text-muted">{t("superadmin.owner_contact")}</span>
          <span dir="ltr">{detail.owner_contact.name} · {detail.owner_contact.phone}</span>
          <span className="text-muted">{t("superadmin.col_users")}</span>
          <span>{detail.users_count}</span>
          <span className="text-muted">{t("superadmin.pos_used")}</span>
          <span>
            {detail.pos_terminals.used} / {detail.pos_terminals.max ?? "∞"}
          </span>
        </div>

        <div className="grid grid-cols-2 gap-3">
          <div>
            <label className={labelCls}>{t("superadmin.status_label")}</label>
            <select className={inputCls} value={status} onChange={(e) => setStatus(e.target.value)}>
              <option value="active">{t("superadmin.status_active")}</option>
              <option value="suspended">{t("superadmin.status_suspended")}</option>
            </select>
          </div>
          <div>
            <label className={labelCls}>{t("superadmin.plan")}</label>
            <select className={inputCls} value={form.plan ?? "standard"} onChange={(e) => set("plan", e.target.value)}>
              <option value="standard">{t("superadmin.plan_standard")}</option>
              <option value="premium">{t("superadmin.plan_premium")}</option>
              <option value="enterprise">{t("superadmin.plan_enterprise")}</option>
            </select>
          </div>
        </div>

        <div>
          <label className={labelCls}>{t("superadmin.business_name")}</label>
          <input className={`${inputCls} ${errors.name ? "border-danger" : ""}`} value={form.name ?? ""} onChange={(e) => set("name", e.target.value)} />
          {errors.name && <p className="text-[11px] text-danger mt-1">{errors.name}</p>}
        </div>

        <div className="grid grid-cols-2 gap-3">
          <DrawerField
            label={t("leads.phone")}
            value={form.contact_phone ?? ""}
            onChange={(v) => set("contact_phone", v)}
            error={errors.contact_phone}
            hint={isValidPhone(form.contact_phone ?? "") ? normalizePhone(form.contact_phone ?? "") ?? "" : ""}
          />
          <DrawerField label={t("leads.city")} value={form.city ?? ""} onChange={(v) => set("city", v)} error={errors.city} />
        </div>

        <div className="grid grid-cols-3 gap-3">
          <DrawerField label={t("superadmin.starts_at")} type="date" value={form.subscription_starts_at ?? ""} onChange={(v) => set("subscription_starts_at", v)} error={errors.subscription_starts_at} />
          <DrawerField label={t("superadmin.expires_at")} type="date" value={form.expires_at ?? ""} onChange={(v) => set("expires_at", v)} error={errors.expires_at} />
          <DrawerField label={t("superadmin.max_seats")} type="number" value={form.max_pos_registers ?? ""} onChange={(v) => set("max_pos_registers", v)} error={errors.max_pos_registers} />
        </div>

        <div className="pt-2 border-t border-border/60">
          <p className="text-[11px] font-semibold text-gold tracking-wide mb-3">{t("superadmin.reset_admin")}</p>
          <div className="grid grid-cols-2 gap-3">
            <DrawerField label={t("superadmin.new_password")} type="password" value={form.admin_password ?? ""} onChange={(v) => set("admin_password", v)} error={errors.admin_password} hint={t("superadmin.leave_blank_hint")} />
            <DrawerField label={t("superadmin.confirm_password")} type="password" value={form.admin_password_confirmation ?? ""} onChange={(v) => set("admin_password_confirmation", v)} error={errors.admin_password_confirmation} />
          </div>
        </div>

        <div className="flex gap-3 pt-2">
          <button
            onClick={save}
            disabled={saving}
            className="flex-1 py-3 rounded-2xl bg-gradient-to-r from-[#D49A37] to-[#B8860B] text-[#0A111E] text-sm font-semibold hover:opacity-90 transition-opacity flex items-center justify-center gap-2 disabled:opacity-60"
          >
            {saving && <Loader2 className="w-4 h-4 animate-spin" />}
            {saving ? t("common.saving") : t("common.save")}
          </button>
          <button onClick={onClose} className="px-6 py-3 rounded-2xl border border-border text-sm hover:bg-card-hover transition-colors">
            {t("common.cancel")}
          </button>
        </div>
      </motion.div>
    </SlideOver>
  );
}

/* ─── Lead approval modal ───────────────────────────────────────── */

function ApproveLeadModal({
  lead,
  onClose,
  onError,
}: {
  lead: PlatformLead | null;
  onClose: () => void;
  onError: (msg: string) => void;
}) {
  const { t, locale } = useI18n();
  const [subdomain, setSubdomain] = useState("");
  const [email, setEmail] = useState("");
  const [days, setDays] = useState("30");
  const [plan, setPlan] = useState("standard");
  const [errors, setErrors] = useState<{ subdomain?: string; email?: string; _?: string }>({});
  const [saving, setSaving] = useState(false);
  const [result, setResult] = useState<ApproveLeadResponse | null>(null);
  const [copiedKey, setCopiedKey] = useState<string | null>(null);

  useEffect(() => {
    if (!lead) return;
    const id = requestAnimationFrame(() => {
      setSubdomain(lead.subdomain ?? "");
      setEmail(lead.email ?? "");
      setDays("30");
      setPlan("standard");
      setErrors({});
      setResult(null);
      setCopiedKey(null);
    });
    return () => cancelAnimationFrame(id);
  }, [lead]);

  if (!lead) return null;

  const submit = async () => {
    if (saving) return;
    setSaving(true);
    setErrors({});
    try {
      const res = await approvePlatformLead(useAuthStore.getState().token!, lead.id, {
        subdomain: subdomain.trim() || undefined,
        email: email.trim() || undefined,
        subscription_days: Number(days),
        plan,
      });
      setResult(res);
    } catch (err) {
      if (err instanceof ApiError && err.errors) {
        const m: { subdomain?: string; email?: string; _?: string } = {};
        for (const [k, v] of Object.entries(err.errors)) if (v?.length) m[k as keyof typeof m] = v[0];
        setErrors(m);
      }
      onError(err instanceof ApiError ? err.message : t("superadmin.approve_failed"));
    } finally {
      setSaving(false);
    }
  };

  const copy = async (key: string, text: string) => {
    try {
      await navigator.clipboard.writeText(text);
      setCopiedKey(key);
      setTimeout(() => setCopiedKey((k) => (k === key ? null : k)), 2000);
    } catch {
      onError(t("superadmin.approve_failed"));
    }
  };

  return (
    <div
      className="fixed inset-0 z-[95] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
      onClick={onClose}
    >
      <motion.div
        className="relative w-full max-w-md rounded-3xl bg-card border border-border shadow-2xl p-6"
        initial={{ opacity: 0, scale: 0.95, y: 10 }}
        animate={{ opacity: 1, scale: 1, y: 0 }}
        transition={{ type: "spring", stiffness: 400, damping: 30 }}
        onClick={(e) => e.stopPropagation()}
      >
        {!result ? (
          <>
            <h3 className="text-base font-bold mb-1">
              {t("superadmin.approve_title", { name: lead.business_name })}
            </h3>
            <p className="text-xs text-muted mb-5 leading-relaxed">{t("superadmin.approve_message")}</p>

            <div className="rounded-2xl border border-border bg-card/60 p-3.5 mb-4 grid grid-cols-2 gap-y-1.5 text-xs">
              <span className="text-muted">{t("superadmin.col_contact")}</span>
              <span dir="ltr" className="text-end">{lead.name} · {lead.phone}</span>
              <span className="text-muted">{t("superadmin.col_type")}</span>
              <span className="text-end">{locale === "ar" ? lead.business_type?.name_ar : lead.business_type?.name_en ?? "—"}</span>
              {lead.email && (
                <>
                  <span className="text-muted">Email</span>
                  <span dir="ltr" className="text-end">{lead.email}</span>
                </>
              )}
            </div>

            <div className="space-y-3.5">
              <div>
                <label className={labelCls}>{t("superadmin.approve_subdomain")}</label>
                <input
                  className={`${inputCls} ${errors.subdomain ? "border-danger" : ""}`}
                  value={subdomain}
                  onChange={(e) => setSubdomain(e.target.value)}
                  placeholder="my-store"
                  dir="ltr"
                  autoComplete="off"
                />
                {errors.subdomain && <p className="text-[11px] text-danger mt-1">{errors.subdomain}</p>}
              </div>
              <div>
                <label className={labelCls}>{t("superadmin.approve_email")}</label>
                <input
                  className={`${inputCls} ${errors.email ? "border-danger" : ""}`}
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  dir="ltr"
                  autoComplete="off"
                />
                {errors.email && <p className="text-[11px] text-danger mt-1">{errors.email}</p>}
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className={labelCls}>{t("superadmin.approve_days")}</label>
                  <input
                    className={inputCls}
                    type="number"
                    min={1}
                    value={days}
                    onChange={(e) => setDays(e.target.value)}
                    dir="ltr"
                  />
                </div>
                <div>
                  <label className={labelCls}>{t("superadmin.approve_plan")}</label>
                  <select className={inputCls} value={plan} onChange={(e) => setPlan(e.target.value)}>
                    <option value="standard">{t("superadmin.plan_standard")}</option>
                    <option value="premium">{t("superadmin.plan_premium")}</option>
                    <option value="enterprise">{t("superadmin.plan_enterprise")}</option>
                  </select>
                </div>
              </div>
            </div>

            {errors._ && (
              <p className="mt-3 text-[11px] text-danger flex items-center gap-1">
                <AlertCircle className="w-3 h-3" /> {errors._}
              </p>
            )}

            <div className="flex gap-3 mt-6">
              <button
                onClick={submit}
                disabled={saving}
                className="flex-1 py-3 rounded-2xl bg-gradient-to-r from-[#D49A37] to-[#B8860B] text-[#0A111E] text-sm font-semibold hover:opacity-90 transition-opacity flex items-center justify-center gap-2 disabled:opacity-60"
              >
                {saving ? (
                  <>
                    <Loader2 className="w-4 h-4 animate-spin" />
                    {t("superadmin.approving")}
                  </>
                ) : (
                  <>
                    <Send className="w-4 h-4" />
                    {t("superadmin.approve_confirm")}
                  </>
                )}
              </button>
              <button
                onClick={onClose}
                disabled={saving}
                className="px-5 py-3 rounded-2xl border border-border text-sm hover:bg-card-hover transition-colors disabled:opacity-60"
              >
                {t("common.cancel")}
              </button>
            </div>
          </>
        ) : (
          <>
            <div className="flex flex-col items-center text-center mb-5">
              <div className="w-12 h-12 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 flex items-center justify-center mb-3">
                <CheckCircle2 className="w-6 h-6 text-emerald-400" />
              </div>
              <h3 className="text-base font-bold">{t("superadmin.approve_confirm")}</h3>
            </div>

            <div className="space-y-3">
              <div className="rounded-2xl border border-border bg-card/60 p-3.5 grid grid-cols-2 gap-y-2 text-xs">
                <span className="text-muted">{t("superadmin.approve_plan")}</span>
                <span className="text-end capitalize">{result.plan ?? "standard"}</span>
                <span className="text-muted">{t("superadmin.approved_subdomain")}</span>
                <span dir="ltr" className="text-end">{result.lead?.subdomain ?? result.domain.split(".")[0]}</span>
              </div>
              <div className="rounded-2xl border border-border bg-card/60 p-3.5">
                <p className="text-[10px] text-muted mb-1">{t("superadmin.approved_domain")}</p>
                <p className="text-sm font-semibold" dir="ltr">{result.store_url ?? result.domain}</p>
              </div>
              <div className="rounded-2xl border border-border bg-card/60 p-3.5">
                <p className="text-[10px] text-muted mb-1 flex items-center gap-1">
                  <ExternalLink className="w-3 h-3" /> {t("superadmin.approved_link")}
                </p>
                <div className="flex items-center gap-2">
                  <input
                    className="w-full bg-transparent text-xs text-foreground outline-none"
                    value={result.activation_url}
                    readOnly
                    dir="ltr"
                    onFocus={(e) => e.target.select()}
                  />
                  <button
                    onClick={() => copy("activation", result.activation_url)}
                    className="shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-[#D49A37]/15 text-[#D49A37] text-xs font-semibold hover:bg-[#D49A37]/25 transition-colors"
                  >
                    {copiedKey === "activation" ? <Check className="w-3.5 h-3.5" /> : <Copy className="w-3.5 h-3.5" />}
                    {copiedKey === "activation" ? t("superadmin.copied") : t("superadmin.copy_link")}
                  </button>
                </div>
              </div>
              {result.database && (
                <div className="rounded-2xl border border-border bg-card/60 p-3.5">
                  <p className="text-[10px] text-muted mb-1">{t("superadmin.approved_database")}</p>
                  <div className="flex items-center gap-2">
                    <span className="w-full font-mono text-xs text-foreground" dir="ltr">{result.database}</span>
                    <button
                      onClick={() => result.database && copy("database", result.database)}
                      className="shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-[#D49A37]/15 text-[#D49A37] text-xs font-semibold hover:bg-[#D49A37]/25 transition-colors"
                    >
                      {copiedKey === "database" ? <Check className="w-3.5 h-3.5" /> : <Copy className="w-3.5 h-3.5" />}
                      {copiedKey === "database" ? t("superadmin.copied") : t("superadmin.copy_link")}
                    </button>
                  </div>
                </div>
              )}
            </div>

            <p className="mt-4 text-[11px] text-muted text-center">{t("superadmin.send_manually_hint")}</p>

            <button
              onClick={onClose}
              className="w-full mt-5 py-3 rounded-2xl bg-gradient-to-r from-[#1E4E8C] to-[#3A75C4] text-white text-sm font-semibold hover:opacity-90 transition-opacity"
            >
              {t("common.done")}
            </button>
          </>
        )}
      </motion.div>
    </div>
  );
}
