<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReturnExchange extends Model
{
    use BelongsToBusiness;

    protected static function booted(): void
    {
        static::bootBelongsToBusiness();
    }

    protected $fillable = [
        'business_id',
        'user_id',
        'invoice_id',
        'return_number',
        'type',
        'status',
        'refund_method',
        'returned_amount',
        'exchanged_amount',
        'difference_amount',
        'refund_amount',
        'exchange_invoice_id',
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'returned_amount' => 'decimal:2',
            'exchanged_amount' => 'decimal:2',
            'difference_amount' => 'decimal:2',
            'refund_amount' => 'decimal:2',
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

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function exchangeInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'exchange_invoice_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReturnExchangeItem::class);
    }
}
