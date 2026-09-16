<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZReport extends Model
{
    use BelongsToBusiness;

    protected static function booted(): void
    {
        static::bootBelongsToBusiness();
    }

    protected $fillable = [
        'business_id',
        'shift_id',
        'user_id',
        'report_number',
        'started_at',
        'ended_at',
        'opening_balance',
        'closing_balance',
        'expected_cash',
        'actual_cash',
        'variance',
        'total_sales',
        'total_refunds',
        'total_discounts',
        'total_tax',
        'total_transactions',
        'payment_breakdown',
        'top_products',
        'summary',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'closing_balance' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'actual_cash' => 'decimal:2',
            'variance' => 'decimal:2',
            'total_sales' => 'decimal:2',
            'total_refunds' => 'decimal:2',
            'total_discounts' => 'decimal:2',
            'total_tax' => 'decimal:2',
            'payment_breakdown' => 'array',
            'top_products' => 'array',
            'summary' => 'array',
            'metadata' => 'array',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
