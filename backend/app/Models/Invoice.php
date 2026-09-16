<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
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
        'shift_id',
        'customer_id',
        'invoice_number',
        'status',
        'total_amount',
        'tax_amount',
        'discount_amount',
        'net_amount',
        'subtotal',
        'shipping_amount',
        'payment_status',
        'due_date',
        'currency',
        'notes',
        'metadata',
        'sent_at',
        'voided_at',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'shipping_amount' => 'decimal:2',
            'metadata' => 'array',
            'due_date' => 'date',
            'sent_at' => 'datetime',
            'voided_at' => 'datetime',
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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function returnsExchanges(): HasMany
    {
        return $this->hasMany(ReturnExchange::class, 'invoice_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    protected function isOverdue(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->due_date && $this->due_date->isPast() && $this->payment_status !== 'paid',
        );
    }

    public function scopeUnpaid($query)
    {
        return $query->whereIn('payment_status', ['unpaid', 'partial']);
    }

    public function scopeOverdue($query)
    {
        return $query->where('due_date', '<', now())->unpaid();
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function calculateTotals(): void
    {
        $subtotal = $this->items->sum(fn ($item) => (float) $item->total);
        $totalTax = $this->items->sum(fn ($item) => (float) $item->tax_amount);
        $totalDiscount = $this->items->sum(fn ($item) => (float) $item->discount);

        $this->update([
            'subtotal' => $subtotal,
            'total_amount' => $subtotal,
            'tax_amount' => $totalTax,
            'discount_amount' => $totalDiscount,
            'net_amount' => $subtotal + (float) $this->shipping_amount,
        ]);
    }

    public function recalculatePaymentStatus(): void
    {
        $paidAmount = $this->payments()->where('status', 'completed')->sum('amount');
        $netAmount = (float) $this->net_amount;

        if ($paidAmount <= 0) {
            $status = 'unpaid';
        } elseif ($paidAmount >= $netAmount) {
            $status = 'paid';
        } else {
            $status = 'partial';
        }

        $this->update(['payment_status' => $status]);
    }
}
