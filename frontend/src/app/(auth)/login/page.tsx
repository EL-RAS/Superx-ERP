"use client";

import { useState, useCallback, useRef } from "react";
import { useRouter } from "next/navigation";
import Image from "next/image";
import { motion, AnimatePresence } from "framer-motion";
import { useAuthStore } from "@/stores/auth-store";
import { loginRequest, tenantLogin, resolveTenantHost, ApiError } from "@/lib/api";
import type { LoginResponse, TenantLoginResponse } from "@/lib/types";
import { useI18n } from "@/lib/i18n";
import { User, Lock, Eye, EyeOff, ArrowRight, Loader2, AlertCircle, ExternalLink } from "lucide-react";

const spring = { type: "spring", stiffness: 400, damping: 30 } as const;

function FloatingInput({
  label,
  type = "text",
  value,
  onChange,
  icon: Icon,
  rightElement,
  autoFocus,
}: {
  label: string;
  type?: string;
  value: string;
  onChange: (v: string) => void;
  icon: React.ComponentType<{ className?: string }>;
  rightElement?: React.ReactNode;
  autoFocus?: boolean;
}) {
  const [focused, setFocused] = useState(false);
  const active = focused || value.length > 0;

  return (
    <div className="relative group">
      <div
        className={`absolute -inset-px rounded-2xl transition-all duration-500 ${
          focused
            ? "opacity-100 bg-gradient-to-r from-gold/30 via-gold/10 to-gold/30"
            : "opacity-0"
        }`}
      />
      <div className="relative flex items-center rounded-2xl border border-border bg-card backdrop-blur-md group-hover:border-border-hover transition-all duration-300">
        <div className="ps-4.5 flex items-center pointer-events-none">
          <Icon
            className={`w-4 h-4 transition-all duration-300 ${
              focused ? "text-gold" : "text-muted"
            }`}
          />
        </div>
        <div className="relative flex-1 py-3.5 pe-4">
          <label
            className={`absolute start-0 transition-all duration-300 pointer-events-none origin-left ${
              active
                ? "text-[10px] font-medium text-muted -top-0.5 scale-[0.85]"
                : "text-sm text-muted top-1/2 -translate-y-1/2 scale-100"
            }`}
          >
            {label}
          </label>
          <input
            type={type}
            value={value}
            onChange={(e) => onChange(e.target.value)}
            onFocus={() => setFocused(true)}
            onBlur={() => setFocused(false)}
            autoFocus={autoFocus}
            className="w-full bg-transparent text-sm text-foreground outline-none pt-2.5 placeholder-transparent tracking-wide"
          />
        </div>
        {rightElement && <div className="pe-3.5">{rightElement}</div>}
      </div>
    </div>
  );
}

