"use client";

import { Suspense, useCallback, useEffect, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import Image from "next/image";
import Link from "next/link";
import { motion } from "framer-motion";
import {
  fetchActivation,
  activateStore,
  storeLoginUrl,
  ApiError,
} from "@/lib/api";
import type { ActivationPreview, ActivateResponse } from "@/lib/types";
import { useI18n } from "@/lib/i18n";
import PasswordInput from "@/components/ui/PasswordInput";
import {
  Store,
  User,
  Mail,
  Lock,
  AtSign,
  CheckCircle2,
  AlertCircle,
  Loader2,
  ArrowRight,
} from "lucide-react";

const spring = { type: "spring", stiffness: 400, damping: 30 } as const;

const CURRENCIES = ["JOD", "USD", "SAR", "AED", "QAR", "KWD", "OMR", "EGP"];

const inputCls =
  "w-full bg-transparent text-sm text-foreground outline-none py-3.5 pe-4 tracking-wide placeholder:text-muted/60";

const strongPassword =
  /^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[@$!%*?&]).{8,}$/;

const usernamePattern = /^[a-zA-Z0-9_]{3,30}$/;

export default function ActivatePage() {
  return (
    <Suspense>
      <ActivateBody />
    </Suspense>
  );
}

function Field({
  label,
  icon: Icon,
  error,
  hint,
  children,
}: {
  label: string;
  icon: React.ComponentType<{ className?: string }>;
  error?: string;
  hint?: string;
  children: React.ReactNode;
}) {
  return (
    <div>
      <label className="block text-[11px] font-medium text-muted tracking-wide mb-1.5">
        {label}
      </label>
      <div
        className={`flex items-center rounded-2xl border bg-card transition-all duration-300 ${
          error ? "border-danger" : "border-border focus-within:border-gold/60"
        }`}
      >
        <div className="ps-4 flex items-center pointer-events-none">
          <Icon className="w-4 h-4 text-muted" />
        </div>
        {children}
      </div>
      {hint && !error && <p className="text-[10px] text-muted mt-1 ps-1">{hint}</p>}
      {error && (
        <p className="text-[11px] text-danger mt-1 ps-1 flex items-center gap-1">
          <AlertCircle className="w-3 h-3" /> {error}
        </p>
      )}
    </div>
  );
}

