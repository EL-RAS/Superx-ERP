"use client";

import { useEffect, useState, useCallback } from "react";
import { motion } from "framer-motion";
import { useAuthStore } from "@/stores/auth-store";
import {
  fetchLoyaltyCards,
  createLoyaltyCard,
  createLoyaltyTransaction,
  fetchLoyaltyTransactions,
  Customers,
  ApiError,
} from "@/lib/api";
import type { LoyaltyCard, LoyaltyTransaction, Customer } from "@/lib/types";
import { formatCurrency } from "@/lib/types";
import { mapFieldErrors } from "@/lib/validation";
import { usePagination } from "@/lib/pagination";
import PageHeader from "@/components/ui/PageHeader";
import DataTable from "@/components/ui/DataTable";
import type { Column } from "@/components/ui/DataTable";
import SlideOver from "@/components/ui/SlideOver";
import { Gift, Plus, Loader2, AlertCircle, Search } from "lucide-react";
import { useI18n } from "@/lib/i18n";

const TIER_COLORS: Record<string, string> = {
  bronze: "bg-amber-500/10 text-amber-400 border-amber-500/30",
  silver: "bg-slate-400/10 text-slate-300 border-slate-400/30",
  gold: "bg-yellow-500/10 text-yellow-400 border-yellow-500/30",
  platinum: "bg-cyan-500/10 text-cyan-400 border-cyan-500/30",
};

