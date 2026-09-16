<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromotionUsage extends Model
{
    use BelongsToBusiness;

    protected static function booted(): void
    {
        static::bootBelongsToBusiness();
    }

    protected $fillable = [
        'business_id',
        'promotion_id',
        'invoice_id',
        'discount_amount',
        'associated_revenue',
    ];

    protected function casts(): array
    {
        return [
            'discount_amount' => 'decimal:2',
            'associated_revenue' => 'decimal:2',
        ];
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
