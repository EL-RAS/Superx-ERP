"use client";

import { useEffect, useState, useCallback } from "react";
import Link from "next/link";
import { motion } from "framer-motion";
import { useAuthStore } from "@/stores/auth-store";
import { useI18n } from "@/lib/i18n";
import KPICard from "@/components/ui/KPICard";
import { isSupermarketVertical } from "@/lib/morphing-engine";
import { fetchTenantDashboard } from "@/lib/api";
import type { DashboardStats, SupermarketDashboardStats } from "@/lib/types";
import { formatCurrency } from "@/lib/types";
import { DollarSign, Package, Receipt, AlertTriangle, Wallet, ShoppingCart, CalendarClock, ChevronRight } from "lucide-react";

const CATEGORY_COLORS: Record<string, string> = {
	produce: "#10b981",
	dairy: "#38bdf8",
	frozen: "#06b6d4",
	beverages: "#3b82f6",
	bakery: "#f59e0b",
	meat: "#ef4444",
	snacks: "#a855f7",
	other: "#94a3b8",
};

const PAYMENT_METHODS: { key: string; labelKey: string; color: string }[] = [
	{ key: "cash", labelKey: "pos.cash", color: "#10b981" },
	{ key: "card", labelKey: "pos.card", color: "#3b82f6" },
	{ key: "bank_transfer", labelKey: "pos.bank_transfer", color: "#6366f1" },
	{ key: "check", labelKey: "pos.check", color: "#f59e0b" },
	{ key: "mobile", labelKey: "pos.mobile", color: "#a855f7" },
	{ key: "credit", labelKey: "pos.pay_later", color: "#64748b" },
];

function HourlyAreaChart({ data }: { data: { hour: number; sales: number }[] }) {
	const W = 600;
	const H = 180;
	const PAD = 10;
	const max = Math.max(...data.map((d) => d.sales), 1);
	const n = data.length;
	const step = (W - PAD * 2) / (n - 1);
	const pt = (i: number) => {
		const x = PAD + i * step;
		const y = H - PAD - (data[i].sales / max) * (H - PAD * 2);
		return { x, y };
	};
	const line = data.map((_, i) => `${i === 0 ? "M" : "L"} ${pt(i).x.toFixed(1)} ${pt(i).y.toFixed(1)}`).join(" ");
	const area = `${line} L ${(PAD + (n - 1) * step).toFixed(1)} ${H - PAD} L ${PAD} ${H - PAD} Z`;
	const labels = [8, 12, 16, 20, 23];
	const labelIdx = labels.map((h) => data.findIndex((d) => d.hour === h)).filter((i) => i >= 0);

	return (
		<div>
			<svg viewBox={`0 0 ${W} ${H}`} className="w-full h-44" role="img">
				<defs>
					<linearGradient id="hourlyFill" x1="0" y1="0" x2="0" y2="1">
						<stop offset="0%" stopColor="#10b981" stopOpacity="0.35" />
						<stop offset="100%" stopColor="#10b981" stopOpacity="0.02" />
					</linearGradient>
				</defs>
				<path d={area} fill="url(#hourlyFill)" />
				<path d={line} fill="none" stroke="#10b981" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" />
				{data.map((d, i) =>
					d.sales > 0 ? <circle key={i} cx={pt(i).x} cy={pt(i).y} r={3} fill="#10b981" /> : null,
				)}
			</svg>
			<div className="flex justify-between text-[10px] text-muted mt-1 px-1">
				{labelIdx.map((i) => (
					<span key={i}>{data[i].hour}:00</span>
				))}
			</div>
		</div>
	);
}

