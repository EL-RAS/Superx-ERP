<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Promotion;
use Illuminate\Support\Collection;

class PromotionService
{
    /**
     * Evaluate a POS cart against the business's active promotions.
     *
     * Single Best Deal Rule: a cart item may NEVER stack multiple promotions.
     * When several promotions are eligible for the same item, only the one that
     * yields the highest monetary discount for that item is applied; cart-wide
     * promotions (fixed / bundle / whole-cart percentage) are attributed to the
     * items they cover so the per-item competition stays exact.
     *
     * `$localTime` is the client's local wall clock ("H:i" or "H:i:s") so
     * happy-hour windows are evaluated in the store's local timezone instead of
     * the server's UTC clock; null falls back to the server time.
     *
     * @param  array<int, array{product_id: int, quantity: int|float, unit_price: float}>  $items
     * @return array{applied_promotions: array<int, array{id: mixed, name: string, type: string, discount: float}>, total_discount: float, cart_subtotal: float, cart_total: float}
     */
    public function apply(array $items, ?string $localTime = null): array
    {
        $cartSubtotal = (float) collect($items)->sum(
            fn (array $item) => (float) $item['quantity'] * (float) $item['unit_price']
        );

        $applied = [];
        $totalDiscount = 0.0;

        if ($this->promotionsEnabled() && $cartSubtotal > 0) {
            $activePromotions = Promotion::active()->get();

            if ($activePromotions->isNotEmpty()) {
                $products = $this->loadProducts(array_column($items, 'product_id'));

                $best = $this->bestPerItemDiscounts($activePromotions, $items, $products, $cartSubtotal, $this->resolveAtMinutes($localTime));

                $appliedById = [];

                foreach ($best as $entry) {
                    $promotion = $entry['promotion'];
                    $discount = $entry['discount'];

                    $totalDiscount += $discount;

                    if (! isset($appliedById[$promotion->id])) {
                        $appliedById[$promotion->id] = [
                            'id' => $promotion->id,
                            'name' => $promotion->name,
                            'type' => $promotion->type,
                            'discount' => 0.0,
                        ];
                    }

                    $appliedById[$promotion->id]['discount'] += $discount;
                }

                foreach ($appliedById as &$appliedPromo) {
                    $appliedPromo['discount'] = round($appliedPromo['discount'], 4);
                }

                $applied = array_values($appliedById);
            }
        }

        $totalDiscount = min($totalDiscount, $cartSubtotal);

        return [
            'applied_promotions' => $applied,
            'total_discount' => round($totalDiscount, 4),
            'cart_subtotal' => round($cartSubtotal, 4),
            'cart_total' => round(max(0, $cartSubtotal - $totalDiscount), 4),
        ];
    }

    protected function promotionsEnabled(): bool
    {
        $business = app(BusinessContext::class)->getBusiness();

        if (! $business) {
            return false;
        }

        return (bool) ($business->mergedSettings()['promotions_enabled'] ?? false);
    }

    /**
     * Parse a client "H:i" / "H:i:s" local-time string into minutes since midnight.
     */
    protected function resolveAtMinutes(?string $localTime): ?int
    {
        if (! $localTime || ! preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($localTime), $m)) {
            return null;
        }

