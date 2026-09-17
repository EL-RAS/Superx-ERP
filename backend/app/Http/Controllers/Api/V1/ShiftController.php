<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\ZReport;
use App\Services\AccountingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ShiftController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Shift::query();

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        $shifts = $query->with('user:id,name')
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 10));

        return response()->json($shifts);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'opening_balance' => 'required|numeric|min:0',
            'carried_balance' => 'nullable|numeric|min:0',
        ]);

        $openShift = Shift::where('user_id', $request->user()->id)
            ->where('status', 'open')
            ->first();

        if ($openShift) {
            return response()->json([
                'message' => 'You already have an open shift. Close it before opening a new one.',
                'shift' => $openShift,
            ], 422);
        }

        $shiftNumber = 'SH-'.strtoupper(Str::random(8));

        $opening = round((float) $validated['opening_balance'], 2);
        $carried = round((float) ($validated['carried_balance'] ?? 0), 2);
        $postedAmount = round(max(0, $opening - $carried), 2);

        return DB::transaction(function () use ($request, $shiftNumber, $opening, $carried, $postedAmount) {
            $shift = Shift::create([
                'business_id' => $request->user()->business_id,
                'user_id' => $request->user()->id,
                'shift_number' => $shiftNumber,
                'opening_balance' => $opening,
                'status' => 'open',
                'started_at' => now(),
                'metadata' => [
                    'carried_balance' => $carried,
                    'float_posted_amount' => $postedAmount,
                ],
            ]);

            app(AccountingService::class)->postShiftOpenEntry(
                $request->user()->business_id,
                $shift,
                $postedAmount,
                $request->user()->id
            );

            return response()->json($shift, 201);
        });
    }

    public function show(Shift $shift): JsonResponse
    {
        $shift->load('user:id,name');

        return response()->json($shift);
    }

    public function close(Request $request, Shift $shift): JsonResponse
    {
        if ($shift->status !== 'open') {
            return response()->json(['message' => 'This shift is already closed.'], 422);
        }

        $user = $request->user();
        if ($user->role !== 'admin' && $user->id !== $shift->user_id) {
            return response()->json(['message' => 'Only the shift owner or an administrator can close this shift.'], 403);
        }

        $validated = $request->validate([
            'actual_cash' => 'required|numeric|min:0',
            'payment_breakdown' => 'nullable|array',
            'payment_breakdown.cash' => 'nullable|numeric|min:0',
            'payment_breakdown.card' => 'nullable|numeric|min:0',
        ]);

        $businessId = $shift->business_id;

        $invoices = $shift->invoices()
            ->where('status', '!=', 'void')
            ->get();

        $totalSales = $invoices->sum('net_amount');
        $totalTax = $invoices->sum('tax_amount');
        $totalDiscounts = $invoices->sum('discount_amount');
        $totalTransactions = $invoices->count();

        $payments = $shift->payments()
            ->where('status', 'completed')
            ->whereIn('method', ['cash', 'card'])
            ->get();

        $paymentBreakdown = $validated['payment_breakdown'] ?? [
            'cash' => $payments->where('method', 'cash')->sum('amount'),
            'card' => $payments->where('method', 'card')->sum('amount'),
        ];

        $cashRefunds = round((float) $shift->payments()
            ->where('method', 'cash')
            ->where('refunded_amount', '>', 0)
            ->sum('refunded_amount'), 2);

        $totalRefunds = round((float) $shift->payments()
            ->where('refunded_amount', '>', 0)
            ->sum('refunded_amount'), 2);

        $expectedCash = round(
            (float) $shift->opening_balance
            + (float) $shift->payments()
                ->where('method', 'cash')
                ->sum('amount')
            - $cashRefunds,
            2
        );

        $shift->update([
            'total_sales' => $totalSales,
            'total_refunds' => $totalRefunds,
            'total_discounts' => $totalDiscounts,
            'total_transactions' => $totalTransactions,
        ]);

        $shift->close(
            $validated['actual_cash'],
            $paymentBreakdown,
            $cashRefunds,
            $expectedCash
        );

        app(AccountingService::class)->postShiftCloseEntry(
            $businessId,
            $shift,
            $request->user()->id
        );

        $reportNumber = 'ZR-'.strtoupper(Str::random(8));

        $topProducts = $invoices->flatMap(fn ($inv) => $inv->items ?? [])
            ->groupBy('product_id')
            ->map(fn ($items) => [
                'product_id' => $items->first()->product_id,
                'name' => $items->first()->name,
                'quantity' => $items->sum('quantity'),
                'total' => $items->sum('total'),
            ])
            ->sortByDesc('quantity')
            ->take(10)
            ->values()
            ->toArray();

        ZReport::create([
            'business_id' => $businessId,
            'shift_id' => $shift->id,
            'user_id' => $shift->user_id,
            'report_number' => $reportNumber,
            'started_at' => $shift->started_at,
            'ended_at' => now(),
            'opening_balance' => $shift->opening_balance,
            'closing_balance' => $validated['actual_cash'],
            'expected_cash' => $shift->expected_cash,
            'actual_cash' => $validated['actual_cash'],
            'variance' => $shift->variance,
            'total_sales' => $totalSales,
            'total_refunds' => $shift->total_refunds,
            'total_discounts' => $totalDiscounts,
            'total_tax' => $totalTax,
            'total_transactions' => $totalTransactions,
            'payment_breakdown' => $paymentBreakdown,
            'top_products' => $topProducts,
            'summary' => [
                'invoice_count' => $totalTransactions,
                'avg_transaction' => $totalTransactions > 0 ? round($totalSales / $totalTransactions, 4) : 0,
                'cash_received' => $paymentBreakdown['cash'] ?? 0,
                'cash_refunds' => $cashRefunds,
                'card_received' => $paymentBreakdown['card'] ?? 0,
            ],
        ]);

        $shift->load('user:id,name');

        return response()->json($shift);
    }

    public function zReport(Shift $shift): JsonResponse
    {
        $shift->load('user:id,name');

        $zReport = ZReport::where('shift_id', $shift->id)->first();

        if ($zReport) {
            $zReport->load(['shift:id,shift_number', 'user:id,name']);
            $zReport->setAttribute('shift_number', $zReport->shift?->shift_number);
            $zReport->setAttribute('cashier', $zReport->user?->name);

            return response()->json([
                'shift' => $shift,
                'z_report' => $zReport,
            ]);
        }

        $paymentBreakdown = $shift->payment_breakdown ?? [];

        if (empty($paymentBreakdown)) {
            $payments = $shift->payments()
                ->where('status', 'completed')
                ->whereIn('method', ['cash', 'card'])
                ->get();

            $paymentBreakdown = [
                'cash' => $payments->where('method', 'cash')->sum('amount'),
                'card' => $payments->where('method', 'card')->sum('amount'),
            ];
        }

        $cashRefunds = round((float) $shift->payments()
            ->where('method', 'cash')
            ->where('refunded_amount', '>', 0)
            ->sum('refunded_amount'), 2);

        $expectedCash = $shift->expected_cash !== null
            ? (float) $shift->expected_cash
            : round(
                (float) $shift->opening_balance
                + (float) $shift->payments()->where('method', 'cash')->sum('amount')
                - $cashRefunds,
                2
            );

        return response()->json([
            'shift' => $shift,
            'z_report' => [
                'shift_number' => $shift->shift_number,
                'cashier' => $shift->user?->name,
                'started_at' => $shift->started_at,
                'ended_at' => $shift->ended_at,
                'opening_balance' => $shift->opening_balance,
                'closing_balance' => $shift->closing_balance,
                'expected_cash' => $expectedCash,
                'actual_cash' => $shift->actual_cash,
                'variance' => $shift->actual_cash !== null
                    ? round((float) $shift->actual_cash - $expectedCash, 2)
                    : null,
                'total_sales' => $shift->total_sales,
                'total_refunds' => $shift->total_refunds,
                'total_discounts' => $shift->total_discounts,
                'total_transactions' => $shift->total_transactions,
                'payment_breakdown' => $paymentBreakdown,
                'cash_refunds' => $cashRefunds,
                'net_cash' => round((float) $shift->total_sales - (float) $shift->total_refunds, 2),
                'shift' => [
                    'id' => $shift->id,
                    'shift_number' => $shift->shift_number,
                ],
                'user' => [
                    'id' => $shift->user_id,
                    'name' => $shift->user?->name,
                ],
            ],
        ]);
    }

    public function current(Request $request): JsonResponse
    {
        $shift = Shift::where('user_id', $request->user()->id)
            ->where('status', 'open')
            ->with('user:id,name')
            ->first();

        if ($shift) {
            $cashSales = (float) $shift->payments()
                ->where('method', 'cash')
                ->sum('amount');

            $cashRefunds = round((float) $shift->payments()
                ->where('method', 'cash')
                ->where('refunded_amount', '>', 0)
                ->sum('refunded_amount'), 2);

            $shift->expected_cash = round((float) $shift->opening_balance + $cashSales - $cashRefunds, 2);
        }

        return response()->json($shift);
    }

    /**
     * The requesting user's most recent closed shift, used by the POS start-shift
     * modal to prefill the opening float (handover cash) without exposing the
     * business-wide shift history (which is gated behind pos.shifts).
     */
    public function lastClosed(Request $request): JsonResponse
    {
        $shift = Shift::where('user_id', $request->user()->id)
            ->where('status', 'closed')
            ->with('user:id,name')
            ->latest('ended_at')
            ->first();

        return response()->json($shift);
    }
}
