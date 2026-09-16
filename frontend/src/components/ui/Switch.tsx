"use client";

import { useId } from "react";

interface SwitchProps {
  checked: boolean;
  onChange: (checked: boolean) => void;
  disabled?: boolean;
  id?: string;
  "aria-label"?: string;
}

export default function Switch({
  checked,
  onChange,
  disabled,
  id,
  "aria-label": ariaLabel,
}: SwitchProps) {
  const autoId = useId();
  const switchId = id ?? autoId;

  return (
    <button
      type="button"
      id={switchId}
      role="switch"
      aria-checked={checked}
      aria-label={ariaLabel}
      disabled={disabled}
      onClick={() => onChange(!checked)}
      className={`relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 ${
        checked
          ? "bg-primary/30 border border-primary/50"
          : "bg-card/80 border border-border"
      }`}
    >
      <span
        className={`pointer-events-none absolute top-0.5 h-5 w-5 rounded-full bg-foreground shadow-sm transition-all duration-200 ${
          checked ? "start-[22px]" : "start-0.5"
        }`}
      />
    </button>
  );
}