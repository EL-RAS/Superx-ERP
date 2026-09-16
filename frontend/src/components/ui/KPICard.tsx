"use client";
import { motion } from "framer-motion";
import { TrendingUp, TrendingDown } from "lucide-react";
import { formatCurrency } from "@/lib/types";
import { useI18n } from "@/lib/i18n";
import type { LucideIcon } from "lucide-react";

interface KPICardProps {
  icon: LucideIcon;
  label: string;
  value: number | string;
  change?: number;
  format?: "currency" | "number" | "default";
  accent?: string;
}

export default function KPICard({ icon: Icon, label, value, change, format = "default", accent = "violet" }: KPICardProps) {
  const { locale } = useI18n();
  const displayValue = format === "currency" ? formatCurrency(Number(value), locale) : format === "number" ? Number(value).toLocaleString() : String(value);

  return (
    <motion.div
      initial={{ opacity: 0, y: 20 }}
      animate={{ opacity: 1, y: 0 }}
      className="glass rounded-2xl p-5 hover:border-border-hover/80 transition-all duration-300 group"
    >
      <div className="flex items-start justify-between">
        <div className={`p-2.5 rounded-xl bg-${accent}-500/10`}>
          <Icon className={`w-5 h-5 text-${accent}-400`} />
        </div>
        {change !== undefined && (
          <div className={`flex items-center gap-1 text-xs font-medium ${change >= 0 ? "text-green-400" : "text-red-400"}`}>
            {change >= 0 ? <TrendingUp className="w-3.5 h-3.5" /> : <TrendingDown className="w-3.5 h-3.5" />}
            {Math.abs(change)}%
          </div>
        )}
      </div>
      <div className="mt-4">
        <p className="text-2xl font-bold tracking-tight text-foreground">{displayValue}</p>
        <p className="text-sm text-muted mt-1">{label}</p>
      </div>
    </motion.div>
  );
}
