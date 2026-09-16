<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Shift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Slugs that resolve to the supermarket/grocery vertical. The canonical
     * identifier is "supermarket_hypermarket"; "supermarket" and "grocery"
     * are kept as legacy aliases so older seeds/tests still dispatch.
     */
    public const SUPERMARKET_SLUGS = ['supermarket_hypermarket', 'supermarket', 'grocery'];

    /**
     * Unified tenant dashboard entry point: inspects the authenticated
     * user's business type and dispatches to the supermarket command center
     * for grocery verticals, otherwise the generic stats overview.
     */
    public function dashboard(Request $request): JsonResponse
    {
        if ($this->isSupermarket($request)) {
            return $this->supermarket($request);
        }

        return $this->__invoke($request);
    }

    private function isSupermarket(Request $request): bool
    {
        $slug = $request->user()?->business?->businessType?->slug ?? '';

        return in_array(mb_strtolower($slug), self::SUPERMARKET_SLUGS, true);
    }

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $businessId = $user->business_id;

        $productCount = Product::where('business_id', $businessId)->count();
        $activeProductCount = Product::where('business_id', $businessId)->where('is_active', true)->count();

        $invoiceStats = Invoice::where('business_id', $businessId)
            ->where('status', '!=', 'void')
            ->selectRaw('count(*) as total_invoices')
            ->selectRaw('coalesce(sum(net_amount), 0) as total_revenue')
            ->selectRaw('coalesce(sum(case when payment_status = ? then net_amount else 0 end), 0) as paid_amount', ['paid'])
            ->selectRaw('coalesce(sum(case when payment_status = ? then net_amount else 0 end), 0) as unpaid_amount', ['unpaid'])
            ->first();

        $todayStats = Invoice::where('business_id', $businessId)
            ->where('status', '!=', 'void')
            ->whereDate('created_at', now()->toDateString())
            ->selectRaw('count(*) as total')
            ->selectRaw('coalesce(sum(net_amount), 0) as revenue')
            ->first();

        $revenue = (float) $invoiceStats->total_revenue;
        $cogs = $this->cogs($businessId);
        $grossProfit = round($revenue - $cogs, 2);
        $grossMargin = $revenue > 0 ? round(($grossProfit / $revenue) * 100, 2) : 0;

        $recentInvoices = Invoice::where('business_id', $businessId)
            ->with('user:id,name')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(fn ($inv) => [
                'id' => $inv->id,
                'invoice_number' => $inv->invoice_number,
                'net_amount' => $inv->net_amount,
                'payment_status' => $inv->payment_status,
                'created_by' => $inv->user->name ?? 'Unknown',
                'created_at' => $inv->created_at->toISOString(),
            ]);

        return response()->json([
            'products' => [
                'total' => $productCount,
                'active' => $activeProductCount,
            ],
            'invoices' => [
                'total' => $invoiceStats->total_invoices,
                'revenue' => $revenue,
                'paid' => (float) $invoiceStats->paid_amount,
                'unpaid' => (float) $invoiceStats->unpaid_amount,
                'gross_profit' => $grossProfit,
                'gross_margin' => $grossMargin,
                'cogs' => $cogs,
            ],
            'today' => [
                'transactions' => (int) $todayStats->total,
                'revenue' => (float) $todayStats->revenue,
            ],
            'recent_invoices' => $recentInvoices,
        ]);
    }

    /**
     * Real-time operational command center for supermarket / grocery
     * businesses. Aggregates today's sales, hourly trend, category split,
     * payment methods, expiry + low-stock alerts, top movers and the active
     * shift so the dashboard can act as a live control panel.
     */
    public function supermarket(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $expiryWarningDays = (int) ($request->user()->business->mergedSettings()['expiry_warning_days'] ?? 30);

        // ── Today vs Yesterday revenue ────────────────────────────────
        $todayStats = Invoice::where('business_id', $businessId)
            ->where('status', '!=', 'void')
            ->whereDate('created_at', $today)
            ->selectRaw('count(*) as transactions')
            ->selectRaw('coalesce(sum(net_amount), 0) as revenue')
            ->first();

        $yesterdayRevenue = (float) Invoice::where('business_id', $businessId)
            ->where('status', '!=', 'void')
            ->whereDate('created_at', $yesterday)
            ->sum('net_amount');

        $todayRevenue = (float) $todayStats->revenue;
        $todayTransactions = (int) $todayStats->transactions;
        $todayCogs = $this->cogs($businessId, $today);
        $todayGrossProfit = round($todayRevenue - $todayCogs, 2);
        $todayGrossMargin = $todayRevenue > 0 ? round($todayGrossProfit / $todayRevenue * 100, 2) : 0;
        $revenueChange = $yesterdayRevenue > 0
            ? round(($todayRevenue - $yesterdayRevenue) / $yesterdayRevenue * 100, 1)
            : ($todayRevenue > 0 ? 100.0 : 0.0);
        $averageTicket = $todayTransactions > 0 ? round($todayRevenue / $todayTransactions, 2) : 0;

        // ── Hourly sales trend (operating window 8 AM - 11 PM) ─────────
        $hourlyRows = Invoice::where('business_id', $businessId)
            ->where('status', '!=', 'void')
            ->whereDate('created_at', $today)
            ->selectRaw('EXTRACT(HOUR FROM created_at) as hour')
            ->selectRaw('coalesce(sum(net_amount), 0) as sales')
            ->groupByRaw('EXTRACT(HOUR FROM created_at)')
            ->pluck('sales', 'hour')
            ->map(fn ($v) => round((float) $v, 2));

        $hourlySales = [];
        for ($h = 8; $h <= 23; $h++) {
            $hourlySales[] = [
                'hour' => $h,
                'sales' => (float) ($hourlyRows[(string) $h] ?? 0),
            ];
        }

        // ── Sales by grocery category (today) ─────────────────────────
        $items = InvoiceItem::where('business_id', $businessId)
            ->whereHas('invoice', fn ($q) => $q
                ->where('business_id', $businessId)
                ->where('status', '!=', 'void')
                ->whereDate('created_at', $today))
            ->with('product:id,category')
            ->get();

        $categoryTotals = [];
        foreach ($items as $item) {
            $bucket = $this->groceryBucket($item->product?->category);
            $categoryTotals[$bucket] ??= ['category' => $bucket, 'revenue' => 0.0, 'quantity' => 0.0];
            $categoryTotals[$bucket]['revenue'] += (float) $item->quantity * (float) $item->unit_price;
            $categoryTotals[$bucket]['quantity'] += (float) $item->quantity;
        }

        $salesByCategory = collect($categoryTotals)
            ->values()
            ->map(fn ($c) => [
                'category' => $c['category'],
                'revenue' => round($c['revenue'], 2),
                'quantity' => round($c['quantity'], 2),
            ])
            ->sortByDesc('revenue')
            ->values();

        // ── Payment method split (today) ──────────────────────────────
        // Supermarket POS accepts only Cash and Card, so the customer-facing
        // widget shows exactly those two legs.
        $paymentRows = Payment::where('business_id', $businessId)
            ->where('status', 'completed')
            ->whereDate('created_at', $today)
            ->whereIn('method', ['cash', 'card'])
            ->selectRaw('method, coalesce(sum(amount), 0) as total')
            ->groupBy('method')
            ->pluck('total', 'method');

        $paymentBreakdown = [
            'cash' => round((float) ($paymentRows['cash'] ?? 0), 2),
            'card' => round((float) ($paymentRows['card'] ?? 0), 2),
        ];

        // ── Expiry alerts (configurable warning window, sellable stock) ─
        $expiryAlerts = ProductBatch::where('business_id', $businessId)
            ->where('is_active', true)
            ->where('expiry_date', '>=', now()->startOfDay())
            ->where('expiry_date', '<=', now()->addDays($expiryWarningDays))
            ->whereRaw('(quantity - quantity_sold) > 0')
            ->with('product:id,name,sku,unit')
            ->orderBy('expiry_date', 'asc')
            ->limit(8)
            ->get()
            ->map(fn ($b) => [
                'batch_id' => $b->id,
                'product_id' => $b->product_id,
                'product_name' => $b->product?->name ?? 'Unknown',
                'batch_number' => $b->batch_number,
                'expiry_date' => $b->expiry_date?->toDateString(),
                'days_remaining' => (int) now()->startOfDay()->diffInDays($b->expiry_date),
                'current_stock' => round((float) $b->quantity - (float) $b->quantity_sold, 2),
            ]);

        // ── Low stock alerts ───────────────────────────────────────────
        $lowStock = Product::where('business_id', $businessId)
            ->where('is_active', true)
            ->where('min_stock', '>', 0)
            ->with(['batches' => function ($q) {
                $q->where(function ($qq) {
                    $qq->whereNull('expiry_date')->orWhere('expiry_date', '>=', now());
                })->whereRaw('(quantity - quantity_sold) > 0');
            }])
            ->get()
            ->filter(function (Product $p) {
                $stock = $p->has_batch
                    ? (float) $p->batches->sum(fn ($b) => (float) $b->quantity - (float) $b->quantity_sold)
                    : (float) $p->stock_quantity;

                return $stock <= (float) $p->min_stock;
            })
            ->map(fn (Product $p) => [
                'product_id' => $p->id,
                'product_name' => $p->name,
                'sku' => $p->sku,
                'current_stock' => round(
                    $p->has_batch
                        ? (float) $p->batches->sum(fn ($b) => (float) $b->quantity - (float) $b->quantity_sold)
                        : (float) $p->stock_quantity,
                    2
                ),
                'min_stock' => round((float) $p->min_stock, 2),
            ])
            ->sortBy('current_stock')
            ->values()
            ->take(8);

        // ── Top 5 fast-moving items (today by quantity) ───────────────
        $topProducts = InvoiceItem::where('business_id', $businessId)
            ->whereNotNull('product_id')
            ->whereHas('invoice', fn ($q) => $q
                ->where('business_id', $businessId)
                ->where('status', '!=', 'void')
                ->whereDate('created_at', $today))
            ->with('product:id,name,image_url,unit')
            ->selectRaw('product_id, coalesce(sum(quantity), 0) as quantity, coalesce(sum(quantity * unit_price), 0) as revenue')
            ->groupBy('product_id')
            ->orderByRaw('sum(quantity) desc')
            ->limit(5)
            ->get()
            ->map(fn ($it) => [
                'product_id' => $it->product_id,
                'name' => $it->product?->name ?? 'Unknown',
                'image_url' => $it->product?->image_url,
                'unit' => $it->product?->unit,
                'quantity' => round((float) $it->quantity, 2),
                'revenue' => round((float) $it->revenue, 2),
            ]);

        // ── Active shift (latest open across the business) ─────────────
        $activeShift = Shift::with('user:id,name')
            ->where('business_id', $businessId)
            ->where('status', 'open')
            ->latest('started_at')
            ->first();

        $activeShiftData = null;
        if ($activeShift) {
            $shiftInvoices = $activeShift->invoices()
                ->where('status', '!=', 'void')
                ->get();

            $shiftPayments = $activeShift->payments()
                ->where('status', 'completed')
                ->get();

            $cashSales = $shiftPayments->where('method', 'cash')->sum('amount');
            $cashRefunds = round((float) $activeShift->payments()
                ->where('method', 'cash')
                ->where('refunded_amount', '>', 0)
                ->sum('refunded_amount'), 2);

            $activeShiftData = [
                'id' => $activeShift->id,
                'shift_number' => $activeShift->shift_number,
                'cashier' => $activeShift->user?->name ?? 'Unknown',
                'started_at' => $activeShift->started_at?->toISOString(),
                'opening_balance' => (float) $activeShift->opening_balance,
                'expected_cash' => round((float) $activeShift->opening_balance + (float) $cashSales - $cashRefunds, 2),
                'total_sales' => round((float) $shiftInvoices->sum('net_amount'), 2),
                'total_transactions' => $shiftInvoices->count(),
                'cash_refunds' => $cashRefunds,
            ];
        }

        return response()->json([
            'generated_at' => now()->toISOString(),
            'today' => [
                'revenue' => $todayRevenue,
                'change' => $revenueChange,
                'yesterday_revenue' => $yesterdayRevenue,
                'gross_profit' => $todayGrossProfit,
                'gross_margin' => $todayGrossMargin,
                'transactions' => $todayTransactions,
                'average_ticket' => $averageTicket,
            ],
            'inventory' => [
                'expiring_count' => count($expiryAlerts),
                'low_stock_count' => count($lowStock),
            ],
            'hourly_sales' => $hourlySales,
            'sales_by_category' => $salesByCategory,
            'payment_breakdown' => $paymentBreakdown,
            'expiry_alerts' => $expiryAlerts,
            'low_stock' => $lowStock,
            'top_products' => $topProducts,
            'active_shift' => $activeShiftData,
        ]);
    }

    /**
     * Cost of goods sold across non-void invoices. Batch-exact when the
     * line carries FEFO deduction metadata, otherwise falls back to the
     * product cost. When $date is given only invoices created on that day
     * are included.
     */
    private function cogs(string $businessId, ?string $date = null): float
    {
        $items = InvoiceItem::where('business_id', $businessId)
            ->whereHas('invoice', function ($q) use ($businessId, $date) {
                $q->where('business_id', $businessId)->where('status', '!=', 'void');
                if ($date) {
                    $q->whereDate('created_at', $date);
                }
            })
            ->with('product:id,cost')
            ->get();

        $total = 0.0;

        foreach ($items as $item) {
            $qty = (float) $item->quantity;
            $deductions = is_array($item->metadata['deductions'] ?? null) ? $item->metadata['deductions'] : [];

            if ($deductions) {
                foreach ($deductions as $d) {
                    $total += (float) ($d['unit_cost'] ?? 0) * (float) ($d['quantity'] ?? 0);
                }
            } else {
                $total += $qty * (float) ($item->product?->cost ?? 0);
            }
        }

        return round($total, 2);
    }

    /**
     * Map a free-text product category to a fixed grocery bucket used by the
     * donut chart. English + Arabic keywords, anything else → other.
     */
    private function groceryBucket(?string $category): string
    {
        $c = mb_strtolower(trim((string) $category));

        $rules = [
            'produce' => ['produce', 'fruit', 'vegetable', 'veg', 'خضار', 'فواكه', 'خضراوات'],
            'dairy' => ['dairy', 'milk', 'cheese', 'yogurt', 'yoghurt', 'لبن', 'جبن', 'البان', 'ألبان'],
            'frozen' => ['frozen', 'ice', 'مجمد', 'مجمدات', 'مثلجات'],
            'beverages' => ['beverage', 'drink', 'juice', 'water', 'soda', 'مشروب', 'مشروبات', 'عصير', 'ماء'],
            'bakery' => ['bakery', 'bread', 'pastry', 'مخبز', 'خبز', 'معجنات'],
            'meat' => ['meat', 'fish', 'chicken', 'seafood', 'poultry', 'لحم', 'دجاج', 'سمك'],
            'snacks' => ['snack', 'chips', 'candy', 'chocolate', 'سناك', 'شيبس', 'حلويات'],
        ];

        foreach ($rules as $bucket => $keywords) {
            foreach ($keywords as $kw) {
                if ($c !== '' && str_contains($c, $kw)) {
                    return $bucket;
                }
            }
        }

        return 'other';
    }
}
