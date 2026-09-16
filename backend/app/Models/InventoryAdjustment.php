<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryAdjustment extends Model
{
    use BelongsToBusiness;
    use SoftDeletes;

    protected static function booted(): void
    {
        static::bootBelongsToBusiness();
    }

    protected $fillable = [
        'business_id',
        'user_id',
        'product_id',
        'batch_id',
        'adjustment_number',
        'type',
        'liability_type',
        'supplier_id',
        'purchase_return_id',
        'quantity_before',
        'quantity_adjusted',
        'quantity_after',
        'unit_cost',
        'reason',
        'notes',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'quantity_before' => 'decimal:2',
            'quantity_adjusted' => 'decimal:2',
            'quantity_after' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'batch_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(InventoryAdjustment::class, 'purchase_return_id');
    }

    /**
     * Posted journal entries that reference this adjustment: the main
     * inventory_adjustment entry plus the sales_return entry for type=return
     * (Dr 4010 + 2020 / Cr refund account) which shares the same reference_id.
     */
    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'reference_id', 'id')
            ->whereIn('reference_type', ['inventory_adjustment', 'sales_return'])
            ->latest();
    }
}
