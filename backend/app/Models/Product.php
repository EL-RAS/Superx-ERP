<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Product extends Model
{
    use BelongsToBusiness;
    use SoftDeletes;

    protected static function booted(): void
    {
        static::bootBelongsToBusiness();
    }

    protected $fillable = [
        'business_id',
        'created_by',
        'collection_id',
        'category_id',
        'name',
        'sku',
        'barcode',
        'unit',
        'price',
        'sale_price',
        'is_on_sale',
        'cost',
        'tax_rate',
        'category',
        'purchase_unit',
        'purchase_unit_qty',
        'has_expiry',
        'has_batch',
        'min_stock',
        'stock_quantity',
        'storage_location',
        'is_weighable',
        'is_active',
        'image_url',
        'metadata',
    ];

    protected $appends = ['is_fully_expired', 'effective_price'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'is_on_sale' => 'boolean',
            'cost' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'purchase_unit_qty' => 'decimal:4',
            'min_stock' => 'decimal:2',
            'stock_quantity' => 'decimal:2',
            'has_expiry' => 'boolean',
            'has_batch' => 'boolean',
            'is_weighable' => 'boolean',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function getIsFullyExpiredAttribute(): bool
    {
        if (! $this->has_batch) {
            return false;
        }
        $hasExpired = $this->batches()->where('expiry_date', '<', now())->exists();
        $hasNonExpiredStock = $this->batches()
            ->where(function ($q) {
                $q->whereNull('expiry_date')
                    ->orWhere('expiry_date', '>=', now());
            })
            ->whereRaw('(quantity - quantity_sold) > 0')
            ->exists();

        return $hasExpired && ! $hasNonExpiredStock;
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function getEffectivePriceAttribute(): float
    {
        if ($this->is_on_sale && $this->sale_price !== null && (float) $this->sale_price > 0) {
            return round((float) $this->sale_price, 2);
        }

        return round((float) $this->price, 2);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(ProductBatch::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function serialNumbers(): HasMany
    {
        return $this->hasMany(SerialNumber::class);
    }

    public function recipe(): HasOne
    {
        return $this->hasOne(Recipe::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(InventoryAdjustment::class);
    }

    public function recalculateStockQuantity(): void
    {
        $stock = (float) $this->batches()
            ->where(function ($q) {
                $q->whereNull('expiry_date')
                    ->orWhere('expiry_date', '>=', now());
            })
            ->sum(DB::raw('quantity - quantity_sold'));

        $this->update(['stock_quantity' => round($stock, 2)]);
    }

    public function updateWeightedAverageCost(): void
    {
        $batches = $this->batches()
            ->whereRaw('(quantity - quantity_sold) > 0')
            ->get(['cost_per_unit', 'quantity', 'quantity_sold']);

        if ($batches->isEmpty()) {
            return;
        }

        $totalValue = $batches->sum(fn ($b) => (float) $b->cost_per_unit * ((float) $b->quantity - (float) $b->quantity_sold));
        $totalStock = $batches->sum(fn ($b) => (float) $b->quantity - (float) $b->quantity_sold);

        if ($totalStock > 0) {
            $this->update(['cost' => round($totalValue / $totalStock, 2)]);
        }
    }

    public function autoPrice(): void
    {
        // Only auto-price products that still have no manually set price;
        // never clobber a price the owner entered or adjusted.
        if (! $this->price || (float) $this->price <= 0) {
            $this->update(['price' => round((float) $this->cost * 3, 2)]);
        }
    }
}