export default function LoginPage() {
  const router = useRouter();
  const { setAuth } = useAuthStore();
  const cardRef = useRef<HTMLDivElement>(null);
  const { t } = useI18n();

  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [activation, setActivation] = useState<{ url: string; business_name?: string } | null>(null);

  const canSubmit = username.length >= 3 && password.length >= 8 && !submitting;

  const handleMouseMove = useCallback(
    (e: React.MouseEvent<HTMLDivElement>) => {
      if (!cardRef.current) return;
      const rect = cardRef.current.getBoundingClientRect();
      const x = ((e.clientX - rect.left) / rect.width) * 100;
      const y = ((e.clientY - rect.top) / rect.height) * 100;
      cardRef.current.style.setProperty("--mouse-x", `${x}%`);
      cardRef.current.style.setProperty("--mouse-y", `${y}%`);
    },
    []
  );

  const handleSubmit = useCallback(async () => {
    if (!canSubmit) return;
    setSubmitting(true);
    setError(null);
    setActivation(null);
    try {
      let res: LoginResponse | TenantLoginResponse;
      const tenantHost = resolveTenantHost();
      if (tenantHost) {
        // Tenant subdomain / explicit ?host= — verify against the tenant DB.
        const t = await tenantLogin(tenantHost, username, password);
        if (t.needs_activation && t.activation_url) {
          setActivation({ url: t.activation_url, business_name: t.business_name });
          return;
        }
        res = t;
      } else {
        res = await loginRequest(username, password);
      }
      setAuth(res.token, res.user, res.business ?? null);
      if (res.user.is_platform_owner) {
        router.replace("/super-admin");
      } else {
        const state = res.business?.subscription?.state;
        router.replace(state === "expired" || state === "suspended" ? "/subscription-expired" : "/dashboard");
      }
    } catch (err: unknown) {
      const msg =
        err instanceof ApiError
          ? err.errors?.username?.[0] ?? err.message
          : "Login failed";
      setError(msg);
    } finally {
      setSubmitting(false);
    }
  }, [username, password, canSubmit, router, setAuth]);

  return (
    <div className="min-h-screen bg-gradient-to-b from-[#0A111E] via-[#0a0e18] to-[#0A111E] text-foreground flex items-center justify-center p-4">
      <style>{`
        .login-card {
          --mouse-x: 50%;
          --mouse-y: 50%;
        }
        .login-card::before {
          content: '';
          position: absolute;
          inset: -1px;
          border-radius: 1.25rem;
          background: radial-gradient(
            400px circle at var(--mouse-x) var(--mouse-y),
            rgba(212,175,55,0.08),
            transparent 60%
          );
          z-index: 0;
          pointer-events: none;
          transition: opacity 0.3s;
        }
        .login-card::after {
          content: '';
          position: absolute;
          inset: 0;
          border-radius: 1.25rem;
          background: linear-gradient(160deg, rgba(27,59,111,0.05) 0%, rgba(0,0,0,0.4) 100%);
          z-index: 0;
          pointer-events: none;
        }
      `}</style>

      <div className="fixed inset-0 overflow-hidden pointer-events-none">
        <motion.div
          className="absolute -top-60 -right-60 w-[700px] h-[700px] bg-[#D49A37]/[0.015] rounded-full blur-[180px]"
          animate={{ scale: [1, 1.2, 1], opacity: [0.4, 0.6, 0.4] }}
          transition={{ duration: 12, repeat: Infinity, ease: "easeInOut" }}
        />
        <motion.div
          className="absolute -bottom-60 -left-60 w-[700px] h-[700px] bg-[#1E4E8C]/[0.02] rounded-full blur-[180px]"
          animate={{ scale: [1.2, 1, 1.2], opacity: [0.3, 0.5, 0.3] }}
          transition={{ duration: 12, repeat: Infinity, ease: "easeInOut", delay: 4 }}
        />
      </div>

      <motion.div
        className="relative w-full max-w-[380px]"
        initial={{ opacity: 0, y: 40, filter: "blur(12px)" }}
        animate={{ opacity: 1, y: 0, filter: "blur(0px)" }}
        transition={{ duration: 0.8, ease: [0.16, 1, 0.3, 1] }}
      >
        <motion.div
          className="flex flex-col items-center gap-4 mb-12"
          initial={{ opacity: 0, y: -20 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ delay: 0.15, ...spring }}
        >
          <div className="relative">
            <Image src="/images/logobg.png" alt="superX Logo" width={288} height={288} className="w-40 h-40 object-contain rounded-2xl shadow-2xl" priority />
            <div className="absolute -inset-3 rounded-[1.8rem] bg-gradient-to-br from-[#D49A37]/20 to-transparent blur-sm -z-10" />
          </div>
          <div className="text-center">
            <span className="text-2xl font-bold tracking-tight block text-foreground">SuperxERP</span>
            <span className="text-[11px] text-muted tracking-[0.2em] uppercase mt-1 block font-medium">
              {t("auth.enterprise_platform")}
            </span>
          </div>
        </motion.div>

        <motion.div
          ref={cardRef}
          className="login-card relative rounded-3xl p-8 shadow-2xl shadow-black/80 bg-card border border-border"
          onMouseMove={handleMouseMove}
          initial={{ opacity: 0, y: 20 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ delay: 0.2, duration: 0.7, ease: [0.16, 1, 0.3, 1] }}
          style={{ position: "relative" }}
        >
          <div className="relative z-10">
            <div className="mb-8">
              <h1 className="text-2xl font-bold tracking-tight mb-1.5 text-foreground">{t("auth.welcome_back")}</h1>
              <p className="text-muted text-sm tracking-wide">
                {t("auth.sign_in_subtitle")}
              </p>
            </div>

            <div
              className="space-y-4"
              onKeyDown={(e) => e.key === "Enter" && handleSubmit()}
            >
              <FloatingInput
                label={t("auth.username")}
                value={username}
                onChange={setUsername}
                icon={User}
                autoFocus
              />

              <FloatingInput
                label={t("auth.password")}
                type={showPassword ? "text" : "password"}
                value={password}
                onChange={setPassword}
                icon={Lock}
                rightElement={
                  <button
                    type="button"
                    onClick={() => setShowPassword(!showPassword)}
                    className="text-muted hover:text-foreground transition-colors p-0.5 rounded-lg"
                    tabIndex={-1}
                  >
                    {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                  </button>
                }
              />

              <AnimatePresence>
                {error && (
                  <motion.div
                    className="p-3.5 rounded-2xl bg-danger/10 border border-danger/20 text-danger text-xs flex items-start gap-2.5 backdrop-blur-sm"
                    initial={{ opacity: 0, y: -8, scale: 0.96 }}
                    animate={{ opacity: 1, y: 0, scale: 1 }}
                    exit={{ opacity: 0, y: -8, scale: 0.96 }}
                    transition={spring}
                  >
                    <AlertCircle className="w-3.5 h-3.5 mt-0.5 flex-shrink-0" />
                    <span className="tracking-wide">{error}</span>
                  </motion.div>
                )}
              </AnimatePresence>

              <AnimatePresence>
                {activation && (
                  <motion.div
                    className="p-3.5 rounded-2xl bg-amber-500/10 border border-amber-500/25 text-amber-400 text-xs flex items-start gap-2.5 backdrop-blur-sm"
                    initial={{ opacity: 0, y: -8, scale: 0.96 }}
                    animate={{ opacity: 1, y: 0, scale: 1 }}
                    exit={{ opacity: 0, y: -8, scale: 0.96 }}
                    transition={spring}
                  >
                    <AlertCircle className="w-3.5 h-3.5 mt-0.5 flex-shrink-0" />
                    <div className="space-y-2 tracking-wide">
                      <p>{t("auth.needs_activation")}</p>
                      <a
                        href={activation.url}
                        className="inline-flex items-center gap-1.5 font-semibold text-amber-300 hover:text-amber-200 transition-colors underline underline-offset-4"
                      >
                        {t("activate.activate_store")}
                        <ExternalLink className="w-3 h-3" />
                      </a>
                    </div>
                  </motion.div>
                )}
              </AnimatePresence>

              <motion.button
                onClick={handleSubmit}
                disabled={!canSubmit}
                className={`w-full py-4 rounded-2xl font-semibold text-sm transition-all duration-300 flex items-center justify-center gap-2.5 tracking-wide ${
                  canSubmit
                    ? "bg-gradient-to-r from-[#1E4E8C] to-[#3A75C4] text-white hover:from-[#3A75C4] hover:to-[#1E4E8C] shadow-lg shadow-[#1E4E8C]/20 hover:shadow-[#1E4E8C]/30 hover:scale-[1.02] active:scale-[0.98]"
                    : "bg-card text-muted cursor-not-allowed border border-border"
                }`}
                whileTap={canSubmit ? { scale: 0.97 } : undefined}
              >
                {submitting ? (
                  <motion.div className="flex items-center gap-2.5" initial={{ opacity: 0 }} animate={{ opacity: 1 }}>
                    <Loader2 className="w-4 h-4 animate-spin" />
                    {t("auth.signing_in")}
                  </motion.div>
                ) : (
                  <motion.div className="flex items-center gap-2.5" initial={{ opacity: 0 }} animate={{ opacity: 1 }}>
                    {t("auth.sign_in")}
                    <ArrowRight className="w-4 h-4" />
                  </motion.div>
                )}
              </motion.button>
            </div>
          </div>
        </motion.div>

        <motion.p
          className="text-center text-muted text-xs mt-10 tracking-wide"
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          transition={{ delay: 0.5 }}
        >
          {t("auth.no_account")}{" "}
          <button
            onClick={() => router.push("/register")}
            className="text-gold hover:text-gold-light transition-colors duration-300 font-medium"
          >
            {t("auth.request_demo")}
          </button>
        </motion.p>
      </motion.div>
    </div>
  );
}
