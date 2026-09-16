<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model
{
    use BelongsToBusiness;
    use SoftDeletes;

    protected static function booted(): void
    {
        static::bootBelongsToBusiness();
    }

    protected $fillable = [
        'business_id',
        'supplier_id',
        'user_id',
        'order_number',
        'status',
        'total_amount',
        'notes',
        'expected_delivery',
        'received_at',
    ];

    protected $appends = [
        'paid_amount',
        'remaining_amount',
    ];

    protected $hidden = [
        'payments_sum',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'expected_delivery' => 'date',
            'received_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PurchaseOrderPayment::class);
    }

    public function getPaidAmountAttribute(): float
    {
        if ($this->relationLoaded('payments')) {
            $sum = $this->payments->where('status', 'completed')->sum('amount');
        } else {
            $sum = $this->getAttribute('payments_sum') ?? $this->payments()->where('status', 'completed')->sum('amount');
        }

        return round((float) $sum, 2);
    }

    public function getRemainingAmountAttribute(): float
    {
        return round((float) $this->total_amount - $this->paid_amount, 2);
    }
}
