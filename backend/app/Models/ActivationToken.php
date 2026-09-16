<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One-time expiring activation token issued by the platform owner when a
 * lead is approved & provisioned. Escorts the first-time setup wizard and
 * is consumed (single-use) when the root tenant admin account is created.
 */
class ActivationToken extends Model
{
    protected $fillable = [
        'lead_id',
        'tenant_id',
        'business_id',
        'token',
        'expires_at',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function isValid(): bool
    {
        return $this->consumed_at === null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    public function consume(): void
    {
        $this->update([
            'consumed_at' => now(),
            'expires_at' => now(), // belt & braces: a consumed token is never "re-valid"
        ]);
    }
}