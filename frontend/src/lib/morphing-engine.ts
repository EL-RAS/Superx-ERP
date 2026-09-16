import { BootstrapConfig, formatCurrency } from "./types";

export function extractNestedValue(obj: Record<string, unknown>, path: string): unknown {
  return path.split(".").reduce<unknown>((acc, key) => {
    if (acc && typeof acc === "object" && key in (acc as Record<string, unknown>)) {
      return (acc as Record<string, unknown>)[key];
    }
    return undefined;
  }, obj);
}

export function formatCellValue(value: unknown, type: string, locale?: string): string {
  if (value === null || value === undefined) return "—";
  switch (type) {
    case "currency": return formatCurrency(Number(value), locale);
    case "date": return new Date(String(value)).toLocaleDateString(locale === "ar" ? "ar-JO" : "en-JO", { year: "numeric", month: "short", day: "numeric" });
    case "boolean": return value ? "Yes" : "No";
    case "badge": return String(value);
    case "number": return Number(value).toLocaleString();
    default: return String(value);
  }
}

export function isRouteAllowed(route: string, config: BootstrapConfig): boolean {
  if (!config.modules || config.modules.length === 0) return true;
  const moduleKey = resolveModule(route);
  if (!moduleKey) return true;
  return config.modules.includes(moduleKey);
}

export function resolveModule(route: string): string | null {
  if (route.startsWith("/dashboard")) return "dashboard";
  if (route.startsWith("/pos")) return "pos";
  if (route.startsWith("/sales") || route.startsWith("/invoices") || route.startsWith("/promotions") || route.startsWith("/returns-exchanges")) return "sales";
  if (route.startsWith("/purchases") || route.startsWith("/suppliers") || route.startsWith("/purchase-orders")) return "purchases";
  if (route.startsWith("/inventory") || route.startsWith("/products") || route.startsWith("/product-batches") || route.startsWith("/product-variants") || route.startsWith("/inventory-adjustments")) return "inventory";
  if (route.startsWith("/accounts") || route.startsWith("/journal-entries") || route.startsWith("/accounting") || route.startsWith("/trial-balance") || route.startsWith("/fiscal-years") || route.startsWith("/bank-reconciliation")) return "accounting";
  if (route.startsWith("/customers") || route.startsWith("/crm")) return "crm";
  if (route.startsWith("/users") || route.startsWith("/roles")) return "users";
  return null;
}

// Supermarket & Hypermarket is the canonical slug; keep legacy aliases so
// older stored/shared values (supermarket/grocery) resolve identically.
const SUPERMARKET_VERTICAL_SLUGS = ["supermarket_hypermarket", "supermarket", "grocery"];

export function isSupermarketVertical(slug?: string | null): boolean {
  const s = (slug || "").toLowerCase();
  return SUPERMARKET_VERTICAL_SLUGS.includes(s);
}

export function getBusinessLabel(slug: string): string {
  const labels: Record<string, string> = {
    supermarket_hypermarket: "Supermarket & Hypermarket",
    supermarket: "Supermarket & Hypermarket",
    clothing_apparel: "Clothing & Apparel",
    electronics_warranty: "Electronics & Warranty",
    fast_food_kds: "Fast Food / KDS",
    fine_dining_reservations: "Fine Dining",
    dental_charting: "Dental Clinic",
    general_clinic: "General Medical Clinic",
    jewelry_store: "Jewelry Store",
  };
  return labels[slug] || slug;
}

export function getBusinessEmoji(slug: string): string {
  const emojis: Record<string, string> = {
    supermarket_hypermarket: "\u{1F6D2}",
    supermarket: "\u{1F6D2}",
    clothing_apparel: "\u{1F455}",
    electronics_warranty: "\u{1F4F1}",
    fast_food_kds: "\u{1F354}",
    fine_dining_reservations: "\u{1F37D}\uFE0F",
    dental_charting: "\u{1F9B7}",
    general_clinic: "\u{1FA7A}",
    jewelry_store: "\u{1F48E}",
  };
  return emojis[slug] || "\u{1F3E2}";
}

export function filterNavigationByFeatures(config: BootstrapConfig) {
  return config.navigation.filter((node) => {
    return isRouteAllowed(node.route, config);
  });
}

export function getDashboardWidgetConfig(config: BootstrapConfig) {
  return config.dashboard_widgets || [];
}



