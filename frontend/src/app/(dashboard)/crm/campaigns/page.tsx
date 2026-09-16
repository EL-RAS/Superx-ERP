"use client";

import { useEffect, useState, useCallback } from "react";
import { motion } from "framer-motion";
import { useAuthStore } from "@/stores/auth-store";
import {
  Campaigns,
  sendCampaign,
  sendTestCampaign,
  fetchCampaignStats,
  fetchCustomerSegments,
  ApiError,
} from "@/lib/api";
import type { Campaign, CampaignStats, CustomerSegments } from "@/lib/types";
import PageHeader from "@/components/ui/PageHeader";
import DataTable from "@/components/ui/DataTable";
import type { Column } from "@/components/ui/DataTable";
import SlideOver from "@/components/ui/SlideOver";
import { usePagination } from "@/lib/pagination";
import {
  Megaphone,
  Search,
  Send,
  Loader2,
  AlertTriangle,
  Star,
  TrendingUp,
  FlaskConical,
} from "lucide-react";
import { useI18n } from "@/lib/i18n";

const SEGMENT_BADGE: Record<string, string> = {
  lost: "bg-red-500/10 text-red-400 border-red-500/30",
  vip: "bg-yellow-500/10 text-yellow-400 border-yellow-500/30",
  all: "bg-blue-500/10 text-blue-400 border-blue-500/30",
  tier: "bg-purple-500/10 text-purple-400 border-purple-500/30",
};

const CHANNEL_BADGE: Record<string, string> = {
  sms: "bg-purple-500/10 text-purple-400 border-purple-500/30",
  whatsapp: "bg-emerald-500/10 text-emerald-400 border-emerald-500/30",
};

const STATUS_BADGE: Record<string, string> = {
  draft: "bg-amber-500/10 text-amber-400 border-amber-500/30",
  sending: "bg-blue-500/10 text-blue-400 border-blue-500/30",
  completed: "bg-emerald-500/10 text-emerald-400 border-emerald-500/30",
  failed: "bg-red-500/10 text-red-400 border-red-500/30",
};

