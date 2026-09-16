<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PoliceBookEntry extends Model
{
    use BelongsToBusiness;

    protected static function booted(): void
    {
        static::bootBelongsToBusiness();
    }

    protected $fillable = [
        'business_id',
        'entry_number',
        'entry_type',
        'item_description',
        'item_weight',
        'item_carat',
        'metal_type',
        'customer_name',
        'customer_id',
        'customer_id_number',
        'transaction_date',
        'transaction_amount',
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'item_weight' => 'decimal:2',
            'transaction_date' => 'date',
            'transaction_amount' => 'decimal:2',
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
}
