<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Promotion extends Model
{
    use BelongsToBusiness;
    use SoftDeletes;

    protected static function booted(): void
    {
        static::bootBelongsToBusiness();
    }

    protected $fillable = [
        'business_id',
        'name',
        'type',
        'value',
        'start_date',
        'end_date',
        'min_quantity',
        'min_amount',
        'buy_quantity',
        'get_quantity',
        'discount_value',
        'max_uses',
        'current_uses',
        'combo_products',
        'happy_hour_start',
        'happy_hour_end',
        'applicable_products',
        'category_id',
        'is_active',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'min_amount' => 'decimal:2',
            'start_date' => 'datetime',
            'end_date' => 'datetime',
            'combo_products' => 'array',
            'applicable_products' => 'array',
            'happy_hour_start' => 'string',
            'happy_hour_end' => 'string',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function promotionUsages(): HasMany
    {
        return $this->hasMany(PromotionUsage::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where('start_date', '<=', now())
            ->where('end_date', '>=', now());
    }

    public function getIsCurrentlyActiveAttribute(): bool
    {
        if (! $this->is_active) {
            return false;
        }
        if ($this->start_date && $this->start_date->isFuture()) {
            return false;
        }
        if ($this->end_date && $this->end_date->isPast()) {
            return false;
        }
        if ($this->max_uses && $this->current_uses >= $this->max_uses) {
            return false;
        }

        return true;
    }

    public function getIsHappyHourAttribute(): bool
    {
        return $this->isHappyHourAt();
    }

    /**
     * Whether the wall-clock time falls inside the happy-hour window.
     *
     * `$atMinutes` is minutes-since-midnight in the business's local wall clock
     * (as supplied by the POS client). When null, the server's own clock is
     * used. Supports overnight windows (end < start, e.g. 18:00 -> 00:00).
     */
    public function isHappyHourAt(?int $atMinutes = null): bool
    {
        if (! $this->happy_hour_start || ! $this->happy_hour_end) {
            return false;
        }

        $start = $this->parseMinutes($this->happy_hour_start);
        $end = $this->parseMinutes($this->happy_hour_end);

        if ($start === null || $end === null) {
            return false;
        }

        $nowMinutes = $atMinutes ?? (((int) now()->format('G') * 60) + (int) now()->format('i'));

        if ($start <= $end) {
            return $nowMinutes >= $start && $nowMinutes <= $end;
        }

        // Overnight window (e.g. 18:00 -> 00:00 or 22:00 -> 02:00): active before midnight and after midnight.
        return $nowMinutes >= $start || $nowMinutes <= $end;
    }

    /**
     * Parse an H:i (or H:i:s) clock string into minutes since midnight.
     */
    protected function parseMinutes(string $time): ?int
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($time), $m)) {
            return null;
        }

        $hour = (int) $m[1];
        $minute = (int) $m[2];

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return ($hour * 60) + $minute;
    }
}
