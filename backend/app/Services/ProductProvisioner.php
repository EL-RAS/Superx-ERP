<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Product;
use App\Scopes\BusinessScope;

/**
 * Creates a minimal tenant product when merchandise arrives (or is ordered)
 * before the merchant has registered it in the store catalog.
 *
 * The defaults mirror what the purchase-order "import as new product" flow has
 * always produced — piece unit, 3x markup, 16% tax, zero opening stock — so a
 * product born from a PO and one born from a goods receipt look identical in
 * the catalog. The caller then flows the new product through the ordinary
 * receiving path, which is what gives it its real initial stock / batch.
 *
 * SKU is generated against the business type prefix and probed for collisions:
 * the `[business_id, sku]` unique index means a naive `count + 1` breaks as
 * soon as a product has been deleted.
 */
class ProductProvisioner
{
    /**
     * @param  array{
     *     business_id: string,
     *     user_id?: int|string|null,
     *     name: string,
     *     unit_cost: int|float|string,
     *     supplier_id?: int|string|null,
     *     source: string,
     *     has_batch?: bool,
     *     is_weighable?: bool,
     * }  $payload
     */
    public static function create(array $payload): Product
    {
        $businessId = (string) $payload['business_id'];
        $cost = round((float) $payload['unit_cost'], 2);
        $hasBatch = (bool) ($payload['has_batch'] ?? false);
        // A measured item arrives as a fraction, so it must be born measured —
        // otherwise the *next* fractional receipt is rejected by the
        // whole-number rule that piece products enforce.
        $weighable = (bool) ($payload['is_weighable'] ?? false);

        return Product::create([
            'business_id' => $businessId,
            'created_by' => $payload['user_id'] ?? null,
            'name' => $payload['name'],
            'sku' => self::nextSku($businessId),
            'barcode' => null,
            'unit' => $weighable ? 'kg' : 'pcs',
            'is_weighable' => $weighable,
            'price' => round($cost * 3, 2),
            'cost' => $cost,
            'tax_rate' => 16,
            'has_expiry' => $hasBatch,
            'has_batch' => $hasBatch,
            'min_stock' => 0,
            'stock_quantity' => 0,
            'is_active' => true,
            'metadata' => [
                'source' => $payload['source'],
                'supplier_id' => $payload['supplier_id'] ?? null,
            ],
        ]);
    }

    private static function nextSku(string $businessId): string
    {
        $slug = Business::withoutGlobalScope(BusinessScope::class)
            ->with('businessType')
            ->find($businessId)
            ?->businessType?->slug ?? 'gen';

        $typeCode = strtoupper(substr(preg_replace('/[^a-z0-9]/i', '', $slug), 0, 4));
        if ($typeCode === '') {
            $typeCode = 'GEN';
        }

        $existing = fn (string $sku): bool => Product::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $businessId)
            ->where('sku', $sku)
            ->exists();

        $sequence = Product::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $businessId)
            ->count() + 1;

        do {
            $sku = sprintf('%s-GEN-%04d', $typeCode, $sequence);
            $sequence++;
        } while ($existing($sku));

        return $sku;
    }
}
