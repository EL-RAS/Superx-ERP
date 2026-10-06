"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { AnimatePresence, motion } from "framer-motion";
import { useI18n } from "@/lib/i18n";
import { useAuthStore } from "@/stores/auth-store";
import type { AppNotification } from "@/lib/types";
import {
  clearNotifications,
  fetchNotifications,
  fetchUnreadNotificationCount,
  markAllNotificationsRead,
  markNotificationRead,
} from "@/lib/api";

const SEVERITY_STYLES: Record<string, string> = {
  warning: "bg-amber-500/15 text-amber-500",
  danger: "bg-red-500/15 text-red-500",
  info: "bg-blue-500/15 text-blue-500",
};

const SEVERITY_DOT: Record<string, string> = {
  warning: "bg-amber-500",
  danger: "bg-red-500",
  info: "bg-blue-500",
};

function severityStyle(severity: string): string {
  return SEVERITY_STYLES[severity] ?? SEVERITY_STYLES.info;
}

function severityDot(severity: string): string {
  return SEVERITY_DOT[severity] ?? SEVERITY_DOT.info;
}

/** Server timestamps arrive as ISO-8601 UTC; render them in the reader's locale. */
function relativeTime(iso: string | null, locale: string): string {
  if (!iso) return "";

  const date = new Date(iso);
  const seconds = Math.round((date.getTime() - Date.now()) / 1000);
  if (Number.isNaN(seconds)) return "";

  const rtf = new Intl.RelativeTimeFormat(locale, { numeric: "auto" });
  const abs = Math.abs(seconds);

  if (abs < 60) return rtf.format(seconds, "second");
  if (abs < 3600) return rtf.format(Math.round(seconds / 60), "minute");
  if (abs < 86400) return rtf.format(Math.round(seconds / 3600), "hour");
  if (abs < 2592000) return rtf.format(Math.round(seconds / 86400), "day");

  return new Intl.DateTimeFormat(locale, { dateStyle: "medium" }).format(date);
}

