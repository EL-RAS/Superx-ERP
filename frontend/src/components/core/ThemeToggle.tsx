"use client";

import { useTheme } from "next-themes";
import { useI18n } from "@/lib/i18n";

export default function ThemeToggle() {
  const { theme, setTheme } = useTheme();
  const { t, dir } = useI18n();
  const isDark = theme === "dark";
  const knobShift = isDark ? (dir === "rtl" ? "-translate-x-5" : "translate-x-5") : "translate-x-0";

  return (
    <button
      onClick={() => setTheme(isDark ? "light" : "dark")}
      className="relative flex items-center h-8 w-[52px] rounded-full bg-muted/30 border border-border p-0.5 overflow-hidden shrink-0 transition-colors hover:bg-muted/50"
      title={t("topbar.toggle_theme")}
      aria-pressed={isDark}
    >
      <span
        className={`absolute top-0.5 start-0.5 flex items-center justify-center w-7 h-7 rounded-full bg-card shadow-sm transition-transform duration-300 ease-in-out ${knobShift}`}
      />
      <span className="relative z-10 flex-1 flex items-center justify-center">
        <svg
          className={`w-4 h-4 transition-colors duration-300 ${
            isDark ? "text-muted" : "text-amber-500"
          }`}
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
          strokeWidth={1.5}
        >
          <path strokeLinecap="round" strokeLinejoin="round" d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" />
        </svg>
      </span>
      <span className="relative z-10 flex-1 flex items-center justify-center">
        <svg
          className={`w-4 h-4 transition-colors duration-300 ${
            isDark ? "text-blue-400" : "text-muted"
          }`}
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
          strokeWidth={1.5}
        >
          <path strokeLinecap="round" strokeLinejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" />
        </svg>
      </span>
    </button>
  );
}
