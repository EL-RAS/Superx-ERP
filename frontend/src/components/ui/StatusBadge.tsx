"use client";
import { STATUS_COLORS } from "@/lib/morphing-engine";
import { useI18n } from "@/lib/i18n";

export default function StatusBadge({ status }: { status: string }) {
  const { t } = useI18n();
  const color = STATUS_COLORS[status] || "bg-muted/20 text-muted border-border/30";
  const label = t(`status.${status}`);
  return (
    <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border ${color}`}>
      {label !== `status.${status}` ? label : status.replace(/_/g, " ")}
    </span>
  );
}
