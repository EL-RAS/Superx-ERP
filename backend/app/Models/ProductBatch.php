<?php

namespace App\Models;

use App\Exceptions\InsufficientStockException;
use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductBatch extends Model
{
    use BelongsToBusiness;
    use SoftDeletes;

    protected static function booted(): void
    {
        static::bootBelongsToBusiness();
    }

    protected $fillable = [
        'business_id',
        'product_id',
        'batch_number',
        'source_type',
        'goods_receipt_id',
        'quantity',
        'quantity_sold',
        'quantity_returned',
        'supplier_id',
        'received_date',
        'expiry_date',
        'manufacturing_date',
        'cost_per_unit',
        'total_cost',
        'selling_price',
        'storage_location',
        'is_active',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'quantity_sold' => 'decimal:2',
            'quantity_returned' => 'decimal:2',
            'cost_per_unit' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'expiry_date' => 'date',
            'manufacturing_date' => 'date',
            'received_date' => 'date',
            'is_active' => 'boolean',
            'metadata' => 'array',
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

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->expiry_date && $this->expiry_date->isPast();
    }

    public function getIsExpiringSoonAttribute(): bool
    {
        return $this->expiry_date && $this->expiry_date->diffInDays(now()) <= 30 && ! $this->is_expired;
    }

    public function getRemainingQuantityAttribute(): float
    {
        return (float) $this->quantity - (float) $this->quantity_sold;
    }

    public static function getAvailableFefoBatches(int $productId, string $businessId): Collection
    {
        return static::where('product_id', $productId)
            ->where('business_id', $businessId)
            ->whereRaw('(quantity - quantity_sold) > 0')
            ->where(function ($q) {
                $q->whereNull('expiry_date')
                    ->orWhere('expiry_date', '>=', now());
            })
            ->orderBy('expiry_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();
    }

    public static function getAvailableFefoStock(int $productId, string $businessId): float
    {
        return (float) static::getAvailableFefoBatches($productId, $businessId)
            ->sum(fn ($b) => (float) $b->quantity - (float) $b->quantity_sold);
    }

    /**
     * Deduct quantity from batches using FEFO (First Expired, First Out).
     * Returns array of batch deductions. Throws InsufficientStockException when
     * the demanded quantity exceeds available batch stock — unless $allowNegative
     * is true, in which case the oversold remainder is booked against the last
     * eligible batch (mirroring simple-product negative stock). The whole
     * deduction runs inside a database transaction so a multi-batch deduction
     * can never leave stock half-updated.
     */
    public static function deductFefo(int $productId, string $businessId, float $quantity, bool $allowNegative = false): array
    {
        $batches = static::getAvailableFefoBatches($productId, $businessId);

        if ($batches->isEmpty() || (! $allowNegative && $quantity > static::getAvailableFefoStock($productId, $businessId) + 0.001)) {
            throw new InsufficientStockException(
                'Insufficient batch stock. Available: '.static::getAvailableFefoStock($productId, $businessId).", requested: {$quantity}."
            );
        }

        $remaining = $quantity;
        $deductions = [];
        $lastBatch = null;

        DB::transaction(function () use (&$deductions, &$remaining, &$lastBatch, $batches) {
            foreach ($batches as $batch) {
                if ($remaining <= 0) {
                    break;
                }

                $batchAvailable = (float) $batch->quantity - (float) $batch->quantity_sold;
                $toDeduct = min($remaining, $batchAvailable);

                if ($toDeduct > 0) {
                    $batch->increment('quantity_sold', $toDeduct);
                    $deductions[] = [
                        'batch_id' => $batch->id,
                        'batch_number' => $batch->batch_number,
                        'quantity' => round($toDeduct, 2),
                        'unit_cost' => (float) $batch->cost_per_unit,
                    ];
                    $remaining -= $toDeduct;
                }

                if ($batchAvailable > 0) {
                    $lastBatch = $batch;
                }
            }

            if ($remaining > 0 && $lastBatch) {
                $lastBatch->increment('quantity_sold', $remaining);
                $deductions[] = [
                    'batch_id' => $lastBatch->id,
                    'batch_number' => $lastBatch->batch_number,
                    'quantity' => round($remaining, 2),
                    'unit_cost' => (float) $lastBatch->cost_per_unit,
                ];
            }
        });

        return $deductions;
    }
}
