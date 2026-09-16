"use client";

import { useState, useCallback, useEffect } from "react";
import Image from "next/image";
import Link from "next/link";
import { motion } from "framer-motion";
import {
  fetchBusinessTypes,
  submitLead,
  ApiError,
  BusinessTypeItem,
} from "@/lib/api";
import { isValidPhone, normalizePhone } from "@/lib/phone";
import { useI18n } from "@/lib/i18n";
import {
  User,
  Building2,
  Phone,
  MapPin,
  Store,
  ArrowRight,
  Loader2,
  AlertCircle,
  CheckCircle2,
} from "lucide-react";

const spring = { type: "spring", stiffness: 400, damping: 30 } as const;

function Field({
  label,
  icon: Icon,
  error,
  hint,
  children,
}: {
  label: string;
  icon: React.ComponentType<{ className?: string }>;
  error?: string | null;
  hint?: string | null;
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

const inputCls =
  "w-full bg-transparent text-sm text-foreground outline-none py-3.5 pe-4 tracking-wide placeholder:text-muted/60";

export default function DemoRequestPage() {
  const { t, locale } = useI18n();

  const [types, setTypes] = useState<BusinessTypeItem[]>([]);
  const [name, setName] = useState("");
  const [businessName, setBusinessName] = useState("");
  const [phone, setPhone] = useState("");
  const [city, setCity] = useState("");
  const [businessTypeId, setBusinessTypeId] = useState<number | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);
  const [done, setDone] = useState(false);

  useEffect(() => {
    fetchBusinessTypes()
      .then(setTypes)
      .catch(() => {});
  }, []);

  const normalizedPreview = phone.trim() ? normalizePhone(phone) : null;

  const validate = useCallback((): boolean => {
    const e: Record<string, string> = {};
    if (!name.trim()) e.name = t("leads.name_required");
    if (!businessName.trim()) e.business_name = t("leads.business_name_required");
    if (!phone.trim()) e.phone = t("leads.phone_required");
    else if (!isValidPhone(phone)) e.phone = t("leads.phone_invalid");
    setErrors(e);
    return Object.keys(e).length === 0;
  }, [name, businessName, phone, t]);

  const handleSubmit = useCallback(async () => {
    if (submitting) return;
    if (!validate()) return;
    setSubmitting(true);
    try {
      await submitLead({
        name: name.trim(),
        business_name: businessName.trim(),
        business_type_id: businessTypeId,
        phone: normalizePhone(phone) ?? phone.trim(),
        city: city.trim() || null,
      });
      setDone(true);
    } catch (err: unknown) {
      if (err instanceof ApiError && err.errors) {
        const mapped: Record<string, string> = {};
        for (const [k, v] of Object.entries(err.errors)) {
          if (v?.length) mapped[k] = v[0];
        }
        setErrors(mapped);
      } else {
        setErrors({ _: err instanceof ApiError ? err.message : t("leads.failed") });
      }
    } finally {
      setSubmitting(false);
    }
  }, [submitting, validate, name, businessName, businessTypeId, phone, city, t]);

  return (
    <div className="min-h-screen bg-gradient-to-b from-[#0A111E] via-[#0a0e18] to-[#0A111E] text-foreground flex items-center justify-center p-4">
      <motion.div
        className="relative w-full max-w-[440px]"
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
          <h1 className="text-xl font-bold tracking-tight text-foreground">{t("leads.title")}</h1>
          <p className="text-muted text-xs text-center max-w-[320px] leading-relaxed">
            {t("leads.subtitle")}
          </p>
        </motion.div>

        <div className="relative rounded-3xl p-7 shadow-2xl shadow-black/80 bg-card border border-border">
          {done ? (
            <div className="py-6 flex flex-col items-center gap-4 text-center">
              <CheckCircle2 className="w-14 h-14 text-emerald-400" />
              <h2 className="text-lg font-semibold">{t("leads.success_title")}</h2>
              <p className="text-muted text-sm max-w-[300px]">{t("leads.success_message")}</p>
              <Link
                href="/"
                className="mt-2 inline-flex items-center gap-2 px-5 py-3 rounded-2xl bg-gradient-to-r from-[#1E4E8C] to-[#3A75C4] text-white text-sm font-semibold hover:opacity-90 transition-opacity"
              >
                {t("auth.back_home")}
                <ArrowRight className="w-4 h-4" />
              </Link>
            </div>
          ) : (
            <div className="space-y-4" onKeyDown={(e) => e.key === "Enter" && handleSubmit()}>
              <Field label={t("leads.contact_name")} icon={User} error={errors.name}>
                <input
                  className={inputCls}
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  autoComplete="off"
                  autoFocus
                />
              </Field>

              <Field label={t("leads.business_name_field")} icon={Building2} error={errors.business_name}>
                <input
                  className={inputCls}
                  value={businessName}
                  onChange={(e) => setBusinessName(e.target.value)}
                  autoComplete="off"
                />
              </Field>

              <Field label={t("leads.phone")} icon={Phone} error={errors.phone} hint={normalizedPreview ?? undefined}>
                <input
                  className={inputCls}
                  value={phone}
                  onChange={(e) => setPhone(e.target.value)}
                  dir="ltr"
                  inputMode="tel"
                  autoComplete="off"
                />
              </Field>

              <Field label={`${t("leads.city")} (${t("common.optional")})`} icon={MapPin} error={errors.city}>
                <input
                  className={inputCls}
                  value={city}
                  onChange={(e) => setCity(e.target.value)}
                  autoComplete="off"
                />
              </Field>

              <Field label={`${t("leads.business_type")} (${t("common.optional")})`} icon={Store} error={errors.business_type_id}>
                <select
                  className={`${inputCls} [&>option]:bg-[#0d1526]`}
                  value={businessTypeId ?? ""}
                  onChange={(e) =>
                    setBusinessTypeId(e.target.value ? Number(e.target.value) : null)
                  }
                >
                  <option value="">{t("leads.select_business_type")}</option>
                  {types.map((bt) => (
                    <option key={bt.id} value={bt.id}>
                      {locale === "ar" ? bt.name_ar || bt.name_en : bt.name_en}
                    </option>
                  ))}
                </select>
              </Field>

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
                    {t("leads.submitting")}
                  </>
                ) : (
                  <>
                    {t("leads.submit")}
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
