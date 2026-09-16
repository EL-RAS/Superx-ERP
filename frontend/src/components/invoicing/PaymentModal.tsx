"use client";

import { useState } from "react";
import { motion, AnimatePresence } from "framer-motion";
import { useI18n } from "@/lib/i18n";
import { X, Banknote, CreditCard, ArrowRightLeft, Check, Wallet } from "lucide-react";

type PaymentMethod = "cash" | "card" | "bank_transfer" | "check" | "mobile";

interface PaymentModalProps {
  open: boolean;
  onClose: () => void;
  onPay: (data: { amount: number; method: PaymentMethod; reference_number?: string; notes?: string }) => Promise<void>;
  total: number;
  currency?: string;
  loading?: boolean;
}

const methods: { key: PaymentMethod; icon: typeof Banknote; color: string }[] = [
  { key: "cash", icon: Banknote, color: "emerald" },
  { key: "card", icon: CreditCard, color: "blue" },
  { key: "bank_transfer", icon: ArrowRightLeft, color: "violet" },
  { key: "check", icon: Wallet, color: "amber" },
  { key: "mobile", icon: Check, color: "cyan" },
];

export default function PaymentModal({ open, onClose, onPay, total, currency = "JOD", loading = false }: PaymentModalProps) {
  const { t, locale } = useI18n();
  const [method, setMethod] = useState<PaymentMethod>("cash");
  const [amount, setAmount] = useState(String(total.toFixed(3)));
  const [reference, setReference] = useState("");
  const [notes, setNotes] = useState("");

  const handleSubmit = async () => {
    const amt = parseFloat(amount);
    if (isNaN(amt) || amt <= 0) return;
    await onPay({
      amount: amt,
      method,
      ...(reference ? { reference_number: reference } : {}),
      ...(notes ? { notes } : {}),
    });
  };

  const colorMap: Record<string, string> = {
    emerald: "bg-emerald-500/20 hover:bg-emerald-500/30 text-emerald-400 border-emerald-500/30",
    blue: "bg-blue-500/20 hover:bg-blue-500/30 text-blue-400 border-blue-500/30",
    violet: "bg-primary/20 hover:bg-primary/30 text-primary-light border-primary/30",
    amber: "bg-amber-500/20 hover:bg-amber-500/30 text-amber-400 border-amber-500/30",
    cyan: "bg-cyan-500/20 hover:bg-cyan-500/30 text-cyan-400 border-cyan-500/30",
  };

  return (
    <AnimatePresence>
      {open && (
        <>
          <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} onClick={() => !loading && onClose()} className="fixed inset-0 bg-black/60 backdrop-blur-sm z-50" />
          <div className="fixed inset-0 flex items-center justify-center z-50 p-4">
            <motion.div initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }} exit={{ opacity: 0, scale: 0.95 }} className="glass rounded-2xl p-6 w-full max-w-md">
              <div className="flex items-center justify-between mb-5">
                <h3 className="text-lg font-semibold text-foreground">{t("invoices.record_payment")}</h3>
                <button onClick={onClose} className="p-1.5 text-muted hover:text-foreground rounded-lg transition-colors"><X className="w-5 h-5" /></button>
              </div>

              <div className="mb-5">
                <p className="text-sm text-muted mb-1">{t("invoices.total")}</p>
                <p className="text-2xl font-bold text-foreground">{parseFloat(total.toFixed(3))} {currency}</p>
              </div>

              <div className="mb-4">
                <label className="text-sm text-muted mb-2 block">{t("invoices.payment_method")}</label>
                <div className="grid grid-cols-3 gap-2">
                  {methods.map((m) => {
                    const Icon = m.icon;
                    return (
                      <button
                        key={m.key}
                        onClick={() => setMethod(m.key)}
                        className={`flex flex-col items-center gap-1.5 py-3 rounded-xl text-xs font-medium border transition-colors ${
                          method === m.key ? colorMap[m.color] : "bg-card/80 border-border text-muted hover:text-foreground"
                        }`}
                      >
                        <Icon className="w-5 h-5" />
                        {t(`invoices.${m.key === "bank_transfer" ? "bank_transfer" : m.key}`)}
                      </button>
                    );
                  })}
                </div>
              </div>

              <div className="space-y-3 mb-5">
                <div>
                  <label className="text-sm text-muted mb-1 block">{t("invoices.amount")}</label>
                  <input
                    type="number"
                    step="0.001"
                    min="0"
                    max={total}
                    value={amount}
                    onChange={(e) => setAmount(e.target.value)}
                    className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors"
                  />
                </div>
                <div>
                  <label className="text-sm text-muted mb-1 block">{t("invoices.reference")}</label>
                  <input
                    type="text"
                    value={reference}
                    onChange={(e) => setReference(e.target.value)}
                    placeholder={t("invoices.reference")}
                    className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors"
                  />
                </div>
                <div>
                  <label className="text-sm text-muted mb-1 block">{t("invoices.notes")}</label>
                  <input
                    type="text"
                    value={notes}
                    onChange={(e) => setNotes(e.target.value)}
                    placeholder={t("invoices.notes")}
                    className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors"
                  />
                </div>
              </div>

              <div className="flex gap-3">
                <button onClick={handleSubmit} disabled={loading || parseFloat(amount) <= 0} className="flex-1 py-3 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors disabled:opacity-40">
                  {loading ? t("invoices.saving") : t("invoices.record_payment")}
                </button>
                <button onClick={onClose} disabled={loading} className="px-6 py-3 text-sm text-muted hover:text-foreground transition-colors">
                  {t("pos.cancel")}
                </button>
              </div>
            </motion.div>
          </div>
        </>
      )}
    </AnimatePresence>
  );
}
