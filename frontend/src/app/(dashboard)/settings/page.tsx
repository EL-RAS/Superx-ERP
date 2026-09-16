"use client";

import { useCallback, useEffect, useState } from "react";
import { motion } from "framer-motion";
import { toast } from "sonner";
import { useAuthStore } from "@/stores/auth-store";
import { useI18n, businessTypeLabel } from "@/lib/i18n";
import { fetchBusinessSettings, updateBusinessSettings } from "@/lib/api";
import { mapFieldErrors } from "@/lib/validation";
import { isSupermarketVertical } from "@/lib/morphing-engine";
import { DocumentNumbers } from "@/lib/types";
import PageHeader from "@/components/ui/PageHeader";
import PasswordInput from "@/components/ui/PasswordInput";
import Switch from "@/components/ui/Switch";
import StoreLogoUploader from "@/components/core/StoreLogoUploader";
import { Building2, ShoppingCart, FileText, Loader2, AlertCircle, Receipt, Shield, Users, Printer, PackageSearch } from "lucide-react";

const ERROR_FIELDS = [
  "business_name",
  "admin_name",
  "admin_email",
  "sales_invoice_prefix",
  "purchase_order_prefix",
  "grn_prefix",
  "invoice_footer_terms",
  "default_tax_rate",
  "tax_calculation_method",
  "loyalty_earn_rate",
  "loyalty_redemption_rate",
  "loyalty_alert_threshold",
  "jofotara_client_id",
  "jofotara_secret_key",
  "tax_number",
  "phone",
  "address",
  "receipt_footer_message",
  "scale_barcode_prefix",
  "expiry_warning_days",
] as const;

const numberBase = (full: string): string => {
  const m = full.match(/(\d+)\s*$/);
  return m ? m[1] : "1";
};

