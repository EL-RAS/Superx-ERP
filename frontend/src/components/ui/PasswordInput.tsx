"use client";

import { useState } from "react";
import { Eye, EyeOff } from "lucide-react";

const baseCls =
  "w-full px-4 py-2.5 pe-11 bg-card/80 border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-primary/50 transition-colors disabled:opacity-60";

const bareCls =
  "w-full bg-transparent text-sm text-foreground outline-none py-3.5 pe-9 tracking-wide placeholder:text-muted/60";

/**
 * Reusable password input with an inline show/hide toggle.
 *
 * `bare` renders a transparent input meant to live inside an existing bordered
 * wrapper (auth-page `Field` rows) instead of drawing its own border.
 */
export default function PasswordInput({
  id,
  name,
  value,
  onChange,
  onBlur,
  placeholder,
  autoComplete = "new-password",
  error,
  disabled,
  autoFocus,
  className,
  bare,
  dir,
  maxLength,
  tabIndex,
  showToggle = true,
}: {
  id?: string;
  name?: string;
  value: string;
  onChange: (v: string) => void;
  onBlur?: () => void;
  placeholder?: string;
  autoComplete?: string;
  error?: string;
  disabled?: boolean;
  autoFocus?: boolean;
  className?: string;
  bare?: boolean;
  dir?: "ltr" | "rtl" | "auto";
  maxLength?: number;
  tabIndex?: number;
  showToggle?: boolean;
}) {
  const [visible, setVisible] = useState(false);

  const inputCls = bare
    ? `${bareCls} ${className ?? ""}`.trim()
    : `${baseCls} ${error ? "border-red-500/50" : "border-border"} ${className ?? ""}`.trim();

  return (
    <div className="relative w-full">
      <input
        id={id}
        name={name}
        type={visible ? "text" : "password"}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        onBlur={onBlur}
        placeholder={placeholder}
        autoComplete={autoComplete}
        disabled={disabled}
        autoFocus={autoFocus}
        dir={dir}
        maxLength={maxLength}
        tabIndex={tabIndex}
        aria-invalid={error ? true : undefined}
        className={inputCls}
      />
      {showToggle && (
        <button
          type="button"
          onClick={() => setVisible((v) => !v)}
          disabled={disabled}
          tabIndex={-1}
          aria-label={visible ? "Hide password" : "Show password"}
          aria-pressed={visible}
          className="absolute end-3 top-1/2 -translate-y-1/2 text-muted hover:text-foreground transition-colors p-0.5 rounded-lg disabled:opacity-40"
        >
          {visible ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
        </button>
      )}
    </div>
  );
}