export default function NotificationsDropdown() {
  const { locale, t } = useI18n();
  const router = useRouter();
  const { token, business } = useAuthStore();

  const [open, setOpen] = useState(false);
  const [items, setItems] = useState<AppNotification[]>([]);
  const [unread, setUnread] = useState(0);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(false);
  const dropdownRef = useRef<HTMLDivElement>(null);

  const bizId = business?.id;

  // The badge is cosmetic: a failed poll must never break the header.
  const loadBadge = useCallback(() => {
    if (!token || !bizId) return;
    fetchUnreadNotificationCount(token, bizId)
      .then((res) => setUnread(res.unread_count))
      .catch(() => undefined);
  }, [token, bizId]);

  const loadList = useCallback(() => {
    if (!token || !bizId) return;
    setLoading(true);
    setError(false);
    fetchNotifications(token, bizId, { per_page: 15 })
      .then((res) => {
        setItems(res.data);
        setUnread(res.unread_count);
      })
      .catch(() => setError(true))
      .finally(() => setLoading(false));
  }, [token, bizId]);

  useEffect(() => {
    loadBadge();
    window.addEventListener("focus", loadBadge);
    return () => window.removeEventListener("focus", loadBadge);
  }, [loadBadge]);

  useEffect(() => {
    function handleClickOutside(e: MouseEvent) {
      if (dropdownRef.current && !dropdownRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    }
    document.addEventListener("mousedown", handleClickOutside);
    return () => document.removeEventListener("mousedown", handleClickOutside);
  }, []);

  function toggleOpen() {
    const next = !open;
    setOpen(next);
    if (next) loadList();
  }

  function markAsRead(id: string) {
    if (!token || !bizId) return;

    const target = items.find((n) => n.id === id);
    if (!target || target.read) return;

    setItems((prev) => prev.map((n) => (n.id === id ? { ...n, read: true } : n)));
    setUnread((prev) => Math.max(0, prev - 1));

    markNotificationRead(token, bizId, id)
      .then((res) => setUnread(res.unread_count))
      .catch(() => loadList());
  }

  function openNotification(n: AppNotification) {
    markAsRead(n.id);

    if (n.action_url && n.action_url.startsWith("/")) {
      setOpen(false);
      router.push(n.action_url);
    }
  }

  function markAllRead() {
    if (!token || !bizId || unread === 0) return;

    setItems((prev) => prev.map((n) => ({ ...n, read: true })));
    setUnread(0);

    markAllNotificationsRead(token, bizId)
      .then((res) => setUnread(res.unread_count))
      .catch(() => loadList());
  }

  function clearAll() {
    if (!token || !bizId) return;

    setItems([]);
    setUnread(0);
    setOpen(false);

    clearNotifications(token, bizId)
      .then((res) => setUnread(res.unread_count))
      .catch(() => loadList());
  }

  const isAr = locale === "ar";

  return (
    <div className="relative" ref={dropdownRef}>
      <button
        onClick={toggleOpen}
        className="relative min-h-11 min-w-11 flex items-center justify-center rounded-lg text-muted hover:bg-accent-dim hover:text-accent transition-colors"
        title={t("topbar.notifications")}
      >
        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
          <path strokeLinecap="round" strokeLinejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
        </svg>
        {unread > 0 && (
          <span className="absolute -top-0.5 -end-0.5 min-w-[18px] h-[18px] flex items-center justify-center rounded-full bg-danger text-[10px] font-bold text-foreground px-1">
            {unread > 99 ? "99+" : unread}
          </span>
        )}
      </button>

      <AnimatePresence>
        {open && (
          <motion.div
            className="absolute end-0 top-full mt-2 w-[calc(100vw-1.5rem)] max-w-[380px] rounded-xl bg-card border border-border shadow-2xl shadow-black/20 z-50 overflow-hidden"
            initial={{ opacity: 0, y: -8, scale: 0.96 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            exit={{ opacity: 0, y: -8, scale: 0.96 }}
            transition={{ duration: 0.15 }}
          >
            <div className="flex items-center justify-between px-4 py-3 border-b border-border">
              <h3 className="text-sm font-semibold text-foreground">{t("topbar.notifications")}</h3>
              {unread > 0 && !loading && !error && (
                <button onClick={markAllRead} className="text-[11px] text-primary hover:underline">
                  {t("topbar.mark_all_read")}
                </button>
              )}
            </div>

            <div className="max-h-80 overflow-y-auto">
              {loading ? (
                <div className="px-4 py-8 text-center text-sm text-muted">{t("common.loading")}</div>
              ) : error ? (
                <div className="px-4 py-8 text-center">
                  <p className="text-sm text-muted mb-3">{t("topbar.load_failed")}</p>
                  <button
                    onClick={loadList}
                    className="text-xs text-primary hover:underline"
                  >
                    {t("common.refresh")}
                  </button>
                </div>
              ) : items.length === 0 ? (
                <div className="px-4 py-8 text-center text-sm text-muted">
                  {t("topbar.no_notifications")}
                </div>
              ) : (
                items.map((n) => (
                  <div
                    key={n.id}
                    onClick={() => openNotification(n)}
                    className={`flex items-start gap-3 px-4 py-3 cursor-pointer transition-colors hover:bg-accent-dim ${
                      !n.read ? "bg-accent/5" : ""
                    }`}
                  >
                    <div
                      className={`mt-1.5 w-2 h-2 rounded-full shrink-0 ${!n.read ? severityDot(n.severity) : "bg-transparent"}`}
                    />
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-2">
                        <span
                          className={`inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium ${severityStyle(n.severity)}`}
                        >
                          {n.title[isAr ? "ar" : "en"] ?? n.title.en}
                        </span>
                      </div>
                      <p className="text-xs text-foreground mt-1 line-clamp-2">
                        {n.message[isAr ? "ar" : "en"] ?? n.message.en}
                      </p>
                      <p className="text-[11px] text-muted mt-1">
                        {relativeTime(n.created_at, locale)}
                      </p>
                    </div>
                  </div>
                ))
              )}
            </div>

            {!loading && !error && items.length > 0 && (
              <div className="flex items-center justify-center px-4 py-2.5 border-t border-border">
                <button
                  onClick={clearAll}
                  className="text-xs text-muted hover:text-danger transition-colors"
                >
                  {t("topbar.clear_all")}
                </button>
              </div>
            )}
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  );
}
