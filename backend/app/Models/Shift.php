<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    use BelongsToBusiness;

    protected static function booted(): void
    {
        static::bootBelongsToBusiness();
    }

    protected $fillable = [
        'business_id',
        'user_id',
        'shift_number',
        'opening_balance',
        'closing_balance',
        'expected_cash',
        'actual_cash',
        'variance',
        'total_sales',
        'total_refunds',
        'total_discounts',
        'total_transactions',
        'payment_breakdown',
        'status',
        'started_at',
        'ended_at',
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
            'payment_breakdown' => 'array',
            'metadata' => 'array',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
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

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public static function openFor(string $businessId, int $userId): ?Shift
    {
        return static::where('business_id', $businessId)
            ->where('user_id', $userId)
            ->where('status', 'open')
            ->latest('started_at')
            ->first();
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    public function close(float $actualCash, array $paymentBreakdown, float $cashRefunds = 0): void
    {
        $cashSales = (float) ($paymentBreakdown['cash'] ?? 0);
        $expectedCash = round((float) $this->opening_balance + $cashSales - $cashRefunds, 2);

        $this->update([
            'status' => 'closed',
            'ended_at' => now(),
            'closing_balance' => $actualCash,
            'expected_cash' => $expectedCash,
            'actual_cash' => $actualCash,
            'variance' => round($actualCash - $expectedCash, 2),
            'payment_breakdown' => $paymentBreakdown,
        ]);
    }
}