function CategoryDonut({ entries }: { entries: { category: string; revenue: number; quantity: number }[] }) {
	const total = entries.reduce((s, e) => s + e.revenue, 0);
	if (total <= 0) return null;
	let acc = 0;
	const stops: string[] = [];
	entries.forEach((e) => {
		const pct = (e.revenue / total) * 100;
		const color = CATEGORY_COLORS[e.category] ?? "#94a3b8";
		stops.push(`${color} ${acc.toFixed(1)}% ${(acc + pct).toFixed(1)}%`);
		acc += pct;
	});

	return (
		<div className="flex items-center gap-6">
			<div
				className="relative w-36 h-36 rounded-full shrink-0"
				style={{ background: `conic-gradient(${stops.join(", ")})` }}
			>
				<div className="absolute inset-3 rounded-full bg-card" />
				<div className="absolute inset-0 flex flex-col items-center justify-center">
					<span className="text-xl font-bold text-foreground">{entries.length}</span>
					<span className="text-[10px] text-muted uppercase">{/* categories count */}</span>
				</div>
			</div>
			<div className="space-y-1.5 min-w-0 flex-1">
				{entries.map((e) => (
					<div key={e.category} className="flex items-center gap-2 text-sm">
						<span className="w-2.5 h-2.5 rounded-full shrink-0" style={{ background: CATEGORY_COLORS[e.category] ?? "#94a3b8" }} />
						<span className="text-muted truncate flex-1">{e.category}</span>
						<span className="text-foreground font-medium">{formatCurrency(e.revenue)}</span>
						<span className="text-muted text-xs w-10 text-end">{total > 0 ? ((e.revenue / total) * 100).toFixed(0) : 0}%</span>
					</div>
				))}
			</div>
		</div>
	);
}

