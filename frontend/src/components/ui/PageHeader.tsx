"use client";
import { motion } from "framer-motion";

interface PageHeaderProps {
  title: string;
  subtitle?: string;
  action?: React.ReactNode;
  actions?: React.ReactNode;
}

export default function PageHeader({ title, subtitle, action, actions }: PageHeaderProps) {
  return (
    <motion.div
      initial={{ opacity: 0, y: -10 }}
      animate={{ opacity: 1, y: 0 }}
      className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-6 md:mb-8"
    >
      <div>
        <h1 className="text-xl sm:text-2xl font-bold tracking-tight text-foreground">{title}</h1>
        {subtitle && <p className="text-sm text-muted mt-1">{subtitle}</p>}
      </div>
      <div className="flex flex-wrap items-center gap-2 sm:gap-3">
        {actions}
        {action}
      </div>
    </motion.div>
  );
}
