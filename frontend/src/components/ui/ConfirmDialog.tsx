"use client";
import { useEffect } from "react";
import { motion, AnimatePresence } from "framer-motion";
import { AlertTriangle } from "lucide-react";
import { useI18n } from "@/lib/i18n";

interface ConfirmDialogProps {
  open: boolean;
  onClose: () => void;
  onConfirm: () => void;
  title: string;
  message: string;
  confirmLabel?: string;
  confirmVariant?: "danger" | "primary";
  loading?: boolean;
  error?: string;
}

export default function ConfirmDialog({ open, onClose, onConfirm, title, message, confirmLabel, confirmVariant = "danger", loading, error }: ConfirmDialogProps) {
  const { t } = useI18n();
  useEffect(() => {
    if (open) document.body.style.overflow = "hidden";
    else document.body.style.overflow = "";
    return () => { document.body.style.overflow = ""; };
  }, [open]);

  const btnClass = confirmVariant === "danger"
    ? "bg-red-500 hover:bg-red-600 text-white"
    : "bg-primary hover:bg-primary-light text-white";

  return (
    <AnimatePresence>
      {open && (
        <>
          <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            onClick={onClose}
            className="fixed inset-0 bg-black/60 backdrop-blur-sm z-50"
          />
          <div className="fixed inset-0 flex items-center justify-center z-50 p-4">
            <motion.div
              initial={{ opacity: 0, scale: 0.95 }}
              animate={{ opacity: 1, scale: 1 }}
              exit={{ opacity: 0, scale: 0.95 }}
              className="glass rounded-2xl p-6 w-full max-w-md"
            >
              <div className="flex items-center gap-3 mb-4">
                <div className="p-2 rounded-xl bg-red-500/10">
                  <AlertTriangle className="w-5 h-5 text-red-400" />
                </div>
                <h3 className="text-lg font-semibold text-foreground">{title}</h3>
              </div>
              <p className="text-sm text-muted mb-6">{message}</p>
              {error && (
                <p className="text-sm text-red-400 bg-red-500/10 border border-red-500/30 rounded-xl px-3 py-2 mb-6">{error}</p>
              )}
              <div className="flex justify-end gap-3">
                <button
                  onClick={onClose}
                  disabled={loading}
                  className="px-4 py-2 rounded-xl text-sm font-medium text-muted hover:text-foreground hover:bg-card-hover transition-colors"
                >
                  {t("common.cancel")}
                </button>
                <button
                  onClick={onConfirm}
                  disabled={loading}
                  className={`px-4 py-2 rounded-xl text-sm font-medium transition-colors ${btnClass} disabled:opacity-50`}
                >
                  {loading ? t("common.processing") : (confirmLabel ?? t("common.confirm"))}
                </button>
              </div>
            </motion.div>
          </div>
        </>
      )}
    </AnimatePresence>
  );
}