function ActivateBody() {
  const router = useRouter();
  const params = useSearchParams();
  const { t } = useI18n();

  const token = params.get("token") ?? "";

  const [phase, setPhase] = useState<"loading" | "error" | "form" | "success">("loading");
  const [preview, setPreview] = useState<ActivationPreview | null>(null);
  const [result, setResult] = useState<ActivateResponse | null>(null);
  const [error, setError] = useState<string | null>(null);

  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");
  const [currency, setCurrency] = useState("JOD");
  const [taxEnabled, setTaxEnabled] = useState(true);
  const [taxRate, setTaxRate] = useState("16");
  const [taxMethod, setTaxMethod] = useState<"inclusive" | "exclusive">("exclusive");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    let mounted = true;
    (async () => {
      if (!token) {
        if (mounted) setPhase("error");
        return;
      }
      try {
        const p = await fetchActivation(token);
        if (!mounted) return;
        setPreview(p);
        setName(p.business.contact.name ?? "");
        setEmail(p.business.contact.email ?? "");
        if (p.business.slug) setUsername(`${p.business.slug}_admin`);
        setPhase("form");
      } catch (err) {
        if (!mounted) return;
        setError(err instanceof ApiError ? err.message : t("activate.invalid_message"));
        setPhase("error");
      }
    })();
    return () => {
      mounted = false;
    };
  }, [token, t]);

  const validate = useCallback((): boolean => {
    const e: Record<string, string> = {};
    if (!name.trim()) e.name = t("activate.name_required");
    if (!email.trim()) e.email = t("activate.email_required");
    else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())) e.email = t("activate.email_invalid");
    if (!username.trim()) e.username = t("activate.username_required");
    else if (!usernamePattern.test(username.trim())) e.username = t("activate.username_invalid");
    if (!password) e.password = t("activate.password_required");
    else if (!strongPassword.test(password)) e.password = t("activate.password_weak");
    if (password !== passwordConfirmation) e.password_confirmation = t("activate.password_mismatch");
    const rate = Number(taxRate);
    if (taxEnabled && (Number.isNaN(rate) || rate < 0 || rate > 100)) {
      e.tax_rate = "0–100";
    }
    setErrors(e);
    return Object.keys(e).length === 0;
  }, [name, email, username, password, passwordConfirmation, taxRate, taxEnabled, t]);

  const handleSubmit = useCallback(async () => {
    if (submitting || !validate()) return;
    setSubmitting(true);
    setErrors({});
    try {
      const res = await activateStore({
        token,
        name: name.trim(),
        email: email.trim(),
        username: username.trim(),
        password,
        password_confirmation: passwordConfirmation,
        currency,
        tax_enabled: taxEnabled,
        default_tax_rate: taxEnabled ? Number(taxRate) : 0,
        tax_calculation_method: taxMethod,
      });
      setResult(res);
      setPhase("success");
    } catch (err) {
      if (err instanceof ApiError && err.errors) {
        const mapped: Record<string, string> = {};
        for (const [k, v] of Object.entries(err.errors)) {
          if (v?.length) mapped[k] = v[0];
        }
        setErrors(mapped);
      } else {
        setErrors({ _: err instanceof ApiError ? err.message : t("activate.failed") });
      }
    } finally {
      setSubmitting(false);
    }
  }, [submitting, validate, token, name, email, username, password, passwordConfirmation, currency, taxEnabled, taxRate, taxMethod, t]);

  const goToLogin = () => {
    if (result?.onboarding.domain) {
      router.push(storeLoginUrl(result.onboarding.domain));
    } else if (result?.onboarding.login_url) {
      window.location.href = result.onboarding.login_url;
    } else {
      router.push("/login");
    }
  };

  return (
    <div className="min-h-screen bg-gradient-to-b from-[#0A111E] via-[#0a0e18] to-[#0A111E] text-foreground flex items-center justify-center p-4">
      <motion.div
        className="relative w-full max-w-[460px]"
        initial={{ opacity: 0, y: 40, filter: "blur(12px)" }}
        animate={{ opacity: 1, y: 0, filter: "blur(0px)" }}
        transition={{ duration: 0.8, ease: [0.16, 1, 0.3, 1] }}
      >
        <motion.div
          className="flex flex-col items-center gap-3 mb-8"
          initial={{ opacity: 0, y: -20 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ delay: 0.15, ...spring }}
        >
          <Image
            src="/images/logobg.png"
            alt="SuperX Logo"
            width={160}
            height={160}
            className="w-24 h-24 object-contain rounded-2xl"
            priority
          />
          <h1 className="text-xl font-bold tracking-tight">{t("activate.title")}</h1>
          <p className="text-muted text-xs text-center max-w-[340px] leading-relaxed">
            {t("activate.subtitle")}
          </p>
        </motion.div>

        <div className="relative rounded-3xl p-7 shadow-2xl shadow-black/80 bg-card border border-border">
          {phase === "loading" && (
            <div className="py-12 flex flex-col items-center gap-4 text-center">
              <Loader2 className="w-8 h-8 animate-spin text-gold" />
              <p className="text-muted text-sm">{t("activate.loading")}</p>
            </div>
          )}

          {phase === "error" && (
            <div className="py-8 flex flex-col items-center gap-4 text-center">
              <div className="w-14 h-14 rounded-2xl bg-danger/10 border border-danger/20 flex items-center justify-center">
                <AlertCircle className="w-7 h-7 text-danger" />
              </div>
              <h2 className="text-lg font-semibold">{t("activate.invalid_title")}</h2>
              <p className="text-muted text-sm max-w-[320px]">{error ?? t("activate.invalid_message")}</p>
              <Link
                href="/"
                className="mt-2 text-xs text-gold hover:text-gold-light transition-colors"
              >
                {t("auth.back_home")}
              </Link>
            </div>
          )}

          {phase === "success" && result && (
            <div className="py-6 flex flex-col items-center gap-4 text-center">
              <CheckCircle2 className="w-14 h-14 text-emerald-400" />
              <h2 className="text-lg font-semibold">{t("activate.success_title")}</h2>
              <p className="text-muted text-sm max-w-[320px]">{t("activate.success_message")}</p>
              <div className="w-full rounded-2xl border border-border bg-card/60 p-4 space-y-2 text-start">
                <div className="flex items-center justify-between text-xs">
                  <span className="text-muted">{t("activate.your_username")}</span>
                  <span className="font-medium text-foreground" dir="ltr">
                    {result.onboarding.username}
                  </span>
                </div>
                {result.onboarding.domain && (
                  <div className="flex items-center justify-between text-xs">
                    <span className="text-muted">{t("activate.your_domain")}</span>
                    <span className="font-medium text-foreground" dir="ltr">
                      {result.onboarding.domain}
                    </span>
                  </div>
                )}
              </div>
              <button
                onClick={goToLogin}
                className="mt-2 w-full py-3.5 rounded-2xl bg-gradient-to-r from-[#1E4E8C] to-[#3A75C4] text-white text-sm font-semibold hover:opacity-90 transition-opacity inline-flex items-center justify-center gap-2"
              >
                {t("activate.go_to_login")}
                <ArrowRight className="w-4 h-4 rtl:rotate-180" />
              </button>
            </div>
          )}

          {phase === "form" && preview && (
            <div className="space-y-4" onKeyDown={(e) => e.key === "Enter" && handleSubmit()}>
              <div className="rounded-2xl border border-border bg-card/60 p-3.5 flex items-center gap-3">
                <div className="w-10 h-10 rounded-xl bg-[#1E4E8C]/15 flex items-center justify-center flex-shrink-0">
                  <Store className="w-5 h-5 text-[#3A75C4]" />
                </div>
                <div className="min-w-0">
                  <p className="text-sm font-semibold truncate">{preview.business.name}</p>
                  <p className="text-[11px] text-muted truncate" dir="ltr">
                    {preview.business.domain ?? preview.business.subdomain ?? ""}
                  </p>
                </div>
              </div>

              <Field label={t("activate.admin_name")} icon={User} error={errors.name}>
                <input
                  className={inputCls}
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  autoComplete="off"
                  autoFocus
                />
              </Field>

              <Field label={t("activate.email")} icon={Mail} error={errors.email}>
                <input
                  className={inputCls}
                  type="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  dir="ltr"
                  autoComplete="off"
                />
              </Field>

              <Field
                label={t("activate.username")}
                icon={AtSign}
                error={errors.username}
                hint={t("activate.username_hint", {
                  slug: preview.business.slug ? `${preview.business.slug}_admin` : "yourstore_admin",
                })}
              >
                <input
                  className={inputCls}
                  value={username}
                  onChange={(e) => setUsername(e.target.value)}
                  dir="ltr"
                  autoComplete="off"
                  maxLength={30}
                />
              </Field>

              <Field label={t("activate.password")} icon={Lock} error={errors.password} hint={t("activate.password_hint")}>
                <PasswordInput bare value={password} onChange={setPassword} autoComplete="new-password" />
              </Field>

              <Field label={t("activate.password_confirmation")} icon={Lock} error={errors.password_confirmation}>
                <PasswordInput bare value={passwordConfirmation} onChange={setPasswordConfirmation} autoComplete="new-password" />
              </Field>

              <div className="pt-2 border-t border-border/60">
                <p className="text-[11px] font-semibold text-gold tracking-wide mb-3">{t("activate.preferences")}</p>

                <Field label={t("activate.currency")} icon={Store} error={errors.currency}>
                  <select
                    className={`${inputCls} [&>option]:bg-[#0d1526]`}
                    value={currency}
                    onChange={(e) => setCurrency(e.target.value)}
                  >
                    {CURRENCIES.map((c) => (
                      <option key={c} value={c}>{c}</option>
                    ))}
                  </select>
                </Field>

                <label className="mt-3 flex items-center justify-between rounded-2xl border border-border bg-card px-4 py-3 cursor-pointer">
                  <span className="text-sm text-foreground">{t("activate.tax_enabled")}</span>
                  <button
                    type="button"
                    role="switch"
                    aria-checked={taxEnabled}
                    onClick={() => setTaxEnabled(!taxEnabled)}
                    className={`relative w-11 h-6 rounded-full transition-colors ${
                      taxEnabled ? "bg-emerald-500" : "bg-card-hover border border-border"
                    }`}
                    tabIndex={-1}
                  >
                    <span
                      className={`absolute top-0.5 w-5 h-5 rounded-full bg-white shadow transition-all ${
                        taxEnabled ? "start-[22px]" : "start-0.5"
                      }`}
                    />
                  </button>
                </label>

                {taxEnabled && (
                  <div className="mt-3 grid grid-cols-2 gap-3">
                    <div>
                      <label className="block text-[11px] font-medium text-muted tracking-wide mb-1.5">
                        {t("activate.tax_rate")}
                      </label>
                      <input
                        className={`${inputCls} rounded-2xl border border-border bg-card px-4 ${errors.tax_rate ? "border-danger" : ""}`}
                        type="number"
                        min={0}
                        max={100}
                        value={taxRate}
                        onChange={(e) => setTaxRate(e.target.value)}
                        dir="ltr"
                      />
                      {errors.tax_rate && (
                        <p className="text-[11px] text-danger mt-1 ps-1 flex items-center gap-1">
                          <AlertCircle className="w-3 h-3" /> {errors.tax_rate}
                        </p>
                      )}
                    </div>
                    <div>
                      <label className="block text-[11px] font-medium text-muted tracking-wide mb-1.5">
                        {t("activate.tax_method")}
                      </label>
                      <select
                        className={`${inputCls} rounded-2xl border border-border bg-card px-4 [&>option]:bg-[#0d1526]`}
                        value={taxMethod}
                        onChange={(e) => setTaxMethod(e.target.value as "inclusive" | "exclusive")}
                      >
                        <option value="exclusive">{t("activate.tax_exclusive")}</option>
                        <option value="inclusive">{t("activate.tax_inclusive")}</option>
                      </select>
                    </div>
                  </div>
                )}
              </div>

              {errors._ && (
                <div className="p-3 rounded-xl bg-danger/10 border border-danger/20 text-danger text-xs flex items-start gap-2">
                  <AlertCircle className="w-3.5 h-3.5 mt-0.5 flex-shrink-0" />
                  <span>{errors._}</span>
                </div>
              )}

              <button
                onClick={handleSubmit}
                disabled={submitting}
                className={`w-full py-4 rounded-2xl font-semibold text-sm transition-all duration-300 flex items-center justify-center gap-2.5 tracking-wide ${
                  submitting
                    ? "bg-card text-muted cursor-wait border border-border"
                    : "bg-gradient-to-r from-[#1E4E8C] to-[#3A75C4] text-white hover:from-[#3A75C4] hover:to-[#1E4E8C] shadow-lg shadow-[#1E4E8C]/20 hover:scale-[1.02] active:scale-[0.98]"
                }`}
              >
                {submitting ? (
                  <>
                    <Loader2 className="w-4 h-4 animate-spin" />
                    {t("activate.submitting")}
                  </>
                ) : (
                  <>
                    {t("activate.submit")}
                    <ArrowRight className="w-4 h-4 rtl:rotate-180" />
                  </>
                )}
              </button>
            </div>
          )}
        </div>
      </motion.div>
    </div>
  );
}