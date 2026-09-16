"use client";

import { useEffect, useMemo, useState } from "react";
import { usePathname } from "next/navigation";
import { BootstrapConfig } from "@/lib/types";
import Sidebar from "@/components/core/Sidebar";
import TopBar from "@/components/core/TopBar";
import { useI18n } from "@/lib/i18n";

function getNavLabel(pathname: string, config: BootstrapConfig): string | null {
  if (pathname === "/profile") return "nav.profile";
  for (const node of config.navigation) {
    if (node.route === pathname) return node.label;
    if (node.children) {
      for (const child of node.children) {
        if (child.route === pathname) return child.label;
      }
    }
  }
  return null;
}

interface DashboardShellProps {
  config: BootstrapConfig;
  children: React.ReactNode;
}

export default function DashboardShell({
  config,
  children,
}: DashboardShellProps) {
  const pathname = usePathname();
  const { t } = useI18n();
  const [sidebarOpen, setSidebarOpen] = useState(false);

  const title = useMemo(() => {
    if (pathname === "/profile") return t("nav.profile");
    const raw = getNavLabel(pathname, config) ?? t("nav.dashboard");
    const key = `nav.${pathname.replace(/^\//, "").replace(/\//g, ".")}`;
    const translated = t(key);
    return translated !== key ? translated : raw;
  }, [pathname, config, t]);

  useEffect(() => {
    const raf = window.requestAnimationFrame(() => setSidebarOpen(false));
    return () => window.cancelAnimationFrame(raf);
  }, [pathname]);

  return (
    <div className="min-h-screen bg-background text-foreground">
      <Sidebar
        config={config}
        open={sidebarOpen}
        onClose={() => setSidebarOpen(false)}
      />
      <div className="lg:ms-64">
        <TopBar
          config={config}
          title={title}
          onMenuClick={() => setSidebarOpen(true)}
        />
        <main className="p-4 md:p-6">{children}</main>
      </div>
    </div>
  );
}