export default function SettingsPage() {
  const { t } = useI18n();
  const { user, business, config, setConfig, setUser, setBusiness, token } = useAuthStore();

  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const [form, setForm] = useState({ business_name: "", admin_name: "", admin_email: "" });
  const [split, setSplit] = useState(false);
  const [credit, setCredit] = useState(false);
  const [loyalty, setLoyalty] = useState(false);
  const [promotions, setPromotions] = useState(false);
  const [rapid, setRapid] = useState(false);
  const [salesInvoicePrefix, setSalesInvoicePrefix] = useState("INV-");
  const [purchaseOrderPrefix, setPurchaseOrderPrefix] = useState("PO-");
  const [grnPrefix, setGrnPrefix] = useState("GRN-");
  const [invoiceFooterTerms, setInvoiceFooterTerms] = useState("");
  const [taxEnabled, setTaxEnabled] = useState(true);
  const [defaultTaxRate, setDefaultTaxRate] = useState("16");
  const [taxCalcMethod, setTaxCalcMethod] = useState<"inclusive" | "exclusive">("inclusive");
  const [jofotaraEnabled, setJofotaraEnabled] = useState(false);
  const [jofotaraClientId, setJofotaraClientId] = useState("");
  const [jofotaraSecretKey, setJofotaraSecretKey] = useState("");
  const [loyaltyEarnRate, setLoyaltyEarnRate] = useState("1");
  const [loyaltyRedemptionRate, setLoyaltyRedemptionRate] = useState("100");
  const [loyaltyAlertThreshold, setLoyaltyAlertThreshold] = useState("500");
  const [taxNumber, setTaxNumber] = useState("");
  const [phone, setPhone] = useState("");
  const [address, setAddress] = useState("");
  const [autoPrintReceipt, setAutoPrintReceipt] = useState(false);
  const [receiptPaperWidth, setReceiptPaperWidth] = useState<"80mm" | "58mm">("80mm");
  const [receiptFooterMessage, setReceiptFooterMessage] = useState("");
  const [allowNegativeStock, setAllowNegativeStock] = useState(false);
  const [scaleBarcodeParsing, setScaleBarcodeParsing] = useState(false);
  const [scaleBarcodePrefix, setScaleBarcodePrefix] = useState("20");
  const [expiryWarningDays, setExpiryWarningDays] = useState("30");
  const [nextNumbers, setNextNumbers] = useState<DocumentNumbers>({
    sales_invoice: "INV-1",
    purchase_order: "PO-1",
    grn: "GRN-1",
  });

  // Pay-later / credit sales are not offered to supermarket cashiers.
  const isSupermarket = isSupermarketVertical(business?.business_type?.slug);

  useEffect(() => {
    if (!token || !business) return;
    fetchBusinessSettings(token, business.id)
      .then((res) => {
        const s = res.settings;
        setForm({
          business_name: business?.name ?? "",
          admin_name: user?.name ?? "",
          admin_email: user?.email ?? "",
        });
        setSplit(s.allow_split_payments === true);
        setCredit(s.allow_credit_sales === true);
        setLoyalty(s.loyalty_enabled === true);
        setPromotions(s.promotions_enabled === true);
        setRapid(s.rapid_mode === true);
        setSalesInvoicePrefix((s.sales_invoice_prefix as string) || "INV-");
        setPurchaseOrderPrefix((s.purchase_order_prefix as string) || "PO-");
        setGrnPrefix((s.grn_prefix as string) || "GRN-");
        setInvoiceFooterTerms((s.invoice_footer_terms as string) || "");
        setTaxEnabled(s.tax_enabled !== false);
        setDefaultTaxRate(String(s.default_tax_rate ?? "16"));
        setTaxCalcMethod((s.tax_calculation_method as "inclusive" | "exclusive") || "inclusive");
        setJofotaraEnabled(s.jofotara_enabled === true);
        setJofotaraClientId((s.jofotara_client_id as string) || "");
        setJofotaraSecretKey((s.jofotara_secret_key as string) || "");
        setLoyaltyEarnRate(String(s.loyalty_earn_rate ?? "1"));
        setLoyaltyRedemptionRate(String(s.loyalty_redemption_rate ?? "100"));
        setLoyaltyAlertThreshold(String(s.loyalty_alert_threshold ?? "500"));
        setTaxNumber((s.tax_number as string) || "");
        setPhone((s.phone as string) || "");
        setAddress((s.address as string) || "");
        setAutoPrintReceipt(s.auto_print_receipt === true);
        setReceiptPaperWidth((s.receipt_paper_width as "80mm" | "58mm") || "80mm");
        setReceiptFooterMessage((s.receipt_footer_message as string) || "");
        setAllowNegativeStock(s.allow_negative_stock === true);
        setScaleBarcodeParsing(s.scale_barcode_parsing === true);
        setScaleBarcodePrefix((s.scale_barcode_prefix as string) || "20");
        setExpiryWarningDays(String(s.expiry_warning_days ?? "30"));
        setNextNumbers(res.next_numbers);
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [token, business, user?.name, user?.email]);

  const setField = (key: string, value: string) => {
    setForm((p) => ({ ...p, [key]: value }));
    setErrors((p) => ({ ...p, [key]: "" }));
  };

  const handleSave = async () => {
    if (!business || !token) return;

    const clientErrors: Record<string, string> = {};
    if (!form.business_name.trim()) clientErrors.business_name = t("settings.business_name_required");
    if (!form.admin_name.trim()) clientErrors.admin_name = t("settings.admin_name_required");
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.admin_email.trim())) clientErrors.admin_email = t("settings.admin_email_invalid");
    if (!salesInvoicePrefix.trim()) clientErrors.sales_invoice_prefix = t("settings.prefix_required");
    if (!purchaseOrderPrefix.trim()) clientErrors.purchase_order_prefix = t("settings.prefix_required");
    if (!grnPrefix.trim()) clientErrors.grn_prefix = t("settings.prefix_required");
    const rate = Number(defaultTaxRate);
    if (defaultTaxRate.trim() === "" || Number.isNaN(rate) || rate < 0 || rate > 100) {
      clientErrors.default_tax_rate = t("settings.tax_rate_range");
    }
    for (const [key, value, min, max] of [
      ["loyalty_earn_rate", loyaltyEarnRate, 1, 100],
      ["loyalty_redemption_rate", loyaltyRedemptionRate, 1, 1000],
      ["loyalty_alert_threshold", loyaltyAlertThreshold, 0, 10000],
    ] as const) {
      const n = Number(value);
      if (value.trim() === "" || Number.isNaN(n) || n < min || n > max) {
        clientErrors[key] = t("settings.loyalty_rate_range");
      }
    }
    if (scaleBarcodeParsing && !/^\d{1,5}$/.test(scaleBarcodePrefix.trim())) {
      clientErrors.scale_barcode_prefix = t("settings.scale_prefix_invalid");
    }
    const expiryDays = Number(expiryWarningDays);
    if (expiryWarningDays.trim() === "" || Number.isNaN(expiryDays) || expiryDays < 1 || expiryDays > 365) {
      clientErrors.expiry_warning_days = t("settings.expiry_days_range");
    }
    if (Object.keys(clientErrors).length > 0) {
      setErrors(clientErrors);
      toast.error(clientErrors[Object.keys(clientErrors)[0]]);
      return;
    }

    setSaving(true);
    setErrors({});
    try {
      const updated = await updateBusinessSettings(token, business.id, {
        business_name: form.business_name,
        admin_name: form.admin_name,
        admin_email: form.admin_email,
        allow_split_payments: split,
        allow_credit_sales: isSupermarket ? false : credit,
        loyalty_enabled: loyalty,
        promotions_enabled: promotions,
        rapid_mode: rapid,
        sales_invoice_prefix: salesInvoicePrefix,
        purchase_order_prefix: purchaseOrderPrefix,
        grn_prefix: grnPrefix,
        invoice_footer_terms: invoiceFooterTerms,
        tax_enabled: taxEnabled,
        default_tax_rate: Number(defaultTaxRate),
        tax_calculation_method: taxCalcMethod,
        jofotara_enabled: jofotaraEnabled,
        jofotara_client_id: jofotaraClientId || null,
        jofotara_secret_key: jofotaraSecretKey || null,
        loyalty_earn_rate: Number(loyaltyEarnRate),
        loyalty_redemption_rate: Number(loyaltyRedemptionRate),
        loyalty_alert_threshold: Number(loyaltyAlertThreshold),
        tax_number: taxNumber || null,
        phone: phone || null,
        address: address || null,
        auto_print_receipt: autoPrintReceipt,
        receipt_paper_width: receiptPaperWidth,
        receipt_footer_message: receiptFooterMessage || null,
        allow_negative_stock: allowNegativeStock,
        scale_barcode_parsing: scaleBarcodeParsing,
        scale_barcode_prefix: scaleBarcodeParsing ? scaleBarcodePrefix.trim() || null : null,
        expiry_warning_days: Number(expiryWarningDays),
      });
      if (config) setConfig({ ...config, settings: updated.settings, business_name: updated.business_name });
      if (user) setUser({ ...user, name: updated.user.name, email: updated.user.email });
      setBusiness({ ...business, name: updated.business_name });
      setNextNumbers(updated.next_numbers);
      setSaved(true);
      toast.success(t("common.saved"));
      setTimeout(() => setSaved(false), 2000);
    } catch (err) {
      setErrors(mapFieldErrors(err, [...ERROR_FIELDS]));
      setSaved(false);
      toast.error(err instanceof Error ? err.message : t("common.error"));
    } finally {
      setSaving(false);
    }
  };

  const inputClass = (error?: string) =>
    `w-full px-4 py-2.5 bg-card/80 border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-primary/50 transition-colors ${error ? "border-red-500/50" : "border-border"}`;

  const fieldError = (key: string) => errors[key] && (
    <p className="flex items-center gap-1.5 text-xs text-red-400 mt-1.5">
      <AlertCircle className="w-3 h-3" /> {errors[key]}
    </p>
  );

  const nextPreview = useCallback(
    (prefix: string, full: string) => `${prefix || ""}${numberBase(full)}`,
    []
  );

  return (
    <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="space-y-6">
      <PageHeader title={t("settings.title")} subtitle={t("settings.subtitle")} />

      {/* ── Business Information ── */}
      <div className="glass rounded-2xl p-6 space-y-4">
        <div className="flex items-center gap-2">
          <Building2 className="w-5 h-5 text-primary-light" />
          <h3 className="text-lg font-medium text-foreground">{t("settings.business_info")}</h3>
        </div>
        <div className="flex flex-col sm:flex-row items-start sm:items-center gap-4">
          <StoreLogoUploader size={72} />
          <div>
            <p className="text-sm font-medium text-foreground">{t("settings.logo")}</p>
            <p className="text-xs text-muted mt-0.5">{t("settings.logo_desc")}</p>
          </div>
        </div>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label className="text-xs text-muted uppercase tracking-wider block mb-1.5">{t("settings.business_name")}</label>
            <input value={form.business_name} onChange={(e) => setField("business_name", e.target.value)}
              autoComplete="off" className={inputClass(errors.business_name)} />
            {fieldError("business_name")}
          </div>
          <div>
            <label className="text-xs text-muted uppercase tracking-wider block mb-1.5">{t("settings.business_type")}</label>
            <p className="w-full px-4 py-2.5 bg-card/60 border border-border rounded-xl text-sm text-muted">{businessTypeLabel(t, business?.business_type?.slug)}</p>
          </div>
          <div>
            <label className="text-xs text-muted uppercase tracking-wider block mb-1.5">{t("settings.admin_name")}</label>
            <input value={form.admin_name} onChange={(e) => setField("admin_name", e.target.value)}
              autoComplete="off" className={inputClass(errors.admin_name)} />
            {fieldError("admin_name")}
          </div>
          <div>
            <label className="text-xs text-muted uppercase tracking-wider block mb-1.5">{t("settings.admin_email")}</label>
            <input type="email" value={form.admin_email} onChange={(e) => setField("admin_email", e.target.value)}
              autoComplete="off" className={inputClass(errors.admin_email)} />
            {fieldError("admin_email")}
          </div>
          <div>
            <label className="text-xs text-muted uppercase tracking-wider block mb-1.5">{t("settings.currency")}</label>
            <p className="w-full px-4 py-2.5 bg-card/60 border border-border rounded-xl text-sm text-muted">{t("settings.currency_display")}</p>
          </div>
          <div>
            <label className="text-xs text-muted uppercase tracking-wider block mb-1.5">{t("settings.phone")}</label>
            <input dir="ltr" value={phone} onChange={(e) => { setPhone(e.target.value); setErrors((p) => ({ ...p, phone: "" })); }}
              autoComplete="off" className={inputClass(errors.phone)} />
            {fieldError("phone")}
          </div>
        </div>
        <div>
          <label className="text-xs text-muted uppercase tracking-wider block mb-1.5">{t("settings.address")}</label>
          <input value={address} onChange={(e) => { setAddress(e.target.value); setErrors((p) => ({ ...p, address: "" })); }}
            autoComplete="off" className={inputClass(errors.address)} />
          {fieldError("address")}
        </div>
      </div>

      {/* ── POS Settings ── */}
      <div className="glass rounded-2xl p-6 space-y-4">
        <div className="flex items-center gap-2">
          <ShoppingCart className="w-5 h-5 text-primary-light" />
          <h3 className="text-lg font-medium text-foreground">{t("settings.pos_settings")}</h3>
        </div>
        <div className="space-y-4">
          <label className="flex items-center justify-between p-4 bg-card/60 border border-border rounded-xl cursor-pointer hover:border-border-hover transition-colors">
            <div>
              <p className="text-sm font-medium text-foreground">{t("settings.allow_split_credit")}</p>
              <p className="text-xs text-muted mt-0.5">{t("settings.allow_split_credit_desc")}</p>
            </div>
            <Switch checked={split} onChange={setSplit} aria-label={t("settings.allow_split_credit")} />
          </label>
          {!isSupermarket && (
          <label className="flex items-center justify-between p-4 bg-card/60 border border-border rounded-xl cursor-pointer hover:border-border-hover transition-colors">
              <div>
                <p className="text-sm font-medium text-foreground">{t("settings.allow_credit_sales")}</p>
                <p className="text-xs text-muted mt-0.5">{t("settings.allow_credit_sales_desc")}</p>
              </div>
<Switch checked={credit} onChange={setCredit} aria-label={t("settings.allow_credit_sales")} />
            </label>
          )}
          <label className="flex items-center justify-between p-4 bg-card/60 border border-border rounded-xl cursor-pointer hover:border-border-hover transition-colors">
            <div>
              <p className="text-sm font-medium text-foreground">{t("settings.promotions_enabled")}</p>
              <p className="text-xs text-muted mt-0.5">{t("settings.promotions_enabled_desc")}</p>
            </div>
            <Switch checked={promotions} onChange={setPromotions} aria-label={t("settings.promotions_enabled")} />
          </label>
          <label className="flex items-center justify-between p-4 bg-card/60 border border-border rounded-xl cursor-pointer hover:border-border-hover transition-colors">
            <div>
              <p className="text-sm font-medium text-foreground">{t("settings.rapid_mode")}</p>
              <p className="text-xs text-muted mt-0.5">{t("settings.rapid_mode_desc")}</p>
            </div>
            <Switch checked={rapid} onChange={setRapid} aria-label={t("settings.rapid_mode")} />
          </label>
        </div>
      </div>

      {/* ── CRM / Loyalty Settings (hidden when the CRM module is disabled) ── */}
      {config?.features?.crm_enabled !== false && (
      <div className="glass rounded-2xl p-6 space-y-4">
        <div className="flex items-center gap-2">
          <Users className="w-5 h-5 text-primary-light" />
          <h3 className="text-lg font-medium text-foreground">{t("settings.crm_loyalty") || "CRM / Loyalty"}</h3>
        </div>
        <div className="space-y-4">
          <label className="flex items-center justify-between p-4 bg-card/60 border border-border rounded-xl cursor-pointer hover:border-border-hover transition-colors">
            <div>
              <p className="text-sm font-medium text-foreground">{t("settings.loyalty_enabled")}</p>
              <p className="text-xs text-muted mt-0.5">{t("settings.loyalty_enabled_desc")}</p>
            </div>
            <Switch checked={loyalty} onChange={setLoyalty} aria-label={t("settings.loyalty_enabled")} />
          </label>
          {loyalty && (
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4 p-4 bg-card/40 border border-border rounded-xl">
              <div>
                <label className="text-xs text-muted uppercase tracking-wider block mb-1.5">{t("settings.loyalty_earn_rate")}</label>
                <div className="relative">
                  <input type="number" min="1" max="100" value={loyaltyEarnRate}
                    onChange={(e) => setLoyaltyEarnRate(e.target.value)}
                    className={`${inputClass()} pe-12`} />
                  <span className="absolute end-3 top-1/2 -translate-y-1/2 text-muted text-xs">{t("settings.loyalty_per_jod")}</span>
                </div>
              </div>
              <div>
                <label className="text-xs text-muted uppercase tracking-wider block mb-1.5">{t("settings.loyalty_redemption_rate")}</label>
                <div className="relative">
                  <input type="number" min="1" max="1000" value={loyaltyRedemptionRate}
                    onChange={(e) => setLoyaltyRedemptionRate(e.target.value)}
                    className={`${inputClass()} pe-12`} />
                  <span className="absolute end-3 top-1/2 -translate-y-1/2 text-muted text-xs">{t("settings.loyalty_points")}</span>
                </div>
              </div>
              <div>
                <label className="text-xs text-muted uppercase tracking-wider block mb-1.5">{t("settings.loyalty_alert_threshold")}</label>
                <div className="relative">
                  <input type="number" min="0" max="10000" value={loyaltyAlertThreshold}
                    onChange={(e) => setLoyaltyAlertThreshold(e.target.value)}
                    className={`${inputClass()} pe-12`} />
                  <span className="absolute end-3 top-1/2 -translate-y-1/2 text-muted text-xs">{t("settings.loyalty_points")}</span>
                </div>
              </div>
            </div>
          )}
        </div>
      </div>
      )}

      {/* ── Tax & E-Invoicing ── */}
      <div className="glass rounded-2xl p-6 space-y-4">
        <div className="flex items-center gap-2">
          <Receipt className="w-5 h-5 text-primary-light" />
          <h3 className="text-lg font-medium text-foreground">{t("settings.tax_einvoicing")}</h3>
        </div>
        <div className="space-y-4">
          <label className="flex items-center justify-between p-4 bg-card/60 border border-border rounded-xl cursor-pointer hover:border-border-hover transition-colors">
            <div>
              <p className="text-sm font-medium text-foreground">{t("settings.tax_enabled")}</p>
              <p className="text-xs text-muted mt-0.5">{t("settings.tax_enabled_desc")}</p>
            </div>
            <Switch checked={taxEnabled} onChange={setTaxEnabled} aria-label={t("settings.tax_enabled")} />
          </label>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="text-xs text-muted uppercase tracking-wider block mb-1.5">{t("settings.default_tax_rate")}</label>
              <div className="relative">
                <input type="number" step="0.01" min="0" max="100" value={defaultTaxRate}
                  onChange={(e) => setDefaultTaxRate(e.target.value)}
                  className={`${inputClass()} pe-8`} />
                <span className="absolute end-3 top-1/2 -translate-y-1/2 text-muted text-sm">%</span>
              </div>
            </div>
            <div>
              <label className="text-xs text-muted uppercase tracking-wider block mb-1.5">{t("settings.tax_calc_method")}</label>
              <select value={taxCalcMethod} onChange={(e) => setTaxCalcMethod(e.target.value as "inclusive" | "exclusive")}
                className={inputClass()}>
                <option value="inclusive">{t("settings.tax_inclusive")}</option>
                <option value="exclusive">{t("settings.tax_exclusive")}</option>
              </select>
            </div>
          </div>
        </div>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label className="text-xs text-muted uppercase tracking-wider block mb-1.5">{t("settings.tax_number")}</label>
            <input dir="ltr" value={taxNumber} onChange={(e) => { setTaxNumber(e.target.value); setErrors((p) => ({ ...p, tax_number: "" })); }}
              placeholder="TIN" autoComplete="off" className={inputClass(errors.tax_number)} />
            {fieldError("tax_number")}
          </div>
        </div>
        <div className="border-t border-border pt-4 space-y-4">
          <div className="flex items-center gap-2">
            <Shield className="w-4 h-4 text-primary-light" />
            <p className="text-sm font-medium text-foreground">{t("settings.jofotara")}</p>
          </div>
          <label className="flex items-center justify-between p-4 bg-card/60 border border-border rounded-xl cursor-pointer hover:border-border-hover transition-colors">
            <div>
              <p className="text-sm font-medium text-foreground">{t("settings.jofotara_enabled")}</p>
              <p className="text-xs text-muted mt-0.5">{t("settings.jofotara_enabled_desc")}</p>
            </div>
            <Switch checked={jofotaraEnabled} onChange={setJofotaraEnabled} aria-label={t("settings.jofotara_enabled")} />
          </label>
          {jofotaraEnabled && (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label className="text-xs text-muted uppercase tracking-wider block mb-1.5">{t("settings.jofotara_client_id")}</label>
                <input value={jofotaraClientId} onChange={(e) => setJofotaraClientId(e.target.value)}
                  placeholder="Client ID" autoComplete="off" className={inputClass()} />
              </div>
              <div>
                <label className="text-xs text-muted uppercase tracking-wider block mb-1.5">{t("settings.jofotara_secret_key")}</label>
                <PasswordInput value={jofotaraSecretKey} onChange={setJofotaraSecretKey}
                  placeholder="Secret Key" autoComplete="off" />
              </div>
            </div>
          )}
        </div>
      </div>

      {/* ── Document Prefixes & Terms ── */}
      <div className="glass rounded-2xl p-6 space-y-4">
        <div className="flex items-center gap-2">
          <FileText className="w-5 h-5 text-primary-light" />
          <h3 className="text-lg font-medium text-foreground">{t("settings.documents")}</h3>
        </div>
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div>
            <label className="text-xs text-muted uppercase tracking-wider block mb-1.5">{t("settings.sales_invoice_prefix")}</label>
            <input value={salesInvoicePrefix} onChange={(e) => setSalesInvoicePrefix(e.target.value)}
              autoComplete="off" className={inputClass()} />
            <p className="text-xs text-muted mt-1.5">{t("settings.next_number")} <span className="text-foreground font-mono">{nextPreview(salesInvoicePrefix, nextNumbers.sales_invoice)}</span></p>
          </div>
          <div>
            <label className="text-xs text-muted uppercase tracking-wider block mb-1.5">{t("settings.purchase_order_prefix")}</label>
            <input value={purchaseOrderPrefix} onChange={(e) => setPurchaseOrderPrefix(e.target.value)}
              autoComplete="off" className={inputClass()} />
            <p className="text-xs text-muted mt-1.5">{t("settings.next_number")} <span className="text-foreground font-mono">{nextPreview(purchaseOrderPrefix, nextNumbers.purchase_order)}</span></p>
          </div>
          <div>
            <label className="text-xs text-muted uppercase tracking-wider block mb-1.5">{t("settings.grn_prefix")}</label>
            <input value={grnPrefix} onChange={(e) => setGrnPrefix(e.target.value)}
              autoComplete="off" className={inputClass()} />
            <p className="text-xs text-muted mt-1.5">{t("settings.next_number")} <span className="text-foreground font-mono">{nextPreview(grnPrefix, nextNumbers.grn)}</span></p>
          </div>
        </div>
        <div>
          <label className="text-xs text-muted uppercase tracking-wider block mb-1.5">{t("settings.default_terms")}</label>
          <textarea value={invoiceFooterTerms} onChange={(e) => setInvoiceFooterTerms(e.target.value)}
            rows={4} placeholder={t("settings.default_terms_placeholder")}
            className={inputClass()} />
          <p className="text-xs text-muted mt-1.5">{t("settings.default_terms_desc")}</p>
        </div>
      </div>

      {/* ── Thermal Receipt & Printer ── */}
      <div className="glass rounded-2xl p-6 space-y-4">
        <div className="flex items-center gap-2">
          <Printer className="w-5 h-5 text-primary-light" />
          <h3 className="text-lg font-medium text-foreground">{t("settings.receipt_printer")}</h3>
        </div>
        <div className="space-y-4">
          <label className="flex items-center justify-between p-4 bg-card/60 border border-border rounded-xl cursor-pointer hover:border-border-hover transition-colors">
            <div>
              <p className="text-sm font-medium text-foreground">{t("settings.auto_print_receipt")}</p>
              <p className="text-xs text-muted mt-0.5">{t("settings.auto_print_receipt_desc")}</p>
            </div>
            <Switch checked={autoPrintReceipt} onChange={setAutoPrintReceipt} aria-label={t("settings.auto_print_receipt")} />
          </label>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="text-xs text-muted uppercase tracking-wider block mb-1.5">{t("settings.receipt_paper_width")}</label>
              <select value={receiptPaperWidth} onChange={(e) => setReceiptPaperWidth(e.target.value as "80mm" | "58mm")}
                className={inputClass()}>
                <option value="80mm">80 mm</option>
                <option value="58mm">58 mm</option>
              </select>
            </div>
          </div>
          <div>
            <label className="text-xs text-muted uppercase tracking-wider block mb-1.5">{t("settings.receipt_footer_message")}</label>
            <textarea value={receiptFooterMessage} onChange={(e) => { setReceiptFooterMessage(e.target.value); setErrors((p) => ({ ...p, receipt_footer_message: "" })); }}
              rows={3} placeholder={t("settings.receipt_footer_placeholder")} className={inputClass(errors.receipt_footer_message)} />
            <p className="text-xs text-muted mt-1.5">{t("settings.receipt_footer_desc")}</p>
            {fieldError("receipt_footer_message")}
          </div>
        </div>
      </div>

      {/* ── Inventory & POS Control ── */}
      <div className="glass rounded-2xl p-6 space-y-4">
        <div className="flex items-center gap-2">
          <PackageSearch className="w-5 h-5 text-primary-light" />
          <h3 className="text-lg font-medium text-foreground">{t("settings.inventory_pos")}</h3>
        </div>
        <div className="space-y-4">
          <label className="flex items-center justify-between p-4 bg-card/60 border border-border rounded-xl cursor-pointer hover:border-border-hover transition-colors">
            <div>
              <p className="text-sm font-medium text-foreground">{t("settings.allow_negative_stock")}</p>
              <p className="text-xs text-muted mt-0.5">{t("settings.allow_negative_stock_desc")}</p>
            </div>
            <Switch checked={allowNegativeStock} onChange={setAllowNegativeStock} aria-label={t("settings.allow_negative_stock")} />
          </label>
          <label className="flex items-center justify-between p-4 bg-card/60 border border-border rounded-xl cursor-pointer hover:border-border-hover transition-colors">
            <div>
              <p className="text-sm font-medium text-foreground">{t("settings.scale_barcode_parsing")}</p>
              <p className="text-xs text-muted mt-0.5">{t("settings.scale_barcode_parsing_desc")}</p>
            </div>
            <Switch checked={scaleBarcodeParsing} onChange={setScaleBarcodeParsing} aria-label={t("settings.scale_barcode_parsing")} />
          </label>
          {scaleBarcodeParsing && (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 p-4 bg-card/40 border border-border rounded-xl">
              <div>
                <label className="text-xs text-muted uppercase tracking-wider block mb-1.5">{t("settings.scale_barcode_prefix")}</label>
                <input dir="ltr" value={scaleBarcodePrefix} onChange={(e) => { setScaleBarcodePrefix(e.target.value); setErrors((p) => ({ ...p, scale_barcode_prefix: "" })); }}
                  autoComplete="off" className={inputClass(errors.scale_barcode_prefix)} />
                <p className="text-xs text-muted mt-1.5">{t("settings.scale_barcode_prefix_desc")}</p>
                {fieldError("scale_barcode_prefix")}
              </div>
            </div>
          )}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="text-xs text-muted uppercase tracking-wider block mb-1.5">{t("settings.expiry_warning_days")}</label>
              <div className="relative">
                <input type="number" min="1" max="365" value={expiryWarningDays}
                  onChange={(e) => { setExpiryWarningDays(e.target.value); setErrors((p) => ({ ...p, expiry_warning_days: "" })); }}
                  className={`${inputClass(errors.expiry_warning_days)} pe-8`} />
                <span className="absolute end-3 top-1/2 -translate-y-1/2 text-muted text-sm">{t("common.days")}</span>
              </div>
              <p className="text-xs text-muted mt-1.5">{t("settings.expiry_warning_days_desc")}</p>
              {fieldError("expiry_warning_days")}
            </div>
          </div>
        </div>
      </div>

      {/* ── Save Bar ── */}
      <div className="flex items-center gap-3">
        <button onClick={handleSave} disabled={saving || loading}
          className="flex items-center gap-2 px-5 py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors disabled:opacity-40">
          {saving ? <Loader2 className="w-4 h-4 animate-spin" /> : null}
          {saving ? t("common.saving") : t("common.save")}
        </button>
        {saved && <span className="text-xs text-emerald-400">{t("common.saved")}</span>}
      </div>
    </motion.div>
  );
}
