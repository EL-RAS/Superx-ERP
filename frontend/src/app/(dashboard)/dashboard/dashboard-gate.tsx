"use client";

import { useEffect, useState, useRef } from "react";
import { useRouter, usePathname } from "next/navigation";
import { motion } from "framer-motion";
import { useAuthStore } from "@/stores/auth-store";
import { fetchBootstrap, ApiError } from "@/lib/api";
import { useI18n } from "@/lib/i18n";
import DashboardShell from "@/components/core/DashboardShell";
import { AlertCircle, LogIn, ShieldX, TriangleAlert } from "lucide-react";

const ROUTE_PERMISSIONS: { prefix: string; module: string; key?: string; feature?: "crm" }[] = [
  { prefix: "/dashboard", module: "dashboard" },
  { prefix: "/pos/shifts", module: "pos", key: "pos.shifts" },
  { prefix: "/pos", module: "pos" },
  { prefix: "/invoices", module: "sales" },
  { prefix: "/orders", module: "sales" },
  { prefix: "/returns-exchanges", module: "sales" },
  { prefix: "/promotions", module: "sales" },
  { prefix: "/products", module: "inventory" },
  { prefix: "/inventory", module: "inventory" },
  { prefix: "/warehouses", module: "inventory" },
  { prefix: "/accounts", module: "accounting" },
  { prefix: "/journal-entries", module: "accounting" },
  { prefix: "/accounting", module: "accounting" },
  { prefix: "/trial-balance", module: "accounting" },
  { prefix: "/fiscal-years", module: "accounting" },
  { prefix: "/bank-reconciliation", module: "accounting" },
  { prefix: "/crm", module: "crm", feature: "crm" },
  // /customers is core (Sales/POS/AR use it); it is permission-gated only and
  // stays reachable when the CRM module is feature-disabled.
  { prefix: "/customers", module: "crm" },
  { prefix: "/loyalty", module: "crm", feature: "crm" },
  { prefix: "/users", module: "users_roles" },
  { prefix: "/roles", module: "users_roles" },
  { prefix: "/suppliers", module: "purchases" },
  { prefix: "/purchase-orders", module: "purchases" },
  { prefix: "/purchases", module: "purchases" },
  { prefix: "/reports", module: "reports" },
  { prefix: "/settings", module: "settings" },
];

function requiredModuleFor(pathname: string): { prefix: string; module: string; key?: string; feature?: "crm" } | null {
  const match = ROUTE_PERMISSIONS.filter((r) => pathname.startsWith(r.prefix)).sort(
    (a, b) => b.prefix.length - a.prefix.length
  )[0];
  return match ? match : null;
}

