<?php

namespace App\Rules;

use App\Models\Product;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Enforces unit-aware quantity precision. Piece/count products (sold by the
 * piece: any non-weighable product whose unit is not a measurement) must be
 * ordered in positive whole numbers — fractional pieces are invalid. Weight /
 * measured products (is_weighable or a volume/weight unit) accept decimals up
 * to $maxDecimals places.
 *
 * The rule accepts either a concrete product id (single-quantity payloads like
 * `quantity` / `quantity_adjusted`) or a resolver closure that maps the
 * validated attribute back to its sibling `product_id` (array payloads like
 * `items.*.quantity`).
 */
class ProductQuantity implements ValidationRule
{
    private const MEASURED_UNITS = [
        'kg', 'g', 'mg', 'gram', 'grams', 'kilo', 'kilogram', 'kilograms',
        'oz', 'lb', 'lbs', 'pound', 'pounds',
        'l', 'liter', 'liters', 'litre', 'litres', 'ml',
        'tbsp', 'tsp', 'cup', 'cups',
    ];

    /**
     * @param  Closure(string): (int|string|null)|int|null  $productResolver
     */
    public function __construct(
        private readonly Closure|int|null $productResolver = null,
        private readonly int $maxDecimals = 3,
    ) {}

    public static function forProduct(int|string $productId, int $maxDecimals = 3): self
    {
        return new self((int) $productId, $maxDecimals);
    }

    public static function forItems(
        callable $resolver,
        int $maxDecimals = 3,
    ): self {
        return new self(Closure::fromCallable($resolver), $maxDecimals);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $productId = $this->resolveProductId($attribute);

        if ($productId === null) {
            return;
        }

        $product = Product::withTrashed()->find($productId);

        if (! $product) {
            return;
        }

        $allowedDecimals = $this->isMeasured($product) ? $this->maxDecimals : 0;
        $decimals = $this->decimalPlaces((string) $value);

        if ($decimals > $allowedDecimals) {
            $unitLabel = $product->unit ? strtolower($product->unit) : 'pcs';

            $fail($allowedDecimals === 0
                ? "The :attribute must be a whole number for {$unitLabel} products."
                : "The :attribute cannot have more than {$allowedDecimals} decimal place(s) for {$unitLabel} products.");
        }
    }

    private function resolveProductId(string $attribute): int|string|null
    {
        if (is_int($this->productResolver) || is_string($this->productResolver)) {
            return $this->productResolver === '' ? null : $this->productResolver;
        }

        return $this->productResolver === null ? null : ($this->productResolver)($attribute);
    }

    private function isMeasured(Product $product): bool
    {
        if ($product->is_weighable) {
            return true;
        }

        $unit = strtolower(trim((string) $product->unit));

        return $unit !== '' && in_array($unit, self::MEASURED_UNITS, true);
    }

    private function decimalPlaces(string $value): int
    {
        $value = strtolower(trim($value));

        if ($value === '' || $value === '0') {
            return 0;
        }

        // Scientific notation (e.g. 1.5e3) has no meaningful fixed precision
        // from the client perspective — defer to the numeric rules.
        if (str_contains($value, 'e')) {
            return 0;
        }

        if (! str_contains($value, '.')) {
            return 0;
        }

        $fraction = explode('.', $value)[1];

        return strlen(rtrim($fraction, '0'));
    }
}
