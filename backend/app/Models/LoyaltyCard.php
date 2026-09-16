<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoyaltyCard extends Model
{
    use BelongsToBusiness;

    protected static function booted(): void
    {
        static::bootBelongsToBusiness();
    }

    protected $fillable = [
        'business_id',
        'customer_id',
        'card_number',
        'points_balance',
        'total_points_earned',
        'total_points_redeemed',
        'total_spend',
        'tier',
        'is_active',
        'tier_upgraded_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'points_balance' => 'integer',
            'total_points_earned' => 'decimal:2',
            'total_points_redeemed' => 'decimal:2',
            'total_spend' => 'decimal:2',
            'is_active' => 'boolean',
            'tier_upgraded_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class);
    }

    public function earnPoints(int $points, string $description = null, int $invoiceId = null): LoyaltyTransaction
    {
        $this->increment('points_balance', $points);
        $this->increment('total_points_earned', $points);

        return $this->transactions()->create([
            'business_id' => $this->business_id,
            'type' => 'earn',
            'points' => $points,
            'balance_after' => $this->points_balance,
            'description' => $description ?? "Earned {$points} points",
            'invoice_id' => $invoiceId,
            'points_expiry_date' => now()->addYear(),
        ]);
    }

    public function redeemPoints(int $points, string $description = null, int $invoiceId = null): ?LoyaltyTransaction
    {
        if ($this->points_balance < $points) return null;

        $this->decrement('points_balance', $points);
        $this->increment('total_points_redeemed', $points);

        return $this->transactions()->create([
            'business_id' => $this->business_id,
            'type' => 'redeem',
            'points' => $points,
            'balance_after' => $this->points_balance,
            'description' => $description ?? "Redeemed {$points} points",
            'invoice_id' => $invoiceId,
        ]);
    }

    public function upgradeTier(): void
    {
        $spend = (float) $this->total_spend;
        $tier = 'bronze';
        if ($spend >= 5000) $tier = 'platinum';
        elseif ($spend >= 2000) $tier = 'gold';
        elseif ($spend >= 500) $tier = 'silver';

        if ($tier !== $this->tier) {
            $this->update(['tier' => $tier, 'tier_upgraded_at' => now()]);
        }
    }
}