export default function DashboardGate({
  children,
}: {
  children: React.ReactNode;
}) {
  const { business, config, setConfig, setLoading, isLoading, hydrate, logout } =
    useAuthStore();
  const [error, setError] = useState<string | null>(null);
  const router = useRouter();
  const pathname = usePathname();
  const { t } = useI18n();
  const bootstrappingRef = useRef(false);

  useEffect(() => {
    const hydrated = hydrate();
    if (!hydrated) {
      router.replace("/login");
      return;
    }

    const state = useAuthStore.getState();
    const currentToken = state.token;
    const currentBusiness = state.business;

    // Platform owners live in the SuperX portal, not inside a tenant.
    if (state.user?.is_platform_owner && !currentBusiness) {
      router.replace("/super-admin");
      return;
    }

    if (!currentToken || !currentBusiness) {
      router.replace("/login");
      return;
    }

    // Hard lockout: expired / suspended tenants can browse nothing.
    const loginState = currentBusiness.subscription?.state;
    if (loginState === "expired" || loginState === "suspended") {
      router.replace(`/subscription-expired?state=${loginState}`);
      return;
    }

    if (config) {
      setLoading(false);
      return;
    }

    if (bootstrappingRef.current) return;
    bootstrappingRef.current = true;

    setLoading(true);
    fetchBootstrap(currentToken, currentBusiness.id)
      .then((data) => {
        const subState = data.subscription?.state;
        if (subState === "expired" || subState === "suspended") {
          useAuthStore.getState().setBusiness({
            ...currentBusiness,
            subscription: data.subscription ?? null,
          });
          router.replace(`/subscription-expired?state=${subState}`);
          return;
        }
        setConfig(data);
      })
      .catch((err) => {
        // IdentifyBusiness returns 403 with a subscription_* code when the
        // tenant is locked; everything else 401/403 is an auth problem.
        if (err instanceof ApiError && err.status === 403) {
          router.replace("/subscription-expired?state=blocked");
          return;
        }
        if (
          err.message?.includes("401") ||
          err.message?.includes("Unauthenticated")
        ) {
          logout();
          window.location.href = "/login";
          return;
        }
        setError(err.message);
        setLoading(false);
        bootstrappingRef.current = false;
      });
    // eslint-disable-next-line react-hooks/exhaustive-deps -- runs once on mount
  }, []);

  if (error) {
    return (
      <div className="min-h-screen bg-background flex items-center justify-center p-4">
        <motion.div
          className="text-center max-w-sm"
          initial={{ opacity: 0, y: 12 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.4, ease: [0.16, 1, 0.36, 1] }}
        >
          <div className="w-14 h-14 rounded-full bg-danger/10 border border-danger/20 flex items-center justify-center mx-auto mb-4">
            <AlertCircle className="w-7 h-7 text-danger" />
          </div>
          <p className="text-foreground font-medium mb-1">{t("dashboard.failed")}</p>
          <p className="text-muted text-sm mb-6">{error}</p>
          <button
            onClick={() => {
              logout();
              window.location.href = "/login";
            }}
            className="px-5 py-2.5 bg-card text-foreground rounded-xl hover:bg-card-hover transition-all text-sm font-medium flex items-center gap-2 mx-auto border border-border"
          >
            <LogIn className="w-4 h-4" /> {t("dashboard.sign_in_again")}
          </button>
        </motion.div>
      </div>
    );
  }

  if (isLoading || !config) {
    return (
      <div className="min-h-screen bg-background flex items-center justify-center">
        <motion.div
          className="text-center"
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          transition={{ duration: 0.3 }}
        >
          <motion.div
            className="w-10 h-10 border-[3px] border-border border-t-gold rounded-full mx-auto mb-4"
            animate={{ rotate: 360 }}
            transition={{ duration: 1, repeat: Infinity, ease: "linear" }}
          />
          <p className="text-muted text-sm">{t("dashboard.loading")}</p>
        </motion.div>
      </div>
    );
  }

  const requiredModule = requiredModuleFor(pathname || "");
  const permissions = config.user_permissions ?? [];
  const isAllowed =
    (!requiredModule || permissions.includes(requiredModule.key ?? `${requiredModule.module}.view`)) &&
    !(requiredModule?.feature === "crm" && config.features?.crm_enabled === false);

  if (!isAllowed) {
    return (
      <div className="min-h-screen bg-background flex items-center justify-center p-4">
        <motion.div
          className="text-center max-w-sm"
          initial={{ opacity: 0, y: 12 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.4, ease: [0.16, 1, 0.36, 1] }}
        >
          <div className="w-14 h-14 rounded-full bg-amber-500/10 border border-amber-500/20 flex items-center justify-center mx-auto mb-4">
            <ShieldX className="w-7 h-7 text-amber-400" />
          </div>
          <p className="text-foreground font-medium mb-1">{t("roles.no_access")}</p>
          <p className="text-muted text-sm mb-6">{requiredModule.key ?? requiredModule.module}</p>
          <button
            onClick={() => router.push(permissions.includes("pos.view") ? "/pos" : "/dashboard")}
            className="px-5 py-2.5 bg-primary text-foreground rounded-xl hover:bg-primary-light transition-all text-sm font-medium"
          >
            {permissions.includes("pos.view") ? t("nav.pos") : t("nav.dashboard")}
          </button>
        </motion.div>
      </div>
    );
  }

  const subState = config.subscription?.state ?? business?.subscription?.state;
  const showExpiryWarning = subState === "expiring_soon";

  return (
    <div>
      {showExpiryWarning && (
        <div className="sticky top-0 z-[60] w-full bg-amber-500/15 border-b border-amber-500/30 text-amber-300 px-4 py-2.5 flex items-center justify-center gap-2 text-xs font-medium backdrop-blur-sm">
          <TriangleAlert className="w-3.5 h-3.5 flex-shrink-0" />
          <span>
            {t("dashboard.expiring_banner", {
              days: String(
                config.subscription?.days_remaining ??
                  business?.subscription?.days_remaining ??
                  7
              ),
            })}
          </span>
        </div>
      )}
      <DashboardShell config={config}>{children}</DashboardShell>
    </div>
  );
}
