<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use App\Services\PromotionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PromotionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Promotion::query();

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->has('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('name', 'ilike', "%{$search}%");
        }

        $promotions = $query->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 10));

        $stats = DB::table('promotion_usages')
            ->whereIn('promotion_id', $promotions->pluck('id'))
            ->selectRaw('promotion_id, COUNT(*) as times_used, ROUND(SUM(associated_revenue), 2) as total_revenue, ROUND(SUM(discount_amount), 2) as total_discount')
            ->groupBy('promotion_id')
            ->get()
            ->keyBy('promotion_id');

        $promotions->getCollection()->transform(function ($promotion) use ($stats) {
            $row = $stats->get($promotion->id);
            $timesUsed = (int) ($row->times_used ?? 0);
            $totalRevenue = (float) ($row->total_revenue ?? 0);
            $totalDiscount = (float) ($row->total_discount ?? 0);

            $promotion->setAttribute('stats', [
                'times_used' => $timesUsed,
                'total_revenue' => round($totalRevenue, 2),
                'total_discount' => round($totalDiscount, 2),
                'effectiveness' => $this->effectiveness($timesUsed, $totalRevenue, $totalDiscount),
            ]);

            return $promotion;
        });

        return response()->json($promotions);
    }

    /**
     * Classify a promotion's real-world impact from its recorded usage.
     *  - negative_margin: discounts ate more than 30% of the generated revenue.
     *  - high_impact: meaningful revenue or a solid number of redemptions.
     *  - low_impact: unused, or below both thresholds.
     */
    private function effectiveness(int $timesUsed, float $totalRevenue, float $totalDiscount): string
    {
        if ($timesUsed <= 0) {
            return 'low_impact';
        }

        $discountRate = $totalRevenue > 0 ? $totalDiscount / $totalRevenue : 1.0;
        if ($discountRate > 0.30) {
            return 'negative_margin';
        }

        if ($totalRevenue >= 500.0 || $timesUsed >= 20) {
            return 'high_impact';
        }

        return 'low_impact';
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'type' => 'required|in:percentage,fixed,bogo,bundle,multi_buy,category,happy_hour',
            'value' => 'required|numeric|min:0',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'min_quantity' => 'nullable|integer|min:1',
            'min_amount' => 'nullable|numeric|min:0',
            'buy_quantity' => 'nullable|integer|min:1',
            'get_quantity' => 'nullable|integer|min:1',
            'discount_value' => 'nullable|numeric|min:0',
            'max_uses' => 'nullable|integer|min:1',
            'combo_products' => 'nullable|array',
            'happy_hour_start' => 'nullable|date_format:H:i',
            'happy_hour_end' => 'nullable|date_format:H:i',
            'applicable_products' => 'nullable|array',
            'category_id' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
            'metadata' => 'nullable|array',
        ]);

        $validated['business_id'] = $request->user()->business_id;
        $validated['is_active'] = $validated['is_active'] ?? true;
        $validated['current_uses'] = 0;

        $promotion = Promotion::create($validated);

        return response()->json($promotion, 201);
    }

    public function show(Promotion $promotion): JsonResponse
    {
        return response()->json($promotion);
    }

    public function update(Request $request, Promotion $promotion): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string',
            'type' => 'sometimes|in:percentage,fixed,bogo,bundle,multi_buy,category,happy_hour',
            'value' => 'sometimes|numeric|min:0',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after:start_date',
            'min_quantity' => 'nullable|integer|min:1',
            'min_amount' => 'nullable|numeric|min:0',
            'buy_quantity' => 'nullable|integer|min:1',
            'get_quantity' => 'nullable|integer|min:1',
            'discount_value' => 'nullable|numeric|min:0',
            'max_uses' => 'nullable|integer|min:1',
            'combo_products' => 'nullable|array',
            'happy_hour_start' => 'nullable|date_format:H:i',
            'happy_hour_end' => 'nullable|date_format:H:i',
            'applicable_products' => 'nullable|array',
            'category_id' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
            'metadata' => 'nullable|array',
        ]);

        $promotion->update($validated);

        return response()->json($promotion);
    }

    public function destroy(Promotion $promotion): JsonResponse
    {
        $promotion->delete();

        return response()->json(['message' => 'Promotion deleted.']);
    }

    public function apply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|numeric|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'customer_id' => 'nullable|integer',
            'local_time' => 'nullable|date_format:H:i',
        ]);

        $result = app(PromotionService::class)->apply($validated['items'], $validated['local_time'] ?? null);

        return response()->json($result);
    }
}
