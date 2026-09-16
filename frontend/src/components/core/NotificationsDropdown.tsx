"use client";

import { useState, useRef, useEffect } from "react";
import { useI18n } from "@/lib/i18n";
import { motion, AnimatePresence } from "framer-motion";

interface Notification {
  id: number;
  title: string;
  title_ar: string;
  message: string;
  message_ar: string;
  time: string;
  time_ar: string;
  type: "warning" | "info" | "danger";
  read: boolean;
}

const INITIAL_NOTIFICATIONS: Notification[] = [
  {
    id: 1,
    title: "Low Stock Alert",
    title_ar: "تنبيح: مخزون منخفض",
    message: "iPhone 15 Pro Max — 3 units remaining",
    message_ar: "آيفون 15 برو ماكس — 3 وحدات متبقية",
    time: "5 min ago",
    time_ar: "منذ 5 دقائق",
    type: "warning",
    read: false,
  },
  {
    id: 2,
    title: "Pending Invoice",
    title_ar: "فاتورة معلقة",
    message: "INV-2026-0041 — JOD 1,250.00 awaiting payment",
    message_ar: "فاتورة INV-2026-0041 — 1,250.00 د.أ بانتظار الدفع",
    time: "12 min ago",
    time_ar: "منذ 12 دقيقة",
    type: "danger",
    read: false,
  },
  {
    id: 3,
    title: "System Update",
    title_ar: "تحديث النظام",
    message: "New version v2.4.0 is available for download",
    message_ar: "الإصدار الجديد v2.4.0 متاح للتحميل",
    time: "1 hour ago",
    time_ar: "منذ ساعة",
    type: "info",
    read: false,
  },
  {
    id: 4,
    title: "Low Stock Alert",
    title_ar: "تنبيح: مخزون منخفض",
    message: "Samsung Galaxy S24 — 5 units remaining",
    message_ar: "سامسونج جالكسي S24 — 5 وحدات متبقية",
    time: "2 hours ago",
    time_ar: "منذ ساعتين",
    type: "warning",
    read: true,
  },
  {
    id: 5,
    title: "New Customer Registered",
    title_ar: "عميل جديد مسجل",
    message: "Ahmad Al-Khatib joined as a wholesale customer",
    message_ar: "أحمد الخطيب انضم كعميل جملة",
    time: "3 hours ago",
    time_ar: "منذ 3 ساعات",
    type: "info",
    read: true,
  },
];

const TYPE_STYLES: Record<string, string> = {
  warning: "bg-amber-500/15 text-amber-500",
  danger: "bg-red-500/15 text-red-500",
  info: "bg-blue-500/15 text-blue-500",
};

const TYPE_DOT: Record<string, string> = {
  warning: "bg-amber-500",
  danger: "bg-red-500",
  info: "bg-blue-500",
};

export default function NotificationsDropdown() {
  const { locale, t } = useI18n();
  const [open, setOpen] = useState(false);
  const [notifications, setNotifications] = useState(INITIAL_NOTIFICATIONS);
  const dropdownRef = useRef<HTMLDivElement>(null);

  const unreadCount = notifications.filter((n) => !n.read).length;

  useEffect(() => {
    function handleClickOutside(e: MouseEvent) {
      if (dropdownRef.current && !dropdownRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    }
    document.addEventListener("mousedown", handleClickOutside);
    return () => document.removeEventListener("mousedown", handleClickOutside);
  }, []);

  function markAsRead(id: number) {
    setNotifications((prev) =>
      prev.map((n) => (n.id === id ? { ...n, read: true } : n))
    );
  }

  function markAllRead() {
    setNotifications((prev) => prev.map((n) => ({ ...n, read: true })));
  }

  function clearAll() {
    setNotifications([]);
    setOpen(false);
  }

  const isAr = locale === "ar";

  return (
    <div className="relative" ref={dropdownRef}>
      <button
        onClick={() => setOpen(!open)}
        className="relative p-2 rounded-lg text-muted hover:bg-accent-dim hover:text-accent transition-colors"
        title={t("topbar.notifications")}
      >
        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
          <path strokeLinecap="round" strokeLinejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
        </svg>
        {unreadCount > 0 && (
          <span className="absolute -top-0.5 -end-0.5 min-w-[18px] h-[18px] flex items-center justify-center rounded-full bg-danger text-[10px] font-bold text-foreground px-1">
            {unreadCount}
          </span>
        )}
      </button>

      <AnimatePresence>
        {open && (
          <motion.div
            className="absolute end-0 top-full mt-2 w-[380px] rounded-xl bg-card border border-border shadow-2xl shadow-black/20 z-50 overflow-hidden"
            initial={{ opacity: 0, y: -8, scale: 0.96 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            exit={{ opacity: 0, y: -8, scale: 0.96 }}
            transition={{ duration: 0.15 }}
          >
            <div className="flex items-center justify-between px-4 py-3 border-b border-border">
              <h3 className="text-sm font-semibold text-foreground">{t("topbar.notifications")}</h3>
              {unreadCount > 0 && (
                <button
                  onClick={markAllRead}
                  className="text-[11px] text-primary hover:underline"
                >
                  {t("topbar.mark_all_read")}
                </button>
              )}
            </div>

            <div className="max-h-80 overflow-y-auto">
              {notifications.length === 0 ? (
                <div className="px-4 py-8 text-center text-sm text-muted">
                  {t("topbar.no_notifications")}
                </div>
              ) : (
                notifications.map((n) => (
                  <div
                    key={n.id}
                    onClick={() => markAsRead(n.id)}
                    className={`flex items-start gap-3 px-4 py-3 cursor-pointer transition-colors hover:bg-accent-dim ${
                      !n.read ? "bg-accent/5" : ""
                    }`}
                  >
                    <div className={`mt-0.5 w-2 h-2 rounded-full shrink-0 ${!n.read ? TYPE_DOT[n.type] : "bg-transparent"}`} />
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-2">
                        <span className={`inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium ${TYPE_STYLES[n.type]}`}>
                          {isAr ? n.title_ar : n.title}
                        </span>
                      </div>
                      <p className="text-xs text-foreground mt-1 line-clamp-2">
                        {isAr ? n.message_ar : n.message}
                      </p>
                      <p className="text-[11px] text-muted mt-1">
                        {isAr ? n.time_ar : n.time}
                      </p>
                    </div>
                  </div>
                ))
              )}
            </div>

            {notifications.length > 0 && (
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
