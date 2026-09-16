<?php

namespace App\Models;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariant extends Model
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
        'sku',
        'barcode',
        'attribute1_name',
        'attribute1_value',
        'attribute2_name',
        'attribute2_value',
        'price_adjustment',
        'cost_adjustment',
        'stock_quantity',
        'is_active',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'price_adjustment' => 'decimal:2',
            'cost_adjustment' => 'decimal:2',
            'stock_quantity' => 'integer',
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
}
