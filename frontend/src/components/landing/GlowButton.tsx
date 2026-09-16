"use client";

import { motion } from "framer-motion";
import { ArrowRight } from "lucide-react";

interface GlowButtonProps {
  children: React.ReactNode;
  onClick?: () => void;
  variant?: "primary" | "secondary";
  className?: string;
}

export default function GlowButton({
  children,
  onClick,
  variant = "primary",
  className = "",
}: GlowButtonProps) {
  if (variant === "secondary") {
    return (
      <motion.button
        whileHover={{ scale: 1.04 }}
        whileTap={{ scale: 0.97 }}
        onClick={onClick}
        className={`group flex items-center gap-3 px-8 py-4 rounded-2xl text-base font-medium border border-border bg-card/60 hover:bg-card/80 text-muted hover:text-foreground transition-all duration-300 hover:border-gold/40 hover:shadow-[0_0_30px_rgba(212,154,55,0.08)] ${className}`}
      >
        {children}
        <ArrowRight className="w-5 h-5 group-hover:translate-x-1 transition-transform" />
      </motion.button>
    );
  }

  return (
    <motion.button
      whileHover={{ scale: 1.04 }}
      whileTap={{ scale: 0.97 }}
      onClick={onClick}
      className={`group relative flex items-center gap-3 px-8 py-4 rounded-2xl text-base font-semibold text-white overflow-hidden transition-all duration-300 hover:shadow-[0_0_40px_rgba(27,59,111,0.35)] ${className}`}
    >
      <div className="absolute inset-0 bg-linear-to-r from-[#1E4E8C] to-[#3A75C4] group-hover:from-[#3A75C4] group-hover:to-[#1E4E8C] transition-all duration-500" />
      <div className="absolute inset-0 opacity-0 group-hover:opacity-100 transition-opacity duration-500 bg-gradient-to-r from-[#1E4E8C]/40 via-[#D49A37]/20 to-[#3A75C4]/40 blur-xl" />
      <span className="relative z-10 flex items-center gap-3">
        {children}
        <ArrowRight className="w-5 h-5 group-hover:translate-x-1 transition-transform" />
      </span>
    </motion.button>
  );
}