export const STATUS_COLORS: Record<string, string> = {
  new: "bg-cyan-500/20 text-cyan-400 border-cyan-500/30",
  pending: "bg-yellow-500/20 text-yellow-400 border-yellow-500/30",
  draft: "bg-muted/20 text-muted border-border/30",
  preparing: "bg-blue-500/20 text-blue-400 border-blue-500/30",
  cooking: "bg-orange-500/20 text-orange-400 border-orange-500/30",
  ready: "bg-green-500/20 text-green-400 border-green-500/30",
  served: "bg-muted/20 text-muted border-border/30",
  confirmed: "bg-blue-500/20 text-blue-400 border-blue-500/30",
  checked_in: "bg-purple-500/20 text-purple-400 border-purple-500/30",
  in_progress: "bg-indigo-500/20 text-indigo-400 border-indigo-500/30",
  completed: "bg-green-500/20 text-green-400 border-green-500/30",
  cancelled: "bg-red-500/20 text-red-400 border-red-500/30",
  no_show: "bg-red-500/20 text-red-400 border-red-500/30",
  approved: "bg-green-500/20 text-green-400 border-green-500/30",
  rejected: "bg-red-500/20 text-red-400 border-red-500/30",
  submitted: "bg-blue-500/20 text-blue-400 border-blue-500/30",
  under_review: "bg-amber-500/20 text-amber-400 border-amber-500/30",
  fulfilled: "bg-emerald-500/20 text-emerald-400 border-emerald-500/30",
  paid: "bg-green-500/20 text-green-400 border-green-500/30",
  scheduled: "bg-cyan-500/20 text-cyan-400 border-cyan-500/30",
  received: "bg-purple-500/20 text-purple-400 border-purple-500/30",
  partially_received: "bg-amber-500/20 text-amber-400 border-amber-500/30",
  diagnosed: "bg-amber-500/20 text-amber-400 border-amber-500/30",
  delivered: "bg-emerald-500/20 text-emerald-400 border-emerald-500/30",
  open: "bg-cyan-500/20 text-cyan-400 border-cyan-500/30",
  waiting_parts: "bg-amber-500/20 text-amber-400 border-amber-500/30",
  on_hold: "bg-muted/20 text-muted border-border/30",
  resolved: "bg-green-500/20 text-green-400 border-green-500/30",
  closed: "bg-muted/20 text-muted border-border/30",
  seated: "bg-blue-500/20 text-blue-400 border-blue-500/30",
  in_stock: "bg-green-500/20 text-green-400 border-green-500/30",
  sold: "bg-blue-500/20 text-blue-400 border-blue-500/30",
  returned: "bg-orange-500/20 text-orange-400 border-orange-500/30",
  active: "bg-green-500/20 text-green-400 border-green-500/30",
  inactive: "bg-muted/20 text-muted border-border/30",
  expired: "bg-red-500/20 text-red-400 border-red-500/30",
  unpaid: "bg-red-500/20 text-red-400 border-red-500/30",
  partial: "bg-yellow-500/20 text-yellow-400 border-yellow-500/30",
  free: "bg-green-500/20 text-green-400 border-green-500/30",
  occupied: "bg-red-500/20 text-red-400 border-red-500/30",
  reserved: "bg-yellow-500/20 text-yellow-400 border-yellow-500/30",
  // Shift statuses
  starting: "bg-blue-500/20 text-blue-400 border-blue-500/30",
  closing: "bg-amber-500/20 text-amber-400 border-amber-500/30",
  // Inventory adjustment types
  waste: "bg-red-500/20 text-red-400 border-red-500/30",
  damage: "bg-amber-500/20 text-amber-400 border-amber-500/30",
  count: "bg-blue-500/20 text-blue-400 border-blue-500/30",
  return: "bg-violet-500/20 text-violet-400 border-violet-500/30",
  transfer: "bg-cyan-500/20 text-cyan-400 border-cyan-500/30",
};

export const TABLE_ICONS: Record<string, string> = {
  "layout-dashboard": "LayoutDashboard",
  "receipt": "Receipt",
  "package": "Package",
  "truck": "Truck",
  "calculator": "Calculator",
  "bar-chart-2": "BarChart3",
  "settings": "Settings",
  "shopping-cart": "ShoppingCart",
  "file-text": "FileText",
  "users": "Users",
};