        $hour = (int) $m[1];
        $minute = (int) $m[2];

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return ($hour * 60) + $minute;
    }

    protected function loadProducts(array $productIds): Collection
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));

        if (empty($productIds)) {
            return collect();
        }

        return Product::whereIn('id', $productIds)
            ->get(['id', 'category_id', 'category'])
            ->keyBy('id');
    }

    /**
     * For every cart item, resolve the single eligible promotion that gives the
     * highest monetary discount for that item.
     *
     * @param  array<int, array{product_id: int, quantity: int|float, unit_price: float}>  $items
     * @return array<int, array{promotion: Promotion, discount: float}>
     */
    protected function bestPerItemDiscounts(Collection $promotions, array $items, Collection $products, float $cartSubtotal, ?int $atMinutes = null): array
    {
        $best = [];

        foreach ($promotions as $promotion) {
            if ($promotion->max_uses && $promotion->current_uses >= $promotion->max_uses) {
                continue;
            }

            if ($promotion->min_amount > 0 && $cartSubtotal < (float) $promotion->min_amount) {
                continue;
            }

            $applicableIds = array_map('intval', $promotion->applicable_products ?? []);

            if (! empty($applicableIds)) {
                $cartProductIds = array_map('intval', array_column($items, 'product_id'));

                if (count(array_intersect($applicableIds, $cartProductIds)) === 0) {
                    continue;
                }
            }

            $perItem = $this->perItemDiscountsFor($promotion, $items, $products, $cartSubtotal, $applicableIds, $atMinutes);

            foreach ($perItem as $index => $discount) {
                if ($discount > 0 && (! isset($best[$index]) || $discount > $best[$index]['discount'])) {
                    $best[$index] = ['promotion' => $promotion, 'discount' => $discount];
                }
            }
        }

        return $best;
    }

    /**
     * Per-item discount contributions for a single promotion.
     *
     * @param  array<int, array{product_id: int, quantity: int|float, unit_price: float}>  $items
     * @param  array<int, int>  $applicableIds
     * @return array<int, float>
     */
    protected function perItemDiscountsFor(Promotion $promotion, array $items, Collection $products, float $cartSubtotal, array $applicableIds, ?int $atMinutes = null): array
    {
        if ($promotion->type === 'happy_hour' && ! $promotion->isHappyHourAt($atMinutes)) {
            return [];
        }

        return match ($promotion->type) {
            'percentage', 'happy_hour' => $this->percentagePerItem($promotion, $items, $applicableIds),
            'fixed' => $this->fixedPerItem($promotion, $items, $cartSubtotal),
            'bogo' => $this->bogoPerItem($promotion, $items, $applicableIds),
            'bundle' => $this->bundlePerItem($promotion, $items),
            'multi_buy' => $this->multiBuyPerItem($promotion, $items, $applicableIds),
            'category' => $this->categoryPerItem($promotion, $items, $products),
            default => [],
        };
    }

    /**
     * @param  array<int, array{product_id: int, quantity: int|float, unit_price: float}>  $items
     * @param  array<int, int>  $applicableIds
     * @return array<int, float>
     */
    protected function percentagePerItem(Promotion $promotion, array $items, array $applicableIds): array
    {
        $minQuantity = (int) $promotion->min_quantity;
        $pct = (float) $promotion->value / 100;
        $result = [];

        foreach ($items as $index => $item) {
            if (! empty($applicableIds) && ! in_array((int) $item['product_id'], $applicableIds, true)) {
                continue;
            }

            if ($minQuantity > 0 && (int) $item['quantity'] < $minQuantity) {
                continue;
            }

            $line = (float) $item['quantity'] * (float) $item['unit_price'];

            if ($line > 0) {
                $result[$index] = round($line * $pct, 4);
            }
        }

        return $result;
    }

    /**
     * Flat cart discount, attributed proportionally so per-item competition stays exact.
     *
     * @param  array<int, array{product_id: int, quantity: int|float, unit_price: float}>  $items
     * @return array<int, float>
     */
    protected function fixedPerItem(Promotion $promotion, array $items, float $cartSubtotal): array
    {
        $flat = min((float) ($promotion->discount_value ?? $promotion->value), $cartSubtotal);

        if ($flat <= 0 || $cartSubtotal <= 0) {
            return [];
        }

        $result = [];

        foreach ($items as $index => $item) {
            $line = (float) $item['quantity'] * (float) $item['unit_price'];

            if ($line <= 0) {
                continue;
            }

            $result[$index] = round($flat * ($line / $cartSubtotal), 4);
        }

        return $result;
    }

    /**
     * @param  array<int, array{product_id: int, quantity: int|float, unit_price: float}>  $items
     * @param  array<int, int>  $applicableIds
     * @return array<int, float>
     */
    protected function bogoPerItem(Promotion $promotion, array $items, array $applicableIds): array
    {
        $buyQty = (int) ($promotion->buy_quantity ?? 2);
        $getQty = (int) ($promotion->get_quantity ?? 1);

        if ($buyQty < 1 || $getQty < 1) {
            return [];
        }

        $result = [];

        foreach ($items as $index => $item) {
            if (! empty($applicableIds) && ! in_array((int) $item['product_id'], $applicableIds, true)) {
                continue;
            }

            $groups = intdiv((int) $item['quantity'], $buyQty + $getQty);

            if ($groups > 0) {
                $result[$index] = round($groups * $getQty * (float) $item['unit_price'], 4);
            }
        }

        return $result;
    }

    /**
     * Combo discount attributed proportionally across the combo products in the cart.
     *
     * @param  array<int, array{product_id: int, quantity: int|float, unit_price: float}>  $items
     * @return array<int, float>
     */
    protected function bundlePerItem(Promotion $promotion, array $items): array
    {
        $comboProducts = array_map('intval', $promotion->combo_products ?? []);

        if (empty($comboProducts)) {
            return [];
        }

        $comboLines = [];
        $comboTotal = 0.0;

        foreach ($items as $index => $item) {
            if (! in_array((int) $item['product_id'], $comboProducts, true)) {
                continue;
            }

            $line = (float) $item['quantity'] * (float) $item['unit_price'];
            $comboLines[$index] = $line;
            $comboTotal += $line;
        }

        if ($comboTotal <= 0) {
            return [];
        }

        $discount = min((float) ($promotion->discount_value ?? 0), $comboTotal);
        $result = [];

        foreach ($comboLines as $index => $line) {
            $result[$index] = round($discount * ($line / $comboTotal), 4);
        }

        return $result;
    }

    /**
     * @param  array<int, array{product_id: int, quantity: int|float, unit_price: float}>  $items
     * @param  array<int, int>  $applicableIds
     * @return array<int, float>
     */
    protected function multiBuyPerItem(Promotion $promotion, array $items, array $applicableIds): array
    {
        $bundleSize = (int) ($promotion->min_quantity ?? 2);
        $bundlePrice = (float) $promotion->value;

        if ($bundleSize < 1) {
            return [];
        }

        $result = [];

        foreach ($items as $index => $item) {
            if (! empty($applicableIds) && ! in_array((int) $item['product_id'], $applicableIds, true)) {
                continue;
            }

            $qty = (float) $item['quantity'];

            if ($qty < $bundleSize) {
                continue;
            }

            $unit = (float) $item['unit_price'];
            $groups = (int) floor($qty / $bundleSize);
            $remainder = $qty - ($groups * $bundleSize);
            $discount = max(0, ($qty * $unit) - (($groups * $bundlePrice) + ($remainder * $unit)));

            if ($discount > 0) {
                $result[$index] = round($discount, 4);
            }
        }

        return $result;
    }

    /**
     * @param  array<int, array{product_id: int, quantity: int|float, unit_price: float}>  $items
     * @return array<int, float>
     */
    protected function categoryPerItem(Promotion $promotion, array $items, Collection $products): array
    {
        $categoryId = (int) $promotion->category_id;

        if (! $categoryId) {
            return [];
        }

        $category = $promotion->category;
        $minQuantity = (int) $promotion->min_quantity;
        $pct = (float) $promotion->value / 100;
        $result = [];

        foreach ($items as $index => $item) {
            $product = $products->get((int) $item['product_id']);

            if (! $product) {
                continue;
            }

            $matches = (int) $product->category_id === $categoryId;

            if (! $matches && $category && $product->category) {
                $matches = strtolower((string) $product->category) === strtolower((string) $category->name);
            }

            if (! $matches) {
                continue;
            }

            if ($minQuantity > 0 && (int) $item['quantity'] < $minQuantity) {
                continue;
            }

            $line = (float) $item['quantity'] * (float) $item['unit_price'];

            if ($line > 0) {
                $result[$index] = round($line * $pct, 4);
            }
        }

        return $result;
    }
}
