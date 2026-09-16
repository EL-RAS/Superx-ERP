<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    protected $fillable = [
        'name',
        'business_name',
        'business_type_id',
        'phone',
        'email',
        'subdomain',
        'city',
        'status',
        'tenant_business_id',
        'approved_by',
        'provisioned_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'provisioned_at' => 'datetime',
        ];
    }

    public function businessType(): BelongsTo
    {
        return $this->belongsTo(BusinessType::class);
    }

    public function activationTokens(): HasMany
    {
        return $this->hasMany(ActivationToken::class);
    }

    public function isProvisioned(): bool
    {
        return $this->tenant_business_id !== null;
    }
}