export default function CampaignsPage() {
  const { t, locale } = useI18n();
  const { token, business } = useAuthStore();

  const [campaigns, setCampaigns] = useState<Campaign[]>([]);
  const [total, setTotal] = useState(0);
  const [segments, setSegments] = useState<CustomerSegments | null>(null);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState("");
  const { page, perPage, setPage, resetPage, changePageSize } = usePagination();
  const [selected, setSelected] = useState<Campaign | null>(null);
  const [selectedStats, setSelectedStats] = useState<CampaignStats | null>(null);
  const [slideOpen, setSlideOpen] = useState(false);
  const [createOpen, setCreateOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [sending, setSending] = useState(false);
  const [testing, setTesting] = useState(false);
  const [testPhone, setTestPhone] = useState("");
  const [toast, setToast] = useState<{
    msg: string;
    type: "success" | "error";
  } | null>(null);

  const [form, setForm] = useState({
    name: "",
    segment_type: "all" as "lost" | "vip" | "all",
    channel: "sms" as "sms" | "whatsapp",
    message_template: "",
  });

  const showToast = useCallback((msg: string, type: "success" | "error") => {
    setToast({ msg, type });
    window.setTimeout(() => setToast(null), 3000);
  }, []);

  const fetchCampaigns = useCallback(() => {
    if (!token || !business) return;
    setLoading(true);
    const params: Record<string, string | number> = { page, per_page: perPage };
    if (search.trim()) params.search = search.trim();
    Campaigns.list(token, business.id, params)
      .then((res) => {
        const rows = Array.isArray(res) ? res : (res as { data: Campaign[] }).data;
        setCampaigns(rows);
        setTotal(typeof res === "object" && res !== null && "total" in res ? Number((res as { total: number }).total) : rows.length);
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [token, business, search, page, perPage]);

  useEffect(() => {
    const timer = setTimeout(fetchCampaigns, 300);
    return () => clearTimeout(timer);
  }, [fetchCampaigns]);

  useEffect(() => {
    if (!token || !business) return;
    fetchCustomerSegments(token, business.id)
      .then(setSegments)
      .catch(() => setSegments(null));
  }, [token, business]);

  const columns: Column[] = [
    { key: "name", label: t("crm.campaign_name") },
    {
      key: "segment_type",
      label: t("crm.segment_type"),
      render: (v) => (
        <span
          className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border ${SEGMENT_BADGE[String(v)] ?? SEGMENT_BADGE.all}`}
        >
          {t(`crm.segment_${String(v)}`)}
        </span>
      ),
    },
    {
      key: "channel",
      label: t("crm.channel"),
      render: (v) => (
        <span
          className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border ${CHANNEL_BADGE[String(v)] ?? CHANNEL_BADGE.sms}`}
        >
          {t(`crm.channel_${String(v)}`)}
        </span>
      ),
    },
    {
      key: "status",
      label: t("common.status"),
      render: (v) => (
        <span
          className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border ${STATUS_BADGE[String(v)] ?? STATUS_BADGE.draft}`}
        >
          {t(`crm.status_${String(v)}`)}
        </span>
      ),
    },
    { key: "recipients_count", label: t("crm.recipients"), type: "number" },
    {
      key: "sent_at",
      label: t("crm.sent_at"),
      render: (v) =>
        v ? new Date(String(v)).toLocaleDateString(locale) : "—",
    },
  ];

  const openDetail = (row: Record<string, unknown>) => {
    const campaign = row as unknown as Campaign;
    setSelected(campaign);
    setSlideOpen(true);
    setSelectedStats(null);
    setTestPhone("");
    if (token && business) {
      fetchCampaignStats(token, business.id, campaign.id)
        .then(setSelectedStats)
        .catch(() => setSelectedStats(null));
    }
  };

  const handleCreate = async () => {
    if (!token || !business) return;
    if (!form.name.trim()) return;
    setSaving(true);
    try {
      await Campaigns.create(token, business.id, {
        name: form.name.trim(),
        segment_type: form.segment_type,
        channel: form.channel,
        message_template: form.message_template,
      });
      setCreateOpen(false);
      setForm({
        name: "",
        segment_type: "all",
        channel: "sms",
        message_template: "",
      });
      showToast(t("crm.created") || t("common.success"), "success");
      fetchCampaigns();
    } catch (err) {
      const apiErr = err instanceof ApiError ? err : null;
      showToast(
        apiErr?.message || t("common.error"),
        "error"
      );
    } finally {
      setSaving(false);
    }
  };

  const handleSend = async () => {
    if (!token || !business || !selected) return;
    setSending(true);
    try {
      const res = await sendCampaign(token, business.id, selected.id);
      setSelected({ ...selected, ...res });
      fetchCampaignStats(token, business.id, selected.id).then(setSelectedStats).catch(() => {});
      showToast(t("crm.send_campaign") + " ✓", "success");
      fetchCampaigns();
    } catch (err) {
      const apiErr = err instanceof ApiError ? err : null;
      showToast(apiErr?.message || t("common.error"), "error");
    } finally {
      setSending(false);
    }
  };

  const handleSendTest = async () => {
    if (!token || !business || !selected) return;
    if (!testPhone.trim()) {
      showToast(t("crm.test_phone_required") || "Enter a test phone number", "error");
      return;
    }
    setTesting(true);
    try {
      const res = await sendTestCampaign(token, business.id, selected.id, testPhone.trim());
      if (res.success) {
        showToast(t("crm.test_sent") || "Test sent", "success");
        if (res.provider) {
          showToast(`${t("crm.test_sent") || "Test sent"} (${res.provider})`, "success");
        }
      } else {
        showToast(res.error || t("common.error"), "error");
      }
    } catch (err) {
      const apiErr = err instanceof ApiError ? err : null;
      showToast(apiErr?.message || t("common.error"), "error");
    } finally {
      setTesting(false);
    }
  };

  return (
    <motion.div
      initial={{ opacity: 0, y: 12 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.4 }}
    >
      <PageHeader
        title={t("crm.campaigns")}
        subtitle={t("crm.campaigns_subtitle")}
        action={
          <button
            onClick={() => {
              setForm({
                name: "",
                segment_type: "all",
                channel: "sms",
                message_template: "",
              });
              setCreateOpen(true);
            }}
            className="flex items-center gap-2 px-4 py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors"
          >
            <Megaphone className="w-4 h-4" /> {t("crm.new_campaign")}
          </button>
        }
      />

      <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div className="glass rounded-2xl p-4 border border-red-500/10">
          <div className="flex items-center gap-3">
            <div className="p-2 bg-red-500/10 rounded-xl">
              <AlertTriangle className="w-5 h-5 text-red-400" />
            </div>
            <div>
              <p className="text-2xl font-bold text-foreground">
                {segments?.lost ?? 0}
              </p>
              <p className="text-xs font-medium text-red-400">
                {t("crm.segment_lost")}
              </p>
            </div>
          </div>
          <p className="text-xs text-muted mt-2">
            {t("crm.segment_lost_desc") || "Customers who haven't purchased recently"}
          </p>
        </div>

        <div className="glass rounded-2xl p-4 border border-yellow-500/10">
          <div className="flex items-center gap-3">
            <div className="p-2 bg-yellow-500/10 rounded-xl">
              <Star className="w-5 h-5 text-yellow-400" />
            </div>
            <div>
              <p className="text-2xl font-bold text-foreground">
                {segments?.vip ?? 0}
              </p>
              <p className="text-xs font-medium text-yellow-400">
                {t("crm.segment_vip")}
              </p>
            </div>
          </div>
          <p className="text-xs text-muted mt-2">
            {t("crm.segment_vip_desc") || "High-value customers with strong loyalty"}
          </p>
        </div>

        <div className="glass rounded-2xl p-4 border border-emerald-500/10">
          <div className="flex items-center gap-3">
            <div className="p-2 bg-emerald-500/10 rounded-xl">
              <TrendingUp className="w-5 h-5 text-emerald-400" />
            </div>
            <div>
              <p className="text-2xl font-bold text-foreground">
                {segments?.active ?? 0}
              </p>
              <p className="text-xs font-medium text-emerald-400">
                {t("crm.segment_all")}
              </p>
            </div>
          </div>
          <p className="text-xs text-muted mt-2">
            {t("crm.segment_active_desc") || "All active customers in your database"}
          </p>
        </div>
      </div>

      <div className="mb-4">
        <div className="relative max-w-md">
          <Search className="absolute start-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted" />
          <input
            type="text"
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              resetPage();
            }}
            placeholder={t("common.search")}
            className="w-full ps-10 pe-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-primary/50 transition-colors"
          />
        </div>
      </div>

      <DataTable
        columns={columns}
        data={campaigns as unknown as Record<string, unknown>[]}
        loading={loading}
        emptyMessage={t("crm.no_campaigns")}
        emptyIcon={Megaphone}
        onRowClick={openDetail}
        pagination={{ total, page, perPage, onPageChange: setPage, onPerPageChange: changePageSize }}
      />

      <SlideOver
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        title={t("crm.new_campaign")}
      >
        <div className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">
              {t("crm.campaign_name")}
            </label>
            <input
              type="text"
              value={form.name}
              onChange={(e) =>
                setForm((p) => ({ ...p, name: e.target.value }))
              }
              placeholder={t("crm.campaign_name")}
              className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-primary/50 transition-colors"
            />
          </div>

          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">
              {t("crm.segment_type")}
            </label>
            <select
              value={form.segment_type}
              onChange={(e) =>
                setForm((p) => ({
                  ...p,
                  segment_type: e.target.value as "lost" | "vip" | "all",
                }))
              }
              className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-primary/50 transition-colors"
            >
              <option value="all">{t("crm.segment_all")}</option>
              <option value="vip">{t("crm.segment_vip")}</option>
              <option value="lost">{t("crm.segment_lost")}</option>
            </select>
          </div>

          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">
              {t("crm.channel")}
            </label>
            <select
              value={form.channel}
              onChange={(e) =>
                setForm((p) => ({
                  ...p,
                  channel: e.target.value as "sms" | "whatsapp",
                }))
              }
              className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-primary/50 transition-colors"
            >
              <option value="sms">{t("crm.channel_sms")}</option>
              <option value="whatsapp">{t("crm.channel_whatsapp")}</option>
            </select>
          </div>

          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">
              {t("crm.message_template")}
            </label>
            <textarea
              value={form.message_template}
              onChange={(e) =>
                setForm((p) => ({
                  ...p,
                  message_template: e.target.value,
                }))
              }
              rows={5}
              placeholder={
                t("crm.message_placeholder") ||
                "Hi {name}, we miss you! Here's a special offer just for you..."
              }
              className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-primary/50 transition-colors resize-none"
            />
          </div>

          <button
            onClick={handleCreate}
            disabled={saving || !form.name.trim()}
            className="w-full py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors disabled:opacity-50"
          >
            {saving ? (
              <span className="flex items-center justify-center gap-2">
                <Loader2 className="w-4 h-4 animate-spin" />{" "}
                {t("common.saving")}
              </span>
            ) : (
              t("common.save")
            )}
          </button>
        </div>
      </SlideOver>

      <SlideOver
        open={slideOpen}
        onClose={() => setSlideOpen(false)}
        title={selected?.name ?? ""}
      >
        {selected && (
          <div className="space-y-6">
            <div className="glass rounded-2xl p-5 space-y-3">
              <p className="text-sm font-semibold text-muted">
                {t("crm.campaign_name")}
              </p>
              <div className="flex items-center justify-between">
                <span className="text-sm text-muted">
                  {t("crm.segment_type")}
                </span>
                <span
                  className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border ${SEGMENT_BADGE[selected.segment_type] ?? SEGMENT_BADGE.all}`}
                >
                  {t(`crm.segment_${selected.segment_type}`)}
                </span>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-sm text-muted">{t("crm.channel")}</span>
                <span
                  className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border ${CHANNEL_BADGE[selected.channel] ?? CHANNEL_BADGE.sms}`}
                >
                  {t(`crm.channel_${selected.channel}`)}
                </span>
              </div>
              {selected.segment_type === "tier" && (
                <div className="flex items-center justify-between">
                  <span className="text-sm text-muted">{t("crm.tier")}</span>
                  <span className="text-sm font-semibold text-foreground">
                    {String(selected.metadata?.tier ?? "—")}
                  </span>
                </div>
              )}
              <div className="flex items-center justify-between">
                <span className="text-sm text-muted">
                  {t("common.status")}
                </span>
                <span
                  className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border ${STATUS_BADGE[selected.status] ?? STATUS_BADGE.draft}`}
                >
                  {t(`crm.status_${selected.status}`)}
                </span>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-sm text-muted">
                  {t("crm.recipients")}
                </span>
                <span className="text-sm font-semibold text-foreground">
                  {selectedStats?.recipients_count ?? selected.recipients_count}
                </span>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-sm text-muted">
                  {t("crm.sent_count") || "Sent"}
                </span>
                <span className="text-sm font-semibold text-foreground">
                  {selectedStats?.messages?.sent ?? selected.sent_count}
                </span>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-sm text-muted">
                  {t("crm.delivered_count") || "Delivered"}
                </span>
                <span className="text-sm font-semibold text-emerald-400">
                  {selectedStats?.messages?.delivered ?? selected.delivered_count}
                </span>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-sm text-muted">
                  {t("crm.failed_count") || "Failed"}
                </span>
                <span className="text-sm font-semibold text-red-400">
                  {selectedStats?.messages?.failed ?? selected.failed_count}
                </span>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-sm text-muted">{t("crm.sent_at")}</span>
                <span className="text-sm text-foreground">
                  {selected.sent_at
                    ? new Date(selected.sent_at).toLocaleDateString(locale)
                    : "—"}
                </span>
              </div>
            </div>

            <div className="glass rounded-2xl p-5 space-y-3">
              <p className="text-sm font-semibold text-muted">
                {t("crm.message_template")}
              </p>
              <p className="text-sm text-foreground whitespace-pre-wrap bg-card/50 rounded-xl px-4 py-3">
                {selected.message_template || "—"}
              </p>
            </div>

            {selected.status === "draft" || selected.status === "failed" ? (
              <button
                onClick={handleSend}
                disabled={sending}
                className="w-full py-2.5 bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 rounded-xl text-sm font-medium transition-colors disabled:opacity-50"
              >
                {sending ? (
                  <span className="flex items-center justify-center gap-2">
                    <Loader2 className="w-4 h-4 animate-spin" />{" "}
                    {t("common.sending") || "Sending..."}
                  </span>
                ) : (
                  <span className="flex items-center justify-center gap-2">
                    <Send className="w-4 h-4" /> {t("crm.send_campaign")}
                  </span>
                )}
              </button>
            ) : (
              <div className="flex items-center justify-center gap-2 py-2.5 bg-emerald-500/5 border border-emerald-500/20 rounded-xl">
                <span className="text-sm font-medium text-emerald-400">
                  {t(`crm.status_${selected.status}`)}
                </span>
              </div>
            )}

            {selected.status !== "draft" && selected.status !== "sending" && (
              <div className="glass rounded-2xl p-5 space-y-3">
                <p className="text-sm font-semibold text-muted">
                  {t("crm.send_test") || "Send Test Message"}
                </p>
                <div className="flex items-center gap-2">
                  <input
                    type="text"
                    value={testPhone}
                    onChange={(e) => setTestPhone(e.target.value)}
                    placeholder={t("crm.test_phone") || "e.g. +9627xxxxxxxx"}
                    className="flex-1 px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-primary/50 transition-colors"
                  />
                  <button
                    onClick={handleSendTest}
                    disabled={testing}
                    className="flex items-center gap-2 px-4 py-2.5 bg-primary/10 hover:bg-primary/20 text-primary border border-primary/30 rounded-xl text-sm font-medium transition-colors disabled:opacity-50"
                  >
                    {testing ? (
                      <Loader2 className="w-4 h-4 animate-spin" />
                    ) : (
                      <FlaskConical className="w-4 h-4" />
                    )}
                    {t("crm.test_send_btn") || "Test"}
                  </button>
                </div>
              </div>
            )}
          </div>
        )}
      </SlideOver>

      {toast && (
        <div
          className={`fixed top-4 end-4 z-[60] px-4 py-2.5 rounded-xl text-sm font-medium shadow-lg ${
            toast.type === "success"
              ? "bg-emerald-500/10 text-emerald-400 border border-emerald-500/30"
              : "bg-red-500/10 text-red-400 border border-red-500/30"
          }`}
        >
          {toast.msg}
        </div>
      )}
    </motion.div>
  );
}