export default function LoyaltyPage() {
  const { t, locale } = useI18n();
  const { token, business } = useAuthStore();
  const [cards, setCards] = useState<LoyaltyCard[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState("");
  const [detailOpen, setDetailOpen] = useState(false);
  const [selected, setSelected] = useState<LoyaltyCard | null>(null);
  const [history, setHistory] = useState<LoyaltyTransaction[]>([]);
  const [historyLoading, setHistoryLoading] = useState(false);
  const [createOpen, setCreateOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [toast, setToast] = useState<{ msg: string; type: "success" | "error" } | null>(null);
  const [total, setTotal] = useState(0);
  const { page, perPage, setPage, resetPage, changePageSize } = usePagination();

  const [addPoints, setAddPoints] = useState("");
  const [redeemPoints, setRedeemPoints] = useState("");
  const [pointsDesc, setPointsDesc] = useState("");
  const [actionLoading, setActionLoading] = useState<"earn" | "redeem" | null>(null);

  const [customerOptions, setCustomerOptions] = useState<Customer[]>([]);
  const [customerSearch, setCustomerSearch] = useState("");
  const [newCard, setNewCard] = useState({ customer_id: "", card_number: "" });
  const [errors, setErrors] = useState<Record<string, string>>({});

  const showToast = useCallback((msg: string, type: "success" | "error") => {
    setToast({ msg, type });
    window.setTimeout(() => setToast(null), 3000);
  }, []);

  const fetchData = useCallback(() => {
    if (!token || !business) return;
    setLoading(true);
    const params: Record<string, string | number> = { page, per_page: perPage };
    if (search.trim()) params.search = search.trim();
    fetchLoyaltyCards(token, business.id, params)
      .then((res) => {
        setCards(Array.isArray(res) ? res : (res as { data: LoyaltyCard[] }).data);
        setTotal(Array.isArray(res) ? 0 : (res as { total: number }).total);
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [token, business, search, page, perPage]);

  useEffect(() => {
    const timer = setTimeout(fetchData, 300);
    return () => clearTimeout(timer);
  }, [fetchData]);

  const loadHistory = useCallback(
    (cardId: number) => {
      if (!token || !business) return;
      setHistoryLoading(true);
      fetchLoyaltyTransactions(token, business.id, { loyalty_card_id: cardId, per_page: 50 })
        .then((res) => setHistory(Array.isArray(res) ? res : (res as { data: LoyaltyTransaction[] }).data))
        .catch(() => setHistory([]))
        .finally(() => setHistoryLoading(false));
    },
    [token, business]
  );

  const openDetail = (row: Record<string, unknown>) => {
    const card = row as unknown as LoyaltyCard;
    setSelected(card);
    setAddPoints("");
    setRedeemPoints("");
    setPointsDesc("");
    setDetailOpen(true);
    loadHistory(card.id);
  };

  const totals = cards.reduce(
    (acc, c) => ({
      points: acc.points + (Number(c.points_balance) || 0),
      earned: acc.earned + (Number(c.total_points_earned) || 0),
      redeemed: acc.redeemed + (Number(c.total_points_redeemed) || 0),
      spend: acc.spend + (Number(c.total_spend) || 0),
    }),
    { points: 0, earned: 0, redeemed: 0, spend: 0 }
  );

  const columns: Column[] = [
    { key: "card_number", label: t("loyalty.card_number") },
    {
      key: "customer",
      label: t("common.customer"),
      render: (_, row) => (row.customer as { name?: string } | undefined)?.name ?? "—",
    },
    {
      key: "tier",
      label: t("customers.tier_level"),
      type: "badge",
      render: (v) => (
        <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border ${TIER_COLORS[String(v)] ?? TIER_COLORS.bronze}`}>
          {t(`loyalty.tier_${String(v)}`)}
        </span>
      ),
    },
    { key: "points_balance", label: t("loyalty.points"), type: "number" },
    { key: "total_points_earned", label: t("loyalty.total_earned"), type: "number" },
    { key: "total_points_redeemed", label: t("loyalty.total_redeemed"), type: "number" },
    { key: "total_spend", label: t("loyalty.total_spend"), type: "currency" },
    { key: "is_active", label: t("common.status"), type: "boolean" },
  ];

  const handlePointsAction = async (type: "earn" | "redeem") => {
    if (!token || !business || !selected) return;
    const pts = parseInt(type === "earn" ? addPoints : redeemPoints, 10);
    if (isNaN(pts) || pts <= 0) {
      showToast(t("loyalty.points_amount"), "error");
      return;
    }
    if (type === "redeem" && pts > (Number(selected.points_balance) || 0)) {
      showToast(t("pos.insufficient_points"), "error");
      return;
    }
    setActionLoading(type);
    try {
      const res = await createLoyaltyTransaction(token, business.id, {
        loyalty_card_id: selected.id,
        type,
        points: pts,
        description: pointsDesc.trim() || null,
      });
      setSelected(res.card);
      setAddPoints("");
      setRedeemPoints("");
      setPointsDesc("");
      showToast(type === "earn" ? t("loyalty.earn") : t("loyalty.redeem"), "success");
      loadHistory(selected.id);
      fetchData();
    } catch {
      showToast(t("common.error"), "error");
    } finally {
      setActionLoading(null);
    }
  };

  const openCreate = () => {
    setNewCard({ customer_id: "", card_number: `LOY-${String(Date.now()).slice(-8)}${Math.floor(1000 + Math.random() * 9000)}` });
    setCustomerSearch("");
    setCustomerOptions([]);
    setErrors({});
    setCreateOpen(true);
  };

  const loadCustomers = useCallback(
    (q: string) => {
      if (!token || !business) return;
      Customers.list(token, business.id, { search: q, per_page: 25 })
        .then((res) => {
          const data = Array.isArray(res) ? res : (res as { data: Customer[] }).data;
          setCustomerOptions(data);
        })
        .catch(() => setCustomerOptions([]));
    },
    [token, business]
  );

  useEffect(() => {
    if (!createOpen) return;
    const timer = setTimeout(() => loadCustomers(customerSearch.trim()), 300);
    return () => clearTimeout(timer);
  }, [createOpen, customerSearch, loadCustomers]);

  const handleCreateCard = async () => {
    if (!token || !business) return;
    if (!newCard.customer_id) {
      setErrors({ customer_id: t("loyalty.select_customer") });
      return;
    }
    setSaving(true);
    try {
      await createLoyaltyCard(token, business.id, {
        customer_id: Number(newCard.customer_id),
        card_number: newCard.card_number.trim(),
      });
      setCreateOpen(false);
      showToast(t("loyalty.create_card"), "success");
      fetchData();
    } catch (err) {
      const apiErr = err instanceof ApiError ? err : null;
      setErrors(apiErr ? mapFieldErrors(apiErr, ["customer_id", "card_number"]) : {});
      showToast(apiErr?.message || t("common.error"), "error");
    } finally {
      setSaving(false);
    }
  };

  return (
    <motion.div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.4 }}>
      <PageHeader
        title={t("loyalty.title")}
        subtitle={t("loyalty.subtitle", { count: String(cards.length) })}
        action={
          <button onClick={openCreate} className="flex items-center gap-2 px-4 py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors">
            <Plus className="w-4 h-4" /> {t("loyalty.new_card")}
          </button>
        }
      />

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div className="glass rounded-2xl p-4">
          <p className="text-xs font-medium text-muted">{t("loyalty.points")}</p>
          <p className="text-2xl font-bold text-foreground mt-1">{totals.points.toLocaleString(locale)}</p>
        </div>
        <div className="glass rounded-2xl p-4">
          <p className="text-xs font-medium text-muted">{t("loyalty.total_earned")}</p>
          <p className="text-2xl font-bold text-emerald-400 mt-1">{totals.earned.toLocaleString(locale)}</p>
        </div>
        <div className="glass rounded-2xl p-4">
          <p className="text-xs font-medium text-muted">{t("loyalty.total_redeemed")}</p>
          <p className="text-2xl font-bold text-red-400 mt-1">{totals.redeemed.toLocaleString(locale)}</p>
        </div>
        <div className="glass rounded-2xl p-4">
          <p className="text-xs font-medium text-muted">{t("loyalty.total_spend")}</p>
          <p className="text-2xl font-bold text-foreground mt-1">{formatCurrency(totals.spend, locale)}</p>
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

      <DataTable columns={columns} data={cards as unknown as Record<string, unknown>[]} loading={loading} emptyMessage={t("loyalty.empty")} emptyIcon={Gift} onRowClick={openDetail} pagination={{ total, page, perPage, onPageChange: setPage, onPerPageChange: changePageSize }} />

      <SlideOver open={detailOpen} onClose={() => setDetailOpen(false)} title={selected?.card_number ?? ""}>
        {selected && (
          <div className="space-y-6">
            <div className="glass rounded-2xl p-5 space-y-3">
              <p className="text-sm font-semibold text-muted">{t("loyalty.card_details")}</p>
              <div className="flex items-center justify-between">
                <span className="text-sm text-muted">{t("loyalty.card_number")}</span>
                <span className="text-sm font-semibold text-foreground">{selected.card_number}</span>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-sm text-muted">{t("customers.tier_level")}</span>
                <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border ${TIER_COLORS[selected.tier] ?? TIER_COLORS.bronze}`}>
                  {t(`loyalty.tier_${selected.tier}`)}
                </span>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-sm text-muted">{t("loyalty.points")}</span>
                <span className="text-sm font-bold text-emerald-400">{selected.points_balance}</span>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-sm text-muted">{t("loyalty.total_spend")}</span>
                <span className="text-sm font-semibold text-foreground">{formatCurrency(Number(selected.total_spend) || 0, locale)}</span>
              </div>
            </div>

            {selected.customer && (
              <div className="glass rounded-2xl p-5 space-y-2">
                <p className="text-sm font-semibold text-muted">{t("loyalty.customer_info")}</p>
                <p className="text-sm font-semibold text-foreground">{selected.customer.name}</p>
                {selected.customer.phone && <p className="text-sm text-muted">{selected.customer.phone}</p>}
                {selected.customer.email && <p className="text-sm text-muted">{selected.customer.email}</p>}
              </div>
            )}

            <div className="glass rounded-2xl p-5 space-y-3">
              <p className="text-sm font-semibold text-muted">{t("loyalty.quick_actions")}</p>

              <div className="space-y-2">
                <label className="block text-sm font-medium text-muted">{t("loyalty.description")}</label>
                <input
                  type="text"
                  value={pointsDesc}
                  onChange={(e) => setPointsDesc(e.target.value)}
                  placeholder={t("loyalty.description")}
                  className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-primary/50 transition-colors"
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div className="space-y-2">
                  <label className="block text-sm font-medium text-muted">{t("loyalty.points_amount")}</label>
                  <input
                    type="number"
                    min={1}
                    value={addPoints}
                    onChange={(e) => setAddPoints(e.target.value)}
                    placeholder={t("loyalty.add_points")}
                    className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-primary/50 transition-colors"
                  />
                  <button
                    onClick={() => handlePointsAction("earn")}
                    disabled={actionLoading !== null}
                    className="w-full py-2.5 bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 rounded-xl text-sm font-medium transition-colors disabled:opacity-50"
                  >
                    {actionLoading === "earn" ? (
                      <span className="flex items-center justify-center gap-2">
                        <Loader2 className="w-4 h-4 animate-spin" /> {t("loyalty.adding")}
                      </span>
                    ) : (
                      t("loyalty.add_btn")
                    )}
                  </button>
                </div>
                <div className="space-y-2">
                  <label className="block text-sm font-medium text-muted">{t("loyalty.points_amount")}</label>
                  <input
                    type="number"
                    min={1}
                    max={Number(selected.points_balance) || 0}
                    value={redeemPoints}
                    onChange={(e) => setRedeemPoints(e.target.value)}
                    placeholder={t("loyalty.redeem_points")}
                    className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-primary/50 transition-colors"
                  />
                  <button
                    onClick={() => handlePointsAction("redeem")}
                    disabled={actionLoading !== null}
                    className="w-full py-2.5 bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/30 rounded-xl text-sm font-medium transition-colors disabled:opacity-50"
                  >
                    {actionLoading === "redeem" ? (
                      <span className="flex items-center justify-center gap-2">
                        <Loader2 className="w-4 h-4 animate-spin" /> {t("loyalty.redeeming")}
                      </span>
                    ) : (
                      t("loyalty.redeem_btn")
                    )}
                  </button>
                </div>
              </div>
            </div>

            <div className="glass rounded-2xl p-5 space-y-3">
              <p className="text-sm font-semibold text-muted">{t("loyalty.points_history")}</p>
              {historyLoading ? (
                <div className="flex items-center gap-2 text-sm text-muted py-2">
                  <Loader2 className="w-4 h-4 animate-spin" /> {t("common.loading")}
                </div>
              ) : history.length === 0 ? (
                <p className="text-sm text-muted">{t("common.none")}</p>
              ) : (
                <div className="space-y-2 max-h-64 overflow-y-auto">
                  {history.map((trx) => (
                    <div key={trx.id} className="flex items-center justify-between bg-card/50 rounded-xl px-3 py-2">
                      <div>
                        <span
                          className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium border me-2 ${
                            trx.type === "earn"
                              ? "bg-emerald-500/10 text-emerald-400 border-emerald-500/30"
                              : trx.type === "redeem"
                                ? "bg-red-500/10 text-red-400 border-red-500/30"
                                : "bg-slate-400/10 text-slate-300 border-slate-400/30"
                          }`}
                        >
                          {t(`loyalty.${trx.type}`)}
                        </span>
                        <span className="text-xs text-muted">{trx.description || "—"}</span>
                      </div>
                      <div className="text-end">
                        <span className={`text-sm font-semibold ${trx.type === "redeem" ? "text-red-400" : "text-emerald-400"}`}>
                          {trx.type === "redeem" ? "-" : "+"}{trx.points}
                        </span>
                        {trx.balance_after !== null && trx.balance_after !== undefined && (
                          <p className="text-xs text-muted">
                            {t("loyalty.points")}: {trx.balance_after}
                          </p>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>
        )}
      </SlideOver>

      <SlideOver open={createOpen} onClose={() => setCreateOpen(false)} title={t("loyalty.create_card")}>
        <div className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">{t("loyalty.select_customer")}</label>
            <input
              type="text"
              value={customerSearch}
              onChange={(e) => {
                setCustomerSearch(e.target.value);
                setNewCard((p) => ({ ...p, customer_id: "" }));
              }}
              placeholder={t("common.search")}
              className={`w-full px-4 py-2.5 bg-card/80 border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-primary/50 transition-colors ${errors.customer_id ? "border-red-500/50" : "border-border"}`}
            />
            {customerOptions.length > 0 && (
              <div className="mt-2 max-h-48 overflow-y-auto border border-border rounded-xl divide-y divide-border/40">
                {customerOptions.map((c) => (
                  <button
                    key={c.id}
                    type="button"
                    onClick={() => {
                      setNewCard((p) => ({ ...p, customer_id: String(c.id) }));
                      setCustomerSearch(c.name);
                      setErrors((p) => ({ ...p, customer_id: "" }));
                    }}
                    className={`w-full text-start px-4 py-2.5 text-sm hover:bg-card-hover/30 transition-colors ${newCard.customer_id === String(c.id) ? "bg-primary/10" : ""}`}
                  >
                    <span className="font-medium text-foreground">{c.name}</span>
                    {c.phone && <span className="block text-xs text-muted">{c.phone}</span>}
                  </button>
                ))}
              </div>
            )}
            {errors.customer_id && (
              <p className="flex items-center gap-1 text-xs text-red-400 mt-1">
                <AlertCircle className="w-3 h-3" /> {errors.customer_id}
              </p>
            )}
          </div>

          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">{t("loyalty.card_number")}</label>
            <input
              type="text"
              value={newCard.card_number}
              onChange={(e) => {
                setNewCard((p) => ({ ...p, card_number: e.target.value }));
                if (errors.card_number) setErrors((p) => ({ ...p, card_number: "" }));
              }}
              className={`w-full px-4 py-2.5 bg-card/80 border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-primary/50 transition-colors ${errors.card_number ? "border-red-500/50" : "border-border"}`}
            />
            {errors.card_number && (
              <p className="flex items-center gap-1 text-xs text-red-400 mt-1">
                <AlertCircle className="w-3 h-3" /> {errors.card_number}
              </p>
            )}
          </div>

          <button
            onClick={handleCreateCard}
            disabled={saving}
            className="w-full py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors disabled:opacity-50"
          >
            {saving ? (
              <span className="flex items-center justify-center gap-2">
                <Loader2 className="w-4 h-4 animate-spin" /> {t("loyalty.creating")}
              </span>
            ) : (
              t("loyalty.create_card")
            )}
          </button>
        </div>
      </SlideOver>

      {toast && (
        <div className={`fixed top-4 end-4 z-[60] px-4 py-2.5 rounded-xl text-sm font-medium shadow-lg ${toast.type === "success" ? "bg-emerald-500/10 text-emerald-400 border border-emerald-500/30" : "bg-red-500/10 text-red-400 border border-red-500/30"}`}>
          {toast.msg}
        </div>
      )}
    </motion.div>
  );
}
