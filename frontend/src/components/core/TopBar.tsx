"use client";

import { useState, useRef, useEffect, useCallback } from "react";
import { useRouter } from "next/navigation";
import { BootstrapConfig } from "@/lib/types";
import { useAuthStore } from "@/stores/auth-store";
import { useI18n, roleLabel, businessTypeLabel } from "@/lib/i18n";
import { LogOut, ChevronDown, User, Globe, Menu } from "lucide-react";
import SearchModal from "@/components/core/SearchModal";
import NotificationsDropdown from "@/components/core/NotificationsDropdown";
import ThemeToggle from "@/components/core/ThemeToggle";

interface TopBarProps {
  config: BootstrapConfig;
  title: string;
  onMenuClick?: () => void;
}

export default function TopBar({ config, title, onMenuClick }: TopBarProps) {
  const { user, logout } = useAuthStore();
  const { locale, setLocale, t } = useI18n();
  const businessLabel = businessTypeLabel(t, config.business_type);
  const router = useRouter();
  const [dropdownOpen, setDropdownOpen] = useState(false);
  const [searchOpen, setSearchOpen] = useState(false);
  const dropdownRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    function handleClickOutside(e: MouseEvent) {
      if (dropdownRef.current && !dropdownRef.current.contains(e.target as Node)) {
        setDropdownOpen(false);
      }
    }
    document.addEventListener("mousedown", handleClickOutside);
    return () => document.removeEventListener("mousedown", handleClickOutside);
  }, []);

  const handleSearchShortcut = useCallback((e: KeyboardEvent) => {
    if ((e.metaKey || e.ctrlKey) && e.key === "k") {
      e.preventDefault();
      setSearchOpen((v) => !v);
    }
  }, []);

  useEffect(() => {
    window.addEventListener("keydown", handleSearchShortcut);
    return () => window.removeEventListener("keydown", handleSearchShortcut);
  }, [handleSearchShortcut]);

  const handleLogout = () => {
    logout();
    window.location.href = "/login";
  };

  const initials = user?.name
    ? user.name.split(" ").map((w) => w[0]).join("").toUpperCase().slice(0, 2)
    : "U";

  return (
    <>
      <header className="h-16 border-b border-border bg-background/80 backdrop-blur-xl flex items-center justify-between px-4 md:px-6 sticky top-0 z-30">
        <div className="flex items-center gap-3 min-w-0">
          <button
            onClick={onMenuClick}
            className="lg:hidden p-2 rounded-lg text-muted hover:bg-accent-dim hover:text-foreground transition-colors"
            title={t("topbar.menu")}
          >
            <Menu className="w-5 h-5" />
          </button>
          <div className="min-w-0">
            <h1 className="text-lg font-semibold text-foreground truncate">{title}</h1>
            <p className="text-xs text-muted truncate">{businessLabel}</p>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <button
            onClick={() => setSearchOpen(true)}
            className="hidden md:flex items-center gap-2 px-3 py-1.5 rounded-lg bg-card border border-border hover:border-primary/30 transition-colors cursor-pointer"
          >
            <svg className="w-4 h-4 text-muted" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
              <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
            </svg>
            <span className="text-sm text-muted">{t("topbar.search")}</span>
            <kbd className="text-[10px] text-muted bg-muted/20 border border-border px-1.5 py-0.5 rounded font-mono">
              {navigator.platform?.includes("Mac") ? "⌘" : "Ctrl+"}K
            </kbd>
          </button>

          <NotificationsDropdown />

          <ThemeToggle />

          <button
            onClick={() => setLocale(locale === "en" ? "ar" : "en")}
            className="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-sm font-medium text-muted hover:bg-accent-dim hover:text-accent transition-colors"
            title={locale === "en" ? "\u0627\u0644\u0639\u0631\u0628\u064A\u0629" : "English"}
          >
            <Globe className="w-4 h-4" />
            <span className="text-xs font-semibold">{locale === "en" ? "AR" : "EN"}</span>
          </button>

          <div className="relative" ref={dropdownRef}>
            <button
              onClick={() => setDropdownOpen(!dropdownOpen)}
              className="flex items-center gap-2 p-1.5 rounded-xl hover:bg-accent-dim transition-colors"
            >
              {user?.avatar ? (
                // eslint-disable-next-line @next/next/no-img-element
                <img
                  src={user.avatar}
                  alt={user.name || "User"}
                  className="w-8 h-8 rounded-full object-cover border border-border"
                />
              ) : (
                <div className="w-8 h-8 rounded-full bg-gradient-to-br from-[#1E4E8C] to-[#D49A37] flex items-center justify-center">
                  <span className="text-xs font-bold text-white">{initials}</span>
                </div>
              )}
              <div className="hidden md:block text-start">
                <p className="text-sm font-medium text-foreground leading-tight">{user?.name || "User"}</p>
                <p className="text-[11px] text-muted leading-tight">{roleLabel(t, user?.role)}</p>
              </div>
              <ChevronDown className="w-4 h-4 text-muted hidden md:block" />
            </button>

            {dropdownOpen && (
              <div className="absolute end-0 top-full mt-2 w-56 rounded-xl bg-card border border-border shadow-2xl shadow-black/20 py-1.5 z-50">
                <div className="px-4 py-3 border-b border-border">
                  <p className="text-sm font-semibold text-foreground">{user?.name || "User"}</p>
                  <p className="text-xs text-muted mt-0.5">{user?.email || ""}</p>
                </div>
                <div className="py-1.5">
                  <button
                    onClick={() => {
                      setDropdownOpen(false);
                      router.push("/profile");
                    }}
                    className="w-full flex items-center gap-3 px-4 py-2 text-sm text-foreground hover:bg-accent-dim transition-colors"
                  >
                    <User className="w-4 h-4 text-muted" />
                    {t("topbar.profile")}
                  </button>
                  <button
                    onClick={handleLogout}
                    className="w-full flex items-center gap-3 px-4 py-2 text-sm text-danger hover:bg-red-500/10 transition-colors"
                  >
                    <LogOut className="w-4 h-4" />
                    {t("topbar.sign_out")}
                  </button>
                </div>
              </div>
            )}
          </div>
        </div>
      </header>

      <SearchModal open={searchOpen} onClose={() => setSearchOpen(false)} config={config} />
    </>
  );
}