export default function DashboardContent() {
	const { token, business } = useAuthStore();
	const { t, locale } = useI18n();
	const [supermarket, setSupermarket] = useState<SupermarketDashboardStats | null>(null);
	const [generic, setGeneric] = useState<DashboardStats | null>(null);
	const [loading, setLoading] = useState(true);
	const businessType = (business?.business_type?.slug || "").toLowerCase();
	const isSupermarket = isSupermarketVertical(businessType);

	const loadDashboard = useCallback(() => {
		if (!token || !business) return;
		setLoading(true);
		fetchTenantDashboard(token, business.id)
			.then((data) => {
				// The backend dispatches the response shape from the tenant
				// business type; route it into the matching view state.
				if (isSupermarket) {
					setSupermarket(data as SupermarketDashboardStats);
					setGeneric(null);
				} else {
					setGeneric(data as DashboardStats);
					setSupermarket(null);
				}
			})
			.catch(() => { })
			.finally(() => setLoading(false));
	}, [token, business, isSupermarket]);

	useEffect(() => {
		const timer = setTimeout(loadDashboard, 300);
		return () => clearTimeout(timer);
	}, [loadDashboard]);

	if (loading) {
		return (
			<motion.div className="min-h-screen flex items-center justify-center p-8 text-muted">
				<div className="text-center">
					<motion.div className="w-12 h-12 rounded-2xl bg-card/50 mx-auto mb-4 animate-spin" />
					<p className="text-foreground mb-2">{t("dashboard.loading")}</p>
				</div>
			</motion.div>
		);
	}

	// ── Generic dashboard (non-supermarket business types) ───────────
	if (!isSupermarket) {
		const s = generic;
		if (!s) {
			return (
				<div className="p-8 text-muted text-center">
					<p>{t("supermarket_dashboard.no_data")}</p>
				</div>
			);
		}
		const cogs = Number(s.invoices.cogs) || 0;
		const gross = Number(s.invoices.gross_profit) || 0;
		const margin = Number(s.invoices.gross_margin) || 0;
		return (
			<motion.div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} className="space-y-6">
				<div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
					<Link href="/invoices?filter=today" className="group">
						<KPICard icon={DollarSign} label={t("dashboard.revenue")} value={s.invoices.revenue} format="currency" accent="emerald" />
					</Link>
					<KPICard icon={Package} label={t("dashboard.gross_profit")} value={gross} format="currency" change={margin} accent="green" />
					<KPICard icon={Receipt} label={t("dashboard.invoices")} value={s.invoices.total} format="number" accent="indigo" />
					<Link href="/products" className="group">
						<KPICard icon={Package} label={t("dashboard.products")} value={s.products.total} format="number" accent="violet" />
					</Link>
				</div>

				<div className="glass rounded-2xl p-5 border border-border">
					<h3 className="text-lg font-semibold text-foreground mb-4">{t("dashboard.today")}</h3>
					<div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
						<div className="rounded-xl bg-card/50 p-4">
							<p className="text-sm text-muted">{t("dashboard.revenue")}</p>
							<p className="text-2xl font-bold text-foreground mt-1">{formatCurrency(s.today.revenue, locale)}</p>
						</div>
						<div className="rounded-xl bg-card/50 p-4">
							<p className="text-sm text-muted">{t("dashboard.invoices")}</p>
							<p className="text-2xl font-bold text-foreground mt-1">{s.today.transactions}</p>
						</div>
						<div className="rounded-xl bg-card/50 p-4">
							<p className="text-sm text-muted">{t("dashboard.cogs")}</p>
							<p className="text-2xl font-bold text-foreground mt-1">{formatCurrency(cogs, locale)}</p>
						</div>
					</div>
				</div>

				<div className="glass rounded-2xl p-5 border border-border">
					<h3 className="text-lg font-semibold text-foreground mb-4">{t("dashboard.recent_invoices")}</h3>
					<div className="overflow-x-auto">
						<table className="w-full text-sm">
							<thead>
								<tr className="text-muted text-start border-b border-border">
									<th className="py-2 text-start font-medium">{t("dashboard.invoice_number")}</th>
									<th className="py-2 text-start font-medium">{t("dashboard.amount")}</th>
									<th className="py-2 text-start font-medium">{t("common.status")}</th>
									<th className="py-2 text-start font-medium">{t("dashboard.created_by")}</th>
									<th className="py-2 text-start font-medium">{t("common.date")}</th>
								</tr>
							</thead>
							<tbody>
								{s.recent_invoices.map((inv) => (
									<tr key={inv.id} className="border-b border-border/50 text-foreground">
										<td className="py-2">{inv.invoice_number}</td>
										<td className="py-2">{formatCurrency(inv.net_amount, locale)}</td>
										<td className="py-2 capitalize">{inv.payment_status}</td>
										<td className="py-2">{inv.created_by}</td>
										<td className="py-2">{new Date(inv.created_at).toLocaleDateString(locale === "ar" ? "ar-JO" : "en-JO")}</td>
									</tr>
								))}
							</tbody>
						</table>
					</div>
				</div>
			</motion.div>
		);
	}

	// ── Supermarket operational command center ─────────────────────────
	const s = supermarket;
	if (!s) {
		return (
			<div className="p-8 text-muted text-center">
				<p>{t("supermarket_dashboard.no_data")}</p>
			</div>
		);
	}

	const revenueChange = Number(s.today.change) || 0;
	// Customer-facing payment split: only Cash + Card tender are shown in
	// supermarket mode (no bank transfer / check / mobile / pay-later).
	const paymentMethods = PAYMENT_METHODS.filter((m) => m.key === "cash" || m.key === "card");
	const paymentTotal = paymentMethods.reduce((sum, m) => sum + (Number(s.payment_breakdown?.[m.key as keyof SupermarketDashboardStats["payment_breakdown"]]) || 0), 0);
	const activeShift = s.active_shift;

	return (
		<motion.div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} className="space-y-6">
			{/* ========== SECTION 1: TOP KPI SUMMARY CARDS ========== */}
			<div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
				<Link href="/invoices?filter=today" className="group">
					<KPICard
						icon={DollarSign}
						label={t("supermarket_dashboard.today_revenue")}
						value={s.today.revenue}
						format="currency"
						change={revenueChange}
						accent="emerald"
					/>
				</Link>
				<Link href="/invoices?filter=gross_profit" className="group">
					<KPICard
						icon={Package}
						label={t("supermarket_dashboard.gross_profit")}
						value={s.today.gross_profit}
						format="currency"
						change={Number(s.today.gross_margin) || 0}
						accent="green"
					/>
				</Link>
				<Link href="/invoices?filter=today" className="group">
					<KPICard
						icon={Receipt}
						label={t("supermarket_dashboard.transaction_count")}
						value={s.today.transactions}
						format="number"
						accent="indigo"
					/>
				</Link>
				<Link href="/inventory/expiry" className="group">
					<KPICard
						icon={AlertTriangle}
						label={t("supermarket_dashboard.critical_expiry")}
						value={s.inventory.expiring_count}
						format="number"
						accent="red"
					/>
				</Link>
			</div>

			{/* Average ticket + low stock caption row */}
			<div className="flex flex-wrap items-center gap-3 -mt-2">
				<div className="text-sm text-muted">
					{t("supermarket_dashboard.average_ticket")}:{" "}
					<span className="text-foreground font-semibold">{formatCurrency(s.today.average_ticket, locale)}</span>
				</div>
				<div className="text-sm text-muted">
					{t("supermarket_dashboard.low_stock")}:{" "}
					<Link href="/inventory/low-stock" className="text-foreground font-semibold underline decoration-warning/50 hover:decoration-warning">
						{s.inventory.low_stock_count}
					</Link>
				</div>
			</div>

			{/* ========== SECTION 2: CHARTS ========== */}
			<div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
				{/* Hourly Sales Trend */}
				<div className="glass rounded-2xl p-5 border border-border lg:col-span-2">
					<h3 className="text-lg font-semibold text-foreground mb-4">{t("supermarket_dashboard.hourly_sales_trend")}</h3>
					{s.hourly_sales.length > 0 && s.hourly_sales.some((d) => d.sales > 0) ? (
						<HourlyAreaChart data={s.hourly_sales} />
					) : (
						<p className="text-muted text-sm h-44 flex items-center justify-center">{t("supermarket_dashboard.no_data")}</p>
					)}
					<p className="text-xs text-muted mt-2">{t("supermarket_dashboard.peak_hours_8_to_close")}</p>
				</div>

				{/* Sales by Category */}
				<div className="glass rounded-2xl p-5 border border-border">
					<h3 className="text-lg font-semibold text-foreground mb-4">{t("supermarket_dashboard.sales_by_category")}</h3>
					{s.sales_by_category.length > 0 ? (
						<CategoryDonut entries={s.sales_by_category} />
					) : (
						<p className="text-muted text-sm h-36 flex items-center justify-center">{t("supermarket_dashboard.no_data")}</p>
					)}
				</div>
			</div>

			{/* ========== SECTION 3: PAYMENT SPLIT + TOP MOVERS + SHIFT ========== */}
			<div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
				{/* Payment Method Split */}
				<div className="glass rounded-2xl p-5 border border-border">
					<h3 className="text-lg font-semibold text-foreground mb-4">{t("supermarket_dashboard.payment_method_split")}</h3>
					<div className="space-y-3">
						{paymentMethods.map((m) => {
							const value = Number(s.payment_breakdown?.[m.key as keyof SupermarketDashboardStats["payment_breakdown"]]) || 0;
							const pct = paymentTotal > 0 ? (value / paymentTotal) * 100 : 0;
							return (
								<div key={m.key}>
									<div className="flex items-center justify-between text-sm mb-1">
										<span className="text-muted">{t(m.labelKey)}</span>
										<span className="text-foreground font-medium">{formatCurrency(value, locale)}</span>
									</div>
									<div className="h-2 rounded-full bg-card/50 overflow-hidden">
										<div
											className="h-full rounded-full transition-all duration-500"
											style={{ width: `${pct}%`, background: m.color }}
										/>
									</div>
								</div>
							);
						})}
					</div>
				</div>

				{/* Top 5 Fast-Moving Items */}
				<div className="glass rounded-2xl p-5 border border-border">
					<h3 className="text-lg font-semibold text-foreground mb-4">{t("supermarket_dashboard.top_5_fast_moving")}</h3>
					{s.top_products.length > 0 ? (
						<div className="space-y-3">
							{s.top_products.map((p, idx) => {
								const maxQty = Math.max(...s.top_products.map((x) => Number(x.quantity)), 1);
								const pct = (Number(p.quantity) / maxQty) * 100;
								return (
									<Link key={p.product_id} href="/products" className="block group">
										<div className="flex items-center gap-3">
											<span className="text-xs font-bold text-muted w-4 shrink-0">{idx + 1}</span>
											{p.image_url ? (
												<img src={p.image_url} alt="" className="w-8 h-8 rounded-lg object-cover shrink-0 bg-card/50" />
											) : (
												<span className="w-8 h-8 rounded-lg bg-card/50 flex items-center justify-center shrink-0">
													<ShoppingCart className="w-4 h-4 text-muted" />
												</span>
											)}
											<div className="min-w-0 flex-1">
												<div className="flex items-center justify-between gap-2">
													<span className="text-sm text-foreground font-medium truncate">{p.name}</span>
													<span className="text-xs text-muted shrink-0">{p.quantity} {p.unit || t("common.unit")}</span>
												</div>
												<div className="h-1.5 rounded-full bg-card/50 overflow-hidden mt-1">
													<div className="h-full rounded-full bg-emerald-500/70" style={{ width: `${pct}%` }} />
												</div>
											</div>
										</div>
									</Link>
								);
							})}
						</div>
					) : (
						<p className="text-muted text-sm h-36 flex items-center justify-center">{t("supermarket_dashboard.no_items")}</p>
					)}
				</div>

				{/* Active Shift Summary */}
				<div className="glass rounded-2xl p-5 border border-border">
					<h3 className="text-lg font-semibold text-foreground mb-4">{t("supermarket_dashboard.active_shift")}</h3>
					{activeShift ? (
						<div className="space-y-3">
							<div className="flex items-center gap-3">
								<span className="w-10 h-10 rounded-xl bg-accent/10 text-accent flex items-center justify-center">
									<Wallet className="w-5 h-5" />
								</span>
								<div className="min-w-0">
									<p className="text-foreground font-semibold truncate">{activeShift.cashier}</p>
									<p className="text-xs text-muted">#{activeShift.shift_number}</p>
								</div>
							</div>
							<div className="grid grid-cols-2 gap-3 text-sm">
								<div className="rounded-xl bg-card/50 p-3">
									<p className="text-xs text-muted mb-1">{t("supermarket_dashboard.shift_start")}</p>
									<p className="text-foreground font-medium">
										{new Date(activeShift.started_at).toLocaleTimeString(locale === "ar" ? "ar-JO" : "en-JO", { hour: "numeric", minute: "2-digit" })}
									</p>
								</div>
								<div className="rounded-xl bg-card/50 p-3">
									<p className="text-xs text-muted mb-1">{t("supermarket_dashboard.cash_in_drawer")}</p>
									<p className="text-foreground font-medium">{formatCurrency(activeShift.expected_cash, locale)}</p>
								</div>
								<div className="rounded-xl bg-card/50 p-3">
									<p className="text-xs text-muted mb-1">{t("supermarket_dashboard.transaction_count")}</p>
									<p className="text-foreground font-medium">{activeShift.total_transactions}</p>
								</div>
								<div className="rounded-xl bg-card/50 p-3">
									<p className="text-xs text-muted mb-1">{t("dashboard.revenue")}</p>
									<p className="text-foreground font-medium">{formatCurrency(activeShift.total_sales, locale)}</p>
								</div>
							</div>
							<Link
								href="/pos/shifts"
								className="w-full flex items-center justify-center gap-2 rounded-xl bg-accent/10 text-accent hover:bg-accent/20 transition-colors py-2.5 text-sm font-semibold"
							>
								<CalendarClock className="w-4 h-4" />
								{t("supermarket_dashboard.close_shift")}
								<ChevronRight className="w-4 h-4 rtl:rotate-180" />
							</Link>
						</div>
					) : (
						<div className="h-36 flex flex-col items-center justify-center text-center text-muted">
							<Wallet className="w-8 h-8 mb-2 opacity-50" />
							<p className="text-sm">{t("supermarket_dashboard.no_items")}</p>
						</div>
					)}
				</div>
			</div>

			{/* ========== SECTION 4: NEAR-EXPIRY ALERT FEED ========== */}
			<div className="glass rounded-2xl p-5 border border-border">
				<h3 className="text-lg font-semibold text-foreground mb-4">{t("supermarket_dashboard.near_expiry_alert_feed")}</h3>
				{s.expiry_alerts.length > 0 ? (
					<div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
						{s.expiry_alerts.map((a) => (
							<Link key={a.batch_id} href="/inventory/batches" className="rounded-xl bg-card/50 p-3 hover:bg-card/80 transition-colors">
								<div className="flex items-center justify-between gap-2">
									<div className="min-w-0">
										<p className="text-sm text-foreground font-medium truncate">{a.product_name}</p>
										<p className="text-xs text-muted">{t("common.batch")} {a.batch_number} · {a.expiry_date}</p>
									</div>
									<span
										className={`shrink-0 text-xs font-semibold px-2 py-1 rounded-lg ${a.days_remaining <= 7 ? "bg-danger/10 text-danger" : "bg-warning/10 text-warning"}`}
									>
										{t("common.days")}: {a.days_remaining}
									</span>
								</div>
							</Link>
						))}
					</div>
				) : (
					<p className="text-muted text-sm py-4 text-center">{t("supermarket_dashboard.no_expiring")}</p>
				)}
			</div>
		</motion.div>
	);
}
