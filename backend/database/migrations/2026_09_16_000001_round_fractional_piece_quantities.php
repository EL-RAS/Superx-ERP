<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data cleanup for unit-aware quantity enforcement. Piece/count products
 * (non-weighable products whose unit is not a measurement) may only ever hold
 * whole-number quantities — a 20.37 count of "pcs" is meaningless. Row 2 of the
 * old flows (manual batch edits, legacy multi-unit GRNs, direct adjustments)
 * could slip fractional stock past the previous numeric-only rules, so this
 * migration rounds every stock figure for piece products to the nearest unit.
 *
 * Weighed/measured products (is_weighable or a kg/g/liter/ml unit) are left
 * untouched — their stock is legitimately decimal.
 */
return new class extends Migration
{
    private const MEASURED_UNITS = [
        'kg', 'g', 'mg', 'gram', 'grams', 'kilo', 'kilogram', 'kilograms',
        'oz', 'lb', 'lbs', 'pound', 'pounds',
        'l', 'liter', 'liters', 'litre', 'litres', 'ml',
        'tbsp', 'tsp', 'cup', 'cups',
    ];

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['pgsql', 'mysql', 'mariadb', 'sqlite'], true)) {
            return;
        }

        $pieceProductIds = DB::table('products')
            ->where('is_weighable', false)
            ->where(function ($query) {
                $query->whereNull('unit')
                    ->orWhere('unit', '')
                    ->orWhereNotIn(DB::raw('LOWER(unit)'), self::MEASURED_UNITS);
            })
            ->pluck('id');

        if ($pieceProductIds->isEmpty()) {
            return;
        }

        $ids = $pieceProductIds->all();

        DB::table('products')
            ->whereIn('id', $ids)
            ->update(['stock_quantity' => DB::raw('ROUND(stock_quantity)')]);

        foreach (['quantity', 'quantity_sold', 'quantity_returned'] as $column) {
            DB::table('product_batches')
                ->whereIn('product_id', $ids)
                ->whereNotNull($column)
                ->update([$column => DB::raw("ROUND({$column})")]);
        }

        foreach (['quantity_before', 'quantity_adjusted', 'quantity_after'] as $column) {
            DB::table('inventory_adjustments')
                ->whereIn('product_id', $ids)
                ->whereNotNull($column)
                ->update([$column => DB::raw("ROUND({$column})")]);
        }
    }

    public function down(): void
    {
        // Rounding is not reversible — dirty fractional piece stock was
        // meaningless data, not something we can restore.
    }
};
