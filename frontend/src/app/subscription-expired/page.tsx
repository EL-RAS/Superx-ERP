"use client";

import { Suspense, useEffect } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { motion } from "framer-motion";
import { useAuthStore } from "@/stores/auth-store";
import { useI18n } from "@/lib/i18n";
import { Lock, LogOut, MessageCircle, RefreshCw } from "lucide-react";

const SUPPORT_WHATSAPP = process.env.NEXT_PUBLIC_SUPERX_SUPPORT_WHATSAPP ?? "+962790000000";

export default function SubscriptionExpiredPage() {
  return (
    <Suspense>
      <SubscriptionExpiredBody />
    </Suspense>
  );
}

function SubscriptionExpiredBody() {
  const router = useRouter();
  const params = useSearchParams();
  const { business, hydrate, logout } = useAuthStore();
  const { t, locale } = useI18n();

  useEffect(() => {
    hydrate();
  }, [hydrate]);

  const state =
    params.get("state") ??
    (business?.subscription?.state === "suspended" ? "suspended" : "expired");

  const isSuspended = state === "suspended";
  const plan = business?.subscription?.plan;
  const daysRemaining = business?.subscription?.days_remaining;

  const handleRefresh = () => {
    // Re-enter the dashboard; if the tenant was unlocked server-side this
    // succeeds, otherwise bootstrap bounces straight back here.
    window.location.href = "/dashboard";
  };

  return (
    <div className="min-h-screen bg-gradient-to-b from-[#0A111E] via-[#0a0e18] to-[#0A111E] text-foreground flex items-center justify-center p-4">
      <motion.div
        className="relative w-full max-w-[460px]"
        initial={{ opacity: 0, y: 32, filter: "blur(10px)" }}
        animate={{ opacity: 1, y: 0, filter: "blur(0px)" }}
        transition={{ duration: 0.7, ease: [0.16, 1, 0.3, 1] }}
      >
        <div className="relative rounded-3xl p-8 shadow-2xl shadow-black/80 bg-card border border-border text-center">
          <motion.div
            className="w-16 h-16 rounded-full mx-auto mb-5 flex items-center justify-center"
            initial={{ scale: 0.6, opacity: 0 }}
            animate={{ scale: 1, opacity: 1 }}
            transition={{ delay: 0.2, type: "spring", stiffness: 300, damping: 20 }}
          >
            <div
              className={`absolute inset-0 rounded-full blur-md ${
                isSuspended ? "bg-slate-500/20" : "bg-danger/20"
              }`}
            />
            <Lock className={`w-8 h-8 relative ${isSuspended ? "text-slate-300" : "text-danger"}`} />
          </motion.div>

          <h1 className="text-xl font-bold tracking-tight mb-2">
            {isSuspended ? t("subscription.suspended_title") : t("subscription.expired_title")}
          </h1>
          <p className="text-muted text-sm leading-relaxed mb-1">
            {isSuspended ? t("subscription.suspended_message") : t("subscription.expired_message")}
          </p>

          {(plan || typeof daysRemaining === "number") && (
            <p className="text-[11px] text-muted/80 mt-2">
              {plan && `${t("subscription.plan_label")}: ${plan}`}
              {!isSuspended && typeof daysRemaining === "number"
                ? `${plan ? " · " : ""}${t("subscription.days_overdue", { days: String(Math.abs(daysRemaining)) })}`
                : ""}
            </p>
          )}

          <div className="mt-7 space-y-3">
            <a
              href={`https://wa.me/${SUPPORT_WHATSAPP.replace(/[^\d]/g, "")}?text=${encodeURIComponent(
                locale === "ar"
                  ? `مرحباً، أرغب بتجديد اشتراك ${business?.name ?? ""} في SuperX`
                  : `Hello, I would like to renew the SuperX subscription for ${business?.name ?? ""}`
              )}`}
              target="_blank"
              rel="noreferrer"
              className="w-full py-3.5 rounded-2xl font-semibold text-sm flex items-center justify-center gap-2.5 bg-emerald-600 hover:bg-emerald-500 text-white transition-colors shadow-lg shadow-emerald-900/30"
            >
              <MessageCircle className="w-4 h-4" />
              {t("subscription.contact_whatsapp")}
            </a>

            <button
              onClick={handleRefresh}
              className="w-full py-3.5 rounded-2xl font-semibold text-sm flex items-center justify-center gap-2.5 bg-card border border-border text-foreground hover:bg-card-hover transition-colors"
            >
              <RefreshCw className="w-4 h-4" />
              {t("subscription.check_again")}
            </button>

            <button
              onClick={() => {
                logout();
                router.replace("/login");
              }}
              className="w-full py-3 rounded-2xl text-sm text-muted hover:text-foreground transition-colors flex items-center justify-center gap-2"
            >
              <LogOut className="w-4 h-4 rtl:rotate-180" />
              {t("auth.logout")}
            </button>
          </div>

          <p className="mt-6 text-[10px] text-muted/70 tracking-wide">{t("subscription.footer_note")}</p>
        </div>
      </motion.div>
    </div>
  );
}
