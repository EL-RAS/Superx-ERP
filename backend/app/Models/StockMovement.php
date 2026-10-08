<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    use BelongsToBusiness;

    protected static function booted(): void
    {
        static::bootBelongsToBusiness();
    }

    protected $fillable = [
        'business_id',
        'product_id',
        'batch_id',
        'from_warehouse_id',
        'to_warehouse_id',
        'quantity',
        'type',
        'reference_type',
        'reference_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'batch_id');
    }

    /**
     * Record the stock movement rows for a deduction list.
     *
     * Sale-like flows return one frozen deduction per source batch (FEFO for
     * batch-managed products, cost layers for simple ones), so the movement
     * log gets one row per batch with `batch_id` stamped - shelf, invoice and
     * ledger then agree on exactly which batch the units left. Callers with no
     * deduction detail fall back to a single aggregate row (`batch_id = null`).
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int|string, mixed>  $deductions
     * @return array<int, self>
     */
    public static function recordDeductions(array $attributes, array $deductions): array
    {
        $rows = array_values(array_filter(
            $deductions,
            fn ($deduction): bool => is_array($deduction) && (float) ($deduction['quantity'] ?? 0) > 0
        ));

        if ($rows === []) {
            return [static::create($attributes)];
        }

        $movements = [];
        foreach ($rows as $deduction) {
            $notes = $attributes['notes'] ?? null;
            $batchNumber = $deduction['batch_number'] ?? null;

            $movements[] = static::create(array_merge($attributes, [
                'batch_id' => $deduction['batch_id'] ?? null,
                'quantity' => round((float) $deduction['quantity'], 4),
                'notes' => ($batchNumber && $notes) ? "{$notes} [{$batchNumber}]" : $notes,
            ]));
        }

        return $movements;
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }
}
