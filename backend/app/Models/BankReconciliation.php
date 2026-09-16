<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\BelongsToBusiness;

class BankReconciliation extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'account_id', 'statement_date', 'statement_balance',
        'book_balance', 'status', 'reconciled_at', 'reconciled_by',
        'notes', 'metadata',
    ];

    protected $casts = [
        'statement_date' => 'date',
        'statement_balance' => 'decimal:2',
        'book_balance' => 'decimal:2',
        'reconciled_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::bootBelongsToBusiness();
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function reconciler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BankReconciliationLine::class);
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeReconciled($query)
    {
        return $query->where('status', 'reconciled');
    }
}
