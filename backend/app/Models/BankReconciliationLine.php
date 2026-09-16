<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\BelongsToBusiness;

class BankReconciliationLine extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'bank_reconciliation_id', 'journal_entry_line_id',
        'amount', 'description', 'reference', 'statement_date',
        'reconciled', 'reconciled_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'statement_date' => 'date',
        'reconciled' => 'boolean',
        'reconciled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::bootBelongsToBusiness();
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function bankReconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class);
    }

    public function journalEntryLine(): BelongsTo
    {
        return $this->belongsTo(JournalEntryLine::class);
    }
}
