<?php

namespace App\Services;

class TaxCalculator
{
    /**
     * Split one sale line into net-of-tax amount and tax amount.
     *
     * Inclusive tax (tax enabled and method !== exclusive): the line total
     * already contains tax, so net = total / (1 + rate / 100) and tax is the
     * remainder (derived from the raw net, both rounded to 4 dp). Exclusive:
     * net is the full total and tax = (total - discount) * rate. Discounts
     * only affect the exclusive tax (the inclusive path never sees them),
     * matching the long-standing invoice line math.
     *
     * @return array{net: float, tax: float}
     */
    public static function line(float $total, float $rate, bool $inclusive, float $discount = 0.0): array
    {
        if ($inclusive && $rate > 0) {
            $net = $total / (1 + $rate / 100);

            return [
                'net' => round($net, 4),
                'tax' => round($total - $net, 4),
            ];
        }

        return [
            'net' => $total,
            'tax' => ($total - $discount) * ($rate / 100),
        ];
    }
}
